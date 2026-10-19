<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Services\Projetos\ServicoOrganigramaProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ronda 4 (r4_rh_proj) — arrastar membros entre posições do organigrama (legado: ligarArrasto, projectos_organigrama.js:354-367).
 * Permissão `proj_gerir` verificada antes da validação (OWASP A01); o projecto, os membros e as posições ficam isolados pela
 * empresa activa (PertenceEmpresa) e o serviço só aceita membros e posições do próprio projecto.
 */
final class OrganigramaMoverController extends Controller
{
    public function __construct(private readonly ServicoOrganigramaProjetos $organigrama) {}

    /**
     * POST /projetos/{projeto}/organigrama/mover — {membro_id, no_destino_id|null, no_origem_id?, confirmar_excesso?, repor_responsavel?}.
     * `no_origem_id` (quando enviado, mesmo null) é a posição em que o ecrã viu o membro: se mudou entretanto, 409.
     */
    public function mover(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate([
            'membro_id' => ['required', 'integer', 'min:1'],
            'no_destino_id' => ['present', 'nullable', 'integer', 'min:1'],
            'no_origem_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'confirmar_excesso' => ['nullable', 'boolean'],
            'repor_responsavel' => ['nullable', 'boolean'],
        ]);
        $res = $this->organigrama->mover(Projeto::query()->findOrFail($projeto), (int) $d['membro_id'], isset($d['no_destino_id']) ? (int) $d['no_destino_id'] : null,
            $r->exists('no_origem_id'), isset($d['no_origem_id']) ? (int) $d['no_origem_id'] : null, (bool) ($d['confirmar_excesso'] ?? false), (bool) ($d['repor_responsavel'] ?? false));

        return RespostaApi::sucesso($res, ! $res['alterado'] ? 'O membro já estava nessa posição.' : ($res['para'] ? 'Membro movido para a posição.' : 'Membro retirado da posição.'));
    }
}
