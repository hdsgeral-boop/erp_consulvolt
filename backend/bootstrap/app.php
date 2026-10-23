<?php

use App\Exceptions\ErroNegocio;
use App\Exceptions\ManipuladorExcecoesApi;
use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\ResolverEmpresaAtiva;
use App\Http\Middleware\ResolverTenantAtivo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForcarRespostaJson::class]);
        // SaaS (ADR-073): o tenant da sessão de base de dados vem do utilizador autenticado. Sem efeito com erp.saas.ativo=false.
        $middleware->api(append: [ResolverTenantAtivo::class]);
        // Limite geral da API (limitador 'api' do AppServiceProvider, configurável em erp.api.pedidos_por_minuto).
        $middleware->throttleApi();
        $middleware->alias([
            'empresa' => ResolverEmpresaAtiva::class,
            'plataforma' => \App\Http\Middleware\ExigirPlataforma::class,
            'admin_cliente' => \App\Http\Middleware\ExigirAdministradorCliente::class,
            'suporte' => \App\Http\Middleware\ExigirSuporte::class,
            'licenca_exportacao' => \App\Http\Middleware\BloquearExportacaoComLicencaExpirada::class,
        ]);
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
