<?php

use App\Exceptions\ErroNegocio;
use App\Exceptions\ManipuladorExcecoesApi;
use App\Http\Middleware\CacheCondicional;
use App\Http\Middleware\ExigirAdministradorCliente;
use App\Http\Middleware\ExigirPlataforma;
use App\Http\Middleware\ExigirSuporte;
use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\ResolverEmpresaAtiva;
use App\Http\Middleware\ResolverTenantAtivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Página do SPA (catch-all): registada depois da API e SEM o grupo `web` (sem sessão nem cookies).
        then: function (): void {
            Route::group([], base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForcarRespostaJson::class]);
        // Atrás do Nginx/Caddy/túnel da Cloudflare: só se confia no IP do cliente (X-Forwarded-For) e no esquema https
        // (X-Forwarded-Proto) quando o pedido chega de uma rede privada (o proxy). NUNCA no host encaminhado
        // (X-Forwarded-Host): o endereço decide a plataforma/empresa (DominioPedido) e um visitante poderia forjá-lo.
        $middleware->trustProxies(
            at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
        // SaaS (ADR-073): o tenant da sessão de base de dados vem do utilizador autenticado. Sem efeito com erp.saas.ativo=false.
        $middleware->api(append: [ResolverTenantAtivo::class]);
        // Limite geral da API (limitador 'api' do AppServiceProvider, configurável em erp.api.pedidos_por_minuto).
        $middleware->throttleApi();
        $middleware->alias(['empresa' => ResolverEmpresaAtiva::class, 'plataforma' => ExigirPlataforma::class, 'suporte' => ExigirSuporte::class, 'admin_cliente' => ExigirAdministradorCliente::class, 'cache_condicional' => CacheCondicional::class]);
        // API só por token: nunca redireccionar para uma página de login.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return ManipuladorExcecoesApi::renderizar($e);
            }
        });
        // Violações de regras de negócio são respostas esperadas, não erros a registar no log.
        $exceptions->dontReport([ErroNegocio::class]);
    })->create();
