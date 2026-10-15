<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EscalaTrabalho;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Escala do mês (Versão Actual: js/modules/rh/escalas.js; separador «Escala do mês» de assiduidade_ui.js:82).
 * Um registo por colaborador e dia: turno previsto (entrada, saída, 1 ou 2 períodos, intervalo, tolerância) ou folga.
 * Paridade:
 *   - prevalência no dia: escala PRIORITÁRIA › escala normal › horário do contrato › configuração da empresa;
 *   - a prioritária é uma linha à parte: sobrepõe-se à prevista sem a apagar e, retirada, a prevista volta a valer;
 *   - as gerações em massa (contrato, cópia, Excel) nunca substituem uma prioritária sem confirmação explícita
 *     (`substituir_prioritarias`); o que fica protegido é contado;
 *   - aplicar um turno a um intervalo (opcionalmente só certos dias da semana) para vários colaboradores; migrar o
 *     horário do contrato (dias da semana do horário, ou os dias úteis da empresa; salta feriados; folgas opcionais);
 *     copiar um intervalo para outro, dia a dia pela ordem; importar Excel (chave NIF); limpar um intervalo;
 *   - só colaboradores activos.
 * Melhorias:
 *   - validação no servidor e numa transacção (nada fica a meio); auditoria de cada operação em massa;
 *   - não se altera a escala de um mês com a efectividade FECHADA (o fecho congela o apuramento);
 *   - o Excel é lido no servidor (PhpSpreadsheet) e a importação é tudo-ou-nada: com erros não grava nada;
 *   - limites (31 dias × 500 colaboradores por operação) para não bloquear o servidor.
 */
final class ServicoEscalas
{
    public const ORIGENS = ['CONTRATO', 'MANUAL', 'EXCEL', 'COPIA'];

    private const MAX_LINHAS = 20000;

    /** Colunas do modelo Excel (COLUNAS, escalas.js:253): [cabeçalho, campo, instrução, exemplo]. */
    public const COLUNAS = [
        ['NIF', 'nif', 'NIF do colaborador (chave de importação)', '000000000LA000'],
        ['Colaborador', 'nome', 'Só para conferência; a chave é o NIF', 'Nome do colaborador'],
        ['Data', 'dia', 'Dia da escala (DD/MM/AAAA ou AAAA-MM-DD)', '01/09/2026'],
        ['Entrada', 'entrada', 'Hora de entrada (HH:MM). Vazio se for folga', '08:00'],
        ['Saída', 'saida', 'Hora de saída (HH:MM). Vazio se for folga', '17:00'],
        ['Períodos', 'periodos', '1 = período corrido (2 picagens) · 2 = dois períodos (4 picagens)', '2'],
        ['Saída intervalo', 'saida_intervalo', 'Só com 2 períodos: hora em que sai para o intervalo', '12:00'],
        ['Entrada intervalo', 'entrada_intervalo', 'Só com 2 períodos: hora em que regressa do intervalo', '13:00'],
        ['Intervalo (min)', 'intervalo_minutos', 'Só com 1 período: intervalo não pago, em minutos', '60'],
        ['Tolerância (min)', 'tolerancia_min', 'Tolerância de entrada, em minutos', '10'],
        ['Folga', 'folga', 'SIM para dia de descanso (deixa entrada e saída vazias)', ''],
        ['Prioritária', 'prioritaria', 'SIM para sobrepor à escala já prevista nesse dia', ''],
        ['Nota', 'nota', 'Observação livre (ex.: motivo da escala prioritária)', ''],
    ];

    public function __construct(
        private readonly ServicoCalendarioRH $calendario,
        private readonly ServicoAssiduidade $assiduidade,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Leitura ─────────────

    /** @return Collection<int, EscalaTrabalho> */
    public function escalas(string $de, string $ate, ?array $colaboradores = null): Collection
    {
        return EscalaTrabalho::query()->whereBetween('dia', [$de, $ate])->when($colaboradores, fn ($q, $ids) => $q->whereIn('colaborador_id', $ids))
            ->orderBy('dia')->orderBy('colaborador_id')->orderBy('id')->get();
    }

    /** Índice "colaborador|dia" → escala que vale (a prioritária ganha) — indexar, escalas.js:86. @return array<string, EscalaTrabalho> */
    public static function indexar(iterable $lista): array
    {
        $mapa = [];
        foreach ($lista as $e) {
            $k = $e->colaborador_id.'|'.$e->dia->toDateString();
            if (! isset($mapa[$k]) || ($e->prioritaria && ! $mapa[$k]->prioritaria)) {
                $mapa[$k] = $e;
            }
        }

        return $mapa;
    }

    /** Escala no formato do horário (para RegrasContrato::horasPrevistas). */
    public static function comoHorario(EscalaTrabalho $e): array
    {
        return ['entrada' => $e->entrada, 'saida' => $e->saida, 'periodos' => (int) ($e->periodos ?: 1), 'saida_intervalo' => (string) $e->saida_intervalo,
            'entrada_intervalo' => (string) $e->entrada_intervalo, 'intervalo_min' => (int) $e->intervalo_minutos, 'tolerancia_min' => (int) $e->tolerancia_min, 'dias' => []];
    }

    /** Horas previstas por um registo de escala (horasDe, escalas.js:60). */
    public static function horasDe(EscalaTrabalho $e): float
    {
        return $e->folga ? 0.0 : (RegrasContrato::horasPrevistas(self::comoHorario($e)) ?? 0.0);
    }

    /** Grelha do mês para o ecrã (grelhaMes, escalas.js:344). */
    public function grelha(string $mes): array
    {
        $mes = ServicoCalendarioRH::mes($mes);
        $de = "{$mes}-01";
        $ate = date('Y-m-t', strtotime($de));
        $dias = ServicoCalendarioRH::dias($de, $ate);
        $lista = $this->escalas($de, $ate);
        $mapa = self::indexar($lista);
        $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
        $linhas = [];
        foreach (Colaborador::query()->where('estado', 'ACTIVO')->ordenarPorNome('nome_completo')->get(['id', 'nome_completo', 'nif']) as $c) {
            $hor = RegrasContrato::horario(RegrasContrato::emVigor($contratos[$c->id] ?? collect(), $de, $ate)?->horario);
            $horas = 0.0;
            $trab = $folgas = $prio = 0;
            $celulas = [];
            foreach ($dias as $d) {
                $e = $mapa["{$c->id}|{$d}"] ?? null;
                if ($e) {
                    $e->prioritaria && $prio++;
                    if ($e->folga) {
                        $folgas++;
                    } else {
                        $trab++;
                    }
                    $horas += self::horasDe($e);
                }
                $celulas[] = ['dia' => $d, 'escala' => $e ? $this->linha($e) : null];
            }
            $linhas[] = ['colaborador_id' => $c->id, 'nome' => $c->nome_completo, 'nif' => $c->nif, 'horario_contrato' => $hor,
                'horario_contrato_texto' => $hor ? "{$hor['entrada']}–{$hor['saida']}" : null, 'celulas' => $celulas, 'dias_trabalho' => $trab, 'folgas' => $folgas,
                'prioritarias' => $prio, 'horas' => round($horas, 2), 'sem_escala' => count(array_filter($celulas, fn ($x) => $x['escala'] === null))];
        }

        return ['mes' => $mes, 'dias' => $dias, 'linhas' => $linhas, 'total' => ['linhas' => $lista->count(), 'prioritarias' => $lista->where('prioritaria', true)->count()],
            'fechado' => $this->assiduidade->estadoMes($mes)?->estado === 'FECHADO'];
    }

    // ───────────── Escrita ─────────────

    /**
     * Grava uma lista de dias (gravarLinhas, escalas.js:140). A prioritária é uma linha à parte; sem
     * `substituir_prioritarias` uma linha normal não toca num dia que já tem prioritária (fica «protegida»).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return array{criados: int, actualizados: int, protegidos: int}
     */
    public function gravarLinhas(array $linhas, bool $substituirPrioritarias = false): array
    {
        if (count($linhas) > self::MAX_LINHAS) {
            throw new ErroNegocio('Demasiados dias numa só operação (máximo '.self::MAX_LINHAS.'). Divida o intervalo.', 'ESCALA_GRANDE', 422);
        }
        $regs = array_map(fn ($l) => $this->normalizar($l), $linhas);
        if (! $regs) {
            return ['criados' => 0, 'actualizados' => 0, 'protegidos' => 0];
        }
        $activos = Colaborador::query()->where('estado', 'ACTIVO')->whereIn('id', array_unique(array_column($regs, 'colaborador_id')))->pluck('id')->all();
        foreach ($regs as $r) {
            if (! in_array($r['colaborador_id'], $activos, true)) {
                throw new ErroNegocio('Só se escalam colaboradores activos.', 'COLABORADOR_INACTIVO', 422);
            }
        }
        $meses = array_unique(array_map(fn ($r) => substr($r['dia'], 0, 7), $regs));
        foreach ($meses as $m) {
            $this->assiduidade->exigirMesAberto($m);
        }

        return DB::transaction(function () use ($regs, $substituirPrioritarias) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['escalas']);
            $de = min(array_column($regs, 'dia'));
            $ate = max(array_column($regs, 'dia'));
            $porChave = $this->escalas($de, $ate, array_values(array_unique(array_column($regs, 'colaborador_id'))))
                ->groupBy(fn ($e) => $e->colaborador_id.'|'.$e->dia->toDateString());
            $res = ['criados' => 0, 'actualizados' => 0, 'protegidos' => 0];
            $quem = Auth::user()?->nome_utilizador;
            foreach ($regs as $r) {
                $anteriores = $porChave[$r['colaborador_id'].'|'.$r['dia']] ?? collect();
                $prioritaria = $anteriores->first(fn ($x) => $x->prioritaria);
                if ($prioritaria && ! $r['prioritaria'] && ! $substituirPrioritarias) {
                    $res['protegidos']++;

                    continue;
                }
                $alvo = $r['prioritaria'] ? $prioritaria : $anteriores->first(fn ($x) => ! $x->prioritaria);
                if ($alvo) {
                    $alvo->update($r + ['atualizado_por' => $quem]);
                    $res['actualizados']++;
                } else {
                    $nova = EscalaTrabalho::create($r + ['criado_por' => $quem, 'atualizado_por' => $quem]);
                    $porChave[$r['colaborador_id'].'|'.$r['dia']] = $anteriores->push($nova);
                    $res['criados']++;
                }
            }

            return $res;
        });
    }

    /**
     * Aplica um turno a um intervalo para vários colaboradores (aplicar, escalas.js:169). dias_semana vazio = todos.
     *
     * @param  array<string, mixed>  $d
     */
    public function aplicar(array $d): array
    {
        $dias = $this->intervalo($d['de'], $d['ate'] ?? $d['de']);
        $semana = array_map('intval', $d['dias_semana'] ?? []);
        $filtro = $semana ? array_values(array_filter($dias, fn ($x) => in_array((int) date('w', strtotime("{$x} 12:00:00")), $semana, true))) : $dias;
        if (! $filtro) {
            throw new ErroNegocio('Nenhum dia do intervalo corresponde aos dias da semana escolhidos.', 'SEM_DIAS', 422);
        }
        $linhas = [];
        foreach (array_unique(array_map('intval', $d['colaboradores'])) as $cid) {
            foreach ($filtro as $dia) {
                $linhas[] = ['colaborador_id' => $cid, 'dia' => $dia, 'origem' => 'MANUAL'] + array_intersect_key($d, array_flip(['entrada', 'saida', 'periodos', 'saida_intervalo',
                    'entrada_intervalo', 'intervalo_minutos', 'tolerancia_min', 'folga', 'prioritaria', 'nota']));
            }
        }
        $r = $this->gravarLinhas($linhas, (bool) ($d['substituir_prioritarias'] ?? false));
        $this->auditar(! empty($d['prioritaria']) ? 'Escala prioritária' : 'Aplicar escala', count($d['colaboradores']).' colaborador(es) · '.count($filtro).' dia(s) · '
            .(! empty($d['folga']) ? 'folga' : ($d['entrada'] ?? '').'-'.($d['saida'] ?? '')));

        return $r + ['dias' => count($filtro), 'colaboradores' => count(array_unique($d['colaboradores']))];
    }

    /** Migrar o horário do contrato para a escala (gerarDoContrato, escalas.js:190). */
    public function gerarDoContrato(string $de, string $ate, ?array $colaboradores = null, bool $substituirPrioritarias = false, bool $marcarFolgas = false): array
    {
        $dias = $this->intervalo($de, $ate);
        $cfg = $this->calendario->config();
        $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
        $alvo = Colaborador::query()->where('estado', 'ACTIVO')->when($colaboradores, fn ($q, $ids) => $q->whereIn('id', $ids))->ordenarPorNome('nome_completo')->get(['id', 'nome_completo']);
        $linhas = [];
        $semHorario = [];
        foreach ($alvo as $c) {
            $doColab = $contratos[$c->id] ?? collect();
            $comHorario = 0;
            foreach ($dias as $dia) {
                $contrato = RegrasContrato::emVigor($doColab, $dia, $dia);   // o contrato pode mudar a meio do intervalo
                $h = $contrato && ! RegrasContrato::suspensoEm($contrato, $dia) ? RegrasContrato::horario($contrato->horario) : null;
                if (! $h) {
                    continue;
                }
                $comHorario++;
                $semana = $h['dias'] ?: $cfg['dias_uteis'];
                $feriado = in_array($dia, $cfg['feriados'], true);
                if (in_array((int) date('w', strtotime("{$dia} 12:00:00")), $semana, true) && ! $feriado) {
                    $linhas[] = ['colaborador_id' => $c->id, 'dia' => $dia, 'entrada' => $h['entrada'], 'saida' => $h['saida'], 'periodos' => $h['periodos'],
                        'saida_intervalo' => $h['saida_intervalo'], 'entrada_intervalo' => $h['entrada_intervalo'], 'intervalo_minutos' => $h['intervalo_min'],
                        'tolerancia_min' => $h['tolerancia_min'], 'folga' => false, 'origem' => 'CONTRATO'];
                } elseif ($marcarFolgas) {
                    $linhas[] = ['colaborador_id' => $c->id, 'dia' => $dia, 'folga' => true, 'origem' => 'CONTRATO', 'nota' => $feriado ? 'Feriado' : 'Descanso semanal'];
                }
            }
            if ($comHorario === 0) {
                $semHorario[] = $c->nome_completo;
            }
        }
        $r = $this->gravarLinhas($linhas, $substituirPrioritarias);
        $this->auditar('Migrar horário do contrato', "{$de} a {$ate} · ".($alvo->count() - count($semHorario)).' colaborador(es)');

        return $r + ['colaboradores' => $alvo->count() - count($semHorario), 'sem_horario' => $semHorario];
    }

    /** Copia [de_origem, ate_origem] para o intervalo que começa em para_inicio (copiar, escalas.js:225). */
    public function copiar(string $deOrigem, string $ateOrigem, string $paraInicio, ?array $colaboradores = null, bool $prioritaria = false, bool $substituirPrioritarias = false): array
    {
        $origem = $this->intervalo($deOrigem, $ateOrigem);
        $mapa = self::indexar($this->escalas($deOrigem, $ateOrigem, $colaboradores));
        if (! $mapa) {
            throw new ErroNegocio('Não há escala no período de origem para copiar.', 'SEM_ESCALA_ORIGEM', 422);
        }
        $linhas = [];
        $t0 = strtotime("{$paraInicio} 12:00:00");
        foreach ($origem as $i => $diaOrig) {
            $destino = date('Y-m-d', $t0 + $i * 86400);
            foreach ($mapa as $e) {
                if ($e->dia->toDateString() !== $diaOrig) {
                    continue;
                }
                $linhas[] = ['colaborador_id' => $e->colaborador_id, 'dia' => $destino, 'entrada' => $e->entrada, 'saida' => $e->saida, 'periodos' => $e->periodos,
                    'saida_intervalo' => $e->saida_intervalo, 'entrada_intervalo' => $e->entrada_intervalo, 'intervalo_minutos' => $e->intervalo_minutos,
                    'tolerancia_min' => $e->tolerancia_min, 'folga' => (bool) $e->folga, 'prioritaria' => $prioritaria, 'origem' => 'COPIA', 'nota' => $e->nota];
            }
        }
        $r = $this->gravarLinhas($linhas, $substituirPrioritarias);
        $this->auditar('Copiar escala', "{$deOrigem}..{$ateOrigem} → {$paraInicio} · ".count($linhas).' linha(s)');

        return $r + ['linhas' => count($linhas)];
    }

    /** Importa o Excel (lerExcel + importar, escalas.js:291-324): tudo ou nada. */
    public function importar(UploadedFile $ficheiro, bool $substituirPrioritarias = false): array
    {
        $folha = IOFactory::load($ficheiro->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $cab = array_map(fn ($c) => self::chave((string) $c), array_shift($folha) ?? []);
        $idx = [];
        foreach (self::COLUNAS as [$titulo, $campo]) {
            $k = array_search(self::chave($titulo), $cab, true);
            $idx[$campo] = $k === false ? null : $k;
        }
        if ($idx['nif'] === null || $idx['dia'] === null) {
            throw new ErroNegocio('O ficheiro tem de ter as colunas «NIF» e «Data» (descarregue o modelo).', 'FICHEIRO_INVALIDO', 422);
        }
        $activos = Colaborador::query()->where('estado', 'ACTIVO')->whereNotNull('nif')->get(['id', 'nif']);
        $exacto = $activos->keyBy(fn ($c) => strtoupper((string) preg_replace('/[\s.]/', '', (string) $c->nif)));
        // índice sem zeros à esquerda, só quando não é ambíguo (o Excel tira os zeros a um NIF gravado como número)
        $semZeros = $activos->groupBy(fn ($c) => self::chaveNif((string) $c->nif))->filter(fn ($g) => $g->count() === 1)->map->first();
        $procurar = fn (string $nif) => $exacto[strtoupper((string) preg_replace('/[\s.]/', '', $nif))] ?? $semZeros[self::chaveNif($nif)] ?? null;
        $linhas = [];
        $erros = [];
        $sim = fn ($v) => (bool) preg_match('/^(s|sim|x|1|true|v|verdadeiro)$/i', trim((string) $v));
        foreach ($folha as $n => $l) {
            $v = fn (string $campo) => $idx[$campo] === null ? null : (is_string($l[$idx[$campo]] ?? null) ? trim($l[$idx[$campo]]) : ($l[$idx[$campo]] ?? null));
            if (! array_filter($l, fn ($x) => $x !== null && $x !== '')) {
                continue;   // linhas em branco não contam
            }
            $linha = $n + 2;
            $nif = self::chaveNif((string) $v('nif'));
            $c = $nif === '' ? null : $procurar((string) $v('nif'));
            $dia = ServicoAssiduidade::dataIso($v('dia'));
            if ($nif === '' || ! $c || ! $dia) {
                $erros[] = "Linha {$linha}: ".($nif === '' ? 'NIF em falta' : (! $c ? 'não há colaborador activo com o NIF '.$v('nif') : 'data inválida ('.($v('dia') ?? 'vazia').')')).'.';

                continue;
            }
            $folga = $sim($v('folga'));
            $si = ServicoAssiduidade::horaHHMM($v('saida_intervalo'));
            $ei = ServicoAssiduidade::horaHHMM($v('entrada_intervalo'));
            $e = ['colaborador_id' => $c->id, 'dia' => $dia, 'folga' => $folga, 'entrada' => ServicoAssiduidade::horaHHMM($v('entrada')), 'saida' => ServicoAssiduidade::horaHHMM($v('saida')),
                'periodos' => ((int) $v('periodos') === 2 || ($si !== '' && $ei !== '')) ? 2 : 1, 'saida_intervalo' => $si, 'entrada_intervalo' => $ei,
                'intervalo_minutos' => (int) $v('intervalo_minutos'), 'tolerancia_min' => (int) $v('tolerancia_min'), 'prioritaria' => $sim($v('prioritaria')),
                'nota' => (string) ($v('nota') ?? ''), 'origem' => 'EXCEL'];
            try {
                $this->normalizar($e);
                $linhas[] = $e;
            } catch (ErroNegocio $x) {
                $erros[] = "Linha {$linha}: {$x->getMessage()}";
            }
        }
        if ($erros) {
            return ['criados' => 0, 'actualizados' => 0, 'protegidos' => 0, 'erros' => $erros, 'gravado' => false];
        }
        $r = $this->gravarLinhas($linhas, $substituirPrioritarias);
        $this->auditar('Importar escala (Excel)', count($linhas).' linha(s)');

        return $r + ['erros' => [], 'gravado' => true];
    }

    /** Modelo Excel (cabeçalho + instruções) para a importação. */
    public function modelo(): string
    {
        $livro = new Spreadsheet;
        $f = $livro->getActiveSheet()->setTitle('Escala');
        foreach (self::COLUNAS as $i => [$titulo, , , $exemplo]) {
            $f->setCellValue([$i + 1, 1], $titulo);
            $f->setCellValueExplicit([$i + 1, 2], $exemplo, DataType::TYPE_STRING);
        }
        $ajuda = $livro->createSheet()->setTitle('Instruções');
        $ajuda->fromArray([['Coluna', 'Instrução', 'Exemplo'], ...array_map(fn ($c) => [$c[0], $c[2], $c[3]], self::COLUNAS)]);
        $tmp = tempnam(sys_get_temp_dir(), 'escala_');
        (new Xlsx($livro))->save($tmp);
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** Eliminar dias (eliminar, escalas.js:327). */
    public function eliminar(array $ids): int
    {
        $lista = EscalaTrabalho::query()->whereKey($ids)->get();
        foreach ($lista->map(fn ($e) => $e->dia->format('Y-m'))->unique() as $m) {
            $this->assiduidade->exigirMesAberto($m);
        }

        return DB::transaction(function () use ($lista) {
            $lista->each->delete();
            $this->auditar('Eliminar escala', $lista->count().' dia(s)');

            return $lista->count();
        });
    }

    /** Limpar um intervalo (eliminarIntervalo, escalas.js:333): por omissão mantém as prioritárias. */
    public function limpar(string $de, string $ate, ?array $colaboradores = null, bool $apenasPrioritarias = false, bool $manterPrioritarias = true): int
    {
        $this->intervalo($de, $ate);
        $l = $this->escalas($de, $ate, $colaboradores);
        $l = $apenasPrioritarias ? $l->where('prioritaria', true) : ($manterPrioritarias ? $l->where('prioritaria', '!=', true) : $l);

        return $this->eliminar($l->pluck('id')->all());
    }

    // ───────────── Auxiliares ─────────────

    /** Valida e normaliza uma linha (validar + normalizar, escalas.js:104-136). */
    private function normalizar(array $e): array
    {
        $dia = substr((string) ($e['dia'] ?? ''), 0, 10);
        if (empty($e['colaborador_id']) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia) || ! ServicoAssiduidade::dataIso($dia)) {
            throw new ErroNegocio('Indique o colaborador e uma data válida.', 'ESCALA_INVALIDA', 422);
        }
        $folga = (bool) ($e['folga'] ?? false);
        $base = ['colaborador_id' => (int) $e['colaborador_id'], 'dia' => $dia, 'folga' => $folga, 'prioritaria' => (bool) ($e['prioritaria'] ?? false),
            'origem' => in_array($e['origem'] ?? null, self::ORIGENS, true) ? $e['origem'] : 'MANUAL', 'nota' => mb_substr(trim((string) ($e['nota'] ?? '')), 0, 500) ?: null];
        if ($folga) {
            return $base + ['entrada' => null, 'saida' => null, 'periodos' => 1, 'saida_intervalo' => null, 'entrada_intervalo' => null, 'intervalo_minutos' => 0, 'tolerancia_min' => 0];
        }
        $h = RegrasContrato::validarHorario(['entrada' => $e['entrada'] ?? null, 'saida' => $e['saida'] ?? null, 'periodos' => $e['periodos'] ?? 1,
            'saida_intervalo' => $e['saida_intervalo'] ?? null, 'entrada_intervalo' => $e['entrada_intervalo'] ?? null,
            'intervalo_min' => $e['intervalo_minutos'] ?? 0, 'tolerancia_min' => $e['tolerancia_min'] ?? 0], "Em {$dia}")
            ?? throw new ErroNegocio("Em {$dia}: indique a entrada e a saída (HH:MM) ou marque folga.", 'HORARIO_INVALIDO', 422);

        return $base + ['entrada' => $h['entrada'], 'saida' => $h['saida'], 'periodos' => $h['periodos'], 'saida_intervalo' => $h['saida_intervalo'] ?: null,
            'entrada_intervalo' => $h['entrada_intervalo'] ?: null, 'intervalo_minutos' => $h['intervalo_min'], 'tolerancia_min' => $h['tolerancia_min']];
    }

    /** @return list<string> */
    private function intervalo(string $de, string $ate): array
    {
        $a = ServicoAssiduidade::dataIso($de);
        $b = ServicoAssiduidade::dataIso($ate);
        if (! $a || ! $b || $b < $a) {
            throw new ErroNegocio('Intervalo de datas inválido.', 'INTERVALO_INVALIDO', 422);
        }
        if ((strtotime($b) - strtotime($a)) / 86400 > 92) {
            throw new ErroNegocio('O intervalo não pode passar de 3 meses.', 'INTERVALO_GRANDE', 422);
        }

        return ServicoCalendarioRH::dias($a, $b);
    }

    private function linha(EscalaTrabalho $e): array
    {
        return ['id' => $e->id, 'entrada' => $e->entrada, 'saida' => $e->saida, 'periodos' => (int) ($e->periodos ?: 1), 'saida_intervalo' => $e->saida_intervalo,
            'entrada_intervalo' => $e->entrada_intervalo, 'intervalo_minutos' => (int) $e->intervalo_minutos, 'tolerancia_min' => (int) $e->tolerancia_min,
            'folga' => (bool) $e->folga, 'prioritaria' => (bool) $e->prioritaria, 'origem' => $e->origem, 'nota' => $e->nota, 'horas' => self::horasDe($e)];
    }

    private function auditar(string $accao, string $detalhe): void
    {
        $this->auditoria->registar('RH', $accao, $detalhe, 'escalas_trabalho');
    }

    private static function chave(string $c): string
    {
        $c = mb_strtolower(trim($c));
        $c = strtr($c, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $c), '_');
    }

    /** NIF sem espaços nem pontos e sem zeros à esquerda (Versão Actual: índice NIF sem zeros, assiduidade.js). */
    private static function chaveNif(string $nif): string
    {
        return ltrim(strtoupper((string) preg_replace('/[\s.]/', '', $nif)), '0');
    }
}
