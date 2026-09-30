<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\EquipaProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Models\Terceiro;
use Illuminate\Support\Facades\DB;

/**
 * Equipa do projecto (renderProjectEquipa, saveTeamMember, saveTeamMembersBulk, updateTeamMembersBulk,
 * removeTeamMembersBulk, js/ui_projects.js:1350-1778).
 * Mantém do legado:
 *   - uma equipa por projecto («Equipa Principal»), criada na primeira utilização;
 *   - membros internos (colaborador), entidades externas (terceiro) ou texto livre; o mesmo colaborador ou entidade só
 *     uma vez na equipa (o texto livre pode repetir-se);
 *   - papel por omissão: a função do colaborador (em massa) ou «Membro»; horas diárias dedicadas só nos internos, de 1 a 8;
 *   - em massa: acrescenta os seleccionados que ainda não estão na equipa; altera papel e/ou horas de vários;
 *   - remover mantém as horas e os custos já registados.
 * Correcções:
 *   - as horas 1–8 validam-se também na inserção individual (o legado só o fazia em massa);
 *   - remover um membro retira-o das tarefas, do responsável das posições e das linhas de orçamento que tinha a cargo
 *     (o legado deixava referências a um membro que já não existia);
 *   - colaborador e terceiro têm de existir na empresa.
 */
final class ServicoEquipasProjetos
{
    public function __construct(private readonly ServicoProjetos $projetos) {}

    public function equipa(Projeto $p): EquipaProjeto
    {
        return EquipaProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->first()
            ?? EquipaProjeto::create(['projeto_id' => $p->id, 'nome' => 'Equipa Principal']);
    }

    /** Membros com o nome e o tipo resolvidos. */
    public function membros(Projeto $p): array
    {
        $eq = EquipaProjeto::query()->where('projeto_id', $p->id)->pluck('id');
        $membros = MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', $eq)->orderBy('id')->get();
        $colab = Colaborador::query()->withTrashed()->whereIn('id', $membros->pluck('colaborador_id')->filter())->get(['id', 'nome_completo', 'nif'])->keyBy('id');
        $terc = Terceiro::query()->withTrashed()->whereIn('id', $membros->pluck('terceiro_id')->filter())->get(['id', 'nome', 'nif', 'tipo'])->keyBy('id');
        $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->pluck('titulo', 'id');

        return $membros->map(fn ($m) => $m->toArray() + [
            'tipo' => $m->colaborador_id ? 'INTERNO' : ($m->terceiro_id ? 'TERCEIRO' : 'LIVRE'),
            'nome' => self::nomeMembro($m, $colab, $terc),
            'nif' => $m->colaborador_id ? $colab[$m->colaborador_id]?->nif : ($m->terceiro_id ? $terc[$m->terceiro_id]?->nif : null),
            'posicao' => $m->no_organigrama_projeto_id ? ($nos[$m->no_organigrama_projeto_id] ?? null) : null,
        ])->all();
    }

    public static function nomeMembro(MembroEquipaProjeto $m, $colab, $terc): string
    {
        return ($m->colaborador_id ? ($colab[$m->colaborador_id]->nome_completo ?? null) : ($m->terceiro_id ? ($terc[$m->terceiro_id]->nome ?? null) : $m->nome_externo)) ?: 'Desconhecido';
    }

    /**
     * @param  array{tipo: string, colaborador_id?: ?int, terceiro_id?: ?int, nome_externo?: ?string, papel?: ?string, horas_alocadas?: mixed}  $d
     */
    public function guardarMembro(Projeto $p, array $d, ?MembroEquipaProjeto $m = null): MembroEquipaProjeto
    {
        $eq = $m ? EquipaProjeto::query()->findOrFail($m->equipa_projeto_id) : $this->equipa($p);
        $tipo = $d['tipo'] ?? ($m ? ($m->colaborador_id ? 'INTERNO' : ($m->terceiro_id ? 'TERCEIRO' : 'LIVRE')) : 'INTERNO');
        $dados = ['equipa_projeto_id' => $eq->id, 'colaborador_id' => null, 'terceiro_id' => null, 'nome_externo' => null,
            'papel' => trim((string) ($d['papel'] ?? $m?->papel ?? '')) ?: 'Membro', 'horas_alocadas' => null];

        return DB::transaction(function () use ($d, $m, $eq, $tipo, $dados) {
            EquipaProjeto::query()->lockForUpdate()->find($eq->id);
            if ($tipo === 'INTERNO') {
                $dados['colaborador_id'] = (int) ($d['colaborador_id'] ?? $m?->colaborador_id ?? 0) ?: throw new ErroNegocio('Seleccione um colaborador.', 'COLABORADOR_OBRIGATORIO', 422);
                Colaborador::query()->findOr($dados['colaborador_id'], fn () => throw new ErroNegocio('Colaborador inexistente.', 'COLABORADOR_INEXISTENTE', 422));
                $this->exigirUnico($eq, 'colaborador_id', $dados['colaborador_id'], $m, 'Este colaborador já faz parte da equipa.');
                $dados['horas_alocadas'] = self::horas($d['horas_alocadas'] ?? $m?->horas_alocadas ?? 8);
            } elseif ($tipo === 'TERCEIRO') {
                $dados['terceiro_id'] = (int) ($d['terceiro_id'] ?? $m?->terceiro_id ?? 0) ?: throw new ErroNegocio('Seleccione uma entidade.', 'TERCEIRO_OBRIGATORIO', 422);
                Terceiro::query()->findOr($dados['terceiro_id'], fn () => throw new ErroNegocio('Entidade inexistente.', 'TERCEIRO_INEXISTENTE', 422));
                $this->exigirUnico($eq, 'terceiro_id', $dados['terceiro_id'], $m, 'Esta entidade já faz parte da equipa.');
            } elseif ($tipo === 'LIVRE') {
                $dados['nome_externo'] = trim((string) ($d['nome_externo'] ?? $m?->nome_externo ?? '')) ?: throw new ErroNegocio('Escreva o nome do membro externo.', 'NOME_OBRIGATORIO', 422);
            } else {
                throw new ErroNegocio('Tipo de membro inválido (INTERNO, TERCEIRO ou LIVRE).', 'TIPO_INVALIDO', 422);
            }
            if ($m) {
                $m->update($dados);

                return $m->refresh();
            }

            return MembroEquipaProjeto::create($dados);
        });
    }

    /**
     * Acrescenta vários (saveTeamMembersBulk): nunca duplica — relê a equipa antes de gravar.
     *
     * @param  list<int>  $ids
     * @return array{criados: int, ignorados: int}
     */
    public function acrescentarEmMassa(Projeto $p, string $tipo, array $ids, ?string $papel, mixed $horas): array
    {
        if (! in_array($tipo, ['INTERNO', 'TERCEIRO'], true)) {
            throw new ErroNegocio('Tipo inválido (INTERNO ou TERCEIRO).', 'TIPO_INVALIDO', 422);
        }
        $horas = $tipo === 'INTERNO' ? self::horas($horas ?? 8) : null;
        $eq = $this->equipa($p);

        return DB::transaction(function () use ($p, $tipo, $ids, $papel, $horas, $eq) {
            EquipaProjeto::query()->lockForUpdate()->find($eq->id);
            $coluna = $tipo === 'INTERNO' ? 'colaborador_id' : 'terceiro_id';
            $ja = MembroEquipaProjeto::query()->where('equipa_projeto_id', $eq->id)->whereNotNull($coluna)->pluck($coluna)->map(fn ($x) => (int) $x)->all();
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $novos = array_values(array_diff($ids, $ja));
            if (! $novos) {
                throw new ErroNegocio('Os seleccionados já fazem parte da equipa.', 'JA_NA_EQUIPA', 422);
            }
            $entidades = $tipo === 'INTERNO' ? Colaborador::query()->whereIn('id', $novos)->get()->keyBy('id') : Terceiro::query()->whereIn('id', $novos)->get()->keyBy('id');
            if ($falta = array_diff($novos, $entidades->keys()->all())) {
                throw new ErroNegocio('Registos inexistentes: '.implode(', ', $falta).'.', $tipo === 'INTERNO' ? 'COLABORADOR_INEXISTENTE' : 'TERCEIRO_INEXISTENTE', 422);
            }
            $funcoes = $tipo === 'INTERNO' ? CargoFuncao::query()->whereIn('id', $entidades->pluck('cargo_funcao_id')->filter())->pluck('nome', 'id') : collect();
            foreach ($novos as $id) {
                $e = $entidades[$id];
                MembroEquipaProjeto::create(['equipa_projeto_id' => $eq->id, $coluna => $id, 'horas_alocadas' => $horas,
                    'papel' => trim((string) $papel) ?: ($tipo === 'INTERNO' ? ($funcoes[$e->cargo_funcao_id] ?? null) : null) ?: 'Membro']);
            }
            $this->projetos->registar($p->id, 'Adicionar membros em massa', count($novos).' '.($tipo === 'INTERNO' ? 'colaborador(es)' : 'entidade(s)'));

            return ['criados' => count($novos), 'ignorados' => count($ids) - count($novos)];
        });
    }

    /** Altera papel e/ou horas de vários (updateTeamMembersBulk): as horas só nos internos. */
    public function alterarEmMassa(Projeto $p, array $ids, ?string $papel, mixed $horas): int
    {
        $papel = trim((string) $papel);
        $horas = $horas === null || $horas === '' ? null : self::horas($horas);
        if ($papel === '' && $horas === null) {
            throw new ErroNegocio('Indique o papel e/ou as horas a alterar.', 'NADA_A_ALTERAR', 422);
        }

        return DB::transaction(function () use ($p, $ids, $papel, $horas) {
            $n = 0;
            foreach ($this->membrosPorIds($p, $ids) as $m) {
                $mods = array_filter(['papel' => $papel ?: null, 'horas_alocadas' => $m->colaborador_id ? $horas : null], fn ($v) => $v !== null);
                if ($mods) {
                    $m->update($mods);
                    $n++;
                }
            }

            return $n;
        });
    }

    /** Remove da equipa (removeTeamMembersBulk); as horas e custos já registados mantêm-se. */
    public function remover(Projeto $p, array $ids): int
    {
        return DB::transaction(function () use ($p, $ids) {
            $membros = $this->membrosPorIds($p, $ids);
            $idsM = $membros->pluck('id')->all();
            if (! $idsM) {
                throw new ErroNegocio('Nenhum dos membros indicados pertence à equipa do projecto.', 'MEMBRO_INEXISTENTE', 422);
            }
            TarefaProjeto::query()->where('projeto_id', $p->id)->whereIn('atribuido_a_id', $idsM)->update(['atribuido_a_id' => null]);
            NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->whereIn('membro_responsavel_id', $idsM)->update(['membro_responsavel_id' => null]);
            LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->whereIn('membro_equipa_projeto_id', $idsM)->update(['membro_equipa_projeto_id' => null]);
            $membros->each(fn ($m) => $m->delete());
            $this->projetos->registar($p->id, 'Remover membros', count($idsM).' membro(s)');

            return count($idsM);
        });
    }

    private function membrosPorIds(Projeto $p, array $ids)
    {
        return MembroEquipaProjeto::query()->whereIn('id', array_map('intval', $ids))
            ->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->lockForUpdate()->get();
    }

    private function exigirUnico(EquipaProjeto $eq, string $coluna, int $valor, ?MembroEquipaProjeto $m, string $mensagem): void
    {
        if (MembroEquipaProjeto::query()->where('equipa_projeto_id', $eq->id)->where($coluna, $valor)->when($m, fn ($q) => $q->whereKeyNot($m->id))->exists()) {
            throw new ErroNegocio($mensagem, 'JA_NA_EQUIPA', 422);
        }
    }

    private static function horas(mixed $h): string
    {
        $v = (float) str_replace(',', '.', (string) $h);
        if ($v < 1 || $v > 8) {
            throw new ErroNegocio('As horas diárias dedicadas têm de estar entre 1 e 8.', 'HORAS_INVALIDAS', 422);
        }

        return number_format($v, 3, '.', '');
    }
}
