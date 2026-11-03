<?php

namespace App\Console\Commands;

use App\Models\AssuntoMetodoTrabalho;
use App\Models\Colaborador;
use App\Models\DelegacaoChefia;
use App\Models\Empresa;
use App\Models\PedidoPortalColaborador;
use App\Models\PlanoFeriasColaborador;
use App\Models\TarefaUnidadeOrganica;
use App\Services\Estrutura\ServicoEntregaveis;
use App\Services\RH\ServicoDelegacoesChefia;
use App\Services\RH\ServicoEstruturaOrg;
use App\Services\Sistema\NotificadorRH;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Alertas diários de prazos de RH, Método e Estrutura (Fase K, ADR-079; agendado às 07:30). Por empresa activa:
 *   1. entregáveis em atraso (os marcados para avisar) → o dono, ou o responsável da tarefa/da unidade: uma vez por entregável e período;
 *   2. férias aprovadas que começam daqui a 3 dias → o colaborador e a chefia;
 *   3. pedidos do portal e assuntos do Método à espera de decisão há mais de 3 dias → o aprovador (lembrete de 3 em 3 dias);
 *   4. delegações da chefia que terminam amanhã → delegado e delegante.
 * Idempotente (NotificadorRH `unica`: o mesmo aviso não se repete) e isolado: a falha de uma empresa ou de uma secção não impede as
 * restantes. As notificações nunca fazem falhar nada de negócio.
 */
final class AlertasPrazosRHCommand extends Command
{
    private const DIAS_LEMBRETE = 3;

    private const DIAS_ANTES_FERIAS = 3;

    protected $signature = 'erp:rh:alertas-prazos';

    protected $description = 'Avisa de entregáveis em atraso, férias a começar, pedidos e assuntos pendentes e delegações a terminar';

    public function handle(ContextoTenant $tenant, ContextoEmpresa $contexto): int
    {
        $total = ['atrasos' => 0, 'ferias' => 0, 'pendentes' => 0, 'delegacoes' => 0];
        $falhas = 0;
        $tenant->porTenant(function () use ($contexto, &$total, &$falhas) {
            foreach (Empresa::query()->where('estado', Empresa::ESTADO_ATIVO)->orderBy('id')->pluck('id') as $empresa) {
                $contexto->executarComo((int) $empresa, function () use (&$total, &$falhas, $empresa) {
                    foreach (['atrasos' => fn () => $this->atrasos(), 'ferias' => fn () => $this->ferias(), 'pendentes' => fn () => $this->pendentes(), 'delegacoes' => fn () => $this->delegacoes()] as $nome => $secao) {
                        try {
                            $total[$nome] += $secao();
                        } catch (Throwable $e) {
                            $falhas++;
                            report($e);
                            $this->error("Empresa {$empresa} · {$nome}: {$e->getMessage()}");
                        }
                    }
                });
            }
        });
        $this->info("Alertas: {$total['atrasos']} entregável(eis) em atraso, {$total['ferias']} férias a começar, {$total['pendentes']} pendência(s), {$total['delegacoes']} delegação(ões) a terminar.");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function notificador(): NotificadorRH
    {
        return app(NotificadorRH::class);
    }

    /** 1. Entregáveis em atraso → dono (ou responsável da tarefa / da unidade). */
    private function atrasos(): int
    {
        $entregaveis = app(ServicoEntregaveis::class);
        $unidades = $entregaveis->unidades();
        $n = 0;
        foreach ($entregaveis->entregaveisEmAtraso(['soComAlerta' => true]) as $a) {
            $tarefa = TarefaUnidadeOrganica::query()->find($a['tarefa']['id']);
            $destino = $a['dono']['id'] ?? $tarefa?->colaborador_responsavel_id ?? $unidades->get((int) $a['unidade_organica_id'])?->colaborador_responsavel_id;
            if (! $destino) {
                continue;
            }
            $this->notificador()->paraColaborador((int) $destino, 'Entregável em atraso',
                "«{$a['entregavel']['nome']}» ({$a['unidade']['nome']}), período de {$a['periodo_de']}: o prazo era {$a['limite']} e a meta não foi cumprida.", '/m/rh/rh_portal', 'estrutura', 'URGENTE', true);
            $n++;
        }

        return $n;
    }

    /** 2. Férias aprovadas a começar daqui a 3 dias → colaborador e chefia. */
    private function ferias(): int
    {
        $estrutura = app(ServicoEstruturaOrg::class);
        $dia = now()->addDays(self::DIAS_ANTES_FERIAS)->toDateString();
        $n = 0;
        foreach (PlanoFeriasColaborador::query()->where('estado', 'APROVADO')->where('data_inicio', $dia)->get() as $f) {
            $nome = Colaborador::query()->withTrashed()->whereKey($f->colaborador_id)->value('nome_completo');
            $periodo = "{$f->data_inicio->toDateString()} a {$f->data_fim->toDateString()}";
            $this->notificador()->paraColaborador((int) $f->colaborador_id, 'As suas férias começam em breve', "As suas férias ({$periodo}) começam daqui a ".self::DIAS_ANTES_FERIAS.' dias.', '/m/rh/rh_portal', 'rh', 'INFO', true);
            if ($chefe = $estrutura->chefiaDe((int) $f->colaborador_id)) {
                $this->notificador()->paraColaborador($chefe, 'Férias da equipa a começar', "{$nome} entra de férias ({$periodo}) daqui a ".self::DIAS_ANTES_FERIAS.' dias.', '/m/rh/rh_portal', 'rh', 'INFO', true);
            }
            $n++;
        }

        return $n;
    }

    /** 3. Pedidos do portal e assuntos do Método pendentes há mais de 3 dias → o aprovador (lembrete de 3 em 3 dias). */
    private function pendentes(): int
    {
        $delegacoes = app(ServicoDelegacoesChefia::class);
        $limite = now()->subDays(self::DIAS_LEMBRETE);
        $n = 0;
        foreach (PedidoPortalColaborador::query()->where('estado', 'like', 'PENDENTE%')->where('criado_em', '<=', $limite)->orderBy('id')->get() as $p) {
            $etapa = collect($p->etapas)->firstWhere('estado', 'PENDENTE');
            if (! $etapa) {
                continue;
            }
            $nome = Colaborador::query()->withTrashed()->whereKey($p->colaborador_id)->value('nome_completo');
            $titulo = 'Pedido à espera da sua decisão';
            $mensagem = "O pedido de {$p->tipo} #{$p->id} de {$nome} aguarda decisão há mais de ".self::DIAS_LEMBRETE.' dias.';
            if ($etapa['nivel'] === 'CHEFIA') {
                $chefe = (int) ($etapa['aprovador_colaborador_id'] ?? $etapa['aprovador_employee_id'] ?? 0);
                $this->notificador()->paraColaboradores(array_merge([$chefe], $chefe ? $delegacoes->substitutoDe($chefe, 'PORTAL') : []), $titulo, $mensagem, '/m/rh/rh_portal', 'rh', 'AVISO', self::DIAS_LEMBRETE);
            } else {
                $this->notificador()->paraPermissao('rh_portal_aprovar', $titulo, $mensagem, '/m/rh/rh_portal', 'rh', 'AVISO', [(int) $p->colaborador_id], self::DIAS_LEMBRETE);
            }
            $n++;
        }
        foreach (AssuntoMetodoTrabalho::query()->whereNotNull('numero')->whereRaw("deliberacao->>'estado' = 'PENDENTE'")->whereRaw("(deliberacao->>'pedida_em')::timestamptz <= ?", [$limite->toIso8601String()])->orderBy('id')->get() as $a) {
            $d = (array) $a->deliberacao;
            $titulo = "Assunto à espera da sua decisão: {$a->numero}";
            $mensagem = "O assunto «{$a->titulo}» ({$a->numero}) aguarda aprovação há mais de ".self::DIAS_LEMBRETE.' dias.';
            if (! empty($d['aprovador_colaborador_id'])) {
                $chefe = (int) $d['aprovador_colaborador_id'];
                $this->notificador()->paraColaboradores(array_merge([$chefe], $delegacoes->substitutoDe($chefe, 'METODO')), $titulo, $mensagem, '/m/metodo/mt_assuntos', 'metodo', 'AVISO', self::DIAS_LEMBRETE);
            } else {
                $this->notificador()->paraPermissao('mt_aprovar', $titulo, $mensagem, '/m/metodo/mt_assuntos', 'metodo', 'AVISO', [], self::DIAS_LEMBRETE);
            }
            $n++;
        }

        return $n;
    }

    /** 4. Delegações activas que terminam amanhã → delegado e delegante. */
    private function delegacoes(): int
    {
        $amanha = now()->addDay()->toDateString();
        $n = 0;
        foreach (DelegacaoChefia::query()->where('estado', 'ATIVA')->where('data_fim', $amanha)->get() as $d) {
            $nomes = Colaborador::query()->withTrashed()->whereIn('id', [$d->delegante_colaborador_id, $d->delegado_colaborador_id])->pluck('nome_completo', 'id');
            $this->notificador()->paraColaborador((int) $d->delegado_colaborador_id, 'Delegação de chefia a terminar', "A delegação de {$nomes[$d->delegante_colaborador_id]} termina amanhã ({$amanha}).", '/m/rh/rh_portal', 'rh', 'AVISO', true);
            $this->notificador()->paraColaborador((int) $d->delegante_colaborador_id, 'Delegação da sua chefia a terminar', "A sua delegação em {$nomes[$d->delegado_colaborador_id]} termina amanhã ({$amanha}).", '/m/rh/rh_portal', 'rh', 'AVISO', true);
            $n++;
        }

        return $n;
    }
}
