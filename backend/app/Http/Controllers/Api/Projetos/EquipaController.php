<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\EquipaProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Services\Projetos\ServicoEquipasProjetos;
use App\Services\Projetos\ServicoOrganigramaProjetos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/projetos/{projeto} — equipa e organigrama. */
final class EquipaController extends Controller
{
    public function __construct(
        private readonly ServicoEquipasProjetos $equipas,
        private readonly ServicoOrganigramaProjetos $organigrama,
    ) {}

    // ───────────── Equipa ─────────────

    public function equipa(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);
        $p = Projeto::query()->findOrFail($projeto);
        $eq = EquipaProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->first();

        return RespostaApi::sucesso(['equipa' => $eq, 'membros' => $this->equipas->membros($p)], 'Equipa do projecto.');
    }

    public function guardarMembro(Request $r, int $projeto, ?int $membro = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['tipo' => ['nullable', Rule::in(['INTERNO', 'TERCEIRO', 'LIVRE'])], 'colaborador_id' => ['nullable', 'integer'], 'terceiro_id' => ['nullable', 'integer'],
            'nome_externo' => ['nullable', 'string', 'max:255'], 'papel' => ['nullable', 'string', 'max:100'], 'horas_alocadas' => ['nullable', 'numeric']]);
        $m = $membro ? $this->membro($p, $membro) : null;
        $res = $this->equipas->guardarMembro($p, $d, $m);

        return $m ? RespostaApi::sucesso($res, 'Membro actualizado.') : RespostaApi::criado($res, 'Membro adicionado à equipa.');
    }

    public function acrescentarEmMassa(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['tipo' => ['required', Rule::in(['INTERNO', 'TERCEIRO'])], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'],
            'papel' => ['nullable', 'string', 'max:100'], 'horas_alocadas' => ['nullable', 'numeric']]);
        $res = $this->equipas->acrescentarEmMassa(Projeto::query()->findOrFail($projeto), $d['tipo'], $d['ids'], $d['papel'] ?? null, $d['horas_alocadas'] ?? null);

        return RespostaApi::criado($res, "{$res['criados']} membro(s) adicionado(s) à equipa.");
    }

    public function alterarEmMassa(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'papel' => ['nullable', 'string', 'max:100'], 'horas_alocadas' => ['nullable', 'numeric']]);
        $n = $this->equipas->alterarEmMassa(Projeto::query()->findOrFail($projeto), $d['ids'], $d['papel'] ?? null, $d['horas_alocadas'] ?? null);

        return RespostaApi::sucesso(['alterados' => $n], "{$n} membro(s) actualizado(s).");
    }

    public function remover(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $n = $this->equipas->remover(Projeto::query()->findOrFail($projeto), $d['ids']);

        return RespostaApi::sucesso(['removidos' => $n], "{$n} membro(s) removido(s) da equipa.");
    }

    // ───────────── Organigrama ─────────────

    public function organigrama(int $projeto): JsonResponse
    {
        $this->exigir(...ProjetoController::LER);

        return RespostaApi::sucesso($this->organigrama->organigrama(Projeto::query()->findOrFail($projeto), $this->equipas), 'Organigrama do projecto.');
    }

    public function guardarPosicao(Request $r, int $projeto, ?int $posicao = null): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $d = $r->validate(['titulo' => [$posicao ? 'sometimes' : 'required', 'string', 'max:255'], 'area' => ['nullable', 'string', 'max:30'], 'descricao' => ['nullable', 'string', 'max:5000'],
            'vagas' => ['nullable', 'integer', 'min:0'], 'no_pai_id' => ['nullable', 'integer'], 'cor' => ['nullable', Rule::in(ServicoOrganigramaProjetos::CORES)],
            'apoio' => ['nullable', 'boolean'], 'membro_responsavel_id' => ['nullable', 'integer']]);
        $n = $posicao ? NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->findOrFail($posicao) : null;
        $res = $this->organigrama->guardarPosicao($p, $d, $n);

        return $n ? RespostaApi::sucesso($res, 'Posição actualizada.') : RespostaApi::criado($res, 'Posição criada.');
    }

    public function guardarPosicoes(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['no_pai_id' => ['nullable', 'integer'], 'cor' => ['nullable', 'string', 'max:20'], 'linhas' => ['required', 'array', 'min:1'],
            'linhas.*.titulo' => ['nullable', 'string', 'max:255'], 'linhas.*.area' => ['nullable', 'string', 'max:30'], 'linhas.*.vagas' => ['nullable', 'integer', 'min:0']]);
        $n = $this->organigrama->guardarPosicoes(Projeto::query()->findOrFail($projeto), $d['no_pai_id'] ?? null, $d['cor'] ?? 'azul', $d['linhas']);

        return RespostaApi::criado(['criadas' => $n], "{$n} posição(ões) criada(s).");
    }

    public function eliminarPosicao(int $projeto, int $posicao): JsonResponse
    {
        $this->exigir('proj_gerir');
        $p = Projeto::query()->findOrFail($projeto);
        $this->organigrama->eliminarPosicao($p, NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->findOrFail($posicao));

        return RespostaApi::sucesso(null, 'Posição eliminada.');
    }

    public function arrumar(Request $r, int $projeto, int $posicao): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['acao' => ['required', Rule::in(['TRAS', 'FRENTE', 'SUBIR', 'DESCER', 'APOIO'])]]);
        $p = Projeto::query()->findOrFail($projeto);

        return RespostaApi::sucesso($this->organigrama->arrumar($p, NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->findOrFail($posicao), $d['acao']), 'Posição arrumada.');
    }

    public function associarTarefas(Request $r, int $projeto, int $posicao): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['tarefas' => ['present', 'array'], 'tarefas.*' => ['integer']]);
        $p = Projeto::query()->findOrFail($projeto);

        return RespostaApi::sucesso($this->organigrama->associarTarefas($p, NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->findOrFail($posicao), $d['tarefas']),
            'Tarefas associadas à posição.');
    }

    public function alocar(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['membros' => ['required', 'array', 'min:1'], 'membros.*' => ['integer'], 'no_organigrama_projeto_id' => ['nullable', 'integer'],
            'confirmar_excesso' => ['nullable', 'boolean']]);
        $n = $this->organigrama->alocar(Projeto::query()->findOrFail($projeto), $d['membros'], $d['no_organigrama_projeto_id'] ?? null, (bool) ($d['confirmar_excesso'] ?? false));

        return RespostaApi::sucesso(['alocados' => $n], "{$n} membro(s) alocado(s).");
    }

    public function disposicao(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['posicoes' => ['nullable', 'array'], 'posicoes.*' => ['integer'], 'disposicao' => ['nullable', Rule::in(['COLUNA'])],
            'global' => ['nullable', Rule::in(['FINAIS', 'LINHA'])]]);
        $n = $this->organigrama->disposicao(Projeto::query()->findOrFail($projeto), $d['posicoes'] ?? [], $d['disposicao'] ?? null, $d['global'] ?? null);

        return RespostaApi::sucesso(['alteradas' => $n], "{$n} posição(ões) alterada(s).");
    }

    public function mapearOrcamento(Request $r, int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');
        $d = $r->validate(['alteracoes' => ['required', 'array', 'min:1'], 'alteracoes.*.linha_id' => ['required', 'integer'],
            'alteracoes.*.no_organigrama_projeto_id' => ['nullable', 'integer'], 'alteracoes.*.membro_equipa_projeto_id' => ['nullable', 'integer']]);
        $n = $this->organigrama->mapearOrcamento(Projeto::query()->findOrFail($projeto), $d['alteracoes']);

        return RespostaApi::sucesso(['mapeadas' => $n], "{$n} linha(s) de orçamento mapeada(s).");
    }

    public function modeloBase(int $projeto): JsonResponse
    {
        $this->exigir('proj_gerir');

        return RespostaApi::criado(['criadas' => $this->organigrama->modeloBase(Projeto::query()->findOrFail($projeto))], 'Organigrama criado a partir do modelo de obra.');
    }

    private function membro(Projeto $p, int $id): MembroEquipaProjeto
    {
        return MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->findOrFail($id);
    }
}
