<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\Colaborador;
use App\Models\EquipaProjeto;
use App\Models\FolhaHorasProjeto;
use App\Models\MembroEquipaProjeto;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\Projeto;
use App\Models\RazaoAnaliticoProjeto;
use App\Models\ResultadoFolhaSalarial;
use App\Models\TarefaProjeto;
use Illuminate\Support\Facades\DB;

/**
 * Execução em obra: folhas de horas, uso de equipamentos e imputação do custo da mão de obra pelo processamento
 * salarial (showTimesheetModal / saveTimesheet, showEquipmentModal / saveEquipmentLog, js/ui_projects.js:3292-3473;
 * «Integração Analítica: Custo de Mão de Obra em Projetos», js/app_v2.js:6343-6391).
 * Mantém do legado:
 *   - horas por tarefa, colaborador e data, estado REGISTADO;
 *   - equipamento: activo, data, horas × custo/hora → movimento do razão (CUSTOS_EQUIPAMENTO, origem ATIVOS,
 *     «Registo de Obra», documento MAQ-<código>, natureza CUSTO);
 *   - ao contabilizar o processamento salarial, as horas REGISTADAS do mês passam ao razão ao custo/hora do
 *     colaborador = (bruto + INSS patronal) / (dias do contrato × 8) e ficam PROCESSADAS.
 * Correcções:
 *   - só colaboradores internos da equipa registam horas (o legado gravava o id do MEMBRO como colaborador quando o
 *     membro era externo, ui_projects.js:3312 — há 1 caso nos dados);
 *   - horas > 0 e até 24 num registo; a tarefa tem de ser do projecto; projecto encerrado não aceita imputações;
 *   - a imputação salarial pode ser revertida (descontabilizar o processamento deixava o custo no projecto) e não
 *     imputa duas vezes as mesmas horas;
 *   - registos de horas ainda não processados e usos de equipamento podem ser eliminados (a mensagem da eliminação de
 *     tarefas mandava «eliminá-los primeiro», mas o legado não tinha forma de o fazer).
 */
final class ServicoExecucaoProjetos
{
    public const ORIGEM_SALARIOS = 'Processamento Salarial';

    public function __construct(private readonly ServicoProjetos $projetos) {}

    // ───────────── Folhas de horas ─────────────

    public function folhasHoras(Projeto $p): array
    {
        return FolhaHorasProjeto::query()->where('projeto_id', $p->id)->orderByDesc('data')->orderByDesc('id')->get()->all();
    }

    /** @param  array{tarefa_projeto_id: int, colaborador_id: int, data: string, horas: mixed}  $d */
    public function registarHoras(Projeto $p, array $d): FolhaHorasProjeto
    {
        $this->projetos->exigirAberto($p);
        $t = $this->tarefa($p, (int) $d['tarefa_projeto_id']);
        $horas = (float) $d['horas'];
        if ($horas <= 0 || $horas > 24) {
            throw new ErroNegocio('As horas têm de ser maiores que 0 e no máximo 24.', 'HORAS_INVALIDAS', 422);
        }
        $interno = MembroEquipaProjeto::query()->where('colaborador_id', (int) $d['colaborador_id'])
            ->whereIn('equipa_projeto_id', EquipaProjeto::query()->where('projeto_id', $p->id)->select('id'))->exists();
        if (! $interno) {
            throw new ErroNegocio('Só os colaboradores internos da equipa do projecto registam horas.', 'COLABORADOR_FORA_DA_EQUIPA', 422);
        }

        return FolhaHorasProjeto::create(['projeto_id' => $p->id, 'tarefa_projeto_id' => $t->id, 'colaborador_id' => (int) $d['colaborador_id'],
            'data' => $d['data'], 'horas' => number_format($horas, 3, '.', ''), 'estado' => 'REGISTADO']);
    }

    public function eliminarHoras(FolhaHorasProjeto $f): void
    {
        if ($f->estado === 'PROCESSADO') {
            throw new ErroNegocio('As horas já foram imputadas pelo processamento salarial: descontabilize-o primeiro.', 'HORAS_PROCESSADAS', 422);
        }
        $f->delete();
    }

    // ───────────── Equipamentos ─────────────

    /** @param  array{tarefa_projeto_id: int, ativo_imobilizado_id: int, data: string, horas: mixed, custo_hora: mixed}  $d */
    public function registarEquipamento(Projeto $p, array $d): RazaoAnaliticoProjeto
    {
        $this->projetos->exigirAberto($p);
        $t = $this->tarefa($p, (int) $d['tarefa_projeto_id']);
        $ativo = AtivoImobilizado::query()->find($d['ativo_imobilizado_id']) ?? throw new ErroNegocio('Equipamento inexistente.', 'ATIVO_INEXISTENTE', 422);
        $horas = (string) $d['horas'];
        $taxa = (string) $d['custo_hora'];
        if ((float) $horas <= 0 || (float) $horas > 24 || (float) $taxa < 0) {
            throw new ErroNegocio('Horas (0 a 24) e custo/hora (≥ 0) inválidos.', 'VALORES_INVALIDOS', 422);
        }
        $valor = ServicoAnaliticoProjetos::dinheiro(bcmul($horas, $taxa, 4));

        return RazaoAnaliticoProjeto::create(['projeto_id' => $p->id, 'tarefa_projeto_id' => $t->id, 'rubrica' => 'CUSTOS_EQUIPAMENTO', 'modulo_origem' => 'ATIVOS',
            'tipo_documento_origem' => 'REGISTO_OBRA', 'tipo_documento_origem_original' => 'Registo de Obra', 'natureza' => 'CUSTO', 'natureza_original' => 'custo',
            'data' => $d['data'], 'montante' => $valor, 'documento_origem_id' => mb_substr("MAQ-{$ativo->codigo}", 0, 30),
            'descricao' => "Utilização de Equipamento: {$ativo->codigo} ({$horas}h a {$taxa}/h)"]);
    }

    public function eliminarEquipamento(RazaoAnaliticoProjeto $r): void
    {
        if ($r->rubrica !== 'CUSTOS_EQUIPAMENTO' || $r->modulo_origem !== 'ATIVOS') {
            throw new ErroNegocio('Só se eliminam aqui os registos de uso de equipamento.', 'MOVIMENTO_NAO_ELIMINAVEL', 422);
        }
        $r->delete();
    }

    // ───────────── Imputação do processamento salarial ─────────────

    /**
     * Imputa aos projectos as horas REGISTADAS do mês do processamento (app_v2.js:6346-6391). Gancho: chamar no fim de
     * ServicoFolhaSalarial::contabilizar, dentro da transacção.
     *
     * @return array{imputadas: int, valor: string, sem_resultado: int}
     */
    public function imputarPeriodo(PeriodoProcessamentoSalarial $per): array
    {
        [$mes, $ano] = array_map('intval', explode('/', (string) $per->mes_ano));
        $inicio = sprintf('%04d-%02d-01', $ano, $mes);
        $fim = ServicoAnaliticoProjetos::ultimoDia($ano, $mes);

        return DB::transaction(function () use ($per, $inicio, $fim) {
            $folhas = FolhaHorasProjeto::query()->whereBetween('data', [$inicio, $fim])->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'PROCESSADO'))
                ->lockForUpdate()->orderBy('id')->get();
            $resultados = ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $per->id)->whereIn('colaborador_id', $folhas->pluck('colaborador_id')->filter())->get()->keyBy('colaborador_id');
            $nomes = Colaborador::query()->withTrashed()->whereIn('id', $folhas->pluck('colaborador_id')->filter())->pluck('nome_completo', 'id');
            $codigo = ServicoProjetos::codigoAceite('razao_analitico_projetos', 'tipo_documento_origem', 'PROCESSAMENTO_SALARIAL') ? 'PROCESSAMENTO_SALARIAL' : null;
            $r = ['imputadas' => 0, 'valor' => '0.00', 'sem_resultado' => 0];
            foreach ($folhas as $f) {
                $res = $resultados[$f->colaborador_id] ?? null;
                if (! $res) {
                    $r['sem_resultado']++;

                    continue;
                }
                $horasMes = bcmul((string) ((int) $res->dias_contrato ?: 22), '8', 4);
                $custoHora = bcdiv(bcadd((string) ($res->bruto ?? 0), (string) ($res->inss_patronal ?? 0), 4), $horasMes, 8);
                $valor = ServicoAnaliticoProjetos::dinheiro(bcmul((string) $f->horas, $custoHora, 6));
                if (bccomp($valor, '0', 2) <= 0) {
                    continue;
                }
                RazaoAnaliticoProjeto::create(['projeto_id' => $f->projeto_id, 'tarefa_projeto_id' => $f->tarefa_projeto_id, 'rubrica' => 'MAO_DE_OBRA', 'modulo_origem' => 'RH',
                    'tipo_documento_origem' => $codigo, 'tipo_documento_origem_original' => self::ORIGEM_SALARIOS, 'natureza' => 'CUSTO_REAL', 'natureza_original' => 'custo_real',
                    'data' => $f->data, 'montante' => $valor, 'documento_origem_id' => $per->mes_ano,
                    'descricao' => 'Trabalho Interno ('.($nomes[$f->colaborador_id] ?? "#{$f->colaborador_id}").' - '.rtrim(rtrim((string) $f->horas, '0'), '.').'h)']);
                $f->update(['estado' => 'PROCESSADO']);
                $r['imputadas']++;
                $r['valor'] = bcadd($r['valor'], $valor, 2);
            }

            return $r;
        });
    }

    /** Reverte a imputação de um processamento (gancho em ServicoFolhaSalarial::descontabilizar). */
    public function reverterPeriodo(PeriodoProcessamentoSalarial $per): int
    {
        [$mes, $ano] = array_map('intval', explode('/', (string) $per->mes_ano));

        return DB::transaction(function () use ($per, $mes, $ano) {
            $n = RazaoAnaliticoProjeto::query()->where('modulo_origem', 'RH')->where('tipo_documento_origem_original', self::ORIGEM_SALARIOS)
                ->where('documento_origem_id', $per->mes_ano)->delete();
            FolhaHorasProjeto::query()->whereBetween('data', [sprintf('%04d-%02d-01', $ano, $mes), ServicoAnaliticoProjetos::ultimoDia($ano, $mes)])
                ->where('estado', 'PROCESSADO')->update(['estado' => 'REGISTADO']);

            return $n;
        });
    }

    private function tarefa(Projeto $p, int $id): TarefaProjeto
    {
        return TarefaProjeto::query()->where('projeto_id', $p->id)->find($id) ?? throw new ErroNegocio('A tarefa não pertence ao projecto.', 'TAREFA_INVALIDA', 422);
    }
}
