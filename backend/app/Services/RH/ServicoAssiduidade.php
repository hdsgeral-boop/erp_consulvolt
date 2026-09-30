<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EfectividadeAssiduidade;
use App\Models\FechoMensalAssiduidade;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoFeriasColaborador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Efectividade / assiduidade (js/modules/rh/assiduidade.js). Paridade com o apuramento mensal (resumoMes): horas do
 * contrato por dia, arredondamento, tolerância, mínimo de minutos para extra, dias não úteis como extra (com
 * autorização opcional), férias aprovadas/gozadas e ausências aprovadas não são falta, ausências não remuneradas
 * continuam descontadas (art.º 222.º/2), compensação DIA/MENSAL/LIMITE, aviso acima de 3 faltas (art.º 230.º).
 * Correcções (ADR-038):
 *   - só colaboradores ACTIVOS e só dias dentro da admissão/contrato (o legado só excluía «INACTIVO», mas o
 *     formulário gravava «Não ACTIVO», e gerava faltas antes da admissão e depois do fim do contrato);
 *   - as faltas por justificar geram-se em acções explícitas (detectar, fechar) — no legado bastava ABRIR o ecrã
 *     para criar ausências;
 *   - ao lançar no processamento o mês é RECALCULADO com a configuração fotografada no fecho e as ausências
 *     entretanto aprovadas: o legado subtraía as justificações depois da compensação (perdendo horas extra) e
 *     nunca retirava as ausências pedidas (não detectadas) aprovadas depois do fecho;
 *   - lançar exige permissão (o legado não tinha guarda) e retira os lançamentos de assiduidade que deixaram de
 *     ter horas (o legado deixava-os ficar);
 *   - um fecho por mês (estado FECHADO/REABERTO), o histórico fica na auditoria.
 */
final class ServicoAssiduidade
{
    public const ORIGEM_LANCAMENTO = 'ASSIDUIDADE';

    public function __construct(private readonly ServicoCalendarioRH $calendario) {}

    // ───────────── Registos ─────────────

    /** @param  array<string, mixed>  $d  colaborador_id, data, entrada?, saida?, horas?, observacoes?, autorizado_extra? */
    public function gravarRegisto(array $d, string $origem = 'MANUAL', ?string $fonte = null): EfectividadeAssiduidade
    {
        $data = substr((string) $d['data'], 0, 10);
        if ($data > now()->toDateString()) {
            throw new ErroNegocio('A data do registo não pode ser futura.', 'DATA_FUTURA', 422);
        }
        $this->exigirMesAberto(substr($data, 0, 7));
        $horas = isset($d['horas']) && $d['horas'] !== '' && $d['horas'] !== null ? round((float) $d['horas'], 2) : self::horasEntre($d['entrada'] ?? null, $d['saida'] ?? null);
        if ($horas === null) {
            throw new ErroNegocio('Indique as horas ou a entrada e a saída (HH:MM).', 'HORAS_EM_FALTA', 422);
        }
        if ($horas < 0 || $horas > 24) {
            throw new ErroNegocio('As horas do dia têm de estar entre 0 e 24.', 'HORAS_INVALIDAS', 422);
        }
        $colab = Colaborador::query()->findOrFail($d['colaborador_id']);
        $user = Auth::user()?->nome_utilizador;

        return EfectividadeAssiduidade::query()->updateOrCreate(['colaborador_id' => $colab->id, 'data' => $data], [
            'entrada' => $d['entrada'] ?? null, 'saida' => $d['saida'] ?? null, 'horas' => $horas, 'origem' => $origem, 'fonte' => $fonte,
            'observacoes' => $d['observacoes'] ?? null, 'autorizado_extra' => (bool) ($d['autorizado_extra'] ?? false), 'atualizado_por' => $user,
        ] + (EfectividadeAssiduidade::query()->where('colaborador_id', $colab->id)->where('data', $data)->exists() ? [] : ['criado_por' => $user]));
    }

    public function eliminarRegisto(EfectividadeAssiduidade $r): void
    {
        $this->exigirMesAberto($r->data->format('Y-m'));
        $r->delete();
    }

    /**
     * Importação de ficheiro (CSV/TXT/XLSX) do relógio. Colunas reconhecidas pelo cabeçalho: nif | inss | numero/id |
     * nome, data, entrada, saida, horas, observacoes. Procura do colaborador: NIF → INSS → id → nome (como no legado).
     *
     * @return array{gravados: int, ignorados: int, erros: list<string>}
     */
    public function importar(UploadedFile $ficheiro, bool $substituir = true): array
    {
        $folha = IOFactory::load($ficheiro->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $cab = array_map(fn ($c) => self::chave((string) $c), array_shift($folha) ?? []);
        $col = fn (array $nomes) => collect($cab)->search(fn ($c) => in_array($c, $nomes, true));
        $idx = ['nif' => $col(['nif']), 'inss' => $col(['inss', 'numero_inss', 'n_inss']), 'id' => $col(['numero', 'id', 'colaborador_id', 'n']),
            'nome' => $col(['nome', 'colaborador', 'nome_completo']), 'data' => $col(['data', 'dia']), 'entrada' => $col(['entrada', 'hora_entrada']),
            'saida' => $col(['saida', 'hora_saida']), 'horas' => $col(['horas', 'total_horas']), 'observacoes' => $col(['observacoes', 'obs'])];
        if ($idx['data'] === false || ($idx['nif'] === false && $idx['inss'] === false && $idx['id'] === false && $idx['nome'] === false)) {
            throw new ErroNegocio('O ficheiro tem de ter as colunas «data» e uma identificação do colaborador (nif, inss, numero ou nome).', 'FICHEIRO_INVALIDO', 422);
        }
        $colabs = Colaborador::query()->get();
        $porNif = $colabs->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif($c->nif));
        $porInss = $colabs->filter(fn ($c) => $c->numero_inss)->keyBy(fn ($c) => trim($c->numero_inss));
        $porNome = $colabs->keyBy(fn ($c) => mb_strtolower(trim($c->nome_completo)));
        $res = ['gravados' => 0, 'ignorados' => 0, 'erros' => []];
        $v = fn (array $l, string $k) => $idx[$k] === false ? null : (is_string($l[$idx[$k]] ?? null) ? trim($l[$idx[$k]]) : ($l[$idx[$k]] ?? null));
        foreach ($folha as $n => $l) {
            $linha = $n + 2;
            if (! array_filter($l, fn ($x) => $x !== null && $x !== '')) {
                continue;
            }
            $c = ($v($l, 'nif') ? $porNif[ServicoColaboradores::normalizarNif((string) $v($l, 'nif'))] ?? null : null)
                ?? ($v($l, 'inss') ? $porInss[(string) $v($l, 'inss')] ?? null : null)
                ?? ($v($l, 'id') ? $colabs->firstWhere('id', (int) $v($l, 'id')) : null)
                ?? ($v($l, 'nome') ? $porNome[mb_strtolower((string) $v($l, 'nome'))] ?? null : null);
            $data = self::dataIso($v($l, 'data'));
            if (! $c || ! $data) {
                $res['erros'][] = "Linha {$linha}: ".(! $c ? 'colaborador não encontrado' : 'data inválida').'.';

                continue;
            }
            if (! $substituir && EfectividadeAssiduidade::query()->where('colaborador_id', $c->id)->where('data', $data)->exists()) {
                $res['ignorados']++;

                continue;
            }
            try {
                DB::transaction(fn () => $this->gravarRegisto(['colaborador_id' => $c->id, 'data' => $data, 'entrada' => self::horaHHMM($v($l, 'entrada')) ?: null,
                    'saida' => self::horaHHMM($v($l, 'saida')) ?: null, 'horas' => $v($l, 'horas'), 'observacoes' => $v($l, 'observacoes')], 'FICHEIRO', mb_substr($ficheiro->getClientOriginalName(), 0, 50)));
                $res['gravados']++;
            } catch (ErroNegocio $e) {
                $res['erros'][] = "Linha {$linha}: {$e->getMessage()}";
            }
        }

        return $res;
    }

    // ───────────── Apuramento mensal ─────────────

    /**
     * @param  string  $mes  AAAA-MM
     * @return array{mes: string, dias_uteis: int, apurado_ate: string, linhas: list<array<string, mixed>>, totais: array<string, float>}
     */
    public function resumoMes(string $mes, ?string $ateDia = null, ?array $cfg = null): array
    {
        $mes = ServicoCalendarioRH::mes($mes);
        $cfg ??= $this->calendario->config();
        $inicio = "{$mes}-01";
        $fim = date('Y-m-t', strtotime($inicio));
        $ontem = now()->subDay()->toDateString();
        $ateDia ??= $fim >= now()->toDateString() ? $ontem : $fim;
        $dias = ServicoCalendarioRH::dias($inicio, $fim);
        $uteisMes = count(array_filter($dias, fn ($d) => $this->calendario->diaUtil($d, $cfg)));
        $registos = EfectividadeAssiduidade::query()->whereBetween('data', [$inicio, $fim])->get()->groupBy('colaborador_id');
        $ferias = PlanoFeriasColaborador::query()->whereIn('estado', ['APROVADO', 'GOZADO'])->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $inicio)->get()->groupBy('colaborador_id');
        $ausencias = AusenciaFaltaColaborador::query()->where('estado', 'APROVADO')->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $inicio)->get()->groupBy('colaborador_id');
        $contratos = ContratoTrabalho::query()->where('estado', 'ACTIVO')->get()->groupBy('colaborador_id');
        $arred = (int) $cfg['arredondamento_min'];
        $cobre = fn ($lista, string $dia) => ($lista ?? collect())->first(fn ($x) => $x->data_inicio && $x->data_fim && $dia >= $x->data_inicio->toDateString() && $dia <= $x->data_fim->toDateString());

        $linhas = [];
        foreach (Colaborador::query()->where('estado', 'ACTIVO')->orderBy('nome_completo')->get() as $e) {
            $contrato = ($contratos[$e->id] ?? collect())->sortBy('id')->first(fn ($c) => (! $c->data_inicio || $c->data_inicio->toDateString() <= $fim)
                && (! $c->data_fim || $c->data_fim->toDateString() >= $inicio));
            $horasDia = $contrato && (float) $contrato->horas_por_dia > 0 ? (float) $contrato->horas_por_dia : 8.0;
            // dias do colaborador: desde a admissão (ou início do contrato) até ao fim do contrato
            $desde = max(array_filter([$inicio, $e->data_admissao?->toDateString(), $contrato?->data_inicio?->toDateString()]));
            $ate = min(array_filter([$ateDia, $contrato?->data_fim?->toDateString()]));
            $porDia = ($registos[$e->id] ?? collect())->keyBy(fn ($r) => $r->data->toDateString());
            $t = ['trab' => 0.0, 'extra' => 0.0, 'falta' => 0.0, 'comRegisto' => 0, 'diasFalta' => 0, 'feriasAus' => 0, 'naoAut' => 0.0];
            $ocorrencias = [];
            $diasFaltaLista = [];
            foreach ($dias as $dia) {
                $reg = $porDia[$dia] ?? null;
                // fora da admissão/contrato só se ignoram os dias SEM registo (não há falta antes de admitido);
                // o trabalho registado conta sempre (ex.: contrato registado depois do início efectivo)
                if ($dia > $ateDia || (! $reg && ($dia < $desde || $dia > $ate))) {
                    continue;
                }
                $util = $this->calendario->diaUtil($dia, $cfg);
                $deFerias = (bool) $cobre($ferias[$e->id] ?? null, $dia);
                $aprovada = $cobre($ausencias[$e->id] ?? null, $dia);
                $ausente = $aprovada && $aprovada->remunerada !== 'NAO' ? $aprovada : null;
                $h = $reg ? self::arredondar((float) $reg->horas, $arred) : 0.0;
                if ($aprovada && ! $ausente && $util) {   // justificada NÃO remunerada: continua descontada, não é falta injustificada
                    $t['falta'] += $reg ? max(0, $horasDia - $h) : $horasDia;
                    if ($reg) {
                        $t['comRegisto']++;
                        $t['trab'] += $h;
                    }

                    continue;
                }
                if ($reg) {
                    $t['comRegisto']++;
                    $t['trab'] += $h;
                    if ($util) {
                        $extra = $h - $horasDia;
                        if ($extra > 0 && $extra * 60 >= (int) $cfg['extras_min_minutos']) {
                            $t['extra'] += $extra;
                        } elseif ($extra < 0 && abs($extra) * 60 > (int) $cfg['tolerancia_min'] && ! $deFerias && ! $ausente) {
                            $t['falta'] += abs($extra);
                            $ocorrencias[] = ['data' => $dia, 'tipo' => 'PARCIAL', 'horas' => round(abs($extra), 2)];
                        }
                    } elseif ($cfg['extra_nao_util_exige_autorizacao'] && ! $reg->autorizado_extra) {
                        $t['naoAut'] += $h;
                    } else {
                        $t['extra'] += $h;
                    }

                    continue;
                }
                if (! $util) {
                    continue;
                }
                if ($deFerias || $ausente) {
                    $t['feriasAus']++;

                    continue;
                }
                $t['diasFalta']++;
                $t['falta'] += $horasDia;
                $diasFaltaLista[] = $dia;
                $ocorrencias[] = ['data' => $dia, 'tipo' => 'DIA', 'horas' => $horasDia];
            }
            $extraBruta = round($t['extra'], 2);
            $faltaBruta = round($t['falta'], 2);
            $comp = match ($cfg['modo_compensacao']) {
                'MENSAL' => min($extraBruta, $faltaBruta),
                'LIMITE' => min($extraBruta, $faltaBruta, (float) $cfg['limite_compensacao_h']),
                default => 0.0,
            };
            $comp = round($comp, 2);
            $avisos = [];
            if ($comp > 0) {
                $avisos[] = self::h($comp).' h de falta compensadas com horas extra.';
            }
            if ($t['naoAut'] > 0) {
                $avisos[] = self::h($t['naoAut']).' h em dias não úteis sem autorização de trabalho extra: não contam como horas extra.';
            }
            if ($t['diasFalta'] > 3) {
                $avisos[] = "{$t['diasFalta']} faltas sem justificação no mês: acima de 3 num mês há infracção disciplinar (art.º 230.º da Lei 12/23).";
            }
            if (! $contrato) {
                $avisos[] = 'Sem contrato activo no mês: horas do contrato assumidas em 8 h/dia.';
            }
            if ($porDia->isEmpty()) {
                $avisos[] = 'Sem qualquer registo de efectividade no mês.';
            }
            $linhas[] = ['colaborador_id' => $e->id, 'nome' => $e->nome_completo, 'horas_dia' => $horasDia, 'dias_uteis' => $uteisMes, 'dias_com_registo' => $t['comRegisto'],
                'dias_ferias_ausencia' => $t['feriasAus'], 'horas_trabalhadas' => round($t['trab'], 2), 'horas_extra' => round($extraBruta - $comp, 2),
                'horas_falta' => round($faltaBruta - $comp, 2), 'horas_extra_bruta' => $extraBruta, 'horas_falta_bruta' => $faltaBruta, 'horas_compensadas' => $comp,
                'horas_nao_autorizadas' => round($t['naoAut'], 2), 'dias_falta' => $t['diasFalta'], 'dias_falta_lista' => $diasFaltaLista,
                'ocorrencias' => $ocorrencias, 'avisos' => $avisos];
        }
        $totais = ['horas_extra' => round(array_sum(array_column($linhas, 'horas_extra')), 2), 'horas_falta' => round(array_sum(array_column($linhas, 'horas_falta')), 2),
            'dias_falta' => array_sum(array_column($linhas, 'dias_falta'))];

        return ['mes' => $mes, 'dias_uteis' => $uteisMes, 'apurado_ate' => min($ateDia, $fim), 'linhas' => $linhas, 'totais' => $totais];
    }

    /**
     * Faltas detectadas → ausências POR_JUSTIFICAR: cria as novas, retira as que deixaram de existir, mantém as
     * restantes; as já justificadas (em aprovação, aprovadas ou recusadas) não mudam. Dias completos consecutivos
     * (em dias úteis) agrupam-se; dias incompletos ficam um a um.
     *
     * @return array{criadas: int, removidas: int}
     */
    public function detectarFaltas(string $mes, bool $forcar = false, ?array $resumo = null): array
    {
        $mes = ServicoCalendarioRH::mes($mes);
        if (! $forcar) {
            $this->exigirMesAberto($mes);
        }
        if (! $this->periodoSalarialAberto($mes)) {
            throw new ErroNegocio('O processamento salarial do mês já está encerrado.', 'PERIODO_SALARIAL_ENCERRADO', 422);
        }
        $cfg = $this->calendario->config();
        $resumo ??= $this->resumoMes($mes, null, $cfg);
        $existentes = AusenciaFaltaColaborador::query()->whereNotIn('estado', ['POR_JUSTIFICAR', 'CANCELADO'])->get()
            ->reject(fn ($a) => $a->estado === 'RECUSADO' && ! $a->detectada)->groupBy('colaborador_id');
        $desejadas = [];
        foreach ($resumo['linhas'] as $l) {
            $meus = $existentes[$l['colaborador_id']] ?? collect();
            $ocs = array_values(array_filter($l['ocorrencias'], fn ($o) => ! $meus->first(fn ($a) => $o['data'] >= $a->data_inicio->toDateString() && $o['data'] <= ($a->data_fim ?? $a->data_inicio)->toDateString())));
            $grupo = null;
            foreach ($ocs as $o) {
                if ($o['tipo'] === 'DIA' && $grupo && $grupo['_tipo'] === 'DIA' && $this->calendario->proximoUtil($grupo['data_fim'], $cfg) === $o['data']) {
                    $grupo['data_fim'] = $o['data'];
                    $grupo['dias_uteis']++;
                    $grupo['horas_falta'] = round($grupo['horas_falta'] + $o['horas'], 2);

                    continue;
                }
                $grupo && $desejadas[] = $grupo;
                $grupo = ['colaborador_id' => $l['colaborador_id'], '_tipo' => $o['tipo'], 'data_inicio' => $o['data'], 'data_fim' => $o['data'], 'dias_uteis' => 1,
                    'horas_falta' => $o['horas'], 'ocorrencia' => $o['tipo'] === 'DIA' ? 'Dia sem registo de efectividade' : 'Dia incompleto: faltaram '.self::h($o['horas']).' h'];
            }
            $grupo && $desejadas[] = $grupo;
        }
        $chave = fn ($a) => "{$a['colaborador_id']}|{$a['data_inicio']}|{$a['data_fim']}|".number_format((float) $a['horas_falta'], 2, '.', '');
        $querer = collect($desejadas)->keyBy($chave);
        $actuais = AusenciaFaltaColaborador::query()->where('detectada', true)->where('estado', 'POR_JUSTIFICAR')->where('mes', $mes)->get();
        $ja = [];
        $removidas = 0;
        foreach ($actuais as $a) {
            $k = $chave(['colaborador_id' => $a->colaborador_id, 'data_inicio' => $a->data_inicio->toDateString(), 'data_fim' => $a->data_fim->toDateString(), 'horas_falta' => $a->horas_falta]);
            if ($querer->has($k)) {
                $ja[$k] = true;
            } else {
                $a->delete();
                $removidas++;
            }
        }
        $criadas = 0;
        foreach ($querer as $k => $n) {
            if (isset($ja[$k])) {
                continue;
            }
            AusenciaFaltaColaborador::create(['colaborador_id' => $n['colaborador_id'], 'tipo' => null, 'data_inicio' => $n['data_inicio'], 'data_fim' => $n['data_fim'],
                'dias_uteis' => $n['dias_uteis'], 'dias' => count(ServicoCalendarioRH::dias($n['data_inicio'], $n['data_fim'])), 'horas' => $n['_tipo'] === 'PARCIAL' ? $n['horas_falta'] : null,
                'horas_falta' => $n['horas_falta'], 'ocorrencia' => $n['ocorrencia'], 'estado' => 'POR_JUSTIFICAR', 'detectada' => true, 'mes' => $mes,
                'criado_por' => Auth::user()?->nome_utilizador ?? 'sistema']);
            $criadas++;
        }

        return ['criadas' => $criadas, 'removidas' => $removidas];
    }

    // ───────────── Fecho ─────────────

    public function estadoMes(string $mes): ?FechoMensalAssiduidade
    {
        return FechoMensalAssiduidade::query()->where('mes', ServicoCalendarioRH::mes($mes))->first();
    }

    public function fechar(string $mes): FechoMensalAssiduidade
    {
        $mes = ServicoCalendarioRH::mes($mes);
        if ("{$mes}-01" > now()->toDateString()) {
            throw new ErroNegocio('Não é possível fechar um mês que ainda não começou.', 'MES_FUTURO', 422);
        }

        return DB::transaction(function () use ($mes) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["assiduidade:{$mes}"]);
            $f = $this->estadoMes($mes);
            if ($f?->estado === 'FECHADO') {
                throw new ErroNegocio("A efectividade de {$mes} já está fechada.", 'MES_FECHADO', 422);
            }
            $cfg = $this->calendario->config();
            $r = $this->resumoMes($mes, null, $cfg);
            $det = $this->periodoSalarialAberto($mes) ? $this->detectarFaltas($mes, true, $r) : ['criadas' => 0];
            $dados = ['estado' => 'FECHADO', 'dias_uteis' => $r['dias_uteis'], 'linhas' => $r['linhas'], 'totais' => $r['totais'], 'apurado_ate' => $r['apurado_ate'],
                'configuracao' => $cfg, 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador, 'ausencias_geradas' => $det['criadas']];
            $f ? $f->update($dados) : $f = FechoMensalAssiduidade::create($dados + ['mes' => $mes]);
            AusenciaFaltaColaborador::query()->where('detectada', true)->where('mes', $mes)->update(['fecho_mensal_assiduidade_id' => $f->id,
                'pendente_no_fecho' => DB::raw("estado IN ('POR_JUSTIFICAR','PENDENTE_CHEFIA','PENDENTE_RH')")]);

            return $f->refresh();
        });
    }

    public function reabrir(string $mes, string $motivo): FechoMensalAssiduidade
    {
        $mes = ServicoCalendarioRH::mes($mes);

        return DB::transaction(function () use ($mes, $motivo) {
            $f = FechoMensalAssiduidade::query()->where('mes', $mes)->lockForUpdate()->first();
            if ($f?->estado !== 'FECHADO') {
                throw new ErroNegocio("A efectividade de {$mes} não está fechada.", 'MES_NAO_FECHADO', 422);
            }
            if (! $this->periodoSalarialAberto($mes)) {
                throw new ErroNegocio('O processamento salarial do mês já foi encerrado: reabra-o primeiro.', 'PERIODO_SALARIAL_ENCERRADO', 422);
            }
            $f->update(['estado' => 'REABERTO', 'reaberto_em' => now(), 'reaberto_por' => Auth::user()?->nome_utilizador, 'motivo_reabertura' => $motivo]);

            return $f->refresh();
        });
    }

    // ───────────── Lançamento no processamento salarial ─────────────

    /**
     * Horas extra e de falta do mês fechado → lançamentos do processamento (rubricas por horas). O apuramento é
     * recalculado com a configuração do fecho e as ausências aprovadas até agora.
     *
     * @return array{lancados: int, removidos: int, linhas: list<array<string, mixed>>}
     */
    public function lancarNoPeriodo(PeriodoProcessamentoSalarial $p, int $rubricaExtra, int $rubricaFalta): array
    {
        $extra = InfotipoSalarial::query()->findOrFail($rubricaExtra);
        $falta = InfotipoSalarial::query()->findOrFail($rubricaFalta);
        foreach ([[$extra, 'EXTRA'], [$falta, 'FALTA']] as [$i, $tipo]) {
            $info = ['tipo' => $i->tipo, 'nome' => (string) $i->nome, 'calculo_horas' => $i->calculo_horas];
            if (MotorSalarial::tipoHoras($info) !== $tipo) {
                throw new ErroNegocio("A rubrica {$i->nome} não é de ".($tipo === 'EXTRA' ? 'horas extra' : 'faltas por horas').'.', 'RUBRICA_INVALIDA', 422);
            }
        }
        $mes = ServicoCalendarioRH::mes($p->mes_ano);

        return DB::transaction(function () use ($p, $extra, $falta, $mes) {
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$p->mes_ano} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $f = FechoMensalAssiduidade::query()->where('mes', $mes)->lockForUpdate()->first();
            if ($f?->estado !== 'FECHADO') {
                throw new ErroNegocio("Feche primeiro a efectividade de {$mes}.", 'MES_NAO_FECHADO', 422);
            }
            $r = $this->resumoMes($mes, $f->apurado_ate?->toDateString(), $f->configuracao ? array_merge(ServicoCalendarioRH::PADRAO, $f->configuracao) : null);
            $lancados = 0;
            $manter = [];
            foreach ($r['linhas'] as $l) {
                foreach ([[$extra->id, $l['horas_extra']], [$falta->id, $l['horas_falta']]] as [$rubrica, $horas]) {
                    if ($horas <= 0) {
                        continue;
                    }
                    $linha = LinhaFolhaSalarial::query()->updateOrCreate(['periodo_processamento_salarial_id' => $p->id, 'colaborador_id' => $l['colaborador_id'], 'infotipo_salarial_id' => $rubrica],
                        ['horas' => $horas, 'valor' => 0, 'dias_trabalhados' => null, 'origem' => self::ORIGEM_LANCAMENTO]);
                    $manter[] = $linha->id;
                    $lancados++;
                }
            }
            $removidos = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('origem', self::ORIGEM_LANCAMENTO)
                ->whereNotIn('id', $manter ?: [0])->delete();
            $f->update(['lancado_em' => now(), 'lancado_por' => Auth::user()?->nome_utilizador, 'periodo_processamento_salarial_id' => $p->id]);

            return ['lancados' => $lancados, 'removidos' => $removidos, 'linhas' => $r['linhas']];
        });
    }

    // ───────────── Auxiliares ─────────────

    public function exigirMesAberto(string $mes): void
    {
        if ($this->estadoMes($mes)?->estado === 'FECHADO') {
            throw new ErroNegocio("A efectividade de {$mes} está fechada: reabra-a para alterar.", 'MES_FECHADO', 422);
        }
    }

    /** Aberto enquanto não houver processamento salarial do mês ou enquanto este estiver ABERTO. */
    public function periodoSalarialAberto(string $mes): bool
    {
        $p = PeriodoProcessamentoSalarial::query()->where('mes_ano', ServicoCalendarioRH::mesSalarial(ServicoCalendarioRH::mes($mes)))->first();

        return ! $p || $p->estado === 'ABERTO';
    }

    public static function horasEntre(?string $entrada, ?string $saida): ?float
    {
        $min = fn (?string $h) => $h !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($h), $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
        [$a, $b] = [$min($entrada), $min($saida)];
        if ($a === null || $b === null) {
            return null;
        }

        return round(($b >= $a ? $b - $a : 1440 - $a + $b) / 60, 2);   // turno que passa da meia-noite
    }

    public static function arredondar(float $horas, int $minutos): float
    {
        return $minutos > 0 ? round($horas * 60 / $minutos) * $minutos / 60 : round($horas, 2);
    }

    public static function dataIso(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = trim((string) $v);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {   // n.º de série do Excel
            return gmdate('Y-m-d', (int) round(((float) $s - 25569) * 86400));
        }

        return null;
    }

    public static function horaHHMM(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_numeric($v) && (float) $v >= 0 && (float) $v < 1) {   // fracção do dia (Excel)
            $m = (int) round((float) $v * 1440);

            return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }

        return preg_match('/^(\d{1,2})[:h.](\d{2})/', trim((string) $v), $m) ? sprintf('%02d:%s', $m[1], $m[2]) : '';
    }

    private static function chave(string $c): string
    {
        $c = mb_strtolower(trim($c));
        $c = strtr($c, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c', 'º' => '', '.' => '']);

        return preg_replace('/[^a-z0-9]+/', '_', $c);
    }

    private static function h(float $v): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'));
    }
}
