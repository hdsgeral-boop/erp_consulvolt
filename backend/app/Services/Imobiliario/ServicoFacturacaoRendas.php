<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoEncargo;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\RendaArrendamento;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoDocumentosVenda;
use Illuminate\Support\Facades\DB;

/**
 * Facturar rendas (facturarRenda do legado, CT:864-971) sobre ServicoDocumentosVenda::emitir (numeração, selagem AGT, hash, stock e
 * contabilização são de Vendas; aqui não há série nem selagem próprias). Idempotente por renda: a linha da renda é bloqueada, uma
 * renda que já tem `venda_id` devolve a factura existente e o UNIQUE de `venda_id` impede duas facturas para a mesma renda. Só se
 * factura uma renda APROVADA do lado senhorio (uma factura selada não se corrige). Linhas: a renda (menos o desconto, proporcional se
 * for o caso), cada encargo a acrescer e cada extra, todas ao artigo de serviço «Renda» da configuração (IVA do artigo = IVA da renda).
 * O total da factura tem de bater com o da renda (a diferença de arredondamento do IVA por linha, até 5 cêntimos, passa para a renda;
 * acima disso recusa e a transacção desfaz tudo). A retenção do Imposto Predial pelo inquilino (P5: crédito de imposto) regista-se à
 * parte e uma só vez, quando o senhorio a confirma.
 */
final class ServicoFacturacaoRendas
{
    public function __construct(
        private readonly ServicoDocumentosVenda $vendas,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
    ) {}

    /**
     * @param  array{data?: ?string, conta_disponibilidade?: ?string, meio_pagamento?: ?string}  $o
     * @return array{renda: RendaArrendamento, venda_id: int, numero_documento: string, repetida: bool}
     */
    public function facturar(int $rendaId, array $o = []): array
    {
        return DB::transaction(function () use ($rendaId, $o) {
            $r = RendaArrendamento::query()->lockForUpdate()->find($rendaId) ?? throw new ErroNegocio('Renda não encontrada.', 'IMO_RENDA_INEXISTENTE', 404);
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            if ($c->posicao !== 'SENHORIO') {
                throw new ErroNegocio('Neste contrato a empresa é a inquilina: a renda paga-se na Tesouraria, não se factura.', 'IMO_RENDA_LADO');
            }
            if ($r->venda_id !== null) {
                $v = Venda::query()->findOrFail($r->venda_id);

                return ['renda' => $r, 'venda_id' => (int) $v->id, 'numero_documento' => (string) $v->numero_documento, 'repetida' => true];
            }
            if ($r->estado === 'ANULADA') {
                throw new ErroNegocio('Não se factura uma renda anulada.', 'IMO_RENDA_ANULADA');
            }
            if ($r->estado !== 'APROVADA') {
                throw new ErroNegocio('Aprove a renda antes de a facturar — uma factura selada não se corrige.', 'IMO_RENDA_NAO_APROVADA');
            }
            if ($c->estado === 'RASCUNHO') {
                throw new ErroNegocio('Active o contrato antes de facturar as suas rendas.', 'IMO_CONTRATO_RASCUNHO');
            }
            $produto = $this->config->produtoDaRenda((float) $r->iva_taxa);
            if ($c->doc_tipo === 'FR' && empty($o['conta_disponibilidade'])) {
                throw new ErroNegocio('O contrato emite factura-recibo: indique a conta de disponibilidade onde a renda é recebida.', 'IMO_CONTA_DISPONIBILIDADE');
            }
            $linhas = $this->linhas($r, $c, $produto->id);
            $soma = array_reduce($linhas, fn ($t, $l) => bcadd($t, $l['preco_unitario'], 2), '0.00');
            $esperado = bcadd((string) number_format((float) $r->base, 2, '.', ''), number_format((float) $r->extras_total, 2, '.', ''), 2);
            if (bccomp($soma, $esperado, 2) !== 0) {
                throw new ErroNegocio('A renda está desactualizada em relação ao contrato: recalcule-a (e aprove-a de novo) antes de facturar.', 'IMO_RENDA_DESACTUALIZADA');
            }
            $imovel = Imovel::query()->find($c->imovel_id, ['id', 'nome']);
            $fracao = Fracao::query()->find($c->fracao_id, ['id', 'designacao']);
            $venda = $this->vendas->emitir(['tipo_documento' => $c->doc_tipo, 'cliente_id' => $c->terceiro_id, 'data_emissao' => Normaliza::texto($o['data'] ?? null) ?? now()->toDateString(),
                'linhas' => $linhas, 'observacoes' => "Renda de {$r->periodo} — contrato {$c->numero} (".($imovel?->nome ?? '').' · '.($fracao?->designacao ?? '').'). Vencimento '.$r->vencimento->format('Y-m-d').'.']
                + ($c->doc_tipo === 'FR' ? ['conta_disponibilidade' => $o['conta_disponibilidade'], 'meio_pagamento' => $o['meio_pagamento'] ?? 'TRANSFERENCIA'] : []));
            $totalVenda = number_format((float) $venda->total_bruto, 2, '.', '');
            $diferenca = bcsub($totalVenda, number_format((float) $r->total_facturar, 2, '.', ''), 2);
            $ajuste = [];
            if (bccomp($diferenca, '0', 2) !== 0) {
                if (bccomp(ltrim($diferenca, '-'), '0.05', 2) > 0) {
                    throw new ErroNegocio('O total da factura não bate com o da renda. Reveja a configuração do artigo da renda e o IVA.', 'IMO_FACTURA_NAO_BATE');
                }
                $iva = bcadd(number_format((float) $r->iva_valor, 2, '.', ''), $diferenca, 2);   // o documento fiscal manda: o IVA por linha pode diferir 1 cêntimo
                $ajuste = ['iva_valor' => $iva, 'total_facturar' => $totalVenda, 'total_receber' => bcadd(number_format((float) $r->total_receber, 2, '.', ''), $diferenca, 2)];
            }
            $r->update($ajuste + ['estado' => 'FACTURADA', 'venda_id' => $venda->id, 'numero_documento' => $venda->numero_documento, 'data_facturada' => now()->toDateString(),
                'atualizado_por' => Autor::nome()]);

            return ['renda' => $r->refresh(), 'venda_id' => (int) $venda->id, 'numero_documento' => (string) $venda->numero_documento, 'repetida' => false];
        });
    }

    /**
     * Linhas da factura: a renda (menos o desconto), os encargos a acrescer e os extras, com os mesmos valores do cálculo da renda.
     *
     * @return list<array{produto_id: int, quantidade: string, preco_unitario: string, descricao: string}>
     */
    private function linhas(RendaArrendamento $r, ContratoArrendamento $c, int $produtoId): array
    {
        $pr = CalculadoraPeriodos::factorProRata($r->de->format('Y-m-d'), $r->ate->format('Y-m-d'), $c->inicio->format('Y-m-d'), ServicoRendas::fimEfectivo($c), (bool) $c->pro_rata);
        $parcial = bccomp($pr['factor'], '1', 10) < 0 ? " (proporcional: {$pr['cobertos']} de {$pr['total']} dias)" : '';
        $rendaLinha = bcsub(number_format((float) $r->renda, 2, '.', ''), number_format((float) $r->desconto, 2, '.', ''), 2);
        if (bccomp($rendaLinha, '0', 2) < 0) {
            throw new ErroNegocio('O desconto não pode ser maior do que a renda.', 'IMO_DESCONTO_EXCESSIVO');
        }
        $linhas = [['produto_id' => $produtoId, 'quantidade' => '1', 'preco_unitario' => $rendaLinha, 'descricao' => "Renda de {$r->periodo} — contrato {$c->numero}{$parcial}"]];
        foreach (ContratoEncargo::query()->where('contrato_id', $c->id)->where('incluido_na_renda', false)->orderBy('ordem')->get() as $e) {
            $valor = CalculadoraRenda::dinheiro((string) $e->valor);
            $valor = bccomp($pr['factor'], '1', 10) < 0 ? CalculadoraDocumento::arredondar(bcmul($valor, $pr['factor'], 10)) : $valor;
            $linhas[] = ['produto_id' => $produtoId, 'quantidade' => '1', 'preco_unitario' => $valor, 'descricao' => "{$e->designacao} — {$r->periodo}"];
        }
        foreach ($r->extras ?? [] as $x) {
            $linhas[] = ['produto_id' => $produtoId, 'quantidade' => '1', 'preco_unitario' => number_format((float) $x['valor'], 2, '.', ''), 'descricao' => "{$x['designacao']} — {$r->periodo}"];
        }

        return $linhas;
    }

    /**
     * Factura várias rendas; cada uma na sua transacção (uma falha não desfaz as outras).
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $o
     * @return array{feitas: list<array<string, mixed>>, falhas: list<array{id: int, erro: string}>}
     */
    public function facturarVarias(array $ids, array $o = []): array
    {
        $feitas = [];
        $falhas = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $r = $this->facturar($id, $o);
                $feitas[] = ['id' => $id, 'venda_id' => $r['venda_id'], 'numero_documento' => $r['numero_documento'], 'repetida' => $r['repetida']];
            } catch (ErroNegocio $e) {
                $falhas[] = ['id' => $id, 'erro' => $e->getMessage()];
            }
        }

        return ['feitas' => $feitas, 'falhas' => $falhas];
    }

    /**
     * Regista, uma só vez, o Imposto Predial retido pelo inquilino como crédito de imposto (P5): D conta do crédito de imposto / C conta
     * do cliente (com o número da factura), para que o saldo da renda se feche com o que o inquilino pagou já líquido. Idempotente.
     */
    public function registarRetencao(int $rendaId): RendaArrendamento
    {
        return DB::transaction(function () use ($rendaId) {
            $r = RendaArrendamento::query()->lockForUpdate()->find($rendaId) ?? throw new ErroNegocio('Renda não encontrada.', 'IMO_RENDA_INEXISTENTE', 404);
            if ($r->estado !== 'FACTURADA' || ! $r->ip_retido || (float) $r->ip_valor <= 0) {
                throw new ErroNegocio('Só há retenção a registar numa renda facturada em que o inquilino retém o Imposto Predial.', 'IMO_SEM_RETENCAO');
            }
            if ($r->retencao_lancamento !== null) {
                return $r;   // já registada: repetir não lança de novo
            }
            $conta = $this->config->actual()?->conta_ip_credito ?? throw new ErroNegocio('Falta a conta do crédito de imposto (Imposto Predial retido) na configuração do módulo imobiliário.', 'IMO_SEM_CONTA_IP');
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            $cliente = Terceiro::query()->findOrFail($c->terceiro_id);
            $contaCliente = $cliente->contaCliente();
            if ($contaCliente === '') {
                throw new ErroNegocio('O inquilino não tem conta de cliente.', 'IMO_SEM_CONTA_CLIENTE');
            }
            $valor = number_format((float) $r->ip_valor, 2, '.', '');
            $texto = "Imposto Predial retido pelo inquilino — renda de {$r->periodo}, contrato {$c->numero}";
            $linhas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('IMO', 'Imobiliário')->id, 'data_documento' => now()->toDateString(), 'numero_documento' => (string) $r->numero_documento,
                'referencia' => $c->numero, 'descricao' => $texto, 'tipo_origem' => ServicoCaucao::ORIGEM_LANCAMENTO, 'linhas' => [
                    ['codigo_conta' => $conta, 'tipo_dc' => 'D', 'valor' => $valor, 'terceiro_id' => $c->terceiro_id, 'descricao' => $texto],
                    ['codigo_conta' => $contaCliente, 'tipo_dc' => 'C', 'valor' => $valor, 'terceiro_id' => $c->terceiro_id, 'descricao' => $texto],
                ]]);
            $r->update(['retencao_lancamento' => $linhas->first()->numero_lan, 'atualizado_por' => Autor::nome()]);

            return $r->refresh();
        });
    }
}
