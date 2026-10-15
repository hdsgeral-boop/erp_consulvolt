<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Integracoes\IA\Regras\RegraFicheiro;
use App\Services\Integracoes\IA\ServicoFicheirosRegrasIA;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/contabilidade/assistente/ficheiros-regras — Assistente IA › Regras em ficheiro (ecrã `ia_assistente`, Versão Actual).
 * Permissões do catálogo (verificadas ANTES de validar o pedido, OWASP A01):
 *  - ver a lista, o detalhe, o modelo e exportar: ia_assistente_view, ia_regras ou ia_lancar;
 *  - importar, activar, mudar o motor e eliminar: ia_regras;
 *  - calcular (motor interno) e ler um documento com a IA: ia_lancar (nada é gravado: as propostas abrem no formulário de
 *    lançamento, que exige lancamentos_post para gravar).
 * Os registos são da empresa activa (escopo do modelo); a chave do fornecedor nunca é devolvida.
 */
final class FicheirosRegrasIAController extends Controller
{
    private const VER = ['ia_assistente_view', 'ia_regras', 'ia_lancar'];

    public function __construct(private readonly ServicoFicheirosRegrasIA $servico) {}

    public function estado(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->servico->estado($contexto->obrigatorio()), 'Estado do assistente IA.');
    }

    public function index(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->servico->listar($contexto->obrigatorio()), 'Ficheiros de regras.');
    }

    public function show(int $regra): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->servico->obter($regra), 'Ficheiro de regras.');
    }

    public function modelo(): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso(ServicoFicheirosRegrasIA::modelo(), 'Modelo de ficheiro de regras.');
    }

    public function exportar(int $regra): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->servico->exportar($regra), 'Ficheiro de regras.');
    }

    /** POST multipart {ficheiro (.json, até 256 KB), substituir?} ou JSON {conteudo, ficheiro?, substituir?}. */
    public function store(Request $r): JsonResponse
    {
        $this->exigir('ia_regras');
        $d = $r->validate([
            'ficheiro' => ['required_without:conteudo', 'nullable'],
            'conteudo' => ['required_without:ficheiro', 'nullable', 'string', 'max:'.RegraFicheiro::MAX_BYTES],
            'substituir' => ['nullable', 'boolean'],
        ], ['ficheiro.required_without' => 'Escolha o ficheiro JSON da regra.']);
        $upload = $r->file('ficheiro');
        if ($upload) {
            $r->validate(['ficheiro' => ['file', 'max:'.(int) (RegraFicheiro::MAX_BYTES / 1024), 'extensions:json', 'mimetypes:application/json,text/plain,text/json']],
                ['ficheiro.max' => 'O ficheiro é demasiado grande (máximo 256 KB).', 'ficheiro.extensions' => 'O ficheiro tem de ser .json.',
                    'ficheiro.mimetypes' => 'O ficheiro tem de ser um JSON de texto.']);
            $conteudo = (string) file_get_contents($upload->getRealPath());
            $nome = (string) $upload->getClientOriginalName();
        } else {
            $conteudo = (string) $d['conteudo'];
            $nome = is_string($r->input('ficheiro')) ? (string) $r->input('ficheiro') : 'regra.json';
        }
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            throw new ErroNegocio('O ficheiro tem de estar em UTF-8.', 'IA_REGRA_INVALIDA', 422, ['conteudo' => 'Codificação inválida.']);
        }
        $res = $this->servico->importar($conteudo, $nome, (bool) ($d['substituir'] ?? false));

        return $res['versao'] > 1 ? RespostaApi::sucesso($res, "Regra «{$res['nome']}» substituída (versão {$res['versao']}).")
            : RespostaApi::criado($res, "Regra «{$res['nome']}» importada.");
    }

    public function update(Request $r, int $regra): JsonResponse
    {
        $this->exigir('ia_regras');
        $d = $r->validate(['ativo' => ['sometimes', 'boolean'], 'motor' => ['sometimes', Rule::in(RegraFicheiro::MOTORES)]]);

        return RespostaApi::sucesso($this->servico->alterar($regra, $d), 'Regra actualizada.');
    }

    public function destroy(int $regra): JsonResponse
    {
        $this->exigir('ia_regras');
        $this->servico->eliminar($regra);

        return RespostaApi::sucesso(null, 'Regra eliminada. O ficheiro pode voltar a ser importado.');
    }

    /** POST {dias: [{data, valores{nome: número}, outros[{metodo, valor}], ilegivel, net_impresso}]} — motor interno. */
    public function calcular(Request $r, ContextoEmpresa $contexto, int $regra): JsonResponse
    {
        $this->exigir('ia_lancar');
        $d = $r->validate([
            'dias' => ['required', 'array', 'min:1', 'max:'.ServicoFicheirosRegrasIA::MAX_DIAS],
            'dias.*.data' => ['nullable', 'date_format:Y-m-d'],
            'dias.*.valores' => ['nullable', 'array', 'max:100'],
            'dias.*.valores.*' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'dias.*.outros' => ['nullable', 'array', 'max:20'],
            'dias.*.outros.*.metodo' => ['nullable', 'string', 'max:100'],
            'dias.*.outros.*.valor' => ['nullable', 'numeric', 'between:0,999999999999'],
            'dias.*.ilegivel' => ['nullable', 'string', 'max:500'],
            'dias.*.net_impresso' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
        ]);
        $res = $this->servico->calcular($contexto->obrigatorio(), $regra, $d['dias']);

        return RespostaApi::sucesso($res, "{$res['propostas_validas']} proposta(s) de lançamento. Reveja-as no formulário antes de gravar: nada foi gravado.");
    }

    /** POST multipart {ficheiro (PDF/imagem), texto?} — a IA só lê os valores; o cálculo é o do motor interno. */
    public function extrair(Request $r, ContextoEmpresa $contexto, int $regra): JsonResponse
    {
        $this->exigir('ia_lancar');
        $d = $r->validate([
            'ficheiro' => ['required', 'file', 'max:'.(int) config('assistente_ia.max_ficheiro_kb'), 'mimetypes:application/pdf,image/png,image/jpeg,image/webp,image/gif'],
            'texto' => ['nullable', 'string', 'max:4000'],
        ], ['ficheiro.mimetypes' => 'O documento tem de ser PDF ou imagem (PNG, JPEG, WEBP ou GIF).', 'ficheiro.required' => 'Anexe o documento a ler.']);
        $res = $this->servico->extrair($contexto->obrigatorio(), $regra, $r->file('ficheiro'), $d['texto'] ?? null);

        return RespostaApi::sucesso($res, count($res['dias']).' dia(s) lido(s) pela IA; o cálculo foi feito pela regra. Nada foi gravado.');
    }
}
