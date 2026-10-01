<?php

namespace Database\Seeders\E2E;

use App\Models\DiarioContabil;
use App\Models\PlanoConta;
use App\Services\Compras\ServicoConfigCompras;
use App\Services\Logistica\ServicoConfigLogistica;
use App\Services\POS\ServicoConfigPOS;
use App\Services\Tesouraria\ServicoConfigTesouraria;
use App\Services\Vendas\ServicoConfigVendas;

/**
 * Plano de contas mínimo e coerente (classes 1 a 8: classes e grupos totalizadores, contas de movimento usadas pelos
 * fluxos), diários e configurações contabilísticas de Vendas, Compras, Logística, Tesouraria e POS.
 * Corre dentro de ContextoEmpresa::executarComo (empresa activa).
 */
final class E2EContabilidadeSeeder
{
    /** código => [descrição, tipo] — T = totalizadora, M = movimento */
    public const PLANO = [
        '1' => ['Meios fixos e investimentos', 'T'], '11' => ['Imobilizações corpóreas', 'T'], '113' => ['Equipamento básico', 'M'],
        '2' => ['Existências', 'T'], '21' => ['Compras', 'T'], '211' => ['Compras de mercadorias', 'M'],
        '26' => ['Mercadorias', 'T'], '261' => ['Mercadorias em armazém', 'M'],
        '3' => ['Terceiros', 'T'], '31' => ['Clientes', 'T'], '311' => ['Clientes correntes', 'M'],
        '32' => ['Fornecedores', 'T'], '321' => ['Fornecedores correntes', 'M'], '328' => ['Compras em trânsito (transitória)', 'M'],
        '34' => ['Estado', 'T'], '342' => ['Imposto sobre o rendimento', 'T'], '3421' => ['IRT a pagar', 'M'],
        '343' => ['Segurança social', 'T'], '3431' => ['INSS a pagar', 'M'],
        '345' => ['Imposto sobre o valor acrescentado', 'T'], '3451' => ['IVA dedutível', 'M'], '3452' => ['IVA liquidado', 'M'],
        '36' => ['Pessoal', 'T'], '361' => ['Pessoal - remunerações', 'T'], '3611' => ['Remunerações a pagar', 'M'], '3612' => ['Adiantamentos ao pessoal', 'M'],
        '37' => ['Outros valores a receber e a pagar', 'T'], '379' => ['Responsabilidade de operadores de caixa', 'M'],
        '4' => ['Meios monetários', 'T'], '43' => ['Depósitos à ordem', 'T'], '431' => ['Banco Fictício E2E - conta corrente', 'M'],
        '45' => ['Caixa', 'T'], '451' => ['Caixa principal', 'M'],
        '48' => ['Conta transitória', 'T'], '487' => ['Transitória - transferências', 'M'], '488' => ['Transitória - TPA', 'M'], '489' => ['Transitória - numerário', 'M'],
        '5' => ['Capital e reservas', 'T'], '51' => ['Capital', 'T'], '511' => ['Capital social', 'M'],
        '6' => ['Proveitos e ganhos por natureza', 'T'], '61' => ['Vendas', 'T'], '611' => ['Vendas de mercadorias', 'M'],
        '62' => ['Prestações de serviços', 'T'], '621' => ['Serviços prestados', 'M'],
        '68' => ['Outros proveitos e ganhos', 'T'], '688' => ['Outros ganhos', 'T'], '6881' => ['Sobras de caixa', 'M'],
        '6882' => ['Diferenças de câmbio favoráveis', 'M'], '6883' => ['Sobras de inventário', 'M'],
        '7' => ['Custos e perdas por natureza', 'T'], '71' => ['Custo das existências vendidas', 'T'], '711' => ['Custo das mercadorias vendidas', 'M'],
        '72' => ['Custos com o pessoal', 'T'], '721' => ['Remunerações do pessoal', 'M'], '722' => ['Subsídios ao pessoal', 'M'], '725' => ['Encargos sobre remunerações (INSS)', 'M'],
        '75' => ['Fornecimentos e serviços de terceiros', 'T'], '752' => ['Serviços de terceiros', 'M'],
        '76' => ['Outros custos operacionais', 'T'], '767' => ['Comissões bancárias (TPA)', 'M'],
        '78' => ['Outros custos e perdas', 'T'], '788' => ['Outras perdas', 'T'], '7881' => ['Quebras de caixa', 'M'],
        '7882' => ['Diferenças de câmbio desfavoráveis', 'M'], '7883' => ['Quebras de inventário', 'M'],
        '8' => ['Resultados', 'T'], '81' => ['Resultados transitados', 'T'], '811' => ['Resultados transitados', 'M'],
        '88' => ['Resultado líquido do exercício', 'T'], '881' => ['Resultado líquido do exercício', 'M'],
    ];

    public const DIARIOS = [
        'FC' => 'Vendas e facturação', 'RC' => 'Recibos de clientes', 'CP' => 'Compras', 'GEPOS' => 'Ponto de venda (POS)', 'SAL' => 'Salários',
        'TS' => 'Tesouraria', 'SQ' => 'Stock e inventário', 'OD' => 'Operações diversas', 'AM' => 'Amortizações', 'AC' => 'Acréscimos e diferimentos',
    ];

    public function executar(bool $configuracoes = true): void
    {
        foreach (self::PLANO as $codigo => [$descricao, $tipo]) {
            PlanoConta::create(['codigo' => (string) $codigo, 'descricao' => $descricao, 'tipo' => $tipo]);
        }
        foreach (self::DIARIOS as $codigo => $nome) {
            DiarioContabil::create(['codigo' => $codigo, 'nome' => $nome, 'descricao' => $nome]);
        }
        if (! $configuracoes) {
            return;
        }

        app(ServicoConfigVendas::class)->definir(['clientes_default' => '311', 'proveitos_mercadorias' => '611', 'proveitos_servicos' => '621', 'iva_vendas' => '3452']);
        app(ServicoConfigCompras::class)->definir(['compras_mercadorias' => '211', 'inventario_mercadorias' => '261', 'transitoria_compras' => '328',
            'custos_servicos' => '752', 'imobilizado' => '113', 'iva_dedutivel' => '3451', 'diferencas_cambio_desfavoraveis' => '7882',
            'diferencas_cambio_favoraveis' => '6882']);
        app(ServicoConfigLogistica::class)->definir(['custo_mercadorias_vendidas' => '711', 'sobras_inventario' => '6883', 'quebras_inventario' => '7883']);
        app(ServicoConfigTesouraria::class)->definir(['caixa_sobras' => '6881', 'caixa_quebras' => '7881', 'diferencas_cambio_favoraveis' => '6882',
            'diferencas_cambio_desfavoraveis' => '7882']);
        app(ServicoConfigPOS::class)->guardar(['conta_sobra' => '6881', 'conta_quebra' => '7881', 'conta_operador' => '379', 'tolerancia_desvio' => 5]);
    }
}
