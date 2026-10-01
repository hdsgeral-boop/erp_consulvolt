<?php

namespace App\Services\Gestao\Paineis\Cubo;

/**
 * BI contabilístico (vista `accounting_bi`, ui_bi.js:2-160): análise dinâmica dos lançamentos da empresa activa com os campos do
 * legado e a visão padrão "conta × mês, soma do saldo"; períodos "todo o histórico", "mês actual" e "ano actual" (omissão), ou um
 * intervalo de datas. Usa o motor do cubo (ServicoCubo) com a definição CatalogoCubo::bi().
 *
 * Face ao legado: classe 9 excluída (igual); apuramento (períodos 13/14) excluído salvo `incluir_apuramento` (regra dos mapas);
 * nome do diário corrigido (ver CatalogoCubo::bi); sem o registo fictício "Exemplo" quando não há dados — devolve a tabela vazia.
 */
final class ServicoBI
{
    public const PERIODOS = ['todo', 'mes_atual', 'ano_atual'];

    public function __construct(private readonly ServicoCubo $cubo) {}

    /** @return array<string, mixed> */
    public function metadados(): array
    {
        return $this->cubo->descrever('bi', $this->cubo->definicao('bi')) + ['periodos' => [
            ['id' => 'todo', 'rotulo' => 'Todo o histórico'], ['id' => 'mes_atual', 'rotulo' => 'Mês actual'], ['id' => 'ano_atual', 'rotulo' => 'Ano actual']]];
    }

    /** @return array<string, mixed> */
    public function consultar(array $p): array
    {
        if (empty($p['data_inicio']) || empty($p['data_fim'])) {
            [$p['data_inicio'], $p['data_fim']] = self::intervalo($p['periodo'] ?? 'ano_atual');
        }
        $def = $this->cubo->definicao('bi');
        if (! array_key_exists('linhas', $p) && ! array_key_exists('colunas', $p)) {
            $p['linhas'] = $def['padrao']['linhas'];
            $p['colunas'] = $def['padrao']['colunas'];
        }

        return $this->cubo->consultar('bi', $p) + ['periodo_pedido' => $p['periodo'] ?? null];
    }

    /** @return array{0: string, 1: string} */
    public static function intervalo(string $periodo): array
    {
        return match ($periodo) {
            'todo' => ['1900-01-01', '2999-12-31'],
            'mes_atual' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            default => [now()->format('Y').'-01-01', now()->format('Y').'-12-31'],
        };
    }
}
