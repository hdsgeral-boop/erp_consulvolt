<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Services\Logistica\ServicoExportacaoInventarioAgt;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /api/logistica/inventario-agt — ficheiro de Inventário/Existências para a AGT (stock a 31/12 do exercício). */
final class ExportacaoInventarioController extends Controller
{
    public function __construct(private readonly ServicoExportacaoInventarioAgt $inventario) {}

    /** GET /logistica/inventario-agt/validar?ano= — contagens e avisos, sem gerar o ficheiro. */
    public function validar(Request $r): JsonResponse
    {
        $this->exigir('armazem_stock_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'min:2000']]);

        return RespostaApi::sucesso($this->inventario->validar((int) $d['ano']), 'Validação do ficheiro de Inventário.');
    }

    /** GET /logistica/inventario-agt?ano= — ficheiro CSV do stock a 31/12 do exercício. */
    public function gerar(Request $r): Response
    {
        $this->exigir('armazem_stock_view');
        $d = $r->validate(['ano' => ['required', 'integer', 'min:2000']]);
        $res = $this->inventario->gerar((int) $d['ano']);

        return response($res['csv'], 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$res['nome'].'"',
            'X-Inventario-Produtos' => (string) $res['produtos'], 'X-Inventario-Avisos' => rawurlencode(implode(' | ', $res['avisos'])),
        ]);
    }
}
