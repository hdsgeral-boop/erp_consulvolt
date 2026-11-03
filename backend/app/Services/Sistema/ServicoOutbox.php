<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\OutboxEvento;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operação do Transactional Outbox (ADR-080): resumo (comandos e /api/saude), limpeza e reprocessamento. Percorre os tenants
 * (ContextoTenant::porTenant: sem SaaS, uma só passagem) porque a política RLS só mostra os eventos do tenant activo e os globais;
 * os eventos globais (empresa_id nulo) aparecem em todas as passagens e por isso só se contam uma vez.
 */
final class ServicoOutbox
{
    public function __construct(private readonly ContextoTenant $tenant) {}

    /**
     * @return array{pendentes: int, mais_antigo_segundos: int|null, mortos: int, publicados: int, por_tipo: array<string, int>}
     */
    public function resumo(): array
    {
        $r = ['pendentes' => 0, 'mais_antigo_segundos' => null, 'mortos' => 0, 'publicados' => 0, 'por_tipo' => []];
        $globaisContados = false;
        $this->tenant->porTenant(function () use (&$r, &$globaisContados) {
            $filtro = $globaisContados ? 'WHERE empresa_id IS NOT NULL' : '';
            $globaisContados = true;
            foreach (DB::select("SELECT estado, tipo, count(*) AS n, min(criado_em) FILTER (WHERE estado = 'PENDENTE') AS mais_antigo FROM outbox_eventos {$filtro} GROUP BY estado, tipo") as $l) {
                match ($l->estado) {
                    OutboxEvento::PENDENTE => $r['pendentes'] += (int) $l->n,
                    OutboxEvento::MORTO => $r['mortos'] += (int) $l->n,
                    default => $r['publicados'] += (int) $l->n,
                };
                if ($l->estado !== OutboxEvento::PUBLICADO) {
                    $r['por_tipo']["{$l->estado} {$l->tipo}"] = ($r['por_tipo']["{$l->estado} {$l->tipo}"] ?? 0) + (int) $l->n;
                }
                if ($l->mais_antigo !== null) {
                    $idade = max(0, (int) Carbon::parse($l->mais_antigo)->diffInSeconds(now(), true));
                    $r['mais_antigo_segundos'] = max($r['mais_antigo_segundos'] ?? 0, $idade);
                }
            }
        });
        ksort($r['por_tipo']);

        return $r;
    }

    /**
     * Estado para o /api/saude: AVISO se o pendente mais antigo excede `erp.outbox.aviso_atraso_segundos` ou há eventos MORTOS.
     *
     * @return array{estado: string, detalhe: string, pendentes: int, mais_antigo_segundos: int|null, mortos: int}
     */
    public function saude(): array
    {
        $r = $this->resumo();
        $atrasado = $r['mais_antigo_segundos'] !== null && $r['mais_antigo_segundos'] > (int) config('erp.outbox.aviso_atraso_segundos', 600);
        $detalhe = "{$r['pendentes']} pendente(s)".($r['mais_antigo_segundos'] !== null ? ", o mais antigo há {$r['mais_antigo_segundos']} s" : '').", {$r['mortos']} morto(s)";

        return ['estado' => $atrasado || $r['mortos'] > 0 ? 'AVISO' : 'OK', 'detalhe' => $detalhe, 'pendentes' => $r['pendentes'],
            'mais_antigo_segundos' => $r['mais_antigo_segundos'], 'mortos' => $r['mortos']];
    }

    /** Apaga os eventos PUBLICADOS com mais de $dias dias. Os MORTOS nunca se apagam sozinhos. */
    public function limpar(int $dias): int
    {
        $total = 0;
        $limite = now()->subDays($dias);
        $this->tenant->porTenant(function () use (&$total, $limite) {
            $total += DB::table('outbox_eventos')->where('estado', OutboxEvento::PUBLICADO)->where('publicado_em', '<', $limite)->delete();
        });

        return $total;
    }

    /**
     * Volta MORTO → PENDENTE (tentativas a 0, devido já): um evento (`$id`) ou todos os mortos.
     *
     * @return int eventos reposicionados
     */
    public function reprocessar(?int $id): int
    {
        $total = 0;
        $this->tenant->porTenant(function () use (&$total, $id) {
            $total += DB::table('outbox_eventos')->where('estado', OutboxEvento::MORTO)->when($id !== null, fn ($q) => $q->where('id', $id))
                ->update(['estado' => OutboxEvento::PENDENTE, 'tentativas' => 0, 'proxima_tentativa_em' => now(), 'reservado_ate' => null]);
        });
        if ($id !== null && $total === 0) {
            throw new ErroNegocio("Evento #{$id} inexistente ou não está MORTO.", 'NAO_ENCONTRADO', 404);
        }

        return $total;
    }
}
