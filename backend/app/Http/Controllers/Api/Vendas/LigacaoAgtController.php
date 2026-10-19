<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\ComunicacaoAgt;
use App\Models\Empresa;
use App\Models\Venda;
use App\Services\Vendas\Agt\ClienteAgtDireto;
use App\Services\Vendas\Agt\ServicoLigacaoAgt;
use App\Services\Vendas\ServicoConfigFaturacaoEletronica;
use App\Services\Vendas\ServicoCorrecaoAgt;
use App\Services\Vendas\ServicoDocumentosImportadosSaft;
use App\Services\Vendas\ServicoEnvioAgt;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/vendas/faturacao-eletronica/ligacao-empresa, /monitor, /comunicacoes — ligação de cada empresa à AGT
 * (credenciais, chave do contribuinte, webhook), monitorização do envio, registo de comunicações, correcções (E46) e
 * relatório dos documentos integrados de outro sistema no SAF-T (r4_agt).
 *
 * Permissões (catálogo): vendas_agt_ligacao (configurar a ligação; segredos nunca devolvidos), vendas_agt_enviar
 * (enviar, reenviar e emitir correcções), vendas_agt_registos (registo de comunicações); a monitorização abre com
 * vendas_faturacao_view.
 */
final class LigacaoAgtController extends Controller
{
    public function __construct(private readonly ServicoLigacaoAgt $ligacao) {}

    public function obter(): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao', 'vendas_fe_config');

        return RespostaApi::sucesso($this->ligacao->obter(), 'Ligação à AGT da empresa.');
    }

    public function gravar(Request $request): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');
        $d = $request->validate([
            'ativo' => ['required', 'boolean'], 'ambiente' => ['required', Rule::in(ServicoLigacaoAgt::AMBIENTES)],
            'url_base' => ['nullable', 'string', 'max:255', 'url:https'],
        ]);

        return RespostaApi::sucesso($this->ligacao->gravar($d), 'Ligação à AGT gravada.');
    }

    public function gravarCredenciais(Request $request): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');
        $d = $request->validate(['utilizador' => ['required', 'string', 'max:200'], 'palavra_passe' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->ligacao->gravarCredenciais($d['utilizador'], $d['palavra_passe']), 'Credenciais gravadas (cifradas).');
    }

    public function removerCredenciais(): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');

        return RespostaApi::sucesso($this->ligacao->removerCredenciais(), 'Credenciais da empresa retiradas.');
    }

    /** Chave privada do contribuinte (PEM em texto ou ficheiro .pem) e, opcionalmente, chave pública ou certificado. */
    public function gravarChave(Request $request): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');
        $request->validate([
            'chave_privada' => ['required_without:ficheiro_chave', 'nullable', 'string', 'max:20000'],
            'ficheiro_chave' => ['required_without:chave_privada', 'nullable', 'file', 'max:20'],
            'publica_ou_certificado' => ['nullable', 'string', 'max:20000'],
            'ficheiro_certificado' => ['nullable', 'file', 'max:20'],
            'frase_passe' => ['nullable', 'string', 'max:500'],
        ]);
        $privada = $request->file('ficheiro_chave')?->get() ?? (string) $request->input('chave_privada');
        $extra = $request->file('ficheiro_certificado')?->get() ?? $request->input('publica_ou_certificado');

        return RespostaApi::sucesso($this->ligacao->gravarChave((string) $privada, $extra !== null ? (string) $extra : null, $request->input('frase_passe')),
            'Chave do contribuinte validada e gravada (cifrada).');
    }

    public function removerChave(): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');

        return RespostaApi::sucesso($this->ligacao->removerChave(), 'Chave do contribuinte retirada.');
    }

    /** Gera (ou roda) o endereço e o segredo do webhook — mostrados uma única vez. */
    public function gerarWebhook(): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');

        return RespostaApi::sucesso($this->ligacao->gerarWebhook(), 'Endereço e segredo do webhook gerados. Guarde o segredo: não volta a ser mostrado.');
    }

    public function desativarWebhook(): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');

        return RespostaApi::sucesso($this->ligacao->desativarWebhook(), 'Webhook desactivado.');
    }

    /**
     * Teste da ligação: assina um pedido de leitura (listarSeries) com as chaves da empresa e chama a AGT. Confirma o
     * endereço, as credenciais, a assinatura do software (E08/E39) e a do contribuinte (E40).
     */
    public function testar(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('vendas_agt_ligacao');
        $nif = preg_replace('/\s+/', '', (string) Empresa::query()->whereKey($contexto->obrigatorio())->value('nif'));
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
        }
        $estabelecimento = (string) (app(ServicoConfigFaturacaoEletronica::class)->obter()['estabelecimentos'][0]['numero'] ?? '1');
        try {
            $r = app(ClienteAgtDireto::class)->listarSeries($nif, $estabelecimento);
            $erros = ServicoEnvioAgt::errosAgt($r['resposta']['errorList'] ?? []);
            $ok = ($r['http'] ?? 0) === 200 && ! $erros;
            $mensagem = $ok ? 'Ligação à AGT confirmada (listarSeries).' : 'A AGT respondeu HTTP '.($r['http'] ?? 0).($erros ? ': '.implode(' | ', array_map(fn ($e) => trim("{$e['codigo']} {$e['mensagem']}"), $erros)) : '.');
        } catch (ErroNegocio $e) {
            $ok = false;
            $mensagem = $e->getMessage();
        }
        $this->ligacao->registarTeste($ok, $mensagem);

        return RespostaApi::sucesso(['ok' => $ok, 'mensagem' => $mensagem], $mensagem);
    }

    public function monitor(ServicoEnvioAgt $envio): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_agt_enviar', 'vendas_fe_config');

        return RespostaApi::sucesso($envio->monitor(), 'Monitorização da facturação electrónica.');
    }

    /** Registo de comunicações com a AGT (pedido/resposta mascarados), mais recentes primeiro. */
    public function comunicacoes(Request $request): JsonResponse
    {
        $this->exigir('vendas_agt_registos');
        $d = $request->validate([
            'operacao' => ['nullable', 'string', 'max:40'], 'sucesso' => ['nullable', 'boolean'], 'documento' => ['nullable', 'string', 'max:60'],
            'de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $pagina = ComunicacaoAgt::query()
            ->when($d['operacao'] ?? null, fn ($q, $o) => $q->where('operacao', $o))
            ->when(array_key_exists('sucesso', $d) && $d['sucesso'] !== null, fn ($q) => $q->where('sucesso', (bool) $d['sucesso']))
            ->when($d['documento'] ?? null, fn ($q, $n) => $q->whereRaw('documentos::text ILIKE ?', ['%'.addcslashes($n, '%_\\').'%']))
            ->when($d['de'] ?? null, fn ($q, $x) => $q->where('criado_em', '>=', $x))
            ->when($d['ate'] ?? null, fn ($q, $x) => $q->where('criado_em', '<', date('Y-m-d', strtotime("{$x} +1 day"))))
            ->orderByDesc('id')->paginate((int) ($d['por_pagina'] ?? 25));

        return RespostaApi::paginado($pagina);
    }

    /** POST /vendas/documentos/{id}/correccao-agt — documento de correcção (novo n.º, documentStatus «C»). */
    public function corrigir(int $venda, ServicoCorrecaoAgt $correcao, ServicoEnvioAgt $envio): JsonResponse
    {
        $this->exigir('vendas_agt_enviar');
        $novo = $correcao->emitir(Venda::query()->findOrFail($venda));
        $envio->enviarPendentes([$novo->id], true);

        return RespostaApi::criado(['id' => $novo->id, 'numero_documento' => $novo->numero_documento, 'envio' => ServicoEnvioAgt::estadoEnvio($novo->refresh())],
            "Documento de correcção {$novo->numero_documento} emitido.");
    }

    /** Relatório de validação do SAF-T: documentos integrados de outro sistema (n.º original × n.º SAF-T). */
    public function documentosImportados(Request $request, ServicoDocumentosImportadosSaft $importados): JsonResponse
    {
        $this->exigir('vendas_relatorios_view', 'vendas_fe_config');
        $d = $request->validate(['inicio' => ['nullable', 'date_format:Y-m-d'], 'fim' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:inicio']]);

        return RespostaApi::sucesso($importados->relatorio($d['inicio'] ?? null, $d['fim'] ?? null), 'Documentos integrados de outro sistema.');
    }
}
