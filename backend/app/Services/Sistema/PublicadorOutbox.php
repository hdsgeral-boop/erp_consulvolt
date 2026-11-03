<?php

namespace App\Services\Sistema;

use App\Models\OutboxEvento;
use App\Models\Utilizador;
use App\Support\Outbox\ManipuladorOutbox;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publicador do Transactional Outbox (ADR-080): entrega «pelo menos uma vez» os eventos PENDENTES aos manipuladores registados.
 *
 *   - reclamação: `SELECT … FOR UPDATE SKIP LOCKED` numa transacção curta que marca `reservado_ate` (lease, 2 min): vários
 *     publicadores em paralelo (agendador + publicação imediata + outro servidor) nunca pegam no mesmo evento; um publicador que
 *     morre a meio liberta o evento quando o lease expira;
 *   - o manipulador corre FORA dessa transacção, com a empresa do evento activa (`executarComo`), ou sem empresa se for global;
 *   - sucesso → PUBLICADO; excepção → `tentativas`+1, `ultimo_erro` (2000 caracteres) e nova tentativa com recuo exponencial
 *     com jitter (`erp.outbox.recuo_segundos`); esgotadas `erp.outbox.max_tentativas` → MORTO + aviso aos administradores da
 *     empresa (log crítico se global). Um tipo sem manipulador vai logo para MORTO (não fica a rodar).
 * O relógio usado é o da aplicação (como o resto do código), para o mesmo tempo valer em `registar` e aqui.
 */
final class PublicadorOutbox
{
    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * Publica até $max eventos devidos.
     *
     * @return array{publicados: int, falhados: int, mortos: int}
     */
    public function publicarLote(int $max = 100): array
    {
        return $this->processar($this->reclamar(null, max(1, $max)));
    }

    /**
     * Publica só este evento, se estiver PENDENTE, devido e sem reserva de outro publicador.
     *
     * @return array{publicados: int, falhados: int, mortos: int}
     */
    public function publicarUm(int $id): array
    {
        return $this->processar($this->reclamar($id, 1));
    }

    /**
     * Reserva (lease) os eventos devidos e devolve-os. Público para os testes simularem dois publicadores em paralelo.
     *
     * @return list<OutboxEvento>
     */
    public function reclamar(?int $id, int $max): array
    {
        $agora = now();
        $instante = $agora->format('Y-m-d H:i:s.uP');

        return DB::transaction(function () use ($id, $max, $agora, $instante) {
            $ids = array_map(fn ($l) => (int) $l->id, DB::connection()->select(
                "SELECT id FROM outbox_eventos WHERE estado = 'PENDENTE' AND proxima_tentativa_em <= ? AND (reservado_ate IS NULL OR reservado_ate < ?)"
                .($id !== null ? ' AND id = ?' : '').' ORDER BY proxima_tentativa_em, id LIMIT ? FOR UPDATE SKIP LOCKED',
                array_merge([$instante, $instante], $id !== null ? [$id] : [], [$max]),
                false,
            ));
            if ($ids === []) {
                return [];
            }
            OutboxEvento::query()->whereIn('id', $ids)->update(['reservado_ate' => $agora->copy()->addSeconds((int) config('erp.outbox.reserva_segundos', 120))]);

            return OutboxEvento::query()->whereIn('id', $ids)->orderBy('id')->get()->all();
        });
    }

    /**
     * @param  list<OutboxEvento>  $eventos
     * @return array{publicados: int, falhados: int, mortos: int}
     */
    private function processar(array $eventos): array
    {
        $r = ['publicados' => 0, 'falhados' => 0, 'mortos' => 0];
        foreach ($eventos as $evento) {
            $estado = $this->publicar($evento);
            $r[['PUBLICADO' => 'publicados', 'MORTO' => 'mortos'][$estado] ?? 'falhados']++;
        }

        return $r;
    }

    /** @return string estado em que o evento ficou (PUBLICADO, MORTO ou PENDENTE para nova tentativa) */
    private function publicar(OutboxEvento $evento): string
    {
        // o tipo tem pontos (`notificacao.rh`): não usar a notação de pontos do config()
        $classe = ((array) config('erp.outbox.manipuladores', []))[$evento->tipo] ?? null;
        if (! $classe || ! is_a($classe, ManipuladorOutbox::class, true)) {
            $this->matar($evento, "Sem manipulador registado para o tipo «{$evento->tipo}» (erp.outbox.manipuladores).");

            return OutboxEvento::MORTO;
        }
        try {
            $manipulador = app($classe);
            $executar = fn () => $manipulador->manipular((array) $evento->carga, $evento);
            $evento->empresa_id ? $this->contexto->executarComo((int) $evento->empresa_id, $executar) : $this->contexto->semIsolamento($executar);
        } catch (Throwable $erro) {
            return $this->falhou($evento, $erro);
        }
        OutboxEvento::query()->whereKey($evento->id)->update(['estado' => OutboxEvento::PUBLICADO, 'publicado_em' => now(), 'reservado_ate' => null, 'ultimo_erro' => null]);

        return OutboxEvento::PUBLICADO;
    }

    private function falhou(OutboxEvento $evento, Throwable $erro): string
    {
        $tentativas = (int) $evento->tentativas + 1;
        $mensagem = mb_substr(get_class($erro).': '.$erro->getMessage(), 0, 2000);
        Log::warning("Outbox #{$evento->id} ({$evento->tipo}): tentativa {$tentativas} falhou — {$mensagem}");
        if ($tentativas >= (int) config('erp.outbox.max_tentativas', 8)) {
            $evento->tentativas = $tentativas;
            $this->matar($evento, $mensagem);

            return OutboxEvento::MORTO;
        }
        OutboxEvento::query()->whereKey($evento->id)->update(['tentativas' => $tentativas, 'ultimo_erro' => $mensagem, 'reservado_ate' => null,
            'proxima_tentativa_em' => now()->addSeconds($this->recuo($tentativas))]);

        return OutboxEvento::PENDENTE;
    }

    /** Recuo exponencial (tabela em config) com jitter de ±20 %. */
    private function recuo(int $tentativas): int
    {
        $tabela = array_values((array) config('erp.outbox.recuo_segundos', [30, 120, 600]));
        $base = $tabela[min($tentativas, count($tabela)) - 1] ?? 60;

        return max(1, (int) round($base * (mt_rand(80, 120) / 100)));
    }

    private function matar(OutboxEvento $evento, string $motivo): void
    {
        OutboxEvento::query()->whereKey($evento->id)->update(['estado' => OutboxEvento::MORTO, 'tentativas' => $evento->tentativas, 'ultimo_erro' => mb_substr($motivo, 0, 2000), 'reservado_ate' => null]);
        Log::critical("Outbox #{$evento->id} ({$evento->tipo}) MORTO após {$evento->tentativas} tentativa(s): {$motivo}");
        try {
            $this->avisarAdministradores($evento, $motivo);
        } catch (Throwable $erro) {
            report($erro);
        }
    }

    /** Aviso URGENTE aos administradores da empresa do evento (um evento global só deixa o log crítico e o /api/saude). */
    private function avisarAdministradores(OutboxEvento $evento, string $motivo): void
    {
        if (! $evento->empresa_id) {
            return;
        }
        $this->contexto->executarComo((int) $evento->empresa_id, function () use ($evento, $motivo) {
            $permissoes = app(ServicoPermissoes::class);
            $ids = DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')->where('ue.empresa_id', $evento->empresa_id)
                ->where('u.ativo', true)->whereNull('u.eliminado_em')->pluck('u.id')->all();
            $administradores = Utilizador::query()->with('perfil')->whereIn('id', $ids ?: [0])->get()->filter(fn (Utilizador $u) => $permissoes->administrador($u));
            $servico = app(ServicoNotificacoes::class);
            foreach ($administradores as $admin) {
                $servico->criar((int) $evento->empresa_id, 'URGENTE', 'sistema', 'Tarefa em segundo plano falhou definitivamente',
                    "O evento #{$evento->id} ({$evento->tipo}) falhou {$evento->tentativas} vez(es) e foi suspenso: ".mb_substr($motivo, 0, 300).' Contacte o suporte ou reprocesse-o (erp:outbox:reprocessar).',
                    null, (int) $admin->getKey(), 'Sistema');
            }
        });
    }
}
