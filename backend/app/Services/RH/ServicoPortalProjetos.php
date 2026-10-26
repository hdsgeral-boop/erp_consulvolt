<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\FolhaHorasProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Projetos\ServicoExecucaoProjetos;
use App\Services\Projetos\ServicoMetasProjetos;

/**
 * Projectos no Portal do Colaborador (separador «Projectos», 2026-10-24): as minhas tarefas, registar horas e folha de
 * medição. Não reimplementa regras — chama ServicoExecucaoProjetos (horas) e ServicoMetasProjetos (medições), os
 * mesmos motores da gestão de Projectos; só decide o âmbito («é mesmo minha?») e deixa a validação de negócio a eles.
 *
 * Âmbito: só entram tarefas atribuídas directamente ao colaborador (`TarefaProjeto::atribuido_a_id`, que guarda o
 * `membro_equipa_projeto_id`) ou pertencentes a uma posição do organigrama do projecto de que o colaborador é
 * responsável (`NoOrganigramaProjeto::membro_responsavel_id` + `NoOrganigramaProjeto::tarefas`). Ser membro da equipa
 * não basta. O âmbito é verificado outra vez no momento de gravar (`exigirMinhaTarefa`), não só na listagem.
 */
final class ServicoPortalProjetos
{
    public function __construct(
        private readonly ServicoPortalColaborador $portal,
        private readonly ServicoExecucaoProjetos $execucao,
        private readonly ServicoMetasProjetos $metas,
    ) {}

    /** @return list<int> ids de MembroEquipaProjeto do colaborador (um por projecto em que participa) */
    private function meusMembros(int $colaboradorId): array
    {
        return MembroEquipaProjeto::query()->where('colaborador_id', $colaboradorId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return array<int, true> ids de tarefa cobertas por uma posição do organigrama de que o colaborador é responsável */
    private function tarefasPorPosicao(array $membros): array
    {
        $saida = [];
        foreach (NoOrganigramaProjeto::query()->whereIn('membro_responsavel_id', $membros)->get(['tarefas']) as $no) {
            foreach (array_map('intval', (array) ($no->tarefas ?? [])) as $tid) {
                $saida[$tid] = true;
            }
        }

        return $saida;
    }

    /**
     * As minhas tarefas: atribuídas a mim ou à posição do organigrama de que respondo. Só projectos abertos; sem
     * tarefas concluídas.
     *
     * @return list<array<string, mixed>>
     */
    public function minhasTarefas(): array
    {
        $colaborador = $this->portal->exigirColaborador();
        $membros = $this->meusMembros($colaborador->id);
        if (! $membros) {
            return [];
        }
        $porPosicao = $this->tarefasPorPosicao($membros);
        $tarefas = TarefaProjeto::query()->with('projeto:id,codigo,nome,estado')
            ->where(fn ($q) => $q->whereIn('atribuido_a_id', $membros)->when($porPosicao !== [], fn ($q2) => $q2->orWhereIn('id', array_keys($porPosicao))))
            ->where('estado', '<>', 'CONCLUIDA')->orderBy('data_fim')->orderBy('id')->get();

        return $tarefas->filter(fn ($t) => $t->projeto && ! in_array($t->projeto->estado, Projeto::FECHADOS, true))
            ->map(fn ($t) => ['id' => $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome, 'projeto_id' => $t->projeto_id,
                'projeto_codigo' => $t->projeto->codigo, 'projeto_nome' => $t->projeto->nome, 'estado' => $t->estadoEfetivo(),
                'data_inicio' => $t->data_inicio?->toDateString(), 'data_fim' => $t->data_fim?->toDateString(),
                'tem_meta' => $t->temMeta(), 'meta_unidade' => $t->unidadeMeta(), 'meta_quantidade' => (float) $t->meta_quantidade,
                'meta_realizado' => (float) $t->meta_realizado, 'pct_meta' => $t->pctDaMeta(),
                'por_posicao' => ! in_array((int) $t->atribuido_a_id, $membros, true)])->values()->all();
    }

    /** Confirma, no momento de gravar, que a tarefa é mesmo minha (atribuída a mim ou à minha posição) — não só na lista. */
    private function exigirMinhaTarefa(TarefaProjeto $t, int $colaboradorId): void
    {
        $membros = $this->meusMembros($colaboradorId);
        if (in_array((int) $t->atribuido_a_id, $membros, true)) {
            return;
        }
        $naMinhaPosicao = NoOrganigramaProjeto::query()->whereIn('membro_responsavel_id', $membros)->where('projeto_id', $t->projeto_id)
            ->get(['tarefas'])->contains(fn ($no) => in_array($t->id, array_map('intval', (array) ($no->tarefas ?? [])), true));
        if (! $naMinhaPosicao) {
            throw new ErroNegocio('Esta tarefa não lhe está atribuída.', 'TAREFA_FORA_DO_AMBITO', 403);
        }
    }

    /** @param  array{tarefa_projeto_id: int, data: string, horas: mixed}  $d */
    public function registarHoras(array $d): FolhaHorasProjeto
    {
        $colaborador = $this->portal->exigirColaborador();
        $t = TarefaProjeto::query()->findOrFail((int) $d['tarefa_projeto_id']);
        $this->exigirMinhaTarefa($t, $colaborador->id);
        if (substr((string) $d['data'], 0, 10) > now()->toDateString()) {
            throw new ErroNegocio('A data não pode ser futura.', 'DATA_FUTURA', 422);
        }

        return $this->execucao->registarHoras($t->projeto, ['tarefa_projeto_id' => $t->id, 'colaborador_id' => $colaborador->id, 'data' => $d['data'], 'horas' => $d['horas']]);
    }

    /** As minhas horas lançadas no mês (AAAA-MM; omisso = mês actual). */
    public function meusTimesheets(?string $mes): array
    {
        $colaborador = $this->portal->exigirColaborador();
        $mes = $mes && preg_match('/^\d{4}-\d{2}$/', $mes) ? $mes : now()->format('Y-m');
        $de = "{$mes}-01";
        $ate = date('Y-m-t', strtotime($de));
        $folhas = FolhaHorasProjeto::query()->where('colaborador_id', $colaborador->id)->whereBetween('data', [$de, $ate])->orderByDesc('data')->orderByDesc('id')->get();
        $tarefas = TarefaProjeto::query()->whereIn('id', $folhas->pluck('tarefa_projeto_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');

        return $folhas->map(fn ($f) => ['id' => $f->id, 'data' => $f->data?->toDateString(), 'horas' => (float) $f->horas, 'estado' => $f->estado,
            'tarefa_projeto_id' => $f->tarefa_projeto_id, 'tarefa_codigo' => $tarefas[$f->tarefa_projeto_id]->codigo ?? null,
            'tarefa_nome' => $tarefas[$f->tarefa_projeto_id]->nome ?? null])->values()->all();
    }

    /**
     * Regista uma medição numa das minhas tarefas com meta por quantidade (ServicoMetasProjetos::registar).
     *
     * @param  array{quantidade: mixed, data?: ?string, nota?: ?string}  $d
     * @return array{tarefa: TarefaProjeto, realizado: float, pct: int}
     */
    public function registarMedicao(int $tarefaId, array $d): array
    {
        $colaborador = $this->portal->exigirColaborador();
        $t = TarefaProjeto::query()->findOrFail($tarefaId);
        $this->exigirMinhaTarefa($t, $colaborador->id);

        return $this->metas->registar($t->projeto, $t->id, $d['quantidade'], $d['data'] ?? null, $d['nota'] ?? null);
    }
}
