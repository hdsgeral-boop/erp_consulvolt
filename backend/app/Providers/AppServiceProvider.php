<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Services\Vendas\Agt\ClienteAgt;
use App\Services\Vendas\Agt\ClienteAgtPorEmpresa;
use App\Support\Dados\OrdenacaoTexto;
use App\Support\Seguranca\ValorSemFormulas;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Um contexto de empresa por pedido HTTP / trabalho de fila (seguro também com Octane).
        $this->app->scoped(ContextoEmpresa::class);
        $this->app->scoped(ServicoPermissoes::class);
        // SaaS (ADR-073): um tenant por sessão de base de dados; espelha o `app.tenant_id` da ligação.
        $this->app->singleton(ContextoTenant::class);

        // Ligação à AGT (ADR-030; r4_agt): a da empresa activa, quando configurada; senão o driver do servidor
        // (directa, pelo serviço intermédio do legado, ou desligada — por omissão)
        $this->app->bind(ClienteAgt::class, fn ($app) => $app->make(ClienteAgtPorEmpresa::class));
    }

    public function boot(): void
    {
        // Fora de produção: falhar cedo em lazy loading, atributos inexistentes e mass assignment silencioso.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Listas por nome em ordem alfabética portuguesa (a base usa a ordenação C) — ADR-071.
        OrdenacaoTexto::registar();

        // Tipos polimórficos estáveis (não gravar nomes de classes PHP na base de dados).
        Relation::enforceMorphMap([
            'utilizador' => Utilizador::class,
            'empresa' => Empresa::class,
        ]);

        $this->configurarSanctum();
        $this->configurarPermissoes();
        $this->configurarLimites();
        $this->configurarFilas();
        $this->configurarComandosDeManutencao();

        // OWASP A03: textos começados por = + - @ nunca viram fórmulas nos .xlsx gerados (CSV/Excel injection).
        Cell::setValueBinder(new ValorSemFormulas);
    }

    /**
     * SaaS (ADR-073): um trabalho não herda o tenant do anterior (a ligação de um worker é de longa duração e a sessão
     * PostgreSQL mantém o `app.tenant_id`). Antes do trabalho o tenant fica vazio; depois (ou em falha) repõe-se o de quem
     * despachou. Sem efeito com erp.saas.ativo=false.
     */
    private function configurarFilas(): void
    {
        $tenants = [];
        $contexto = fn () => $this->app->make(ContextoTenant::class);

        Queue::before(function () use (&$tenants, $contexto) {
            if (! ContextoTenant::ativo()) {
                return;
            }
            $tenants[] = $contexto()->id();
            $contexto()->limpar();
        });

        $repor = function () use (&$tenants, $contexto) {
            if (! ContextoTenant::ativo() || $tenants === []) {
                return;
            }
            $contexto()->definir(array_pop($tenants));
        };
        Queue::after($repor);
        Queue::failing($repor);
    }

    /**
     * SaaS (ADR-073): comandos de manutencao atravessam tenants e nao tem utilizador. Com SaaS activo e ligacao do dono
     * configurada, correm nessa ligacao (superuser, ignora a RLS). Os restantes (agendador, jobs) definem o tenant.
     */
    private function configurarComandosDeManutencao(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $evento) {
            $ligacao = config('erp.saas.ligacao_dono');
            if (ContextoTenant::ativo() && $ligacao && in_array($evento->command, (array) config('erp.saas.comandos_de_manutencao'), true)) {
                DB::setDefaultConnection($ligacao);
            }
        });
    }

    private function configurarSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(TokenAcesso::class);

        // `utilizadores` tem RLS por tenant_id (IsolamentoRls::TENANT_DIRETAS): o próprio Guard do Sanctum já lê
        // `$accessToken->tokenable` (vendor/laravel/sanctum/src/Guard.php, isValidAccessToken->hasValidProvider)
        // ANTES de chamar authenticateAccessTokensUsing — sem contexto ainda definido nesse ponto, a relação ficava
        // em cache a null (Eloquent só corre a consulta uma vez) e nada feito depois a conseguia mudar: TODO pedido
        // autenticado a seguir ao login falhava com 401 (só não apanhado pelos testes, que correm na ligação do
        // dono, que ignora a RLS). Tem de ser aqui — o ponto mais cedo onde o Sanctum deixa entrar código próprio,
        // antes de tocar em tokenable. tenant_do_token é SECURITY DEFINER (migração saas_rls_tenant_do_token): só
        // devolve um tenant_id a partir do id do token, nunca dados pessoais — o mesmo bypass do login
        // (login_localizar_utilizador), aqui para a autenticação por token.
        Sanctum::getAccessTokenFromRequestUsing(function (Request $request) {
            $token = $request->bearerToken();
            if ($token && ContextoTenant::ativo() && str_contains($token, '|')) {
                [$id] = explode('|', $token, 2);
                if (ctype_digit($id)) {
                    $tenantId = DB::selectOne('SELECT tenant_do_token(?) AS tenant_id', [(int) $id])?->tenant_id;
                    if ($tenantId !== null) {
                        app(ContextoTenant::class)->definir((int) $tenantId);
                    }
                }
            }

            return $token;
        });

        // Paridade com o legado: a sessão expira após N minutos sem actividade (js/app_v2.js:704-723),
        // além da validade absoluta (expira_em). Utilizadores inactivos/eliminados perdem o acesso de imediato.
        Sanctum::authenticateAccessTokensUsing(function (TokenAcesso $token, bool $valido): bool {
            if (! $valido) {
                return false;
            }

            $portador = $token->tokenable;
            if (! $portador instanceof Utilizador || ! $portador->ativo || $portador->trashed()) {
                return false;
            }

            $referencia = $token->ultimo_uso_em ?? $token->criado_em;

            return $referencia !== null && $referencia->gt(now()->subMinutes(config('erp.sessao.inatividade_minutos')));
        });
    }

    private function configurarPermissoes(): void
    {
        // Qualquer chave de permissão do legado (ecrã "<id>_view" ou tarefa) é uma ability do Gate.
        // Policies específicas continuam a decidir quando o perfil não concede a chave (retorno null).
        Gate::before(function (Utilizador $utilizador, string $habilidade) {
            return app(ServicoPermissoes::class)->autoriza($utilizador, $habilidade, app(ContextoEmpresa::class)->id()) ?: null;
        });
    }

    private function configurarLimites(): void
    {
        RateLimiter::for('entrar', function (Request $request) {
            $chave = mb_strtolower((string) $request->input('nome_utilizador')).'|'.$request->ip();

            // Segurança (Fase 6): além do limite por utilizador e IP (ADR-007), um limite por IP impede testar muitos
            // nomes de utilizador a partir do mesmo endereço (password spraying).
            return [
                Limit::perMinute(config('erp.sessao.tentativas_por_minuto'))->by($chave),
                Limit::perMinute((int) (config('erp.sessao.tentativas_por_minuto_ip') ?? 60))->by('ip|'.$request->ip()),
            ];
        });

        // Alteração da própria palavra-passe (exige a actual): poucas tentativas por utilizador, contra adivinhação (OWASP A07)
        RateLimiter::for('palavra-passe', fn (Request $request) => Limit::perMinute(5)->by('pp|'.($request->user()?->getKey() ?: $request->ip())));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('erp.api.pedidos_por_minuto', 300))->by($request->user()?->getKey() ?: $request->ip()));

        // OWASP A04: rotas que fazem pedidos a sistemas EXTERNOS a pedido do utilizador (BAI, relógio biométrico) e
        // operações pesadas (importações de folhas, ZIP de recibos) têm limites próprios, abaixo do geral.
        $quem = fn (Request $request) => (string) ($request->user()?->getKey() ?: $request->ip());
        RateLimiter::for('externo', fn (Request $request) => Limit::perMinute((int) config('erp.api.externo_por_minuto', 6))->by('externo|'.$quem($request)));
        RateLimiter::for('pesado', fn (Request $request) => Limit::perMinute((int) config('erp.api.pesado_por_minuto', 30))->by('pesado|'.$quem($request)));
    }
}
