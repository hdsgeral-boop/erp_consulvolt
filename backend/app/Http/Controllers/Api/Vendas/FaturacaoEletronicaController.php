<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\SerieFaturacaoEletronica;
use App\Models\Venda;
use App\Services\Vendas\Agt\ClienteAgt;
use App\Services\Vendas\ServicoConfigFaturacaoEletronica;
use App\Services\Vendas\ServicoEnvioAgt;
use App\Services\Vendas\ServicoExportacaoSaft;
use App\Services\Vendas\ServicoQrAgt;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** /api/vendas/faturacao-eletronica — ecrã "Facturação electrónica" do legado (js/facturacao_agt_ui.js) + SAF-T e QR. */
final class FaturacaoEletronicaController extends Controller
{
    public function __construct(
        private readonly ServicoConfigFaturacaoEletronica $config,
        private readonly ServicoEnvioAgt $envio,
    ) {}

    public function configuracao(): JsonResponse
    {
        $this->exigir('vendas_faturacao_view', 'vendas_fe_config');

        return RespostaApi::sucesso($this->config->obter(), 'Configuração obtida com sucesso.');
    }

    public function gravarConfiguracao(Request $request): JsonResponse
    {
        $this->exigir('vendas_fe_config');
        $d = $request->validate([
            'ativo' => ['required', 'boolean'], 'data_inicio' => ['nullable', 'date_format:Y-m-d'],
            'estabelecimentos' => ['required', 'array', 'min:1', 'max:100'],
            'estabelecimentos.*.numero' => ['required', 'string', 'max:20'], 'estabelecimentos.*.nome' => ['nullable', 'string', 'max:200'],
            'pais_padrao' => ['nullable', 'regex:/^[A-Z]{2}$/'], 'isencao_padrao' => ['nullable', 'regex:/^M\d{2}$/'],
            'servico.auto' => ['nullable', 'boolean'], 'servico.exigir_series_agt' => ['nullable', 'boolean'],
        ]);

        return RespostaApi::sucesso($this->config->gravar($d), 'Configuração da facturação electrónica gravada.');
    }

    /** Estado da ligação à AGT (sem segredos). */
    public function ligacao(ClienteAgt $agt): JsonResponse
    {
        $this->exigir('vendas_fe_config');

        return RespostaApi::sucesso($agt->saude(), 'Estado da ligação à AGT.');
    }

    public function resumo(): JsonResponse
    {
        $this->exigir('vendas_faturacao_view');

        return RespostaApi::sucesso($this->envio->resumo(), 'Resumo da facturação electrónica.');
    }

    public function enviar(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $d = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer']]);

        return RespostaApi::sucesso($this->envio->enviarPendentes($d['ids'] ?? null), 'Envio à AGT concluído.');
    }

    public function consultar(Request $request): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');
        $d = $request->validate(['ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['integer'], 'forcar' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->envio->consultarEstados($d['ids'] ?? null, (bool) ($d['forcar'] ?? false)), 'Consulta à AGT concluída.');
    }

    /** POST /api/vendas/documentos/{id}/revalidar — refaz o documento electrónico e reenvia. */
    public function revalidar(int $venda): JsonResponse
    {
        $this->exigir('vendas_fat_emitir');

        return RespostaApi::sucesso($this->envio->revalidarEReenviar(Venda::query()->findOrFail($venda)), 'Documento revalidado e reenviado.');
    }

    /** GET /api/vendas/documentos/{id}/pedido-assinado — pré-visualização do pedido assinado, sem envio. */
    public function pedidoAssinado(int $venda): JsonResponse
    {
        $this->exigir('vendas_fe_config');

        return RespostaApi::sucesso($this->envio->previsualizar(Venda::query()->findOrFail($venda)), 'Pedido assinado (não enviado).');
    }

    /** GET /api/vendas/documentos/{id}/qr?formato=png|svg — imagem do QR code do documento. */
    public function qr(Request $request, int $venda, ServicoQrAgt $qr): Response
    {
        $this->exigir('vendas_faturacao_view');
        $formato = $request->validate(['formato' => ['nullable', Rule::in(['png', 'svg'])]])['formato'] ?? 'png';
        $v = Venda::query()->findOrFail($venda);
        $imagem = $qr->imagem($v, $formato);

        return response($imagem['conteudo'], 200, ['Content-Type' => $imagem['tipo'], 'Cache-Control' => 'private, max-age=86400',
            'X-Url-Consulta' => $qr->urlDoDocumento($v)]);
    }

    public function criarSerie(Request $request): JsonResponse
    {
        $this->exigir('vendas_fe_config');
        $serie = $this->config->gravarSerie($this->validarSerie($request));

        return RespostaApi::criado($serie, "Série {$serie->tipo} {$serie->codigo} criada.");
    }

    public function atualizarSerie(Request $request, int $serie): JsonResponse
    {
        $this->exigir('vendas_fe_config');
        $s = $this->config->gravarSerie($this->validarSerie($request), SerieFaturacaoEletronica::query()->findOrFail($serie));

        return RespostaApi::sucesso($s, "Série {$s->tipo} {$s->codigo} actualizada.");
    }

    public function eliminarSerie(int $serie): JsonResponse
    {
        $this->exigir('vendas_fe_config');
        $this->config->eliminarSerie(SerieFaturacaoEletronica::query()->findOrFail($serie));

        return RespostaApi::sucesso(null, 'Série eliminada.');
    }

    /** POST /series/{id}/solicitar-agt — pede à AGT o código da série. */
    public function solicitarSerieAgt(int $serie): JsonResponse
    {
        $this->exigir('vendas_fe_config');
        $s = $this->envio->pedirSerie(SerieFaturacaoEletronica::query()->findOrFail($serie));

        return RespostaApi::sucesso($s, "Série atribuída pela AGT: {$s->agt_codigo}.");
    }

    /** GET /api/vendas/saft?inicio=&fim= — ficheiro SAF-T(AO) de facturação. */
    public function saft(Request $request, ServicoExportacaoSaft $saft): Response
    {
        $this->exigir('vendas_relatorios_view', 'vendas_fe_config');
        $d = $request->validate(['inicio' => ['required', 'date_format:Y-m-d'], 'fim' => ['required', 'date_format:Y-m-d']]);
        $r = $saft->gerar($d['inicio'], $d['fim']);

        return response($r['xml'], 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$r['nome'].'"',
            'X-Saft-Documentos' => (string) $r['documentos'], 'X-Saft-Recibos' => (string) $r['recibos'],
            'X-Saft-Avisos' => rawurlencode(implode(' | ', $r['avisos'])),
        ]);
    }

    private function validarSerie(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(['FT', 'FR', 'NC', 'OR', 'PF', 'NE', 'RE'])], 'ano' => ['required', 'integer', 'min:2000', 'max:2100'],
            'codigo' => ['required', 'regex:/^[A-Za-z0-9]{1,30}$/'], 'origem' => ['nullable', 'string', 'max:30'], 'origem_nome' => ['nullable', 'string', 'max:200'],
            'estabelecimento' => ['nullable', 'string', 'max:20'], 'contingencia' => ['nullable', 'boolean'], 'estado' => ['nullable', Rule::in(['ATIVA', 'FECHADA'])],
        ], [], ['codigo' => 'código da série (letras e números, sem espaços nem "/")']);
    }
}
