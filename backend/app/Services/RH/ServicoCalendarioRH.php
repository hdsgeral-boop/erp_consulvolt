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

    /** 'AAAA-MM' → 'MM/AAAA' (formato dos processamentos salariais). */
    public static function mesSalarial(string $mes): string
    {
        [$ano, $m] = explode('-', $mes);

        return "{$m}/{$ano}";
    }
}
