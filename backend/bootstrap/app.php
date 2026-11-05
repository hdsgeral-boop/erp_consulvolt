<?php

use App\Exceptions\ErroNegocio;
use App\Exceptions\ManipuladorExcecoesApi;
use App\Http\Middleware\BloquearExportacaoComLicencaExpirada;
use App\Http\Middleware\BloquearTokenMfaPendente;
use App\Http\Middleware\CabecalhosSemCache;
use App\Http\Middleware\CacheCondicional;
use App\Http\Middleware\ExigirAdministradorCliente;
use App\Http\Middleware\ExigirAreaPortal;
use App\Http\Middleware\ExigirPlataforma;
use App\Http\Middleware\ExigirSuporte;
use App\Http\Middleware\ExigirTrocaPassword;
use App\Http\Middleware\ForcarRespostaJson;
use App\Http\Middleware\ResolverEmpresaAtiva;
use App\Http\Middleware\ResolverTenantAtivo;
use App\Http\Middleware\RotacionarToken;
use App\Http\Middleware\SanitizarEntrada;
use App\Http\Middleware\ValidarUploadsSeguros;
use App\Http\Middleware\VerificarCsrfDuploSubmit;
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
        // Atrás do nginx/Caddy/túnel: só se confia no IP do cliente (X-Forwarded-For) e no esquema https (X-Forwarded-Proto)
        // quando o pedido chega de uma rede privada (o proxy). NUNCA no host encaminhado (X-Forwarded-Host): o endereço
        // decide a plataforma/empresa (DominioPedido) e um visitante poderia forjá-lo.
        $middleware->trustProxies(
            at: ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Duplo-submit do CSRF (Fase D): só actua quando há cookie de sessão (ver VerificarCsrfDuploSubmit) — o
        // mais cedo possível, antes de gastar trabalho de autenticação/tenant num pedido que já se sabe inválido.
        $middleware->api(prepend: [CabecalhosSemCache::class, ForcarRespostaJson::class, VerificarCsrfDuploSubmit::class, SanitizarEntrada::class, ValidarUploadsSeguros::class]);
        // MFA (Fase 4 da extensão SaaS): um token limitado ('mfa:pendente') nunca pode servir de sessão completa —
        // verificado o mais cedo possível, antes de qualquer outra coisa decidir algo com base no utilizador.
        $middleware->api(append: [BloquearTokenMfaPendente::class]);
        // SaaS (ADR-073): o tenant da sessão de base de dados vem do utilizador autenticado. Sem efeito com erp.saas.ativo=false.
        $middleware->api(append: [ResolverTenantAtivo::class]);
        // Passwords são sempre geradas pelo sistema: bloqueia toda a API (lista branca) até o utilizador a trocar.
        $middleware->api(append: [ExigirTrocaPassword::class]);
        // Limite geral da API (limitador 'api' do AppServiceProvider, configurável em erp.api.pedidos_por_minuto).
        $middleware->throttleApi();
        // Rotação do token de sessão (ADR-082): troca o cookie do token a meio da sessão, na resposta de pedidos autenticados.
        $middleware->api(append: [RotacionarToken::class]);
        $middleware->alias([
            'empresa' => ResolverEmpresaAtiva::class,
            'plataforma' => ExigirPlataforma::class,
            'admin_cliente' => ExigirAdministradorCliente::class,
            'suporte' => ExigirSuporte::class,
            'licenca_exportacao' => BloquearExportacaoComLicencaExpirada::class,
            'area_portal' => ExigirAreaPortal::class,
            'cache_condicional' => CacheCondicional::class,
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
