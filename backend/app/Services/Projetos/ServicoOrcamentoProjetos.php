<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\AditamentoAlteracaoProjeto;
use App\Models\LinhaOrcamentoProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use App\Services\Contabilidade\ServicoPlanoContas;

/**
 * Orçamento base do projecto e aditamentos / trabalhos a mais
 * (showAddBudgetModal / saveBudgetLine / removeBudgetLine, js/ui_projects.js:2056-2158;
 * renderProjectAditamentos / saveChangeOrder / deleteChangeOrder, js/ui_projects.js:3663-3802).
 * Mantém do legado:
 *   - linha = tarefa (ou projecto global) + rubrica + conta opcional + valor previsto > 0; a mão de obra não leva conta;
 *   - rubricas MATERIAIS, MAO_DE_OBRA, SUBCONTRATOS, EQUIPAMENTOS e DIVERSOS (as migradas com outra rubrica mantêm-se);
 *   - editar mantém a posição e o membro responsáveis (mapeados no organigrama);
 *   - aditamento: descrição obrigatória, valor (pode ser negativo), estado PENDENTE / APROVADO / REJEITADO; só os
 *     aprovados somam à venda global.
 * Correcções:
 *   - a conta tem de existir e ser de movimento (o legado só filtrava a lista do ecrã);
 *   - a tarefa tem de ser do mesmo projecto.
 */
final class ServicoOrcamentoProjetos
{
    public const ESTADOS_ADITAMENTO = ['PENDENTE', 'APROVADO', 'REJEITADO'];

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** Linhas com a tarefa e o total (loadProjectTab 'orcamento'). */
    public function linhas(Projeto $p): array
    {
        $linhas = LinhaOrcamentoProjeto::query()->where('projeto_id', $p->id)->orderBy('id')->get();

        return ['total' => ServicoAnaliticoProjetos::dinheiro($linhas->reduce(fn ($s, $l) => bcadd($s, (string) $l->montante, 2), '0')), 'linhas' => $linhas->all()];
    }

    /** @param  array<string, mixed>  $d */
    public function guardarLinha(Projeto $p, array $d, ?LinhaOrcamentoProjeto $l = null): LinhaOrcamentoProjeto
    {
        $rubrica = $d['rubrica'] ?? $l?->rubrica ?? 'MATERIAIS';
        if (! in_array($rubrica, ServicoAnaliticoProjetos::RUBRICAS, true) && $rubrica !== $l?->rubrica) {
            throw new ErroNegocio('Rubrica inválida.', 'RUBRICA_INVALIDA', 422);
        }
        $montante = $d['montante'] ?? $l?->montante;
        if (! is_numeric($montante) || (float) $montante <= 0) {
            throw new ErroNegocio('Insira um valor válido.', 'VALOR_INVALIDO', 422);
        }
        $tarefa = array_key_exists('tarefa_projeto_id', $d) ? ($d['tarefa_projeto_id'] ?: null) : $l?->tarefa_projeto_id;
        if ($tarefa && ! TarefaProjeto::query()->where('projeto_id', $p->id)->whereKey($tarefa)->exists()) {
            throw new ErroNegocio('A tarefa não pertence ao projecto.', 'TAREFA_INVALIDA', 422);
        }
        $conta = $rubrica === 'MAO_DE_OBRA' ? null : (array_key_exists('numero_conta', $d) ? (trim((string) $d['numero_conta']) ?: null) : $l?->numero_conta);
        if ($conta && $conta !== $l?->numero_conta) {
            $this->plano->contaDeMovimento($conta);
        }
        $dados = ['projeto_id' => $p->id, 'tarefa_projeto_id' => $tarefa, 'rubrica' => $rubrica, 'numero_conta' => $conta,
            'montante' => ServicoAnaliticoProjetos::dinheiro($montante)];
        if ($l) {
            $l->update($dados);

            return $l->refresh();
        }

        return LinhaOrcamentoProjeto::create($dados);
    }

    public function eliminarLinha(LinhaOrcamentoProjeto $l): void
    {
        $l->delete();
    }

    // ───────────── Aditamentos ─────────────

    public function aditamentos(Projeto $p): array
    {
        $lista = AditamentoAlteracaoProjeto::query()->where('projeto_id', $p->id)->orderByDesc('id')->get();

        return ['total_aprovado' => ServicoAnaliticoProjetos::dinheiro($lista->where('estado', 'APROVADO')->reduce(fn ($s, $a) => bcadd($s, (string) $a->montante, 2), '0')),
            'aditamentos' => $lista->all()];
    }

    /** @param  array<string, mixed>  $d */
    public function guardarAditamento(Projeto $p, array $d, ?AditamentoAlteracaoProjeto $a = null): AditamentoAlteracaoProjeto
    {
        $descricao = trim((string) ($d['descricao'] ?? $a?->descricao ?? ''));
        if ($descricao === '') {
            throw new ErroNegocio('Preencha a descrição do trabalho a mais.', 'DESCRICAO_OBRIGATORIA', 422);
        }
        $estado = $d['estado'] ?? $a?->estado ?? 'PENDENTE';
        if (! in_array($estado, self::ESTADOS_ADITAMENTO, true)) {
            throw new ErroNegocio('Estado inválido (PENDENTE, APROVADO ou REJEITADO).', 'ESTADO_INVALIDO', 422);
        }
        $montante = $d['montante'] ?? $a?->montante ?? 0;
        if (! is_numeric($montante)) {
            throw new ErroNegocio('Valor inválido.', 'VALOR_INVALIDO', 422);
        }
        $dados = ['projeto_id' => $p->id, 'descricao' => $descricao, 'montante' => ServicoAnaliticoProjetos::dinheiro($montante), 'estado' => $estado];
        if ($a) {
            $antes = $a->estado;
            $a->update($dados);
            if ($antes !== $estado) {
                $this->projetos->registar($p->id, 'Aditamento', "ADIT-{$a->id}: {$antes} → {$estado}");
            }

            return $a->refresh();
        }

        return AditamentoAlteracaoProjeto::create($dados);
    }

    public function eliminarAditamento(AditamentoAlteracaoProjeto $a): void
    {
        $a->delete();
        $this->projetos->registar($a->projeto_id, 'Eliminar aditamento', "ADIT-{$a->id} {$a->descricao}");
    }
}
