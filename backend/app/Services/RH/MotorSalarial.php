<?php

namespace App\Services\RH;

/**
 * Motor de cálculo salarial (Angola) — porta de js/engine_v2.js, js/modules/rh/regras_salariais.js e
 * calculatePeriodData (js/app_v2.js:5787-6016), em decimal exacto (bcmath) e sem acesso à base de dados.
 *
 * Dois modos:
 *   LEGADO — reproduz o motor antigo (períodos migrados e verificação de não-regressão contra o diário):
 *            isenção de 30 000 Kz pelo NOME da rubrica (alimentação/transporte) e sobre o valor CHEIO, flag irt ignorada,
 *            INSS calculado antes de limitar a base a ≥ 0, avençado sobre o bruto, arredondamento só no fim.
 *   ATUAL  — as mesmas fórmulas com as correcções decididas (ADR-014/017/036):
 *            isenção pela flag infotipos.irt = 'conditional_30k' e sobre o valor efectivamente pago (após faltas);
 *            vencimentos com irt = false fora da base de IRT; base INSS nunca negativa; avençado sobre o valor pago
 *            (bruto − faltas); taxas de INSS da empresa (3 % / 8 % por omissão); cada componente arredondado a 2 casas
 *            e líquido = bruto − INSS − IRT − descontos exacto ao cêntimo (o diário fecha sem "ROUNDING_DIFF").
 *
 * Tabela de IRT: a de js/engine_v2.js (decisão do utilizador, 2026-09-29): IRT = fixo + (base − excesso) × taxa.
 */
final class MotorSalarial
{
    public const TABELA_IRT = [
        ['max' => '150000', 'taxa' => '0', 'fixo' => '0', 'excesso' => '0'],
        ['max' => '200000', 'taxa' => '0.16', 'fixo' => '12500', 'excesso' => '150000'],
        ['max' => '300000', 'taxa' => '0.18', 'fixo' => '31250', 'excesso' => '200000'],
        ['max' => '500000', 'taxa' => '0.19', 'fixo' => '49250', 'excesso' => '300000'],
        ['max' => '1000000', 'taxa' => '0.20', 'fixo' => '87250', 'excesso' => '500000'],
        ['max' => '1500000', 'taxa' => '0.21', 'fixo' => '187250', 'excesso' => '1000000'],
        ['max' => '2000000', 'taxa' => '0.22', 'fixo' => '292250', 'excesso' => '1500000'],
        ['max' => '2500000', 'taxa' => '0.23', 'fixo' => '402250', 'excesso' => '2000000'],
        ['max' => '5000000', 'taxa' => '0.24', 'fixo' => '517250', 'excesso' => '2500000'],
        ['max' => '10000000', 'taxa' => '0.245', 'fixo' => '1117250', 'excesso' => '5000000'],
        ['max' => null, 'taxa' => '0.25', 'fixo' => '2342250', 'excesso' => '10000000'],
    ];

    private const E = 10;   // escala interna

    /**
     * @param  array{id: int, avencado?: bool, reformado?: bool, dias_contrato: float|string, contrato?: ?array{dias: float|string, horas: float|string,
     *               remuneracoes: list<array{infotipo_id: int, valor_mes?: mixed, valor_dia?: mixed}>}, lancamentos: list<array{infotipo_id: int, valor: mixed,
     *               dias_trabalhados?: mixed, horas?: mixed}>}  $c
     * @param  array<int, array{id: int, tipo: string, nome: string, inss: bool, irt: mixed, base_horaria?: bool, calculo_horas?: ?string}>  $infotipos
     * @param  array{modo?: string, inss_trabalhador?: string, inss_patronal?: string, he?: array{p1: float, limite: float, p2: float}, taxa_avencado?: string, limite_isencao?: string}  $cfg
     */
    public static function calcular(array $c, array $infotipos, array $cfg = []): array
    {
        $atual = ($cfg['modo'] ?? 'ATUAL') === 'ATUAL';
        $taxaE = bcdiv((string) ($cfg['inss_trabalhador'] ?? '3'), '100', self::E);
        $taxaP = bcdiv((string) ($cfg['inss_patronal'] ?? '8'), '100', self::E);
        $limite = (string) ($cfg['limite_isencao'] ?? '30000');
        $r = fn (string $v) => $atual ? self::arred($v) : $v;   // no modo LEGADO só se arredonda no fim

        $dc = self::n($c['dias_contrato'] ?? 0);
        $bruto = $descontos = $baseInss = '0';
        $subsidios = [];      // categoria => valor pago (base da isenção de 30 000 Kz)
        $rubricas = [];
        $avisos = [];
        $diasTrab = '0';
        $horas = ['extra' => '0', 'falta' => '0', 'infoExtra' => null, 'infoFalta' => null];

        foreach ($c['lancamentos'] as $l) {
            $info = $infotipos[$l['infotipo_id']] ?? null;
            if (isset($l['dias_trabalhados']) && $l['dias_trabalhados'] !== null && bccomp(self::n($l['dias_trabalhados']), $diasTrab, 4) > 0) {
                $diasTrab = self::n($l['dias_trabalhados']);
            }
            if (! $info) {
                continue;
            }
            $tipoHoras = self::tipoHoras($info);
            if ($tipoHoras && isset($l['horas']) && $l['horas'] !== null && $l['horas'] !== '') {
                $k = $tipoHoras === 'EXTRA' ? 'extra' : 'falta';
                $horas[$k] = bcadd($horas[$k], self::n($l['horas']), 4);
                $horas[$k === 'extra' ? 'infoExtra' : 'infoFalta'] ??= $info;

                continue;
            }
            $v = self::n($l['valor']);
            $vencimento = $info['tipo'] === 'VENCIMENTO';
            $tributavel = ! $atual || ! self::irtFalso($info);
            $wd = isset($l['dias_trabalhados']) && $l['dias_trabalhados'] !== null ? self::n($l['dias_trabalhados']) : null;

            if ($vencimento && $wd !== null && bccomp($dc, '0', 4) > 0) {
                $v = $r($v);
                $iBase = count($rubricas);
                $rubricas[] = self::rubrica($info, $v);
                $bruto = bcadd($bruto, $v, self::E);
                if ($info['inss']) {
                    $baseInss = bcadd($baseInss, $v, self::E);
                }
                if (bccomp($wd, $dc, 4) > 0) {
                    $extra = $r(bcmul(bcdiv($v, $dc, self::E), bcsub($wd, $dc, 4), self::E));
                    $rubricas[] = ['nome' => 'H. Extras', 'infotipo_id' => $info['id'], 'tipo' => 'VENCIMENTO', 'valor' => $extra];
                    $bruto = bcadd($bruto, $extra, self::E);
                    if ($info['inss']) {
                        $baseInss = bcadd($baseInss, $extra, self::E);
                    }
                    // legado: só pelo nome EXACTO nesta via
                    self::somarSubsidio($subsidios, $info, bcadd($v, $extra, self::E), $atual, exato: true);
                } elseif (bccomp($wd, $dc, 4) < 0) {
                    $falta = $r(bcmul(bcdiv($v, $dc, self::E), bcsub($dc, $wd, 4), self::E));
                    $rubricas[] = ['nome' => 'Desc. Falta', 'infotipo_id' => $info['id'], 'tipo' => 'DESCONTO', 'valor' => $falta, 'falta' => true, 'origem' => 'PRO_RATA'];
                    $descontos = bcadd($descontos, $falta, self::E);
                    if ($info['inss']) {
                        $baseInss = bcsub($baseInss, $falta, self::E);
                    }
                    // legado: isenção sobre o valor CHEIO; actual: sobre o valor pago
                    self::somarSubsidio($subsidios, $info, $atual ? bcsub($v, $falta, self::E) : $v, $atual);
                } else {
                    self::somarSubsidio($subsidios, $info, $v, $atual);
                }
                if ($atual && self::irtFalso($info)) {
                    // rubrica não tributável: fica fora da base; a sua falta pro-rata não a reduz outra vez
                    $rubricas[$iBase]['isento_irt'] = true;
                    for ($k = $iBase + 1; $k < count($rubricas); $k++) {
                        $rubricas[$k]['de_isento'] = true;
                        if ($rubricas[$k]['tipo'] === 'VENCIMENTO') {
                            $rubricas[$k]['isento_irt'] = true;
                        }
                    }
                }

                continue;
            }

            $v = $r($v);
            $faltaManual = str_contains(mb_strtolower($info['nome']), 'falta') || ($info['calculo_horas'] ?? null) === 'FALTA';
            $rub = self::rubrica($info, $v) + ($info['tipo'] === 'DESCONTO' && $faltaManual ? ['falta' => true] : []);
            if ($vencimento) {
                $bruto = bcadd($bruto, $v, self::E);
                if ($info['inss']) {
                    $baseInss = bcadd($baseInss, $v, self::E);
                }
                self::somarSubsidio($subsidios, $info, $v, $atual);
                if (! $tributavel) {
                    $rub['isento_irt'] = true;
                }
            } elseif ($info['tipo'] === 'DESCONTO') {
                $descontos = bcadd($descontos, $v, self::E);
                if ($faltaManual) {
                    $baseInss = bcsub($baseInss, $v, self::E);
                    if (bccomp($baseInss, '0', self::E) < 0) {
                        $baseInss = '0';
                    }
                }
            } else {
                $rub['informativa'] = true;   // OUTROS: não entra no bruto, nos descontos nem no diário (o legado lançava-a a crédito)
            }
            $rubricas[] = $rub;
        }

        // horas extra (escalões) e faltas por hora, valorizadas pelo valor hora do contrato
        if (bccomp($horas['extra'], '0', 4) > 0 || bccomp($horas['falta'], '0', 4) > 0) {
            $ref = self::referencia($c['contrato'] ?? null, $infotipos);
            if ($ref['aviso']) {
                $avisos[] = $ref['aviso'];
            }
            $he = $cfg['he'] ?? ['p1' => 50, 'limite' => 30, 'p2' => 75];
            if (bccomp($horas['extra'], '0', 4) > 0) {
                $h1 = bccomp($horas['extra'], self::n($he['limite']), 4) < 0 ? $horas['extra'] : self::n($he['limite']);
                $h2 = bcsub($horas['extra'], $h1, 4);
                $valor = self::arred(bcadd(bcmul(bcmul($h1, $ref['hora'], self::E), bcadd('1', bcdiv(self::n($he['p1']), '100', self::E), self::E), self::E),
                    bccomp($h2, '0', 4) > 0 ? bcmul(bcmul($h2, $ref['hora'], self::E), bcadd('1', bcdiv(self::n($he['p2']), '100', self::E), self::E), self::E) : '0', self::E));
                $rubricas[] = ['nome' => 'H. Extras', 'infotipo_id' => $horas['infoExtra']['id'], 'tipo' => 'VENCIMENTO', 'valor' => $valor, 'horas' => $horas['extra']];
                $bruto = bcadd($bruto, $valor, self::E);
                if ($horas['infoExtra']['inss']) {
                    $baseInss = bcadd($baseInss, $valor, self::E);
                }
            }
            if (bccomp($horas['falta'], '0', 4) > 0) {
                $vf = self::arred(bcmul($horas['falta'], $ref['hora'], self::E));
                $rubricas[] = ['nome' => 'Desc. Falta', 'infotipo_id' => $horas['infoFalta']['id'], 'tipo' => 'DESCONTO', 'valor' => $vf, 'falta' => true, 'horas' => $horas['falta']];
                $descontos = bcadd($descontos, $vf, self::E);
                $baseInss = bcsub($baseInss, $vf, self::E);
                if (bccomp($baseInss, '0', self::E) < 0) {
                    $baseInss = '0';
                }
            }
        }

        $faltas = array_reduce(array_filter($rubricas, fn ($x) => ! empty($x['falta']) && empty($x['de_isento'])), fn ($s, $x) => bcadd($s, $x['valor'], self::E), '0');
        $naoTributavel = $atual ? array_reduce(array_filter($rubricas, fn ($x) => ! empty($x['isento_irt'])), fn ($s, $x) => bcadd($s, $x['valor'], self::E), '0') : '0';

        if (! empty($c['avencado'])) {
            // avençado: sem INSS, IRT 6,5 % (legado: sobre o bruto; actual: sobre o valor pago)
            $inssE = $inssP = '0';
            $isencoes = '0';
            $baseIrt = $atual ? bcsub(bcsub($bruto, $faltas, self::E), $naoTributavel, self::E) : $bruto;
            if (bccomp($baseIrt, '0', self::E) < 0) {
                $baseIrt = '0';
            }
            $irt = bcmul($baseIrt, bcdiv((string) ($cfg['taxa_avencado'] ?? '6.5'), '100', self::E), self::E);
        } else {
            if ($atual && bccomp($baseInss, '0', self::E) < 0) {
                $baseInss = '0';
            }
            $reformado = ! empty($c['reformado']);
            $inssE = $reformado ? '0' : bcmul($baseInss, $taxaE, self::E);
            $inssP = $reformado ? '0' : bcmul($baseInss, $taxaP, self::E);
            if (bccomp($baseInss, '0', self::E) < 0) {
                $baseInss = '0';   // legado: limita depois de calcular o INSS
            }
            if ($atual) {
                $inssE = self::arred($inssE);
                $inssP = self::arred($inssP);
            }
            $isencoes = '0';
            foreach ($subsidios as $valor) {
                $isencoes = bcadd($isencoes, bccomp($valor, $limite, self::E) < 0 ? $valor : $limite, self::E);
            }
            $baseIrt = bcsub(bcsub(bcsub(bcsub($bruto, $inssE, self::E), $isencoes, self::E), $faltas, self::E), $naoTributavel, self::E);
            if (bccomp($baseIrt, '0', self::E) < 0) {
                $baseIrt = '0';
            }
            $irt = self::irt($baseIrt);
            $isencoes = bcadd($isencoes, $faltas, self::E);
        }
        if ($atual) {
            $irt = self::arred($irt);
        }
        $liquido = bcsub(bcsub(bcsub($bruto, $inssE, self::E), $irt, self::E), $descontos, self::E);

        return [
            'colaborador_id' => $c['id'], 'avencado' => ! empty($c['avencado']), 'reformado' => ! empty($c['reformado']),
            'dias_contrato' => self::arred($dc), 'dias_trabalhados' => self::arred($diasTrab),
            'bruto' => self::arred($bruto), 'base_inss' => self::arred($baseInss), 'inss_trabalhador' => self::arred($inssE), 'inss_patronal' => self::arred($inssP),
            'isencoes' => self::arred($isencoes), 'base_irt' => self::arred($baseIrt), 'irt' => self::arred($irt), 'descontos' => self::arred($descontos),
            'liquido' => self::arred($liquido),
            'rubricas' => array_map(fn ($x) => ['valor' => self::arred($x['valor'])] + $x, $rubricas), 'avisos' => $avisos, 'modo_calculo' => $atual ? 'ATUAL' : 'LEGADO',
        ];
    }

    /** IRT pela tabela: fixo + (base − excesso) × taxa, no primeiro escalão com base ≤ máximo. */
    public static function irt(string $base): string
    {
        if (bccomp($base, '0', self::E) <= 0) {
            return '0';
        }
        foreach (self::TABELA_IRT as $e) {
            if ($e['max'] === null || bccomp($base, $e['max'], self::E) <= 0) {
                return bcadd($e['fixo'], bcmul(bcsub($base, $e['excesso'], self::E), $e['taxa'], self::E), self::E);
            }
        }

        return '0';
    }

    /** Valor mensal de uma remuneração do contrato (valor_mes, ou valor_dia × dias do contrato). */
    public static function mensal(array $rem, ?array $contrato): string
    {
        if (isset($rem['valor_mes']) && $rem['valor_mes'] !== null && $rem['valor_mes'] !== '') {
            return self::n($rem['valor_mes']);
        }

        return bcmul(self::n($rem['valor_dia'] ?? 0), self::diasContrato($contrato), self::E);
    }

    public static function diasContrato(?array $contrato): string
    {
        return $contrato && (float) ($contrato['dias'] ?? 0) > 0 ? self::n($contrato['dias']) : '22';
    }

    public static function arred(string $v): string
    {
        return bccomp($v, '0', self::E) >= 0 ? bcadd($v, '0.005', 2) : bcsub($v, '0.005', 2);
    }

    /** Rubrica por horas? EXTRA/FALTA forçado em calculo_horas ou pelo nome (seed "Horas Extras" / "Falta …"). */
    public static function tipoHoras(array $info): ?string
    {
        $c = $info['calculo_horas'] ?? null;
        if ($c === 'EXTRA' || $c === 'FALTA') {
            return $c;
        }
        if ($c === 'NAO') {
            return null;
        }
        $n = mb_strtolower($info['nome']);
        if ($info['tipo'] === 'VENCIMENTO' && str_contains($n, 'hora') && str_contains($n, 'extra')) {
            return 'EXTRA';
        }

        return $info['tipo'] === 'DESCONTO' && str_contains($n, 'falta') ? 'FALTA' : null;
    }

    /** @return array{dia: string, hora: string, aviso: ?string} */
    private static function referencia(?array $contrato, array $infotipos): array
    {
        if (! $contrato) {
            return ['dia' => '0', 'hora' => '0', 'aviso' => 'Colaborador sem contrato activo: faltas e horas extra por hora não podem ser valorizadas.'];
        }
        $rem = $contrato['remuneracoes'] ?? [];
        $marcadas = array_filter($rem, fn ($x) => ! empty($infotipos[$x['infotipo_id']]['base_horaria']));
        $aviso = null;
        if (! $marcadas) {
            $marcadas = array_filter($rem, fn ($x) => isset($infotipos[$x['infotipo_id']]) && str_contains(mb_strtolower($infotipos[$x['infotipo_id']]['nome']), 'base'));
            $aviso = $marcadas ? 'Nenhuma rubrica do contrato está marcada para o valor dia/hora: usado o salário base.'
                : 'O contrato não tem salário base nem rubricas marcadas para o valor dia/hora.';
        }
        $mensal = array_reduce($marcadas, fn ($s, $x) => bcadd($s, self::mensal($x, $contrato), self::E), '0');
        $dia = bcdiv($mensal, self::diasContrato($contrato), self::E);
        $horasDia = (float) ($contrato['horas'] ?? 0) > 0 ? self::n($contrato['horas']) : '8';

        return ['dia' => $dia, 'hora' => bcdiv($dia, $horasDia, self::E), 'aviso' => $aviso];
    }

    /** Soma à base da isenção de 30 000 Kz (legado: pelo nome; actual: pela flag conditional_30k). */
    private static function somarSubsidio(array &$subsidios, array $info, string $valor, bool $atual, bool $exato = false): void
    {
        $nome = mb_strtolower(self::semAcentos($info['nome']));
        if ($atual) {
            if (($info['irt'] ?? null) !== 'conditional_30k') {
                return;
            }
            $k = str_contains($nome, 'transporte') ? 'TRANSPORTE' : (str_contains($nome, 'alimenta') ? 'ALIMENTACAO' : 'IT'.$info['id']);
        } elseif ($exato) {
            $k = $info['nome'] === 'Subsídio de alimentação' ? 'ALIMENTACAO' : ($info['nome'] === 'Subsídio de transporte' ? 'TRANSPORTE' : null);
        } else {
            $k = str_contains($nome, 'alimenta') ? 'ALIMENTACAO' : (str_contains($nome, 'transporte') ? 'TRANSPORTE' : null);
        }
        if ($k !== null) {
            $subsidios[$k] = bcadd($subsidios[$k] ?? '0', $valor, self::E);
        }
    }

    private static function irtFalso(array $info): bool
    {
        return $info['irt'] === false || $info['irt'] === 'false' || $info['irt'] === '0' || $info['irt'] === 0;
    }

    private static function rubrica(array $info, string $v): array
    {
        return ['nome' => $info['nome'], 'infotipo_id' => $info['id'], 'tipo' => $info['tipo'], 'valor' => $v];
    }

    private static function semAcentos(string $s): string
    {
        return (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }

    private static function n(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }

        return is_string($v) && is_numeric($v) ? $v : number_format((float) $v, 8, '.', '');
    }
}
