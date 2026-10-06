<?php

namespace App\Providers;

use App\Models\Empresa;
use App\Models\TokenAcesso;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Services\Vendas\Agt\ClienteAgt;
use App\Services\Vendas\Agt\ClienteAgtDesligado;
use App\Services\Vendas\Agt\ClienteAgtDireto;
use App\Services\Vendas\Agt\ClienteAgtIntermedio;
use App\Support\Seguranca\ValorSemFormulas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        // Ligação à AGT (ADR-030): directa, pelo serviço intermédio do legado, ou desligada (por omissão)
        $this->app->bind(ClienteAgt::class, fn ($app) => match (config('erp.agt.driver')) {
            'direto' => $app->make(ClienteAgtDireto::class),
            'intermedio' => $app->make(ClienteAgtIntermedio::class),
            default => $app->make(ClienteAgtDesligado::class),
        });
    }

    public function boot(): void
    {
        // Fora de produção: falhar cedo em lazy loading, atributos inexistentes e mass assignment silencioso.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Tipos polimórficos estáveis (não gravar nomes de classes PHP na base de dados).
        Relation::enforceMorphMap([
            'utilizador' => Utilizador::class,
            'empresa' => Empresa::class,
        ]);

        $this->configurarSanctum();
        $this->configurarPermissoes();
        $this->configurarLimites();

        // OWASP A03: textos começados por = + - @ nunca viram fórmulas nos .xlsx gerados (CSV/Excel injection).
        Cell::setValueBinder(new ValorSemFormulas);
    }

    private function configurarSanctum(): void
    {
        Sanctum::usePersonalAccessTokenModel(TokenAcesso::class);

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

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('erp.api.pedidos_por_minuto', 300))->by($request->user()?->getKey() ?: $request->ip()));

        // OWASP A04: rotas que fazem pedidos a sistemas EXTERNOS a pedido do utilizador (BAI, relógio biométrico) e
        // operações pesadas (importações de folhas, ZIP de recibos) têm limites próprios, abaixo do geral.
        $quem = fn (Request $request) => (string) ($request->user()?->getKey() ?: $request->ip());
        RateLimiter::for('externo', fn (Request $request) => Limit::perMinute((int) config('erp.api.externo_por_minuto', 6))->by('externo|'.$quem($request)));
        RateLimiter::for('pesado', fn (Request $request) => Limit::perMinute((int) config('erp.api.pesado_por_minuto', 30))->by('pesado|'.$quem($request)));
    }
}
