<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoRH;
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
 */
final class ServicoConfiguracaoRH
{
    public const PADRAO = [
        'segregar_encerrar_validar' => false,
        'ferias_dias_mes_admissao' => 2,
        'ferias_meses_minimos_gozo' => 6,
        'ferias_transporte_saldo' => true,
        'ferias_transporte_max_dias' => 22,
    ];

    /** @return array<string, mixed> */
    public function config(): array
    {
        $cfg = self::PADRAO;
        foreach (ConfiguracaoRH::query()->whereIn('chave', array_keys(self::PADRAO))->get() as $r) {
            if ($r->valor !== null) {
                $cfg[$r->chave] = is_bool(self::PADRAO[$r->chave]) ? (bool) $r->valor : (int) $r->valor;
            }
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
                if (! is_bool($valor) && $valor < 0) {
                    throw new ErroNegocio('Os valores das férias não podem ser negativos.', 'DADOS_INVALIDOS', 422);
                }
                ConfiguracaoRH::query()->updateOrCreate(['chave' => $chave], ['valor' => $valor, 'atualizado_por' => Auth::user()?->nome_utilizador]);
            }
        });

        return $this->config();
    }
}
