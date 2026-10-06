<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\Pdf\DocumentoPdf;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Texto\Extenso;
use ZipArchive;

/**
 * Recibos de vencimento em PDF gerados no servidor (lacuna A-10): o mesmo modelo do legado (getReciboVia/getReciboHTML,
 * js/app_v2.js:7623-7780) — duas vias por página (Original — Colaborador / Duplicado — Entidade Patronal, com linha de
 * corte), código e descrição das rubricas, remunerações e descontos, Segurança Social e IRT, totais, líquido por extenso,
 * forma de pagamento (banco e IBAN), assinaturas — e o ficheiro ZIP com um PDF por colaborador
 * (js/shared/janela_processo.js), sempre a partir da fotografia de um período validado.
 */
final class ServicoRecibosSalario
{
    private const COR = [15, 42, 74];

    private const NOMES = ['Sal. Base' => 'Salário Base', 'S. Alim' => 'Subsídio de Alimentação', 'S. Transp' => 'Subsídio de Transporte', 'S. férias' => 'Subsídio de Férias',
        'Adiant.' => 'Adiantamento', 'H. Extras' => 'Horas Extraordinárias', 'Desc. Falta' => 'Desconto por Faltas'];

    private const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    public function __construct(private readonly ServicoFolhaSalarial $folha, private readonly ContextoEmpresa $contexto) {}

    /** @return list<array<string, mixed>> resultados do período validado (todos ou os indicados) */
    public function resultados(PeriodoProcessamentoSalarial $p, ?array $colaboradores = null): array
    {
        if ($p->estado !== 'VALIDADO') {
            throw new ErroNegocio('Os recibos só se emitem de processamentos validados.', 'PERIODO_NAO_VALIDADO', 422);
        }
        $res = array_values(array_filter($this->folha->resultados($p), fn ($r) => $colaboradores === null || in_array((int) $r['colaborador_id'], $colaboradores, true)));
        if ($res === []) {
            throw new ErroNegocio('Nenhum recibo a emitir.', 'SEM_RECIBOS', 422);
        }
        usort($res, fn ($a, $b) => strcmp((string) $a['nome'], (string) $b['nome']));

        return $res;
    }

    /** PDF de um colaborador (uma página com as duas vias). */
    public function pdf(PeriodoProcessamentoSalarial $p, array $r, ?Empresa $empresa = null): string
    {
        $empresa ??= Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $doc = (new DocumentoPdf)->novaPagina();
        $this->via($doc, 8, $p, $r, $empresa, 'Original — Colaborador');
        $doc->linha(12, 148.5, 92, 148.5, 0.2, [148, 163, 184], true)->linha(118, 148.5, 198, 148.5, 0.2, [148, 163, 184], true)
            ->texto(105, 149.5, 'cortar pelo tracejado', 6.5, false, 'C', [148, 163, 184]);
        $this->via($doc, 154, $p, $r, $empresa, 'Duplicado — Entidade Patronal');

        return $doc->conteudo();
    }

    /** Nome do ficheiro PDF de um recibo: RV_AAAAMM_<nome>.pdf (sem acentos nem espaços). */
    public static function nomeFicheiro(PeriodoProcessamentoSalarial $p, array $r): string
    {
        [$mes, $ano] = explode('/', $p->mes_ano);
        $nome = preg_replace('/[^A-Za-z0-9]+/', '_', (string) (@iconv('UTF-8', 'ASCII//TRANSLIT', (string) $r['nome']) ?: $r['colaborador_id']));

        return sprintf('RV_%s%s_%04d_%s.pdf', $ano, $mes, $r['colaborador_id'], trim((string) $nome, '_'));
    }

    /** ZIP com um PDF por colaborador; devolve o caminho do ficheiro temporário. */
    public function zip(PeriodoProcessamentoSalarial $p, ?array $colaboradores = null): string
    {
        $res = $this->resultados($p, $colaboradores);
        $empresa = Empresa::query()->findOrFail($this->contexto->obrigatorio());
        $caminho = tempnam(sys_get_temp_dir(), 'recibos_');
        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::OVERWRITE) !== true) {
            throw new ErroNegocio('Não foi possível criar o ficheiro ZIP.', 'ZIP_FALHOU', 500);
        }
        foreach ($res as $r) {
            $zip->addFromString(self::nomeFicheiro($p, $r), $this->pdf($p, $r, $empresa));
        }
        $zip->close();

        return $caminho;
    }

    private function via(DocumentoPdf $d, float $y, PeriodoProcessamentoSalarial $p, array $r, Empresa $e, string $via): void
    {
        $num = fn ($v) => number_format((float) $v, 2, ',', ' ');
        [$mes, $ano] = explode('/', $p->mes_ano);
        $cinza = [100, 116, 139];
        $x0 = 10;
        $x1 = 200;
        $d->rectangulo($x0, $y, $x1 - $x0, 136, null, [203, 213, 225], 0.3);
        // cabeçalho
        $d->textoLimitado($x0 + 4, $y + 7, (string) $e->nome, 110, 11, true, 'E', self::COR);
        $d->textoLimitado($x0 + 4, $y + 11.5, 'NIF '.($e->nif ?: '—').($e->endereco ? ' · '.$e->endereco : ''), 110, 7, false, 'E', [71, 85, 105]);
        $contactos = implode(' · ', array_filter([$e->telefone, $e->email]));
        if ($contactos !== '') {
            $d->textoLimitado($x0 + 4, $y + 15, $contactos, 110, 7, false, 'E', [71, 85, 105]);
        }
        $d->texto($x1 - 4, $y + 7, 'RECIBO DE VENCIMENTO', 12, true, 'D', self::COR);
        $d->texto($x1 - 4, $y + 11.5, 'Período: '.self::MESES[(int) $mes - 1]." de {$ano}", 8, false, 'D', [51, 65, 85]);
        $d->texto($x1 - 4, $y + 15, sprintf('Recibo n.º %s%s-%04d · Emitido em %s', $ano, $mes, $r['colaborador_id'], now()->format('d/m/Y')), 7, false, 'D', $cinza);
        $d->rectangulo($x1 - 4 - $d->larguraTexto(mb_strtoupper($via), 6.5, true) - 4, $y + 16.8, $d->larguraTexto(mb_strtoupper($via), 6.5, true) + 4, 4, [224, 231, 255], null);
        $d->texto($x1 - 6, $y + 19.8, mb_strtoupper($via), 6.5, true, 'D', [30, 58, 138]);
        $d->rectangulo($x0 + 4, $y + 22.5, $x1 - $x0 - 8, 0.9, self::COR, null);
        // dados do colaborador
        $avencado = ! empty($r['avencado']);
        $regime = $avencado ? 'Prestador avençado (IRT Grupo B)' : (! empty($r['reformado']) ? 'Reformado' : 'Conta de outrem (IRT Grupo A)');
        $pagamento = ! empty($r['iban']) ? trim(($r['banco'] ?: 'Transferência bancária').' · IBAN '.$r['iban']) : 'Transferência bancária';
        $dias = (float) ($r['dias_contrato'] ?? 0) > 0 ? rtrim(rtrim(number_format((float) ($r['dias_trabalhados'] ?: $r['dias_contrato']), 2, ',', ''), '0'), ',')
            .' de '.rtrim(rtrim(number_format((float) $r['dias_contrato'], 2, ',', ''), '0'), ',') : '—';
        $campos = [
            [[$x0 + 4, 64, 'Colaborador', (string) $r['nome']], [$x0 + 68, 46, 'Função', (string) ($r['funcao'] ?? '—')], [$x0 + 114, 36, 'NIF', (string) ($r['nif'] ?? '—')],
                [$x0 + 150, 36, 'N.º Segurança Social', (string) ($r['numero_inss'] ?? '—')]],
            [[$x0 + 4, 64, 'Regime fiscal', $regime], [$x0 + 68, 46, 'Dias trabalhados', $dias], [$x0 + 114, 72, 'Forma de pagamento', $pagamento]],
        ];
        $yy = $y + 25;
        foreach ($campos as $linha) {
            foreach ($linha as [$cx, $cl, $tit, $val]) {
                $d->rectangulo($cx, $yy, $cl, 9, null, [203, 213, 225]);
                $d->texto($cx + 1.5, $yy + 3, mb_strtoupper($tit), 5.5, true, 'E', $cinza);
                $d->textoLimitado($cx + 1.5, $yy + 7.2, $val !== '' ? $val : '—', $cl - 3, 7.5, true, 'E', [15, 23, 42]);
            }
            $yy += 9;
        }
        // rubricas
        $linhas = [];
        foreach ($r['rubricas'] ?? [] as $x) {
            if ((float) ($x['valor'] ?? 0) == 0.0 || ! empty($x['informativa'])) {
                continue;
            }
            $linhas[] = ['cod' => isset($x['infotipo_id']) ? str_pad((string) $x['infotipo_id'], 3, '0', STR_PAD_LEFT) : '', 'nome' => self::NOMES[$x['nome']] ?? (string) $x['nome'],
                'v' => $x['tipo'] === 'VENCIMENTO' ? (float) $x['valor'] : null, 'd' => $x['tipo'] === 'DESCONTO' ? (float) $x['valor'] : null];
        }
        $e1 = (float) ($e->taxa_inss_trabalhador ?? 3);
        if ((float) $r['inss_trabalhador'] > 0) {
            $linhas[] = ['cod' => 'SS', 'nome' => 'Segurança Social — INSS ('.rtrim(rtrim(number_format($e1, 2, ',', ''), '0'), ',').'%)', 'v' => null, 'd' => (float) $r['inss_trabalhador']];
        }
        if ((float) $r['irt'] > 0) {
            $linhas[] = ['cod' => 'IRT', 'nome' => $avencado ? 'Retenção na fonte — IRT Grupo B (6,5%)' : 'Retenção na fonte — IRT Grupo A', 'v' => null, 'd' => (float) $r['irt']];
        }
        $yy += 2;
        $d->rectangulo($x0 + 4, $yy, $x1 - $x0 - 8, 5, self::COR, null);
        foreach ([[$x0 + 6, 'CÓD.', 'E'], [$x0 + 22, 'DESCRIÇÃO', 'E'], [$x0 + 145, 'REMUNERAÇÕES', 'D'], [$x1 - 6, 'DESCONTOS', 'D']] as [$cx, $t, $al]) {
            $d->texto($cx, $yy + 3.5, $t, 6, true, $al, [255, 255, 255]);
        }
        $yy += 5;
        $maximo = 12;
        $altura = count($linhas) > $maximo ? max(2.6, 48 / count($linhas)) : 4.2;
        $fonte = $altura < 4 ? 6.5 : 7.5;
        foreach ($linhas as $i => $l) {
            if ($i % 2) {
                $d->rectangulo($x0 + 4, $yy, $x1 - $x0 - 8, $altura, [248, 250, 252], null);
            }
            $base = $yy + $altura * 0.72;
            $d->texto($x0 + 6, $base, $l['cod'], $fonte - 0.5, false, 'E', $cinza);
            $d->textoLimitado($x0 + 22, $base, $l['nome'], 95, $fonte);
            if ($l['v'] !== null) {
                $d->texto($x0 + 145, $base, $num($l['v']), $fonte, false, 'D');
            }
            if ($l['d'] !== null) {
                $d->texto($x1 - 6, $base, $num($l['d']), $fonte, false, 'D', [185, 28, 28]);
            }
            $yy += $altura;
        }
        if ($linhas === []) {
            $d->texto(105, $yy + 3.5, 'Sem rubricas processadas', 7.5, false, 'C', [148, 163, 184]);
            $yy += 5;
        }
        $d->linha($x0 + 4, $yy, $x1 - 4, $yy, 0.2, [203, 213, 225]);
        // totais
        $tv = array_sum(array_map(fn ($l) => (float) $l['v'], $linhas));
        $td = array_sum(array_map(fn ($l) => (float) $l['d'], $linhas));
        $yt = max($yy + 2, $y + 101);
        foreach ([[$x0 + 4, 56, 'TOTAL DE REMUNERAÇÕES', $tv, false], [$x0 + 62, 56, 'TOTAL DE DESCONTOS', $td, false], [$x0 + 120, $x1 - $x0 - 124, 'LÍQUIDO A RECEBER', (float) $r['liquido'], true]] as [$cx, $cl, $t, $v, $dest]) {
            $d->rectangulo($cx, $yt, $cl, 10, $dest ? self::COR : [248, 250, 252], $dest ? self::COR : [203, 213, 225]);
            $d->texto($cx + 2, $yt + 3.3, $t, 5.5, true, 'E', $dest ? [203, 213, 225] : $cinza);
            $d->texto($cx + 2, $yt + 8.3, $num($v).' Kz', $dest ? 11 : 9, true, 'E', $dest ? [255, 255, 255] : [15, 23, 42]);
        }
        $d->textoLimitado($x0 + 4, $yt + 14, 'Importância líquida: '.Extenso::kwanzas((float) $r['liquido']), $x1 - $x0 - 8, 7, false, 'E', [51, 65, 85]);
        $info = [];
        if (! $avencado && (float) $r['base_inss'] > 0) {
            $info[] = 'Base de incidência INSS: '.$num($r['base_inss']).' Kz';
        }
        if ((float) $r['inss_patronal'] > 0) {
            $info[] = 'INSS a cargo da entidade patronal ('.rtrim(rtrim(number_format((float) ($e->taxa_inss_patronal ?? 8), 2, ',', ''), '0'), ',').'%): '.$num($r['inss_patronal']).' Kz';
        }
        if (! $avencado && (float) $r['base_irt'] > 0) {
            $info[] = 'Matéria colectável IRT: '.$num($r['base_irt']).' Kz';
        }
        if ($info) {
            $d->textoLimitado($x0 + 4, $yt + 17.5, implode('  ·  ', $info), $x1 - $x0 - 8, 6.5, false, 'E', $cinza);
        }
        // assinaturas
        $ya = $y + 129;
        $d->linha($x0 + 10, $ya, $x0 + 80, $ya, 0.2, [51, 65, 85])->texto($x0 + 45, $ya + 3, 'A Entidade Patronal', 7, false, 'C', [51, 65, 85]);
        $d->texto($x0 + 110, $ya - 6, 'Declaro ter recebido a importância líquida acima indicada.', 6, false, 'E', $cinza);
        $d->linha($x0 + 110, $ya, $x1 - 10, $ya, 0.2, [51, 65, 85])->textoLimitado(($x0 + 110 + $x1 - 10) / 2, $ya + 3, 'O Colaborador — '.$r['nome'], 70, 7, false, 'C', [51, 65, 85]);
    }
}
