<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\PeriodoProcessamentoSalarial;
use App\Support\Tenancy\ContextoEmpresa;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Mapa de Remunerações no modelo da AGT («Template IRT A2.1», submissão mensal de IRT e Segurança Social) — ver
 * docs/RH_MAPA_REMUNERACOES_AGT.md. Uma folha «Mapa de Remuneração» com A1 = NIF da empresa, A2 = período (AAAA-M), linha 3
 * de agrupamento (vazia), linha 4 com os cabeçalhos (A..AF) e um trabalhador por linha a partir da linha 5.
 *
 * Fonte de verdade: os resultados da folha validada (fotografia do MotorSalarial — os mesmos do recibo e do Mapa Fiscal de
 * IRT). Este serviço NÃO recalcula o imposto: o INSS (AC) e o IRT (AF) são os do resultado; só reparte as rubricas pelas
 * colunas do modelo e deriva o que o modelo pede e o motor não guarda (excesso/dedução de subsídios, Y e AD como fórmulas).
 */
final class ServicoMapaRemuneracoesAgt
{
    public const FOLHA = 'Mapa de Remuneração';

    public const ROTULO_NIF = 'NIF do Contribuinte';

    public const ROTULO_PERIODO = 'Período (AAAA-MM)';

    /** Cabeçalhos da linha 4 (A..AF), pela ordem do modelo. */
    public const CABECALHOS = ['NIF Trabalhador', 'Nome', 'Nº Segurança Social', 'Província', 'Município', 'Salário Base', 'Descontos por Falta', 'Subsídio Alimentação',
        'Subsídio Transporte', 'Abono Família', 'Reembolso de Despesas', 'Outros', 'Cálculo Manual de Excesso de Subsídios?', 'Excesso Subsídios Não Sujeitos', 'Abono de Falhas',
        'Subsídio Renda de Casa', 'Compensação Por Rescisão', 'Subsídio de Férias', 'Horas Extras', 'Subsídio de Atavio', 'Subsídio de Representação', 'Prémios', 'Subsídio de Natal',
        'Outros Subsídios Sujeitos', 'Salário Ilíquido', 'Registo Manual de Contribuição Segurança Social?', 'Base Tributável Segurança Social', 'Não Sujeito a Segurança Social?',
        'Contribuição Segurança Social', 'Base Tributável IRT', 'Isento IRT?', 'IRT Apurado'];

    /** Larguras do modelo (A..AF). */
    public const LARGURAS = [17.88, 18.85, 22.14, 10.78, 11.17, 14.01, 21.91, 23.52, 22.07, 15.9, 26.21, 8.18, 43.74, 33.64, 18.33, 25.94, 29.31, 20.23, 14.39, 20.49, 29.33, 9.57, 19.39,
        27.76, 16.8, 53.62, 35.77, 34.45, 32.73, 21.36, 12.69, 13.92];

    private const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    /** Rubrica (pelo nome) → coluna do modelo; a primeira regra que bate ganha. */
    private const CLASSES = [
        'F' => '/(salario|vencimento|sal\.?)\s*base|^salario$|^vencimento$/',
        'S' => '/hora.*extra|h\.? ?extra|extraordinari/',
        'H' => '/aliment/',
        'I' => '/transport/',
        'J' => '/famil/',
        'K' => '/reembols|ajudas? de custo|despesas/',
        'O' => '/falha|quebra de caixa/',
        'P' => '/renda|habitac|alojament/',
        'Q' => '/rescis|indemniz/',
        'R' => '/ferias/',
        'T' => '/atavio|ataviament|fardament/',
        'U' => '/representac/',
        'V' => '/premio|bonus|bonificac|gratificac|comiss/',
        'W' => '/natal/',
    ];

    public function __construct(private readonly ServicoFolhaSalarial $folha, private readonly ContextoEmpresa $contexto) {}

    /** Classifica a rubrica de vencimento pelo nome; null = sem coluna própria. */
    public static function coluna(string $nome): ?string
    {
        $n = mb_strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome));
        $n = preg_replace('/[^a-z0-9. ]/', '', $n) ?? $n;
        foreach (self::CLASSES as $col => $re) {
            if (preg_match($re, $n)) {
                return $col;
            }
        }

        return null;
    }

    /**
     * Linhas do modelo (só trabalhadores por conta de outrem: os avençados, Grupo B, não entram no Mapa de Remunerações) e avisos.
     *
     * @return array{linhas: list<array<string, mixed>>, avisos: list<array<string, mixed>>, avencados_excluidos: int}
     */
    public function dados(PeriodoProcessamentoSalarial $p): array
    {
        if ($p->estado !== 'VALIDADO') {
            throw new ErroNegocio('O mapa no modelo da AGT só se exporta de processamentos validados.', 'PERIODO_NAO_VALIDADO', 422);
        }
        $res = $this->folha->resultados($p);
        usort($res, fn ($a, $b) => strcmp((string) $a['nome'], (string) $b['nome']));
        $colabs = Colaborador::query()->withTrashed()->whereIn('id', array_map(fn ($x) => (int) $x['colaborador_id'], $res) ?: [0])->get(['id', 'provincia', 'municipio'])->keyBy('id');
        $linhas = $avisos = [];
        $avencados = 0;
        foreach ($res as $r) {
            if (! empty($r['avencado'])) {
                $avencados++;

                continue;
            }
            $c = $colabs[(int) $r['colaborador_id']] ?? null;
            $linha = $this->linha($r, (string) ($c?->provincia ?? ''), (string) ($c?->municipio ?? ''));
            $linhas[] = $linha;
            $nome = (string) ($r['nome'] ?? '#'.$r['colaborador_id']);
            foreach ([['SEM_NIF', trim((string) $linha['A']) === '', 'NIF do trabalhador em falta'],
                ['SEM_NSS', trim((string) $linha['C']) === '', 'Nº de Segurança Social em falta'],
                ['SEM_LOCALIZACAO', trim($linha['D']) === '' || trim($linha['E']) === '', 'Província/município em falta']] as [$cod, $falta, $msg]) {
                if ($falta) {
                    $avisos[] = ['colaborador_id' => (int) $r['colaborador_id'], 'nome' => $nome, 'codigo' => $cod, 'mensagem' => $msg];
                }
            }
        }

        return ['linhas' => $linhas, 'avisos' => $avisos, 'avencados_excluidos' => $avencados];
    }

    /**
     * Uma linha do modelo a partir de um resultado da folha. Valores em string com 2 casas; Y e AD ficam como fórmulas no ficheiro
     * (calculadas aqui só para validação). Excesso/dedução (N): o que o motor tira à base do IRT além do INSS — isenção dos subsídios
     * (30 000 Kz, alimentação/transporte) e rubricas não tributáveis — ou seja N = Y − AC − base_irt (nunca negativo).
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function linha(array $r, string $provincia = '', string $municipio = ''): array
    {
        $soma = array_fill_keys(['F', 'H', 'I', 'J', 'K', 'L', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X'], '0');
        $faltas = '0';
        $classificado = '0';
        foreach ($r['rubricas'] ?? [] as $x) {
            $v = (string) ($x['valor'] ?? '0');
            if (($x['tipo'] ?? '') === 'DESCONTO') {
                if (! empty($x['falta']) && empty($x['de_isento'])) {
                    $faltas = bcadd($faltas, $v, 2);
                }

                continue;
            }
            if (($x['tipo'] ?? '') !== 'VENCIMENTO' || ! empty($x['informativa'])) {
                continue;
            }
            $col = self::coluna((string) ($x['nome'] ?? '')) ?? (! empty($x['isento_irt']) ? 'L' : null);
            if ($col !== null) {
                $soma[$col] = bcadd($soma[$col], $v, 2);
                $classificado = bcadd($classificado, $v, 2);
            }
        }
        // o que não tem coluna própria (e o arredondamento) vai para «Outros Subsídios Sujeitos»: Y fecha sempre com o bruto da folha
        $soma['X'] = bcsub((string) $r['bruto'], $classificado, 2);
        $y = bcsub((string) $r['bruto'], $faltas, 2);
        $reformado = ! empty($r['reformado']);
        $ac = $reformado ? '0.00' : bcadd((string) $r['inss_trabalhador'], '0', 2);
        $n = bcsub(bcsub($y, $ac, 2), (string) $r['base_irt'], 2);
        if (bccomp($n, '0', 2) < 0) {
            $n = '0.00';
        }
        $ad = bcsub(bcsub($y, $ac, 2), $n, 2);
        if (bccomp($ad, '0', 2) < 0) {
            $ad = '0.00';
        }

        return $soma + [
            'A' => (string) ($r['nif'] ?? ''), 'B' => (string) ($r['nome'] ?? ''), 'C' => (string) ($r['numero_inss'] ?? ''), 'D' => $provincia, 'E' => $municipio,
            'G' => $faltas, 'M' => 'N', 'N' => $n, 'Y' => $y, 'Z' => 'N',
            'AA' => $reformado ? '0.00' : bcadd((string) $r['base_inss'], '0', 2), 'AB' => $reformado ? 'S' : 'N', 'AC' => $ac, 'AD' => $ad, 'AE' => 'N',
            'AF' => bcadd((string) $r['irt'], '0', 2),
            'colaborador_id' => (int) $r['colaborador_id'],
        ];
    }

    /** Nome do ficheiro do modelo: «Mapa Remuneração AAAA - Mês.xlsx». */
    public static function nomeFicheiro(PeriodoProcessamentoSalarial $p): string
    {
        [$mes, $ano] = explode('/', $p->mes_ano);

        return sprintf('Mapa Remuneração %s - %s.xlsx', $ano, self::MESES[(int) $mes - 1]);
    }

    /** Período do cabeçalho tal como no modelo de referência: AAAA-M (mês sem zero à esquerda). */
    public static function periodo(PeriodoProcessamentoSalarial $p): string
    {
        [$mes, $ano] = explode('/', $p->mes_ano);

        return $ano.'-'.(int) $mes;
    }

    /** Neutraliza texto livre que o Excel interpretaria como fórmula (=, +, -, @, tab, CR). */
    public static function seguro(string $t): string
    {
        $t = trim($t);

        return $t !== '' && str_contains("=+-@\t\r", $t[0]) ? "'".$t : $t;
    }

    /** Gera o ficheiro .xlsx num temporário e devolve o caminho. */
    public function gerar(PeriodoProcessamentoSalarial $p): string
    {
        $d = $this->dados($p);
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $livro = new Spreadsheet;
        $f = $livro->getActiveSheet()->setTitle(self::FOLHA);
        $f->setCellValueExplicit('A1', self::seguro((string) $empresa->nif), DataType::TYPE_STRING)->setCellValue('B1', self::ROTULO_NIF);
        $f->setCellValueExplicit('A2', self::periodo($p), DataType::TYPE_STRING)->setCellValue('B2', self::ROTULO_PERIODO);
        $f->getStyle('A1:A2')->getFont()->setBold(true);
        $f->getStyle('A1:A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $f->getStyle('A3:AF4')->applyFromArray(['font' => ['bold' => true], 'fill' => ['fillType' => Fill::FILL_PATTERN_LIGHTTRELLIS],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $f->getRowDimension(4)->setRowHeight(30);
        foreach (self::CABECALHOS as $i => $h) {
            $col = $this->letra($i);
            $f->setCellValue($col.'4', $h);
            $f->getColumnDimension($col)->setWidth(self::LARGURAS[$i]);
        }
        $texto = ['A', 'B', 'C', 'D', 'E', 'M', 'Z', 'AB', 'AE'];
        $r = 5;
        foreach ($d['linhas'] as $l) {
            foreach (self::CABECALHOS as $i => $_) {
                $col = $this->letra($i);
                if ($col === 'Y') {
                    // fórmulas intencionais do modelo: o binder global do ERP trata «=» como texto, por isso gravam-se explicitamente
                    $f->setCellValueExplicit("Y{$r}", "=F{$r}-G{$r}+H{$r}+I{$r}+J{$r}+K{$r}+L{$r}+O{$r}+P{$r}+Q{$r}+R{$r}+S{$r}+T{$r}+U{$r}+V{$r}+W{$r}+X{$r}", DataType::TYPE_FORMULA);
                } elseif ($col === 'AD') {
                    $f->setCellValueExplicit("AD{$r}", "=MAX(0,Y{$r}-AC{$r}-N{$r})", DataType::TYPE_FORMULA);
                } elseif (in_array($col, $texto, true)) {
                    $f->setCellValueExplicit($col.$r, self::seguro((string) $l[$col]), DataType::TYPE_STRING);
                } else {
                    $f->setCellValueExplicit($col.$r, (float) $l[$col], DataType::TYPE_NUMERIC);
                }
            }
            $r++;
        }
        $caminho = tempnam(sys_get_temp_dir(), 'mapa_agt_');
        (new Xlsx($livro))->save($caminho);
        $livro->disconnectWorksheets();

        return $caminho;
    }

    private function letra(int $i): string
    {
        return $i < 26 ? chr(65 + $i) : 'A'.chr(65 + $i - 26);
    }
}
