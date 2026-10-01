<?php

namespace Database\Seeders\E2E;

use App\Models\Armazem;
use App\Models\MeioPagamento;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Services\Compras\ServicoDeliberacaoCompras;
use App\Services\Logistica\ServicoStock;
use App\Services\POS\ServicoTerminaisPOS;

/**
 * Clientes, fornecedores, produtos (com e sem stock), armazém com stock inicial, meios de pagamento da tesouraria,
 * escalões de deliberação de compras e o terminal POS T01 com numerário, TPA e transferência.
 * Corre dentro de ContextoEmpresa::executarComo (empresa activa).
 */
final class E2EComercialSeeder
{
    public function executar(): void
    {
        foreach ([['Cliente Demo Alfa, Lda', '5999000101'], ['Cliente Demo Beta, SA', '5999000102']] as [$nome, $nif]) {
            Terceiro::create(['nome' => $nome, 'nif' => $nif, 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311',
                'endereco' => 'Avenida Fictícia, Luanda', 'email' => 'cliente-'.substr($nif, -3).'@exemplo.invalid']);
        }
        Terceiro::create(['nome' => 'Fornecedor Demo Ómega, Lda', 'nif' => '5999000201', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '321',
            'conta_compra_transitoria' => '328', 'endereco' => 'Zona Industrial Fictícia, Viana']);
        Terceiro::create(['nome' => 'Fornecedor Demo Sigma, SA', 'nif' => '5999000202', 'tipo' => Terceiro::FORNECEDOR, 'codigo_conta' => '321',
            'conta_compra_transitoria' => '328']);

        $contasStock = ['codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'conta_compra' => '211', 'conta_inventario' => '261',
            'conta_custo' => '711', 'conta_iva_dedutivel' => '3451', 'movimenta_stock' => true];
        $a = Produto::create(['codigo' => 'E2E-A01', 'nome' => 'Artigo Demo A (caixa)', 'preco_unitario' => 1000, 'taxa_imposto' => 14] + $contasStock);
        $b = Produto::create(['codigo' => 'E2E-A02', 'nome' => 'Artigo Demo B (pacote)', 'preco_unitario' => 2500, 'taxa_imposto' => 14] + $contasStock);
        Produto::create(['codigo' => 'E2E-A03', 'nome' => 'Artigo Demo C (sem existências)', 'preco_unitario' => 750, 'taxa_imposto' => 14] + $contasStock);
        Produto::create(['codigo' => 'E2E-S01', 'nome' => 'Serviço Demo de Consultoria', 'preco_unitario' => 5000, 'taxa_imposto' => 14, 'movimenta_stock' => false,
            'e_servico' => true, 'codigo_conta' => '621', 'conta_iva_liquidado' => '3452', 'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);
        Produto::create(['codigo' => 'E2E-S02', 'nome' => 'Serviço Demo de Transporte', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'movimenta_stock' => false,
            'e_servico' => true, 'conta_custo' => '752', 'conta_iva_dedutivel' => '3451']);

        $armazem = Armazem::create(['nome' => 'Armazém Central Demo', 'codigo' => 'ARM01', 'localizacao' => 'Luanda', 'predefinido' => true]);
        $hoje = now()->toDateString();
        $stock = app(ServicoStock::class);
        $stock->entrada($a->id, $armazem->id, '100', '600', $hoje, 'Stock inicial E2E');
        $stock->entrada($b->id, $armazem->id, '50', '1500', $hoje, 'Stock inicial E2E');

        MeioPagamento::create(['nome' => 'Caixa principal', 'codigo_conta' => '451', 'ativo' => true, 'predefinido' => true, 'codigo_moeda' => 'AOA']);
        MeioPagamento::create(['nome' => 'Banco Fictício E2E', 'codigo_conta' => '431', 'iban' => 'AO06000000000000000000000', 'ativo' => true,
            'predefinido' => false, 'codigo_moeda' => 'AOA']);

        // pedidos até 1 000 000 Kz: um único nível (compras_ped_aprovar)
        app(ServicoDeliberacaoCompras::class)->definirEscaloes([['nome' => 'Chefe de compras', 'limite' => 1000000], ['nome' => 'Direcção', 'limite' => null]]);

        app(ServicoTerminaisPOS::class)->guardar(['codigo' => 'T01', 'nome' => 'Loja Demo', 'armazem_id' => $armazem->id, 'fundo_maneio_padrao' => 5000,
            'meios_pagamento' => [
                ['id' => 'pm_num', 'tipo' => 'NUMERARIO', 'nome' => 'Numerário', 'conta_transitoria' => '489', 'conta_liquidacao' => '451'],
                ['id' => 'pm_tpa', 'tipo' => 'TPA', 'nome' => 'Multicaixa (TPA)', 'conta_transitoria' => '488', 'conta_liquidacao' => '431', 'comissao_pct' => 1, 'conta_comissao' => '767'],
                ['id' => 'pm_trf', 'tipo' => 'TRANSFERENCIA', 'nome' => 'Transferência', 'conta_transitoria' => '487', 'conta_liquidacao' => '431'],
            ]]);
    }
}
