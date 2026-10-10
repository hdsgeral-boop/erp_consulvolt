<?php

namespace App\Http\Controllers\Api\Orcamento;

use App\Http\Controllers\Controller;
use App\Models\OrcamentoAnual;
use App\Models\RubricaOrcamental;
use App\Services\Orcamento\FormatoOrcamento;
use App\Services\Orcamento\ServicoParidadeOrcamento;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/orcamento/paridade — leituras de paridade com o sistema anterior (secção 11 da verificação visual):
 * lista de orçamentos com dimensões, total das rubricas e última acção, e as causas de um desvio («Porquê?»).
 * Só leituras; a empresa vem do contexto (PertenceEmpresa) e os ids de outra empresa dão 404.
 */
final class ParidadeOrcamentoController extends Controller
{
    public function __construct(private readonly ServicoParidadeOrcamento $paridade) {}

    public function orcamentos(Request $r): JsonResponse
    {
        $this->exigir('orc_orcamentos_view', 'orc_controlo_view', 'orc_contributo', 'orc_cenarios_view', 'orc_cenarios_edit');
        $f = $r->validate(['ano' => ['nullable', 'integer', 'between:2000,2100'], 'tipo' => ['nullable', 'in:EXPLORACAO,TESOURARIA']]);
        $res = $this->paridade->lista(isset($f['ano']) ? (int) $f['ano'] : null, $f['tipo'] ?? null);

        return RespostaApi::sucesso(['orcamentos' => FormatoOrcamento::normalizar($res['orcamentos'], ['total_rubricas']), 'por_omissao' => $res['por_omissao']], 'Orçamentos.');
    }

    public function causas(Request $r, int $orcamento, int $rubrica): JsonResponse
    {
        $this->exigir('orc_controlo_view');
        $d = $r->validate(['de' => ['nullable', 'integer', 'between:1,12'], 'ate' => ['nullable', 'integer', 'between:1,12']]);
        $o = OrcamentoAnual::query()->findOrFail($orcamento);
        $rub = RubricaOrcamental::query()->where('tipo', $o->tipo)->findOrFail($rubrica);

        return RespostaApi::sucesso(FormatoOrcamento::normalizar($this->paridade->causas($o, $rub->id, (int) ($d['de'] ?? 1), (int) ($d['ate'] ?? now()->month)), ['valor_rubrica']),
            'Análise do desvio.');
    }
}
