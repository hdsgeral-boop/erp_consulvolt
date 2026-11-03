<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoRH;
use App\Models\InfotipoSalarial;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Configuração de RH por empresa (tabela configuracoes_rh), com os valores por omissão das decisões do utilizador
 * (2026-10-06):
 *   - decisão 5: segregação de funções nos salários — quem encerra o cálculo não o pode validar. Desligada por omissão;
 *   - decisão 7: férias segundo a Lei Geral do Trabalho de Angola (Lei n.º 12/23, de 27 de Dezembro):
 *       · direito anual de 22 dias úteis (o direito gravado no plano de férias prevalece, como no legado);
 *       · no ano de admissão, 2 dias úteis por cada mês completo de serviço até 31/12, sem exceder os 22 dias;
 *         o gozo só é permitido depois de 6 meses completos de serviço (aviso, não bloqueio, para o RH decidir);
 *       · transporte do saldo não gozado do ano anterior para o ano seguinte, até ao limite configurado
 *         (por omissão 22 dias = um ano de direito; o saldo de anos mais antigos não se acumula para lá do limite).
 *     Os parâmetros são configuráveis por empresa porque a lei e os acordos colectivos podem fixar regras diferentes.
 * Fecho da Versão Actual (#8, minuta do contrato): percentagens do subsídio de férias e do subsídio de Natal sobre o salário
 * base (art. 238.º/1 da Lei n.º 12/23: mínimo legal de 50 %; legado companies.subsidio_ferias_pct/subsidio_natal_pct).
 * Fase K (ADR-079), multas de entregáveis na folha: `infotipo_multa_entregavel_id` (rubrica de DESCONTO onde se lançam; sem ela não se
 * lança nada) e `multas_entregavel_max_pct` (tecto opcional, % do salário base do contrato vigente que as multas de um colaborador
 * podem descontar num período; 0 = sem tecto). Descontos por penalização pecuniária podem estar limitados pela Lei Geral do
 * Trabalho: validar juridicamente antes de activar.
 */
final class ServicoConfiguracaoRH
{
    public const PADRAO = [
        'segregar_encerrar_validar' => false,
        'ferias_dias_mes_admissao' => 2,
        'ferias_meses_minimos_gozo' => 6,
        'ferias_transporte_saldo' => true,
        'ferias_transporte_max_dias' => 22,
        'subsidio_ferias_pct' => 50,
        'subsidio_natal_pct' => 50,
        'multas_entregavel_max_pct' => 0,
        'infotipo_multa_entregavel_id' => null,
    ];

    /** Mínimo legal dos subsídios de férias e de Natal (art. 238.º/1 da Lei n.º 12/23) e um tecto de sanidade. */
    public const SUBSIDIO_MINIMO_PCT = 50;

    public const SUBSIDIO_MAXIMO_PCT = 300;

    /** @return array<string, mixed> */
    public function config(): array
    {
        $cfg = self::PADRAO;
        foreach (ConfiguracaoRH::query()->whereIn('chave', array_keys(self::PADRAO))->get() as $r) {
            if ($r->valor !== null) {
                $cfg[$r->chave] = is_bool(self::PADRAO[$r->chave]) ? (bool) $r->valor : (int) $r->valor;
            }
        }
        // sem rubrica configurada (0 gravado) a configuração devolve null
        $cfg['infotipo_multa_entregavel_id'] = $cfg['infotipo_multa_entregavel_id'] ? (int) $cfg['infotipo_multa_entregavel_id'] : null;
        $cfg['multas_entregavel_max_pct'] = max(0, min(100, (int) $cfg['multas_entregavel_max_pct']));
        // a minuta nunca promete menos do que a lei (valores antigos ou gravados à mão abaixo do mínimo)
        foreach (['subsidio_ferias_pct', 'subsidio_natal_pct'] as $k) {
            $cfg[$k] = max(self::SUBSIDIO_MINIMO_PCT, (int) $cfg[$k]);
        }

        return $cfg;
    }

    public function valor(string $chave): mixed
    {
        return $this->config()[$chave] ?? throw new ErroNegocio("Configuração de RH desconhecida: {$chave}.", 'CONFIG_DESCONHECIDA', 422);
    }

    /** @param  array<string, mixed>  $d  só as chaves a alterar */
    public function gravar(array $d): array
    {
        DB::transaction(function () use ($d) {
            foreach ($d as $chave => $valor) {
                if (! array_key_exists($chave, self::PADRAO)) {
                    throw new ErroNegocio("Configuração de RH desconhecida: {$chave}.", 'CONFIG_DESCONHECIDA', 422);
                }
                $valor = is_bool(self::PADRAO[$chave]) ? (bool) $valor : (int) $valor;
                if ($chave === 'infotipo_multa_entregavel_id' && $valor > 0 && InfotipoSalarial::query()->find($valor)?->tipo !== 'DESCONTO') {
                    throw new ErroNegocio('A rubrica das multas de entregáveis tem de ser uma rubrica do tipo DESCONTO.', 'RUBRICA_NAO_DESCONTO', 422);
                }
                if ($chave === 'multas_entregavel_max_pct' && $valor > 100) {
                    throw new ErroNegocio('O tecto das multas é uma percentagem entre 0 e 100 do salário base.', 'DADOS_INVALIDOS', 422);
                }
                if (! is_bool($valor) && $valor < 0) {
                    throw new ErroNegocio('Os valores das férias não podem ser negativos.', 'DADOS_INVALIDOS', 422);
                }
                if (str_starts_with($chave, 'subsidio_') && ($valor < self::SUBSIDIO_MINIMO_PCT || $valor > self::SUBSIDIO_MAXIMO_PCT)) {
                    throw new ErroNegocio('Os subsídios de férias e de Natal não podem ser inferiores a '.self::SUBSIDIO_MINIMO_PCT.' % do salário base (art. 238.º, n.º 1, da Lei n.º 12/23) nem superiores a '
                        .self::SUBSIDIO_MAXIMO_PCT.' %.', 'SUBSIDIO_ABAIXO_DO_MINIMO', 422);
                }
                ConfiguracaoRH::query()->updateOrCreate(['chave' => $chave], ['valor' => $valor, 'atualizado_por' => Auth::user()?->nome_utilizador]);
            }
        });

        return $this->config();
    }
}
