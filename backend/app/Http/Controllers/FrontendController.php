<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\DominioPedido;
use Illuminate\Foundation\ViteManifestNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Entrega a página de entrada do SPA React. Há duas aplicações, escolhidas pelo host do pedido:
 *  - host da plataforma SaaS (<prefixo_plataforma>.<dominio base>) -> view `plataforma`;
 *  - qualquer outro host -> view `erp`.
 * O react-router assume o caminho no navegador; o Laravel só devolve a página (os activos vêm de /build, via Vite).
 */
class FrontendController extends Controller
{
    public function __invoke(Request $pedido): Response
    {
        $vista = DominioPedido::de($pedido)->area === DominioPedido::PLATAFORMA ? 'plataforma' : 'erp';

        try {
            $conteudo = view($vista)->render();
            $estado = 200;
        } catch (ViteManifestNotFoundException) {
            // Frontend por compilar: página clara em vez de um 500 anónimo.
            $conteudo = view('frontend-indisponivel')->render();
            $estado = 503;
        }

        return response($conteudo, $estado)->header('Cache-Control', 'no-store');
    }
}
