<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Services\Tesouraria\ServicoListagemTesouraria;
use App\Services\Tesouraria\ServicoReparacaoTesouraria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paridade visual da Tesouraria (verificacao_visual/RELATORIO.md, secção 7): indicadores dos agregadores
 * («● Pendentes: N», «● Integrados: N» — updateGlobalTesoSummary) e as ferramentas de reparação do Histórico / Anulação.
 * Autorização no servidor em cada acção, antes de validar a entrada; isolamento por empresa pelo global scope.
 */
final class ParidadeTesourariaController extends Controller
{
    /** Ecrãs da Tesouraria que mostram os indicadores (os três agregadores do legado). */
    private const ECRAS = ['teso_gestao_pagamentos_view', 'teso_folha_caixa_view', 'teso_contab_integracao_view', 'teso_contab_historico_view',
        'teso_gestao_mapas_view', 'teso_gestao_conciliacao_view', 'teso_gestao_conferencia_view', 'teso_meios_pagamento_view'];

    public function __construct(
        private readonly ServicoListagemTesouraria $listagem,
        private readonly ServicoReparacaoTesouraria $reparacao,
    ) {}

    /** GET /api/tesouraria/resumo — contagens por estado (uma consulta agrupada sobre o índice empresa/estado). */
    public function resumo(): JsonResponse
    {
        $this->exigir(...self::ECRAS);

        return RespostaApi::sucesso($this->listagem->resumo(), 'Resumo da tesouraria.');
    }

    /** GET /api/tesouraria/reparacao/integracoes — pré-visualização: integrados sem lançamento activo (não grava). */
    public function integracoesOrfas(Request $r): JsonResponse
    {
        $this->exigir('teso_desintegrar');
        $f = $r->validate(['pesquisa' => ['nullable', 'string', 'max:100']]);

        return RespostaApi::sucesso($this->reparacao->integracoesOrfas(isset($f['pesquisa']) ? trim($f['pesquisa']) : null),
            'Documentos integrados sem lançamento no Diário.');
    }

    /** POST /api/tesouraria/reparacao/integracoes — repõe por integrar os escolhidos (cada um na sua transacção, auditado). */
    public function reporIntegracoes(Request $r): JsonResponse
    {
        $this->exigir('teso_desintegrar');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:'.ServicoReparacaoTesouraria::LIMITE], 'ids.*' => ['integer', 'min:1'],
            'motivo' => ['required', 'string', 'min:5', 'max:500'], 'confirmar_legado' => ['nullable', 'boolean']], [], ['motivo' => 'motivo']);
        $res = $this->reparacao->repor($d['ids'], $d['motivo'], (bool) ($d['confirmar_legado'] ?? false));
        $n = count($res['repostos']);
        $e = count($res['erros']);

        return RespostaApi::sucesso($res, $e ? "{$n} documento(s) reposto(s) por integrar; {$e} recusado(s)." : "{$n} documento(s) reposto(s) por integrar.");
    }
}
