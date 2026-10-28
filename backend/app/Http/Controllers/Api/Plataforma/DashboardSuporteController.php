<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Métricas do backoffice de suporte: tickets por estado, tempo médio de primeira resposta, atrasos e por agente. */
final class DashboardSuporteController extends Controller
{
    public function __construct(private readonly ServicoPermissoes $permissoes) {}

    public function metricas(Request $r): JsonResponse
    {
        $categorias = $this->categoriasPermitidas($r);
        $base = fn () => TicketSuporte::query()->when($categorias !== null, fn ($q) => $q->whereIn('categoria', $categorias));

        $porEstado = (clone $base())->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado');
        $tempoMedioHoras = (clone $base())->whereNotNull('primeira_resposta_em')
            ->selectRaw('avg(extract(epoch from (primeira_resposta_em - criado_em)) / 3600.0) as media')->value('media');
        $emAtraso = (clone $base())->whereNotNull('prazo_sla')->whereNotIn('estado', [TicketSuporte::RESOLVIDO, TicketSuporte::FECHADO])
            ->where('prazo_sla', '<', now())->count();
        $porAgenteContagem = (clone $base())->whereNotNull('agente_id')->selectRaw('agente_id, count(*) as total')->groupBy('agente_id')->pluck('total', 'agente_id');
        $nomesAgentes = Utilizador::query()->whereIn('id', $porAgenteContagem->keys())->pluck('nome_utilizador', 'id');

        return RespostaApi::sucesso([
            'por_estado' => ['ABERTO' => (int) ($porEstado['ABERTO'] ?? 0), 'EM_CURSO' => (int) ($porEstado['EM_CURSO'] ?? 0),
                'RESOLVIDO' => (int) ($porEstado['RESOLVIDO'] ?? 0), 'FECHADO' => (int) ($porEstado['FECHADO'] ?? 0)],
            'tempo_medio_primeira_resposta_horas' => $tempoMedioHoras !== null ? round((float) $tempoMedioHoras, 1) : null,
            'em_atraso' => $emAtraso,
            'por_agente' => $porAgenteContagem->map(fn ($total, $id) => ['agente_id' => (int) $id, 'nome' => $nomesAgentes[$id] ?? "#{$id}", 'total' => (int) $total])->values(),
        ], 'Métricas de suporte obtidas com sucesso.');
    }

    /** null = sem filtro (super-administrador, vê tudo); senão, as categorias cujas chaves o perfil do agente concede. */
    private function categoriasPermitidas(Request $r): ?array
    {
        $u = $r->user();
        if ($u->eSuperAdministrador()) {
            return null;
        }

        return array_values(array_unique(array_keys(array_filter(
            TicketSuporte::CHAVE_POR_CATEGORIA,
            fn ($chave) => $this->permissoes->tem($u, $chave),
        ))));
    }
}
