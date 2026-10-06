<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigAssiduidade;
use Illuminate\Support\Facades\Auth;

/**
 * Configuração da assiduidade e calendário de dias úteis da empresa (js/modules/rh/assiduidade.js:26-75).
 * É a fonte ÚNICA dos dias úteis e feriados para a assiduidade, as ausências e as férias (ADR-038): no legado as
 * férias contavam segunda a sexta fixos e ignoravam os feriados e os dias úteis configurados.
 */
final class ServicoCalendarioRH
{
    public const MODOS_COMPENSACAO = ['DIA', 'MENSAL', 'LIMITE'];

    public const ARREDONDAMENTOS = [0, 5, 10, 15, 30, 60];

    /** Valores por omissão do legado (CONFIG_PADRAO). */
    public const PADRAO = ['dias_uteis' => [1, 2, 3, 4, 5], 'tolerancia_min' => 10, 'arredondamento_min' => 15, 'extras_min_minutos' => 15,
        'feriados' => [], 'relogio' => ['url' => '', 'formato' => 'CSV'], 'modo_compensacao' => 'DIA', 'limite_compensacao_h' => 0,
        'extra_nao_util_exige_autorizacao' => false];

    /** @var array<int, array<string, mixed>> */
    private array $cache = [];

    public function config(): array
    {
        $reg = ConfigAssiduidade::query()->orderBy('id')->first();
        $cfg = self::PADRAO;
        if ($reg) {
            foreach (array_keys(self::PADRAO) as $k) {
                if ($reg->{$k} !== null) {
                    $cfg[$k] = $reg->{$k};
                }
            }
            $cfg['relogio'] = array_merge(self::PADRAO['relogio'], (array) $cfg['relogio']);
        }
        $cfg['dias_uteis'] = array_values(array_map('intval', (array) $cfg['dias_uteis']));
        $cfg['feriados'] = array_values(array_map('strval', (array) $cfg['feriados']));
        $cfg['limite_compensacao_h'] = (float) $cfg['limite_compensacao_h'];

        return $cfg;
    }

    /** @param  array<string, mixed>  $d  dados validados */
    public function gravarConfig(array $d): array
    {
        if (($d['modo_compensacao'] ?? 'DIA') === 'LIMITE' && (float) ($d['limite_compensacao_h'] ?? 0) <= 0) {
            throw new ErroNegocio('No modo de compensação com limite, indique o limite de horas por mês.', 'LIMITE_EM_FALTA', 422);
        }
        $url = (string) ($d['relogio']['url'] ?? '');
        if ($url !== '' && (! preg_match('#^https?://#i', $url) || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PASS)
            || preg_match('/(token|senha|password|apikey|api_key)=/i', $url))) {
            throw new ErroNegocio('O endereço do relógio tem de ser http(s) e não pode conter credenciais.', 'URL_RELOGIO_INVALIDA', 422);
        }
        $d['dias_uteis'] = array_values(array_unique(array_map('intval', $d['dias_uteis'])));
        sort($d['dias_uteis']);
        $d['feriados'] = array_values(array_unique($d['feriados'] ?? []));
        sort($d['feriados']);
        ConfigAssiduidade::query()->updateOrCreate([], $d + ['atualizado_por' => Auth::user()?->nome_utilizador]);

        return $this->config();
    }

    public function diaUtil(string $dia, ?array $cfg = null): bool
    {
        $cfg ??= $this->config();

        return in_array((int) date('w', strtotime($dia.' 12:00:00')), $cfg['dias_uteis'], true) && ! in_array($dia, $cfg['feriados'], true);
    }

    /** @return list<string> dias (AAAA-MM-DD) entre as datas, inclusive */
    public static function dias(string $inicio, string $fim): array
    {
        $saida = [];
        for ($t = strtotime($inicio.' 12:00:00'), $f = strtotime($fim.' 12:00:00'); $t <= $f; $t += 86400) {
            $saida[] = date('Y-m-d', $t);
        }

        return $saida;
    }

    public function diasUteis(string $inicio, string $fim, ?array $cfg = null): int
    {
        $cfg ??= $this->config();

        return count(array_filter(self::dias($inicio, $fim), fn ($d) => $this->diaUtil($d, $cfg)));
    }

    public function proximoUtil(string $dia, array $cfg): string
    {
        $t = strtotime($dia.' 12:00:00');
        for ($i = 0; $i < 370; $i++) {
            $t += 86400;
            if ($this->diaUtil(date('Y-m-d', $t), $cfg)) {
                return date('Y-m-d', $t);
            }
        }

        return date('Y-m-d', $t);
    }

    /** 'AAAA-MM' a partir de 'AAAA-MM' ou 'MM/AAAA'. */
    public static function mes(string $v): string
    {
        if (preg_match('/^(\d{4})-(\d{1,2})$/', trim($v), $m) || preg_match('/^(\d{1,2})[\/\-.](\d{4})$/', trim($v), $n)) {
            [$ano, $mes] = isset($m[1]) ? [$m[1], $m[2]] : [$n[2], $n[1]];
            if ((int) $mes >= 1 && (int) $mes <= 12) {
                return sprintf('%04d-%02d', $ano, $mes);
            }
        }
        throw new ErroNegocio("Mês inválido ({$v}): use AAAA-MM ou MM/AAAA.", 'MES_INVALIDO', 422);
    }

    /**
     * Decisão 6 do utilizador (2026-10-06): feriados nacionais de Angola para pré-carregar na configuração (o utilizador
     * confirma e edita por empresa; nada é gravado sem ele). Lista da Lei dos Feriados Nacionais e Datas de Celebração
     * Nacional (Lei n.º 11/18, de 18 de Setembro, com as alterações posteriores) — CONFIRMAR antes de gravar, porque o
     * Governo pode decretar pontes e tolerâncias de ponto (não incluídas) e a lei pode ser alterada.
     * Móveis: Carnaval (terça-feira, 47 dias antes da Páscoa) e Sexta-feira Santa (2 dias antes da Páscoa).
     *
     * @return list<array{data: string, nome: string, movel: bool}>
     */
    public static function feriadosNacionais(int $ano): array
    {
        if ($ano < 1900 || $ano > 2200) {
            throw new ErroNegocio('Ano inválido.', 'ANO_INVALIDO', 422);
        }
        // Páscoa (algoritmo de Meeus/Jones/Butcher, calendário gregoriano)
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv($b - intdiv($b + 8, 25) + 1, 3) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        $pascoa = strtotime(sprintf('%04d-%02d-%02d 12:00:00', $ano, $mes, $dia));
        $fixos = [
            '01-01' => 'Dia do Ano Novo',
            '02-04' => 'Dia do Início da Luta Armada de Libertação Nacional',
            '03-08' => 'Dia Internacional da Mulher',
            '03-23' => 'Dia da Libertação da África Austral',
            '04-04' => 'Dia da Paz e da Reconciliação Nacional',
            '05-01' => 'Dia Internacional do Trabalhador',
            '09-17' => 'Dia do Fundador da Nação e do Herói Nacional',
            '11-02' => 'Dia dos Finados',
            '11-11' => 'Dia da Independência Nacional',
            '12-25' => 'Dia de Natal e da Família',
        ];
        $lista = array_map(fn ($md, $nome) => ['data' => "{$ano}-{$md}", 'nome' => $nome, 'movel' => false], array_keys($fixos), $fixos);
        $lista[] = ['data' => date('Y-m-d', $pascoa - 47 * 86400), 'nome' => 'Carnaval', 'movel' => true];
        $lista[] = ['data' => date('Y-m-d', $pascoa - 2 * 86400), 'nome' => 'Sexta-feira Santa', 'movel' => true];
        usort($lista, fn ($x, $y) => strcmp($x['data'], $y['data']));

        return $lista;
    }

    /** 'AAAA-MM' → 'MM/AAAA' (formato dos processamentos salariais). */
    public static function mesSalarial(string $mes): string
    {
        [$ano, $m] = explode('-', $mes);

        return "{$m}/{$ano}";
    }
}
