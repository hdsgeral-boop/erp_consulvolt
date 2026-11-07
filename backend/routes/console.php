<?php

use App\Jobs\Sistema\BatimentoWorker;
use App\Models\ContratoFornecedor;
use App\Models\TokenAcesso;
use App\Services\Integracoes\Cambios\ServicoCambiosBAIAutomaticos;
use App\Support\Cache\Batimentos;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Schedule;

// Partições anuais da auditoria preparadas com antecedência (1 de Dezembro).
Schedule::command('erp:auditoria:particoes --anos=2')->yearlyOn(12, 1, '02:00');

// Máquina de estados da licença (Fase 3 da extensão SaaS): sincroniza estado_licenca com a data, antes dos avisos
// e de qualquer outro processamento do dia. Sem efeito com licenças desligadas.
Schedule::command('erp:licencas:sincronizar')->dailyAt('00:30')->withoutOverlapping();

// Avisos de licença por email: 7 e 3 dias antes de expirar, e no próprio dia (ADR-074). Sem efeito com licenças desligadas.
Schedule::command('erp:licencas:avisar')->dailyAt('07:00')->withoutOverlapping();

// Alertas de prazos de RH (Fase K, ADR-079): entregáveis em atraso, férias a começar, pedidos e assuntos pendentes há mais de 3 dias e
// delegações a terminar. Idempotente (não repete avisos), um só servidor de cada vez.
Schedule::command('erp:rh:alertas-prazos')->dailyAt('07:30')->onOneServer()->withoutOverlapping();
Schedule::command('erp:imobiliario:caducar-reservas')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();   // reservas de áreas comuns com o prazo de pagamento esgotado
Schedule::command('erp:imobiliario:obras')->dailyAt('06:30')->onOneServer()->withoutOverlapping();   // gera as ordens dos planos de manutenção e actualiza as recepções de material das obras do imóvel

// Alertas de SLA de suporte violado (Fase 6 da extensão SaaS): corre com frequência porque o prazo é em horas, não em dias.
Schedule::command('erp:suporte:avisar-sla')->everyFifteenMinutes()->withoutOverlapping();

// Transactional Outbox (ADR-080): publica os eventos pendentes (a publicação imediata depois do COMMIT já trata quase todos; isto apanha
// as falhas, as retentativas devidas e os processos que morreram a meio). Limpeza diária dos publicados e das execuções idempotentes
// concluídas (os eventos MORTOS nunca se apagam sozinhos).
Schedule::command('erp:outbox:publicar')->everyMinute()->onOneServer()->withoutOverlapping();
Schedule::command('erp:outbox:limpar --dias=14')->dailyAt('03:10')->onOneServer()->withoutOverlapping();
Schedule::command('erp:idempotencia:limpar --dias=30')->dailyAt('03:20')->onOneServer()->withoutOverlapping();

// Facturação electrónica: envio automático e consulta de estados na AGT (no legado corria no browser, de 2 em 2 min).
Schedule::command('erp:agt:ciclo')->everyTwoMinutes()->withoutOverlapping();

// Contratos de fornecedores: ATIVO → EXPIRADO depois da data de fim (no legado a expiração era manual).
Schedule::call(function () {
    // SaaS (ADR-073): uma passagem por tenant (sem SaaS, uma só).
    app(ContextoTenant::class)->porTenant(fn () => app(ContextoEmpresa::class)->semIsolamento(fn () => ContratoFornecedor::query()->where('estado', 'ATIVO')
        ->whereNotNull('data_fim')->where('data_fim', '<', now()->toDateString())->update(['estado' => 'EXPIRADO'])));
})->dailyAt('00:15')->name('compras:contratos-expirados')->withoutOverlapping();

// Câmbios do BAI automáticos: verifica a cada minuto se a obtenção diária está devida (activa, depois da hora configurada,
// sem sucesso hoje, até 3 tentativas). O resultado fica só pendente de validação (nada é gravado nos câmbios).
Schedule::call(fn () => app(ServicoCambiosBAIAutomaticos::class)->executarSeDevido())
    ->everyMinute()->name('sistema:cambios-bai')->withoutOverlapping(15);

// Tokens expirados (Fase D, reforço de autenticação): tokens_acesso não tem RLS por tenant (migração
// saas_rls_tenant_do_token), por isso esta limpeza corre uma só vez por todos os tenants, sem ContextoTenant::porTenant.
Schedule::call(fn () => TokenAcesso::query()->whereNotNull('expira_em')->where('expira_em', '<', now())->delete())
    ->dailyAt('03:30')->name('autenticacao:tokens-expirados')->withoutOverlapping();

// Batimentos (R11): o /api/saude marca FALHA se o scheduler ou o worker deixarem de bater.
Schedule::call(fn () => Batimentos::registar(Batimentos::SCHEDULER))->everyMinute()->name('sistema:batimento-scheduler');
Schedule::job(new BatimentoWorker, 'baixa')->everyFiveMinutes()->name('sistema:batimento-worker');
