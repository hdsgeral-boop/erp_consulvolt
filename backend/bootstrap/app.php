<?php

use App\Exceptions\ErroNegocio;
use App\Exceptions\ManipuladorExcecoesApi;
use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\ResolverEmpresaAtiva;
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
        $middleware->alias(['empresa' => ResolverEmpresaAtiva::class]);
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
