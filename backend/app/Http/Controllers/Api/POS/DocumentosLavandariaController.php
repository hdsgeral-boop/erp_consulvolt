<?php

namespace App\Http\Controllers\Api\POS;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Venda;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * M-16 — GET /api/pos/lavandaria/ordens/{ordem}/documentos/{venda}: factura (FT/FR) de uma ordem de serviço, com as
 * linhas, para a reimpressão no POS (legado: lavImprimirFactura). Só documentos ligados à ordem (facturas da ordem ou
 * venda do recibo), com as permissões da lavandaria — o operador não precisa do acesso à Facturação.
 */
final class DocumentosLavandariaController extends Controller
{
    private const VER = ['pos_lavandaria_view', 'lav_ordens', 'lav_receber', 'lav_anular', 'lav_tabelas', 'lav_dano_decidir', 'lav_dano_pagar'];

    public function __invoke(int $ordem, int $venda, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir(...self::VER);
        $empresa = $contexto->obrigatorio();
        $ligada = DB::table('pedidos_lavandaria_faturas')->where('empresa_id', $empresa)->where('pedido_lavandaria_id', $ordem)->where('venda_id', $venda)->exists()
            || DB::table('pagamentos_lavandaria')->where('empresa_id', $empresa)->where('pedido_lavandaria_id', $ordem)->where('venda_id', $venda)->exists();
        if (! $ligada) {
            throw new ErroNegocio('O documento não pertence a esta ordem de serviço.', 'NAO_ENCONTRADO', 404);
        }

        return RespostaApi::sucesso(Venda::query()->findOrFail($venda)->load('itensVenda'), 'Documento obtido.');
    }
}
