<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoExportacaoSaftContabilidade;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/contabilidade/saft — ficheiro SAF-T(AO) de Contabilidade (GeneralLedgerEntries, Decreto Executivo n.º 317/20). */
final class ExportacaoSaftController extends Controller
{
    private const PERMISSOES = ['relatorios_contabeis_view', 'contab_agt'];

    public function __construct(private readonly ServicoExportacaoSaftContabilidade $saft) {}

    /** GET /contabilidade/saft/validar?ano= — contagens e avisos, sem gerar o ficheiro. */
    public function validar(Request $r): JsonResponse
    {
        $this->exigir(...self::PERMISSOES);
        $d = $r->validate(['ano' => ['required', 'integer', 'min:2000']]);

        return RespostaApi::sucesso($this->saft->validar((int) $d['ano']), 'Validação do SAF-T de Contabilidade.');
    }

    /** GET /contabilidade/saft?ano= — ficheiro SAF-T(AO) de Contabilidade do exercício. */
    public function gerar(Request $r): Response
    {
        $this->exigir(...self::PERMISSOES);
        $d = $r->validate(['ano' => ['required', 'integer', 'min:2000']]);
        $res = $this->saft->gerar((int) $d['ano']);

        return response($res['xml'], 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$res['nome'].'"',
            'X-Saft-Lancamentos' => (string) $res['lancamentos'], 'X-Saft-Contas' => (string) $res['contas'],
            'X-Saft-Avisos' => rawurlencode(implode(' | ', $res['avisos'])),
        ]);
    }
}
