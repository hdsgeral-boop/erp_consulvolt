<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\AusenciaFaltaColaborador;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\EfectividadeAssiduidade;
use App\Models\EscalaTrabalho;
use App\Models\FechoMensalAssiduidade;
use App\Models\InfotipoSalarial;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PlanoFeriasColaborador;
use App\Support\Seguranca\GuardaUrlSaida;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
 * Versão Actual (Fase B, assiduidade.js:135-226 e 674): as horas previstas de cada dia vêm da escala do mês (prioritária ›
 * normal), senão do horário do contrato, senão das horas/dia do contrato, senão 8; os dias úteis do horário mandam sobre os
 * da empresa; regime de 1 ou 2 períodos (4 picagens); atrasos e saídas antecipadas informativos; dias com o contrato
 * suspenso não contam; colaboradores cessados no mês apurados até à cessação.
 */
final class ServicoAssiduidade
{
    public const ORIGEM_LANCAMENTO = 'ASSIDUIDADE';

    public function __construct(private readonly ServicoCalendarioRH $calendario) {}

    // ───────────── Registos ─────────────

    /**
     * @param  array<string, mixed>  $d  colaborador_id, data, entrada?, saida?, saida_intervalo?, entrada_intervalo?, horas?, observacoes?, autorizado_extra?
     *                                   Versão Actual: com as 4 picagens (dois períodos) as horas são a soma dos dois períodos; as duas
     *                                   picagens do intervalo vão sempre juntas.
     */
    public function gravarRegisto(array $d, string $origem = 'MANUAL', ?string $fonte = null): EfectividadeAssiduidade
    {
        $data = substr((string) $d['data'], 0, 10);
        if ($data > now()->toDateString()) {
            throw new ErroNegocio('A data do registo não pode ser futura.', 'DATA_FUTURA', 422);
        }
        $this->exigirMesAberto(substr($data, 0, 7));
        $si = RegrasContrato::hhmm($d['saida_intervalo'] ?? null) ?: null;
        $ei = RegrasContrato::hhmm($d['entrada_intervalo'] ?? null) ?: null;
        if (($si === null) !== ($ei === null)) {
            throw new ErroNegocio('Indique as duas picagens do intervalo (saída e entrada) ou nenhuma.', 'PICAGENS_INTERVALO', 422);
        }
        $quatro = $si !== null && ! empty($d['entrada']) && ! empty($d['saida'])
            ? (($m = RegrasContrato::horasEntre($d['entrada'], $si)) !== null && ($t = RegrasContrato::horasEntre($ei, $d['saida'])) !== null ? round($m + $t, 2) : null) : null;
        $d['horas'] = is_string($d['horas'] ?? null) ? str_replace(',', '.', trim($d['horas'])) : ($d['horas'] ?? null);   // «7,5» (vírgula decimal)
        if ($d['horas'] !== null && $d['horas'] !== '' && ! is_numeric($d['horas'])) {
            throw new ErroNegocio('As horas do dia têm de ser um número.', 'HORAS_INVALIDAS', 422);
        }
        $horas = isset($d['horas']) && $d['horas'] !== '' && $d['horas'] !== null ? round((float) $d['horas'], 2) : ($quatro ?? self::horasEntre($d['entrada'] ?? null, $d['saida'] ?? null));
        if ($horas === null) {
            throw new ErroNegocio('Indique as horas ou a entrada e a saída (HH:MM).', 'HORAS_EM_FALTA', 422);
        }
        if ($horas < 0 || $horas > 24) {
            throw new ErroNegocio('As horas do dia têm de estar entre 0 e 24.', 'HORAS_INVALIDAS', 422);
        }
        $colab = Colaborador::query()->findOrFail($d['colaborador_id']);
        $user = Auth::user()?->nome_utilizador;

        return EfectividadeAssiduidade::query()->updateOrCreate(['colaborador_id' => $colab->id, 'data' => $data], [
            'entrada' => $d['entrada'] ?? null, 'saida' => $d['saida'] ?? null, 'saida_intervalo' => $si, 'entrada_intervalo' => $ei, 'horas' => $horas, 'origem' => $origem, 'fonte' => $fonte,
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
    public function importar(UploadedFile $ficheiro, bool $substituir = true, string $origem = 'FICHEIRO', ?string $fonte = null): array
    {
        $folha = IOFactory::load($ficheiro->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        $cab = array_map(fn ($c) => self::chave((string) $c), array_shift($folha) ?? []);
        $col = fn (array $nomes) => collect($cab)->search(fn ($c) => in_array($c, $nomes, true));
        $idx = ['nif' => $col(['nif']), 'inss' => $col(['inss', 'numero_inss', 'n_inss']), 'id' => $col(['numero', 'id', 'colaborador_id', 'n']),
            'nome' => $col(['nome', 'colaborador', 'nome_completo']), 'data' => $col(['data', 'dia']), 'entrada' => $col(['entrada', 'hora_entrada']),
            'saida' => $col(['saida', 'hora_saida']), 'horas' => $col(['horas', 'total_horas']), 'observacoes' => $col(['observacoes', 'obs']), 'autorizado' => $col(['autorizado', 'autorizado_extra', 'extra_autorizado']),
            // Versão Actual (assiduidade.js): picagens do intervalo (dois períodos), com sinónimos
            'saida_intervalo' => $col(['saida_intervalo', 'saida_para_intervalo', 'inicio_intervalo', 'saida_almoco']),
            'entrada_intervalo' => $col(['entrada_intervalo', 'regresso_intervalo', 'fim_intervalo', 'entrada_almoco', 'regresso_almoco'])];
        if ($idx['data'] === false || ($idx['nif'] === false && $idx['inss'] === false && $idx['id'] === false && $idx['nome'] === false)) {
            throw new ErroNegocio('O ficheiro tem de ter as colunas «data» e uma identificação do colaborador (nif, inss, numero ou nome).', 'FICHEIRO_INVALIDO', 422);
        }
        $colabs = Colaborador::query()->get();
        $porNif = $colabs->filter(fn ($c) => $c->nif)->keyBy(fn ($c) => ServicoColaboradores::normalizarNif($c->nif));
        $porInss = $colabs->filter(fn ($c) => $c->numero_inss)->keyBy(fn ($c) => trim($c->numero_inss));
        // índices sem zeros à esquerda (o Excel tira-os aos números), usados só quando não são ambíguos
        $semZeros = fn ($lista, callable $chave) => $lista->groupBy(fn ($c) => ltrim((string) $chave($c), '0'))->filter(fn ($g) => $g->count() === 1)->map->first();
        $porNifSemZeros = $semZeros($colabs->filter(fn ($c) => $c->nif), fn ($c) => ServicoColaboradores::normalizarNif($c->nif));
        $porInssSemZeros = $semZeros($colabs->filter(fn ($c) => $c->numero_inss), fn ($c) => trim($c->numero_inss));
        $porNome = $colabs->keyBy(fn ($c) => mb_strtolower(trim($c->nome_completo)));
        $res = ['gravados' => 0, 'ignorados' => 0, 'em_branco' => 0, 'erros' => []];
        $v = fn (array $l, string $k) => $idx[$k] === false ? null : (is_string($l[$idx[$k]] ?? null) ? trim($l[$idx[$k]]) : ($l[$idx[$k]] ?? null));
        $exemploNif = $porNif->keys()->first();
        foreach ($folha as $n => $l) {
            $linha = $n + 2;
            if (! array_filter($l, fn ($x) => $x !== null && $x !== '')) {
                continue;
            }
            $nif = $v($l, 'nif') ? ServicoColaboradores::normalizarNif((string) $v($l, 'nif')) : null;
            $c = ($nif ? $porNif[$nif] ?? $porNifSemZeros[ltrim($nif, '0')] ?? null : null)
                ?? ($v($l, 'inss') ? $porInss[(string) $v($l, 'inss')] ?? $porInssSemZeros[ltrim((string) $v($l, 'inss'), '0')] ?? null : null)
                ?? ($v($l, 'id') ? $colabs->firstWhere('id', (int) $v($l, 'id')) : null)
                ?? ($v($l, 'nome') ? $porNome[mb_strtolower((string) $v($l, 'nome'))] ?? null : null);
            $data = self::dataIso($v($l, 'data'));
            // linha do modelo pré-preenchido deixada em branco (sem entrada, saída nem horas): não é erro
            if (in_array($v($l, 'entrada'), [null, ''], true) && in_array($v($l, 'saida'), [null, ''], true) && in_array($v($l, 'horas'), [null, ''], true)) {
                $res['em_branco']++;

                continue;
            }
            if (! $c || ! $data) {
                $res['erros'][] = "Linha {$linha}: ".(! $c ? 'colaborador não encontrado'.($nif ? " (NIF {$v($l, 'nif')}".($exemploNif ? "; exemplo de NIF registado: {$exemploNif}" : '').')' : '')
                    : 'data inválida').'.';

                continue;
            }
            if (! $substituir && EfectividadeAssiduidade::query()->where('colaborador_id', $c->id)->where('data', $data)->exists()) {
                $res['ignorados']++;

                continue;
            }
            try {
                DB::transaction(fn () => $this->gravarRegisto(['colaborador_id' => $c->id, 'data' => $data, 'entrada' => self::horaHHMM($v($l, 'entrada')) ?: null,
                    'saida' => self::horaHHMM($v($l, 'saida')) ?: null, 'saida_intervalo' => self::horaHHMM($v($l, 'saida_intervalo')) ?: null,
                    'entrada_intervalo' => self::horaHHMM($v($l, 'entrada_intervalo')) ?: null, 'horas' => $v($l, 'horas'), 'observacoes' => $v($l, 'observacoes'),
                    'autorizado_extra' => in_array(self::chave((string) $v($l, 'autorizado')), ['sim', 's', 'x', '1', 'true'], true)], $origem, mb_substr($fonte ?? $ficheiro->getClientOriginalName(), 0, 50)));
                $res['gravados']++;
            } catch (ErroNegocio $e) {
                $res['erros'][] = "Linha {$linha}: {$e->getMessage()}";
            }
        }

        return $res;
    }

    /** Colunas do modelo de importação (modeloImportacao, assiduidade.js:640). */
    public const COLUNAS_MODELO = ['NIF', 'Colaborador', 'Data', 'Entrada', 'Saida intervalo', 'Entrada intervalo', 'Saida', 'Horas', 'Autorizado', 'Observacoes'];

    /**
     * #4 — modelo Excel da efectividade JÁ PREENCHIDO (modeloImportacao, assiduidade.js:618; assiduidade_ui.js:680): uma linha por
     * colaborador ACTIVO e por dia de trabalho do mês, com o horário previsto do dia (escala prioritária › normal › horário do
     * contrato › regime da empresa, regimeDoDia), o NIF como texto (o Excel não lhe come os zeros à esquerda), mais as folhas
     * «Colaboradores» e «Instruções». Os dias de folga, fora da admissão/contrato ou com o contrato suspenso não entram.
     *
     * @return array{linhas: int, sem_nif: list<string>}
     */
    public function modelo(string $mes, string $caminho): array
    {
        $mes = ServicoCalendarioRH::mes($mes);
        $cfg = $this->calendario->config();
        $inicio = "{$mes}-01";
        $fim = date('Y-m-t', strtotime($inicio));
        $dias = ServicoCalendarioRH::dias($inicio, $fim);
        $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
        $escalas = ServicoEscalas::indexar(EscalaTrabalho::query()->whereBetween('dia', [$inicio, $fim])->get());
        $colabs = Colaborador::query()->where('estado', 'ACTIVO')->ordenarPorNome('nome_completo')->get();
        $linhas = [];
        foreach ($colabs as $e) {
            $contrato = RegrasContrato::emVigor($contratos[$e->id] ?? collect(), $inicio, $fim);
            $horario = RegrasContrato::horario($contrato?->horario);
            $desde = max(array_filter([$inicio, $e->data_admissao?->toDateString(), $contrato?->data_inicio?->toDateString()]));
            $ate = min(array_filter([$fim, $contrato?->data_fim?->toDateString()]));
            foreach ($dias as $dia) {
                $esc = $escalas["{$e->id}|{$dia}"] ?? null;
                if ($dia < $desde || $dia > $ate || ($esc ? $esc->folga : ! $this->diaUtilDo($dia, $cfg, $horario)) || ($contrato && RegrasContrato::suspensoEm($contrato, $dia))) {
                    continue;
                }
                $h = $esc ? RegrasContrato::horario(ServicoEscalas::comoHorario($esc)) : $horario;
                if (! $h && (int) ($cfg['periodos_padrao'] ?? 1) === 2) {   // regime da empresa com as horas do intervalo por omissão
                    $h = ['entrada' => '', 'saida' => '', 'saida_intervalo' => RegrasContrato::hhmm($cfg['intervalo_saida_padrao'] ?? null) ?: '12:00',
                        'entrada_intervalo' => RegrasContrato::hhmm($cfg['intervalo_entrada_padrao'] ?? null) ?: '13:00'];
                }
                $linhas[] = [(string) ($e->nif ?? ''), (string) $e->nome_completo, $dia, $h['entrada'] ?? '', $h['saida_intervalo'] ?? '', $h['entrada_intervalo'] ?? '', $h['saida'] ?? '', '', '', ''];
            }
        }

        $livro = new Spreadsheet;
        $folha = $livro->getActiveSheet()->setTitle('Efectividade');
        $folha->fromArray(self::COLUNAS_MODELO);
        $folha->getStyle('A1:J1')->getFont()->setBold(true);
        $folha->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        foreach ($linhas as $n => $l) {
            $r = $n + 2;
            $folha->setCellValueExplicit("A{$r}", $l[0], DataType::TYPE_STRING);   // NIF sempre como texto
            foreach (array_slice($l, 1) as $k => $v) {
                $folha->setCellValueExplicit(chr(66 + $k).$r, $v, DataType::TYPE_STRING);   // nada de fórmulas nem datas convertidas
            }
        }
        foreach (['A' => 18, 'B' => 32, 'C' => 12, 'D' => 9, 'E' => 15, 'F' => 17, 'G' => 9, 'H' => 8, 'I' => 11, 'J' => 24] as $c => $w) {
            $folha->getColumnDimension($c)->setWidth($w);
        }
        $folha->freezePane('A2');

        $fc = $livro->createSheet()->setTitle('Colaboradores');
        $fc->fromArray(['NIF', 'Nome', 'Nº INSS']);
        $fc->getStyle('A1:C1')->getFont()->setBold(true);
        $fc->getStyle('A:A')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $fc->getStyle('C:C')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        foreach ($colabs->values() as $n => $e) {
            $r = $n + 2;
            $fc->setCellValueExplicit("A{$r}", (string) ($e->nif ?? ''), DataType::TYPE_STRING);
            $fc->setCellValueExplicit("B{$r}", (string) $e->nome_completo, DataType::TYPE_STRING);
            $fc->setCellValueExplicit("C{$r}", (string) ($e->numero_inss ?? ''), DataType::TYPE_STRING);
        }
        foreach (['A' => 18, 'B' => 32, 'C' => 14] as $c => $w) {
            $fc->getColumnDimension($c)->setWidth($w);
        }

        $fi = $livro->createSheet()->setTitle('Instruções');
        $instrucoes = [
            ['Como preencher'],
            ['1. A folha «Efectividade» já traz uma linha por colaborador e dia de trabalho do mês, com o NIF e o horário previsto.'],
            ['2. Corrija as horas picadas. Apague as linhas dos dias que não quer importar; as linhas sem entrada, saída nem horas são ignoradas.'],
            ['3. A chave é o NIF; também é aceite o Nº INSS ou o nome exacto. Não altere a coluna NIF.'],
            ['4. Com dois períodos preencha as quatro horas («Entrada», «Saida intervalo», «Entrada intervalo», «Saida»); com período corrido deixe as duas do intervalo vazias.'],
            ['5. Em alternativa às horas de picagem, pode preencher só a coluna «Horas» com o total do dia (aceita vírgula decimal, ex.: 7,5).'],
            ['6. «Autorizado» = SIM marca trabalho extra autorizado em dia não útil.'],
            [''],
            ['O NIF está formatado como texto de propósito: se o Excel o converter em número, os zeros à esquerda perdem-se.'],
            ['A importação já tolera essa perda, mas é mais seguro manter o formato de texto.'],
        ];
        foreach ($instrucoes as $n => [$t]) {
            $fi->setCellValueExplicit('A'.($n + 1), $t, DataType::TYPE_STRING);
        }
        $fi->getStyle('A1')->getFont()->setBold(true);
        $fi->getColumnDimension('A')->setWidth(130);
        $livro->setActiveSheetIndex(0);
        (new Xlsx($livro))->save($caminho);

        return ['linhas' => count($linhas), 'sem_nif' => $colabs->filter(fn ($e) => trim((string) $e->nif) === '')->pluck('nome_completo')->values()->all()];
    }

    /**
     * Faltas injustificadas acumuladas no ano até ao mês indicado (faltasDoAno, assiduidade.js:808; art. 230.º da Lei 12/23:
     * mais de 3 num mês ou de 12 no ano é infracção disciplinar). Os meses fechados usam o apuramento fotografado no fecho;
     * os outros são apurados de novo. O mês indicado conta com as linhas do resumo recebido (evita apurá-lo duas vezes).
     * Os meses anteriores vêm da cache por empresa e ano (faltasPorMes / CacheFaltasDoAno).
     *
     * @param  list<array<string, mixed>>  $linhasDoMes
     * @return array<int, int> colaborador_id => dias de falta no ano
     */
    public function faltasDoAno(string $mes, array $linhasDoMes): array
    {
        $mes = ServicoCalendarioRH::mes($mes);
        [$ano, $m] = array_map('intval', explode('-', $mes));
        $totais = [];
        foreach ($this->faltasPorMes($ano, $m - 1)['meses'] as $faltas) {
            foreach ($faltas as $id => $dias) {
                $totais[(int) $id] = ($totais[(int) $id] ?? 0) + (int) $dias;
            }
        }
        foreach ($linhasDoMes as $l) {
            $id = (int) ($l['colaborador_id'] ?? 0);
            $totais[$id] = ($totais[$id] ?? 0) + (int) ($l['dias_falta'] ?? 0);
        }

        return $totais;
    }

    /**
     * Portal do colaborador: faltas injustificadas do PRÓPRIO no mês corrente e no ano até hoje, pelo mesmo apuramento do ecrã da
     * efectividade (em cache). Null quando a empresa não tem registos de efectividade no ano (sem eles o apuramento marcaria todos
     * os dias úteis como falta): o portal fica com a estimativa pelas faltas detectadas.
     *
     * @return array{mes: int, ano: int}|null
     */
    public function faltasDoColaborador(int $colaboradorId): ?array
    {
        $ano = (int) now()->format('Y');
        $actual = now()->format('Y-m');
        ['meses' => $porMes, 'com_registos' => $comRegistos] = $this->faltasPorMes($ano, (int) now()->format('n'));
        if (! $comRegistos) {
            return null;
        }
        $total = 0;
        foreach ($porMes as $faltas) {
            $total += (int) ($faltas[$colaboradorId] ?? 0);
        }

        return ['mes' => (int) ($porMes[$actual][$colaboradorId] ?? 0), 'ano' => $total];
    }

    /**
     * Dias de falta injustificada por colaborador em cada mês de Janeiro a $ateMes do ano (fechados: o apuramento do fecho; abertos:
     * resumoMes). Os meses antes do primeiro registo de efectividade do ano não se apuram (não há efectividade). Em cache Redis por
     * empresa e ano (CacheFaltasDoAno): cada mês guarda o dia até onde foi apurado e só é recalculado quando esse dia muda (o mês
     * corrente, a cada dia) ou quando a versão da empresa sobe (alteração de registos, fecho, ausências, férias, escala, contrato…).
     *
     * @return array{meses: array<string, array<int, int>>, com_registos: bool} meses: AAAA-MM => [colaborador_id => dias];
     *                                                                          com_registos: há registos de efectividade no ano
     */
    public function faltasPorMes(int $ano, int $ateMes): array
    {
        $ateMes = min(12, $ateMes);
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $chave = CacheFaltasDoAno::chave($empresa, $ano);   // versão lida ANTES de apurar (um pedido atrasado grava na chave antiga)
        $guardado = CacheFaltasDoAno::ler($chave) + ['primeiro' => null, 'meses' => []];
        $mudou = false;
        if (! is_string($guardado['primeiro'])) {   // dia do primeiro registo do ano ('' = nenhum)
            $guardado['primeiro'] = substr((string) EfectividadeAssiduidade::query()->whereBetween('data', [sprintf('%04d-01-01', $ano), sprintf('%04d-12-31', $ano)])->min('data'), 0, 10);
            $mudou = true;
        }
        $primeiro = $guardado['primeiro'];
        $hoje = now()->toDateString();
        $ontem = now()->subDay()->toDateString();
        $fechos = null;
        $res = [];
        for ($k = 1; $k <= $ateMes; $k++) {
            $mk = sprintf('%04d-%02d', $ano, $k);
            $fim = date('Y-m-t', strtotime("{$mk}-01"));
            $ate = $fim >= $hoje ? min($ontem, $fim) : $fim;   // o mesmo «apurado_ate» do resumoMes
            if (($guardado['meses'][$mk]['ate'] ?? null) !== $ate) {
                $fechos ??= FechoMensalAssiduidade::query()->where('mes', '>=', sprintf('%04d-01', $ano))->where('mes', '<=', sprintf('%04d-12', $ano))
                    ->where('estado', 'FECHADO')->get()->keyBy('mes');
                $linhas = match (true) {
                    $fechos->has($mk) => (array) $fechos[$mk]->linhas,
                    $primeiro !== '' && $mk >= substr($primeiro, 0, 7) => $this->resumoMes($mk)['linhas'],
                    default => [],   // antes do primeiro registo do ano não há efectividade a apurar
                };
                $faltas = [];
                foreach ($linhas as $l) {
                    $id = (int) ($l['colaborador_id'] ?? 0);
                    $faltas[$id] = ($faltas[$id] ?? 0) + (int) ($l['dias_falta'] ?? 0);
                }
                $guardado['meses'][$mk] = ['ate' => $ate, 'faltas' => array_filter($faltas)];
                $mudou = true;
            }
            $res[$mk] = $guardado['meses'][$mk]['faltas'];
        }
        if ($mudou) {
            CacheFaltasDoAno::gravar($chave, $guardado);
        }

        return ['meses' => $res, 'com_registos' => $primeiro !== ''];
    }

    /**
     * Acrescenta ao apuramento do mês o acumulado anual de faltas injustificadas e o aviso do art. 230.º (mais de 12 no ano).
     *
     * @param  array<string, mixed>  $resumo
     * @return array<string, mixed>
     */
    public function comFaltasDoAno(array $resumo): array
    {
        $ano = $this->faltasDoAno((string) $resumo['mes'], (array) $resumo['linhas']);
        $resumo['linhas'] = array_map(function ($l) use ($ano) {
            $l['dias_falta_ano'] = $ano[(int) $l['colaborador_id']] ?? 0;
            $aviso = "{$l['dias_falta_ano']} faltas sem justificação no ano: acima de 12 num ano há infracção disciplinar (art.º 230.º da Lei 12/23).";
            if ($l['dias_falta_ano'] > 12 && ! in_array($aviso, $l['avisos'] ?? [], true)) {
                $l['avisos'][] = $aviso;
            }

            return $l;
        }, (array) $resumo['linhas']);
        $resumo['colaboradores_com_faltas'] = count(array_filter($resumo['linhas'], fn ($l) => (int) ($l['dias_falta'] ?? 0) > 0));

        return $resumo;
    }

    /** Limite do que se lê do relógio (5 MB) e tempo máximo do pedido (segundos). */
    public const RELOGIO_MAX_BYTES = 5 * 1024 * 1024;

    public const RELOGIO_TIMEOUT = 20;

    /**
     * M-12 — leitura directa do relógio biométrico pelo SERVIDOR (importarDoRelogio, js/modules/rh/assiduidade.js:436).
     * O legado fazia o fetch no navegador (falhava por CORS e expunha o endereço da rede interna a cada posto). Aqui:
     * só http(s) e sem credenciais no URL (já validado ao gravar a configuração), tempo limite, tamanho máximo, formato
     * CSV ou JSON (lista de objectos com os mesmos nomes de colunas da importação de ficheiro), e o mesmo leitor da
     * importação de ficheiro. Endereços de metadados da nuvem (169.254.x.x) são recusados.
     *
     * @return array{gravados: int, ignorados: int, erros: list<string>, fonte: string}
     */
    public function importarDoRelogio(bool $substituir = true): array
    {
        $cfg = $this->calendario->config();
        $url = trim((string) ($cfg['relogio']['url'] ?? ''));
        if ($url === '') {
            throw new ErroNegocio('Configure o endereço do relógio biométrico em RH › Efectividade › Configuração.', 'RELOGIO_SEM_URL', 422);
        }
        // OWASP A10 (SSRF): esquema, credenciais, loopback/metadados/serviços internos, formas numéricas ambíguas e DNS
        // rebinding (a ligação fica presa aos IP verificados) — GuardaUrlSaida. Redes privadas aceites (relógio na LAN).
        $guarda = GuardaUrlSaida::validar($url, 'URL_RELOGIO_INVALIDA');
        $host = $guarda['host'];
        // stream: o corpo é lido aos bocados e cortado acima do limite, em vez de carregado todo para a memória
        $opcoes = ['allow_redirects' => false, 'stream' => true];
        if ($guarda['resolver'] && defined('CURLOPT_RESOLVE')) {
            $opcoes['curl'] = [CURLOPT_RESOLVE => $guarda['resolver']];
        }
        try {
            $resposta = Http::timeout(self::RELOGIO_TIMEOUT)->withOptions($opcoes)
                ->accept('text/csv, application/json, text/plain')->get($url);
        } catch (\Throwable) {
            throw new ErroNegocio("Não foi possível contactar o relógio em {$host}. Verifique a rede e se o servidor do ERP tem acesso ao equipamento.", 'RELOGIO_INACESSIVEL', 422);
        }
        if (! $resposta->successful()) {
            throw new ErroNegocio("O relógio respondeu com o erro {$resposta->status()}.", 'RELOGIO_ERRO', 422);
        }
        $fluxo = $resposta->toPsrResponse()->getBody();
        $corpo = '';
        while (! $fluxo->eof() && strlen($corpo) <= self::RELOGIO_MAX_BYTES) {
            $corpo .= $fluxo->read(65536);
        }
        $fluxo->close();
        if (strlen($corpo) > self::RELOGIO_MAX_BYTES) {
            throw new ErroNegocio('O relógio devolveu mais de 5 MB: exporte um período mais curto.', 'RELOGIO_GRANDE', 422);
        }
        $json = ($cfg['relogio']['formato'] ?? 'CSV') === 'JSON' || str_contains((string) $resposta->header('Content-Type'), 'json');
        $temp = tempnam(sys_get_temp_dir(), 'relogio_');
        @unlink($temp);
        $caminho = $temp.'.csv';
        try {
            if ($json) {
                $dados = json_decode($corpo, true);
                $dados = is_array($dados) && isset($dados['dados']) && is_array($dados['dados']) ? $dados['dados'] : $dados;
                if (! is_array($dados) || $dados === [] || ! is_array(reset($dados))) {
                    throw new ErroNegocio('O relógio não devolveu registos (JSON vazio ou em formato desconhecido).', 'RELOGIO_SEM_REGISTOS', 422);
                }
                $colunas = array_keys(array_merge(...array_map(fn ($x) => (array) $x, array_values($dados))));
                $f = fopen($caminho, 'w');
                fputcsv($f, $colunas, ',', '"', '\\');
                foreach ($dados as $x) {
                    fputcsv($f, array_map(fn ($c) => is_scalar($x[$c] ?? null) ? (string) $x[$c] : '', $colunas), ',', '"', '\\');
                }
                fclose($f);
            } else {
                if (trim($corpo) === '') {
                    throw new ErroNegocio('O relógio não devolveu registos.', 'RELOGIO_SEM_REGISTOS', 422);
                }
                file_put_contents($caminho, $corpo);
            }
            $res = $this->importar(new UploadedFile($caminho, 'relogio.csv', 'text/csv', null, true), $substituir, 'RELOGIO', $host);
        } finally {
            @unlink($caminho);
        }

        return $res + ['fonte' => $host];
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
        // Versão Actual: o contrato em vigor (suspensos fora do período, cessados até à cessação) e a escala do mês
        $contratos = ContratoTrabalho::query()->get()->groupBy('colaborador_id');
        $escalas = ServicoEscalas::indexar(EscalaTrabalho::query()->whereBetween('dia', [$inicio, $fim])->get());
        $arred = (int) $cfg['arredondamento_min'];
        $cobre = fn ($lista, string $dia) => ($lista ?? collect())->first(fn ($x) => $x->data_inicio && $x->data_fim && $dia >= $x->data_inicio->toDateString() && $dia <= $x->data_fim->toDateString());

        $linhas = [];
        // quem cessou o contrato neste mês continua a ser apurado até à data da cessação
        $colaboradores = Colaborador::query()->where(fn ($q) => $q->where('estado', 'ACTIVO')->orWhere(fn ($q) => $q->where('estado', 'INACTIVO')
            ->where('data_cessacao', '>=', $inicio)))->ordenarPorNome('nome_completo')->get();
        foreach ($colaboradores as $e) {
            $contrato = RegrasContrato::emVigor($contratos[$e->id] ?? collect(), $inicio, $fim);
            // horário do contrato: manda nas horas/dia e nos dias da semana deste colaborador (assiduidade.js:704)
            $horario = RegrasContrato::horario($contrato?->horario);
            $horasDia = RegrasContrato::horasPrevistas($horario) ?? ($contrato && (float) $contrato->horas_por_dia > 0 ? (float) $contrato->horas_por_dia : 8.0);
            // dias do colaborador: desde a admissão (ou início do contrato) até ao fim do contrato
            $desde = max(array_filter([$inicio, $e->data_admissao?->toDateString(), $contrato?->data_inicio?->toDateString()]));
            $ate = min(array_filter([$ateDia, $contrato?->data_fim?->toDateString()]));
            $porDia = ($registos[$e->id] ?? collect())->keyBy(fn ($r) => $r->data->toDateString());
            $t = ['trab' => 0.0, 'extra' => 0.0, 'falta' => 0.0, 'comRegisto' => 0, 'diasFalta' => 0, 'feriasAus' => 0, 'naoAut' => 0.0,
                'atraso' => 0.0, 'antecipada' => 0.0, 'diasAtraso' => 0, 'diasEscala' => 0, 'diasPrioritaria' => 0, 'semIntervalo' => 0, 'suspenso' => 0];
            $ocorrencias = [];
            $diasFaltaLista = [];
            $uteisColab = 0;
            foreach ($dias as $dia) {
                $esc = $escalas["{$e->id}|{$dia}"] ?? null;
                // dias úteis do colaborador: a escala decide folga ou trabalho; sem escala, os dias do horário mandam sobre os da empresa
                $util = $esc ? ! $esc->folga : $this->diaUtilDo($dia, $cfg, $horario);
                $uteisColab += $util ? 1 : 0;
                $reg = $porDia[$dia] ?? null;
                // fora da admissão/contrato só se ignoram os dias SEM registo (não há falta antes de admitido);
                // o trabalho registado conta sempre (ex.: contrato registado depois do início efectivo)
                if ($dia > $ateDia || (! $reg && ($dia < $desde || $dia > $ate))) {
                    continue;
                }
                // contrato suspenso nesse dia: não há falta nem horas previstas (Lei 12/23, arts. 267.º e 270.º)
                if ($contrato && RegrasContrato::suspensoEm($contrato, $dia)) {
                    $t['suspenso']++;

                    continue;
                }
                if ($esc) {
                    $t['diasEscala']++;
                    $t['diasPrioritaria'] += $esc->prioritaria ? 1 : 0;
                }
                $horasDoDia = $esc ? ServicoEscalas::horasDe($esc) : $horasDia;
                // regime do dia (1 ou 2 períodos): escala › contrato › empresa (regimeDoDia, assiduidade.js:204)
                $regime = $this->regimeDoDia($esc, $horario, $cfg);
                $horarioDoDia = $esc ? ($esc->folga ? null : ServicoEscalas::comoHorario($esc)) : $horario;
                $deFerias = (bool) $cobre($ferias[$e->id] ?? null, $dia);
                $aprovada = $cobre($ausencias[$e->id] ?? null, $dia);
                $ausente = $aprovada && $aprovada->remunerada !== 'NAO' ? $aprovada : null;
                $h = $reg ? self::arredondar(self::horasDoRegisto($reg, $regime) ?? (float) $reg->horas, $arred) : 0.0;
                if ($aprovada && ! $ausente && $util) {   // justificada NÃO remunerada: continua descontada, não é falta injustificada
                    $t['falta'] += $reg ? max(0, $horasDoDia - $h) : $horasDoDia;
                    if ($reg) {
                        $t['comRegisto']++;
                        $t['trab'] += $h;
                    }

                    continue;
                }
                if ($reg) {
                    $t['comRegisto']++;
                    $t['trab'] += $h;
                    if ($regime['periodos'] === 2 && RegrasContrato::minutos($reg->saida_intervalo) === null) {
                        $t['semIntervalo']++;
                    }
                    if ($util && $horarioDoDia) {   // atraso e saída antecipada: só informativos (desvioDoDia, assiduidade.js:226)
                        [$atraso, $antecipada] = self::desvioDoDia($reg, $horarioDoDia);
                        if ($atraso > 0) {
                            $t['atraso'] += $atraso;
                            $t['diasAtraso']++;
                        }
                        $t['antecipada'] += $antecipada;
                    }
                    if ($util) {
                        $extra = $h - $horasDoDia;
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
                $t['falta'] += $horasDoDia;
                $diasFaltaLista[] = $dia;
                $ocorrencias[] = ['data' => $dia, 'tipo' => 'DIA', 'horas' => $horasDoDia];
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
            if ($t['diasAtraso'] > 0) {
                $avisos[] = "{$t['diasAtraso']} dia(s) com entrada depois da hora prevista, já descontada a tolerância (".self::h($t['atraso']).' h no total). Os atrasos não descontam no salário.';
            }
            if ($t['diasPrioritaria'] > 0) {
                $avisos[] = "{$t['diasPrioritaria']} dia(s) com escala prioritária, que se sobrepôs à escala prevista.";
            }
            if ($t['semIntervalo'] > 0) {
                $avisos[] = "{$t['semIntervalo']} dia(s) em regime de dois períodos sem as picagens do intervalo: as horas foram apuradas só pela entrada e pela saída.";
            }
            if ($t['suspenso'] > 0) {
                $avisos[] = "{$t['suspenso']} dia(s) com o contrato suspenso: não contam faltas nem horas previstas.";
            }
            $linhas[] = ['colaborador_id' => $e->id, 'nome' => $e->nome_completo, 'horas_dia' => $horasDia, 'dias_uteis' => $uteisColab, 'dias_com_registo' => $t['comRegisto'],
                'dias_ferias_ausencia' => $t['feriasAus'], 'horas_trabalhadas' => round($t['trab'], 2), 'horas_extra' => round($extraBruta - $comp, 2),
                'horas_falta' => round($faltaBruta - $comp, 2), 'horas_extra_bruta' => $extraBruta, 'horas_falta_bruta' => $faltaBruta, 'horas_compensadas' => $comp,
                'horas_nao_autorizadas' => round($t['naoAut'], 2), 'dias_falta' => $t['diasFalta'], 'dias_falta_lista' => $diasFaltaLista,
                'horario' => $horario ? $horario + ['horas_previstas' => RegrasContrato::horasPrevistas($horario)] : null,
                'dias_escala' => $t['diasEscala'], 'dias_escala_prioritaria' => $t['diasPrioritaria'], 'sem_picagem_intervalo' => $t['semIntervalo'],
                'horas_atraso' => round($t['atraso'], 2), 'dias_atraso' => $t['diasAtraso'], 'horas_saida_antecipada' => round($t['antecipada'], 2), 'dias_suspenso' => $t['suspenso'],
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

    // ───────────── Versão Actual: horário, escala e regime de picagem ─────────────

    /** Dia útil para o colaborador: os dias do horário mandam sobre os da empresa; os feriados mantêm-se (ehUtilDo, assiduidade.js:201). */
    private function diaUtilDo(string $dia, array $cfg, ?array $horario): bool
    {
        if (! $horario || ! $horario['dias']) {
            return $this->calendario->diaUtil($dia, $cfg);
        }

        return in_array((int) date('w', strtotime("{$dia} 12:00:00")), $horario['dias'], true) && ! in_array($dia, $cfg['feriados'], true);
    }

    /**
     * Regime de picagem do dia: escala (prioritária › normal) › horário do contrato › configuração da empresa (regimeDoDia, assiduidade.js:204).
     *
     * @return array{periodos: int, intervalo_min: int, fonte: string}
     */
    private function regimeDoDia(?EscalaTrabalho $esc, ?array $horario, array $cfg): array
    {
        if ($esc) {
            $h = $esc->folga ? null : RegrasContrato::horario(ServicoEscalas::comoHorario($esc));

            return ['periodos' => $h['periodos'] ?? 1, 'intervalo_min' => $h['intervalo_min'] ?? 0, 'fonte' => $esc->prioritaria ? 'ESCALA_PRIORITARIA' : 'ESCALA'];
        }
        if ($horario) {
            return ['periodos' => $horario['periodos'], 'intervalo_min' => $horario['intervalo_min'], 'fonte' => 'CONTRATO'];
        }

        return ['periodos' => (int) ($cfg['periodos_padrao'] ?? 1) === 2 ? 2 : 1, 'intervalo_min' => 0, 'fonte' => 'EMPRESA'];
    }

    /**
     * Horas efectivas do registo pelo regime (horasDoRegisto, assiduidade.js:184): com 4 picagens soma os dois períodos;
     * com 2 picagens, entrada→saída menos o intervalo previsto (período corrido). Sem picagens: nulo (vale o total gravado).
     */
    public static function horasDoRegisto(EfectividadeAssiduidade $reg, array $regime): ?float
    {
        if (RegrasContrato::minutos($reg->entrada) === null || RegrasContrato::minutos($reg->saida) === null) {
            return null;
        }
        if (RegrasContrato::minutos($reg->saida_intervalo) !== null && RegrasContrato::minutos($reg->entrada_intervalo) !== null) {
            return round((float) RegrasContrato::horasEntre($reg->entrada, $reg->saida_intervalo) + (float) RegrasContrato::horasEntre($reg->entrada_intervalo, $reg->saida), 2);
        }
        $bruto = (float) self::horasEntre($reg->entrada, $reg->saida);
        $desconto = $regime['periodos'] === 1 ? $regime['intervalo_min'] / 60 : 0;

        return round(max(0, $bruto - $desconto), 2);
    }

    /** Atraso (entrada − prevista − tolerância) e saída antecipada, em horas (desvioDoDia, assiduidade.js:226). @return array{0: float, 1: float} */
    public static function desvioDoDia(EfectividadeAssiduidade $reg, array $hor): array
    {
        [$prevE, $prevS] = [RegrasContrato::minutos($hor['entrada'] ?? null), RegrasContrato::minutos($hor['saida'] ?? null)];
        [$realE, $realS] = [RegrasContrato::minutos($reg->entrada), RegrasContrato::minutos($reg->saida)];
        $atraso = $antecipada = 0.0;
        if ($realE !== null && $prevE !== null) {
            $d = $realE - $prevE;
            $d += $d < -720 ? 1440 : 0;   // entrada do outro lado da meia-noite
            $atraso = $d > (int) ($hor['tolerancia_min'] ?? 0) ? ($d - (int) ($hor['tolerancia_min'] ?? 0)) / 60 : 0.0;
        }
        if ($realS !== null && $prevS !== null) {
            $d = $prevS - $realS;
            $d += $d < -720 ? 1440 : 0;
            $antecipada = $d > 0 ? $d / 60 : 0.0;
        }

        return [round(max(0, $atraso), 2), round(max(0, $antecipada), 2)];
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
