<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Http\Controllers\Controller;
use App\Services\Integracoes\IA\ServicoAssistenteIA;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/contabilidade/assistente — assistente IA para lançamentos (decisão 26). Permissões do catálogo:
 *  - consultar o estado: lancamentos_post, aux_gerir ou config_empresas_gerir;
 *  - activar/desactivar na empresa: config_empresas_gerir;
 *  - pedir propostas: lancamentos_post (nada é gravado; grava-se no formulário de lançamento);
 *  - regras internas: ver com lancamentos_post ou aux_gerir; criar/alterar/eliminar com aux_gerir.
 */
final class AssistenteIAController extends Controller
{
    public function __construct(private readonly ServicoAssistenteIA $ia) {}

    public function estado(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('lancamentos_post', 'aux_gerir', 'config_empresas_gerir');

        return RespostaApi::sucesso($this->ia->estado($contexto->obrigatorio()), 'Estado do assistente IA.');
    }

    public function configurar(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_empresas_gerir');
        $d = $r->validate(['ativo' => ['required', 'boolean']]);

        return RespostaApi::sucesso($this->ia->definirAtivo($contexto->obrigatorio(), (bool) $d['ativo']), $d['ativo'] ? 'Assistente IA activado nesta empresa.' : 'Assistente IA desactivado nesta empresa.');
    }

    /** POST (multipart ou JSON) {texto?, ficheiro?, motor?: auto|regras|ia} — propostas validadas, sem gravar. */
    public function propor(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('lancamentos_post');
        $d = $r->validate([
            'texto' => ['nullable', 'string', 'max:20000'],
            'ficheiro' => ['nullable', 'file', 'max:'.(int) config('assistente_ia.max_ficheiro_kb'), 'mimetypes:application/pdf,image/png,image/jpeg,image/webp,image/gif'],
            'motor' => ['nullable', Rule::in(['auto', 'regras', 'ia'])],
        ], ['ficheiro.mimetypes' => 'O documento tem de ser PDF ou imagem (PNG, JPEG, WEBP ou GIF).']);
        $res = $this->ia->propor($contexto->obrigatorio(), $d['texto'] ?? null, $r->file('ficheiro'), $d['motor'] ?? 'auto');
        $n = count($res['propostas']);

        return RespostaApi::sucesso($res, $n ? "{$n} proposta(s) de lançamento. Reveja-as no formulário antes de gravar: nada foi gravado." : 'Sem propostas para este conteúdo.');
    }

    public function regras(): JsonResponse
    {
        $this->exigir('lancamentos_post', 'aux_gerir');

        return RespostaApi::sucesso($this->ia->regras(), 'Regras internas do assistente.');
    }

    public function gravarRegra(Request $r, ?int $regra = null): JsonResponse
    {
        $this->exigir('aux_gerir');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'palavras_chave' => ['required', 'string', 'max:2000'], 'modelo' => ['required']]);
        $res = $this->ia->gravarRegra($d, $regra);

        return $regra ? RespostaApi::sucesso($res, 'Regra interna gravada.') : RespostaApi::criado($res, 'Regra interna criada.');
    }

    public function eliminarRegra(int $regra): JsonResponse
    {
        $this->exigir('aux_gerir');
        $this->ia->eliminarRegra($regra);

        return RespostaApi::sucesso(null, 'Regra interna eliminada.');
    }
}
