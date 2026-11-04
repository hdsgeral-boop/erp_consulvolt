<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Tenancy\DominioPedido;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /api/sistema/dominio-permitido?domain=<nome> — pergunta do Caddy antes de pedir um certificado HTTPS a pedido
 * (on_demand_tls). 200 só para nomes do sistema: o domínio base, www, o endereço da plataforma, o do portal do colaborador
 * (`portal.<base>`) e <slug>.<base> de um cliente que existe; 404 para o resto (evita certificados para nomes inventados).
 */
final class DominioPermitidoController extends Controller
{
    public function __invoke(Request $r): Response
    {
        $nome = strtolower(trim((string) $r->query('domain')));
        $base = strtolower(trim((string) config('erp.dominio.base')));
        if ($nome === '' || $base === '') {
            return response('', 404);
        }
        if ($nome === $base || $nome === "www.{$base}") {
            return response('', 200);
        }
        if (! str_ends_with($nome, ".{$base}")) {
            return response('', 404);
        }
        $prefixo = substr($nome, 0, -strlen(".{$base}"));
        if ($prefixo === '' || str_contains($prefixo, '.') && $prefixo !== DominioPedido::prefixoPlataforma()) {
            return response('', 404);
        }
        $permitido = $prefixo === DominioPedido::prefixoPlataforma()
            || $prefixo === 'portal'
            || Tenant::query()->where('slug', $prefixo)->exists();

        return response('', $permitido ? 200 : 404);
    }
}
