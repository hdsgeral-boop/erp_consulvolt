<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\EquipaProjeto;
use App\Models\MarcoProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\NoOrganigramaProjeto;
use App\Models\Projeto;
use App\Models\TarefaProjeto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Execução medida das tarefas com meta por quantidade (Versão Actual, 2026-10-11): js/projectos_metas.js.
 * Mantém do legado:
 *   - registar a quantidade executada numa data, com nota; aceita negativos para corrigir, sem deixar o realizado
 *     negativo; o histórico fica em meta_execucoes = [{q, data, nota, por, em}] (registar, :372-393);
 *   - ao chegar a 100 % a tarefa fica CONCLUÍDA com a data da medição em concluida_em; acima de 0 % passa de PENDENTE a
 *     EM_CURSO; fica no registo de actividade do projecto;
 *   - não se regista numa tarefa cujas subtarefas têm meta (a quantidade sobe delas) nem numa tarefa sem meta (:405-411);
 *   - folha de medição: várias tarefas de uma vez, cada linha pela mesma regra, as que falham ficam assinaladas e as
 *     outras gravam (projMetasRegistarEmMassa, :468-628);
 *   - assistente «Tarefas por quantidade»: N partes de uma meta total, cada uma com a sua quantidade e responsável
 *     (membro da equipa ou posição do organigrama), com o prazo repartido em sequência (projMetasEmMassa, :683-838).
 * Correcções:
 *   - permissão e isolamento verificados no servidor; cada medição grava com a tarefa bloqueada (lockForUpdate), para
 *     duas medições simultâneas não se perderem;
 *   - a data da medição não pode ser futura nem a quantidade exceder 1 000 000 000 por registo;
 *   - projecto encerrado ou cancelado não aceita medições;
 *   - as partes do assistente têm de caber nas datas do nível acima (o legado não verificava).
 */
final class ServicoMetasProjetos
{
    public const LIMITE_QTD = 1_000_000_000;

    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoPlaneamentoProjetos $planeamento,
    ) {}

    /**
     * Regista uma medição (registar, projectos_metas.js:372-393).
     *
     * @return array{tarefa: TarefaProjeto, realizado: float, pct: int}
     */
    public function registar(Projeto $p, int $tarefaId, mixed $quantidade, ?string $data = null, ?string $nota = null, ?int $membroId = null): array
    {
        $this->projetos->exigirAberto($p);
        // Folha de medição por pessoa (2026-10-16): a medição pode indicar quem a executou (membro da equipa do projecto)
        if ($membroId !== null && ! in_array($membroId, self::idsMembros($p), true)) {
            throw new ErroNegocio('O executante indicado não pertence à equipa do projecto.', 'EXECUTANTE_INVALIDO', 422);
        }

        return DB::transaction(function () use ($p, $tarefaId, $quantidade, $data, $nota, $membroId) {
            $t = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->find($tarefaId)
                ?? throw new ErroNegocio('Tarefa não encontrada no projecto.', 'TAREFA_INEXISTENTE', 422);
            if (TarefaProjeto::query()->where('tarefa_pai_id', $t->id)->get()->contains(fn ($x) => $x->temMeta())) {
                throw new ErroNegocio('Esta tarefa tem subtarefas com meta própria: a quantidade executada regista-se nas subtarefas e sobe daí.', 'META_NAS_SUBTAREFAS', 422);
            }
            if (! $t->temMeta()) {
                throw new ErroNegocio('Esta tarefa não tem meta por quantidade. Defina a meta primeiro.', 'TAREFA_SEM_META', 422);
            }
            $q = round((float) $quantidade, 3);
            if (! is_numeric($quantidade) || $q == 0.0) {
                throw new ErroNegocio('Indique a quantidade executada (pode ser negativa para corrigir).', 'QUANTIDADE_INVALIDA', 422);
            }
            if (abs($q) > self::LIMITE_QTD) {
                throw new ErroNegocio('Quantidade fora do limite.', 'QUANTIDADE_INVALIDA', 422);
            }
            $hoje = now()->toDateString();
            $dia = substr(trim((string) $data), 0, 10) ?: $hoje;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || ! checkdate((int) substr($dia, 5, 2), (int) substr($dia, 8, 2), (int) substr($dia, 0, 4))) {
                throw new ErroNegocio('Data da medição inválida.', 'DATA_INVALIDA', 422);
            }
            if ($dia > $hoje) {
                throw new ErroNegocio('A data da medição não pode ser futura.', 'DATA_FUTURA', 422);
            }
            $novo = round((float) $t->meta_realizado + $q, 3);
            if ($novo < 0) {
                throw new ErroNegocio('A correcção deixaria o realizado negativo.', 'REALIZADO_NEGATIVO', 422);
            }
            $execucoes = [...$t->medicoes(), ['q' => $q, 'data' => $dia, 'nota' => mb_substr(trim((string) $nota), 0, 500), 'por' => (string) Auth::user()?->nome_utilizador,
                'em' => now()->toIso8601String()] + ($membroId !== null ? ['membro_id' => $membroId] : [])];
            $pct = max(0, min(100, (int) round($novo / (float) $t->meta_quantidade * 100)));
            $estado = $t->estadoEfetivo();
            $dados = ['meta_realizado' => $novo, 'meta_execucoes' => $execucoes, 'percentagem_execucao' => $pct];
            $novoEstado = $pct >= 100 && $estado !== 'CONCLUIDA' ? 'CONCLUIDA' : ($pct > 0 && $pct < 100 && $estado === 'PENDENTE' ? 'EM_CURSO' : null);
            if ($novoEstado) {
                $dados += ['estado' => $novoEstado, 'estado_original' => $novoEstado];
                if (! ServicoProjetos::codigoAceite('tarefas_projeto', 'estado', $novoEstado)) {
                    $dados['estado'] = null;
                }
                if ($novoEstado === 'CONCLUIDA') {
                    $dados['concluida_em'] = ServicoPlaneamentoProjetos::concluidaEm('CONCLUIDA', null, $dia);
                }
            }
            $t->update($dados);
            $fq = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', ' '), '0'), ',');
            $this->projetos->registar($p->id, 'Execução por quantidade', trim("{$t->codigo} {$t->nome}").": {$fq($q)} {$t->unidadeMeta()} em {$dia} (total {$fq($novo)} de {$fq($t->meta_quantidade)})");

            return ['tarefa' => $t->refresh(), 'realizado' => $novo, 'pct' => $pct];
        });
    }

    /**
     * Folha de medição (projMetasRegistarEmMassa, :468-628): uma linha por tarefa; cada linha grava (ou falha) sozinha.
     *
     * @param  list<array{tarefa_id: int, quantidade: mixed, nota?: ?string, membro_id?: ?int}>  $linhas
     * @return array{gravadas: int, falhadas: list<array{tarefa_id: int, nome: ?string, erro: string, codigo: string}>, resultados: list<array{tarefa_id: int, realizado: float, pct: int}>}
     */
    public function registarEmLote(Projeto $p, array $linhas, ?string $data, ?string $notaComum): array
    {
        $this->projetos->exigirAberto($p);
        if (count($linhas) > 500) {
            throw new ErroNegocio('No máximo 500 linhas por folha de medição.', 'LOTE_EXCESSIVO', 422);
        }
        $nomes = TarefaProjeto::query()->where('projeto_id', $p->id)->pluck('nome', 'id');
        $saida = ['gravadas' => 0, 'falhadas' => [], 'resultados' => []];
        foreach ($linhas as $l) {
            $id = (int) ($l['tarefa_id'] ?? 0);
            try {
                $r = $this->registar($p, $id, $l['quantidade'] ?? null, $data, trim((string) ($l['nota'] ?? '')) ?: $notaComum,
                    isset($l['membro_id']) && $l['membro_id'] !== '' ? (int) $l['membro_id'] : null);
                $saida['gravadas']++;
                $saida['resultados'][] = ['tarefa_id' => $id, 'realizado' => $r['realizado'], 'pct' => $r['pct']];
            } catch (ErroNegocio $e) {
                $saida['falhadas'][] = ['tarefa_id' => $id, 'nome' => $nomes[$id] ?? null, 'erro' => $e->getMessage(), 'codigo' => $e->codigo ?? 'ERRO'];
            }
        }

        return $saida;
    }

    /**
     * Linhas da folha de medição: tarefas com meta própria e sem subtarefas com meta, com o que falta e o responsável.
     *
     * @return list<array<string, mixed>>
     */
    public function folhaMedicao(Projeto $p): array
    {
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->get();
        $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->pluck('nome', 'id');
        $lista = ServicoHierarquiaProjetos::ordenarIrmaos(RegrasMetasProjetos::medidas($tarefas));
        $pessoas = self::pessoasDasTarefas($p);

        return $lista->map(fn (TarefaProjeto $t) => ['id' => $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome, 'fase' => $marcos[$t->marco_projeto_id] ?? null,
            'atribuido_a_id' => $t->atribuido_a_id,
            // por pessoa: o responsável directo ou, sem ele, os membros da(s) área(s) do organigrama da tarefa (carga de trabalho)
            'pessoas' => $t->atribuido_a_id ? [(int) $t->atribuido_a_id] : ($pessoas[$t->id] ?? []),
            'unidade' => $t->unidadeMeta(), 'meta' => (float) $t->meta_quantidade, 'realizado' => (float) $t->meta_realizado,
            'falta' => RegrasMetasProjetos::r2((float) $t->meta_quantidade - (float) $t->meta_realizado),
            'medicoes' => array_reverse($t->medicoes())])->values()->all();
    }

    /** @return list<int> ids dos membros da equipa do projecto */
    public static function idsMembros(Projeto $p): array
    {
        return MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Membros de cada tarefa atribuída a uma área do organigrama (sem responsável directo): os que estão nas posições que
     * têm a tarefa — a mesma regra da carga de trabalho (renderCargaTrabalho, ui_projects.js:2683-2934).
     *
     * @return array<int, list<int>> tarefa → membros
     */
    public static function pessoasDasTarefas(Projeto $p): array
    {
        $membros = MembroEquipaProjeto::query()->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))
            ->whereNotNull('no_organigrama_projeto_id')->get(['id', 'no_organigrama_projeto_id'])->groupBy('no_organigrama_projeto_id');
        $saida = [];
        foreach (NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->get(['id', 'tarefas']) as $n) {
            foreach (array_map('intval', (array) ($n->tarefas ?? [])) as $tid) {
                foreach ($membros[$n->id] ?? [] as $m) {
                    $saida[$tid][] = (int) $m->id;
                }
            }
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $saida);
    }

    /**
     * Assistente «Tarefas por quantidade» (projMetasEmMassa, :683-838).
     *
     * @param  array{nome: string, unidade: string, total: mixed, marco_projeto_id?: ?int, tarefa_pai_id?: ?int, data_inicio?: ?string,
     *     data_fim?: ?string, repartir_prazo?: bool, partes: list<array{nome?: ?string, quantidade: mixed, responsavel?: ?string}>}  $d
     * @return list<int> ids criados
     */
    public function criarPorQuantidade(Projeto $p, array $d): array
    {
        $this->projetos->exigirAberto($p);
        $nome = trim((string) ($d['nome'] ?? ''));
        $un = trim((string) ($d['unidade'] ?? ''));
        $total = (float) ($d['total'] ?? 0);
        $partes = array_values($d['partes'] ?? []);
        if ($nome === '') {
            throw new ErroNegocio('Indique a designação base.', 'NOME_OBRIGATORIO', 422);
        }
        if ($un === '') {
            throw new ErroNegocio('Indique a unidade de medida.', 'META_SEM_UNIDADE', 422);
        }
        if (! ($total > 0) || $total > self::LIMITE_QTD) {
            throw new ErroNegocio('Indique a meta total.', 'META_INVALIDA', 422);
        }
        if (count($partes) < 1 || count($partes) > 60) {
            throw new ErroNegocio('Divida a meta entre 1 e 60 partes.', 'PARTES_INVALIDAS', 422);
        }
        $ini = substr((string) ($d['data_inicio'] ?? ''), 0, 10);
        $fim = substr((string) ($d['data_fim'] ?? ''), 0, 10);
        if ($ini !== '' && $fim !== '' && $fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        $repartir = ! empty($d['repartir_prazo']) && $ini !== '' && $fim !== '';
        $dias = $repartir ? max(1, RegrasMetasProjetos::diasEntre($ini, $fim)) : 0;

        return DB::transaction(function () use ($p, $d, $nome, $un, $total, $partes, $ini, $fim, $repartir, $dias) {
            $todas = TarefaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $marcos = MarcoProjeto::query()->where('projeto_id', $p->id)->get()->keyBy('id');
            $nos = NoOrganigramaProjeto::query()->where('projeto_id', $p->id)->lockForUpdate()->get()->keyBy('id');
            $pai = ! empty($d['tarefa_pai_id']) ? ($todas[(int) $d['tarefa_pai_id']] ?? throw new ErroNegocio('A tarefa-mãe não pertence ao projecto.', 'TAREFA_PAI_INVALIDA', 422)) : null;
            $marco = $pai ? $pai->marco_projeto_id : (! empty($d['marco_projeto_id']) ? (int) $d['marco_projeto_id'] : null);
            if (! $pai && $marco && ! isset($marcos[$marco])) {
                throw new ErroNegocio('O milestone não pertence ao projecto.', 'MARCO_INVALIDO', 422);
            }
            $criadas = [];
            $n = count($partes);
            foreach ($partes as $i => $parte) {
                $q = round((float) ($parte['quantidade'] ?? 0), 3);
                if ($q < 0) {
                    throw new ErroNegocio('Parte '.($i + 1).': a quantidade não pode ser negativa.', 'META_INVALIDA', 422);
                }
                [$dIni, $dFim] = [$ini ?: null, $fim ?: null];
                if ($repartir) {
                    $de = intdiv($dias * $i, $n);
                    $ate = intdiv($dias * ($i + 1), $n) - 1;
                    $dIni = RegrasMetasProjetos::somarDias($ini, $de);
                    $dFim = RegrasMetasProjetos::somarDias($ini, max($de, $ate));
                }
                if ($erro = ServicoHierarquiaProjetos::validarDatas($dIni, $dFim, $pai?->id, $pai ? null : $marco, $todas, $marcos)) {
                    throw new ErroNegocio('Parte '.($i + 1).": {$erro}", 'DATAS_FORA_DO_NIVEL', 422);
                }
                $resp = (string) ($parte['responsavel'] ?? '');
                $membro = str_starts_with($resp, 'm:') ? (int) substr($resp, 2) : null;
                $no = str_starts_with($resp, 'n:') ? (int) substr($resp, 2) : null;
                if ($membro && ! $this->planeamento->membroDoProjeto($p, $membro)) {
                    throw new ErroNegocio('Parte '.($i + 1).': o responsável tem de ser membro da equipa do projecto.', 'RESPONSAVEL_INVALIDO', 422);
                }
                if ($no && ! isset($nos[$no])) {
                    throw new ErroNegocio('Parte '.($i + 1).': a posição do organigrama não pertence ao projecto.', 'POSICAO_INVALIDA', 422);
                }
                $t = TarefaProjeto::create(['projeto_id' => $p->id, 'tarefa_pai_id' => $pai?->id, 'marco_projeto_id' => $marco,
                    'nome' => mb_substr(trim((string) ($parte['nome'] ?? '')) ?: "{$nome} ".($i + 1)."/{$n}", 0, 160),
                    'data_inicio' => $dIni, 'data_fim' => $dFim, 'estado' => 'PENDENTE', 'estado_original' => 'PENDENTE', 'percentagem_execucao' => 0,
                    'ordem' => ($i + 1) * 10, 'meta_unidade' => mb_substr($un, 0, 20), 'meta_quantidade' => $q > 0 ? $q : null, 'meta_realizado' => $q > 0 ? 0 : null,
                    'meta_execucoes' => [], 'atribuido_a_id' => $membro]);
                $criadas[] = $t->id;
                if ($no) {
                    $nos[$no]->update(['tarefas' => [...array_map('intval', $nos[$no]->tarefas ?? []), $t->id]]);
                    $nos[$no]->refresh();
                }
            }
            $this->projetos->registar($p->id, 'Criar tarefas por quantidade', "{$nome}: {$n} parte(s), meta {$total} {$un}");

            return $criadas;
        });
    }
}
