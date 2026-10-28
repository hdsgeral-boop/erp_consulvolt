<?php

namespace App\Services\Plataforma;

use App\Models\Empresa;
use App\Models\PlanoLicenca;
use App\Models\Produto;
use App\Models\SubscricaoEmpresa;
use App\Models\Terceiro;
use App\Services\Terceiros\ServicoTerceiros;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Log;

/**
 * Emissão automática da factura AGT da subscrição SaaS quando o pagamento é confirmado (motor de precificação,
 * Módulo D). Reaproveita 100% o pipeline fiscal existente (ServicoDocumentosVenda) emitindo na empresa de
 * facturação da própria plataforma (config('erp.faturacao_saas'), ver CriarEmpresaFaturacaoSaasCommand) — nunca na
 * empresa do cliente, que é quem está a PAGAR, não a vender.
 *
 * `empresa_id=0` desliga a emissão (ambientes sem a empresa de facturação criada, ex.: homologação AGT). Nunca
 * lança para fora: uma falha aqui (AGT em baixo, configuração incompleta) não pode desfazer um pagamento já
 * confirmado — fica em log para reprocessamento manual.
 */
final class ServicoFaturacaoSaas
{
    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoTerceiros $terceiros,
        private readonly ServicoDocumentosVenda $documentos,
    ) {}

    public function emitirParaSubscricaoPaga(SubscricaoEmpresa $s): void
    {
        $empresaFaturacaoId = (int) config('erp.faturacao_saas.empresa_id', 0);
        if ($empresaFaturacaoId <= 0 || $s->venda_id !== null) {
            return;
        }

        try {
            $vendaId = $this->emitir($s, $empresaFaturacaoId);
            $s->forceFill(['venda_id' => $vendaId])->save();
        } catch (\Throwable $e) {
            Log::error("ServicoFaturacaoSaas: falha ao emitir a factura da subscrição #{$s->id}.", ['excecao' => $e->getMessage()]);
        }
    }

    private function emitir(SubscricaoEmpresa $s, int $empresaFaturacaoId): int
    {
        // Lido já no contexto do cliente (ambiente, ainda sem mudar nada) — antes de saltar para a empresa da plataforma.
        $empresaCliente = Empresa::query()->findOrFail($s->empresa_id);
        $plano = PlanoLicenca::query()->findOrFail($s->plano_id);
        $cicloRotulo = (string) (config("erp.planos_catalogo.ciclos.{$s->ciclo_codigo}.rotulo") ?? $s->ciclo_codigo);
        $dadosCliente = ['nif' => trim((string) $empresaCliente->nif), 'nome' => $empresaCliente->nome];
        $descricao = "Subscrição SaaS {$plano->nome} — Ciclo {$cicloRotulo} ({$s->meses} mês(es))";
        $observacoes = $this->observacoesDesconto($s);

        $plataformaTenantId = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $produtoId = (int) config('erp.faturacao_saas.produto_id', 0);
        $contaCliente = (string) config('erp.faturacao_saas.conta_cliente', '');

        return $this->tenant->executarComo($plataformaTenantId, fn () => $this->contexto->executarComo($empresaFaturacaoId,
            function () use ($dadosCliente, $produtoId, $contaCliente, $s, $descricao, $observacoes) {
                $cliente = $this->clienteSaas($dadosCliente, $contaCliente);
                $produto = Produto::query()->findOrFail($produtoId);
                $precoLiquido = $this->precoSemImposto((float) $s->valor, (float) $produto->taxa_imposto);

                $venda = $this->documentos->emitir([
                    'tipo_documento' => 'FT',
                    'cliente_id' => $cliente->id,
                    'data_emissao' => now()->toDateString(),
                    'linhas' => [[
                        'produto_id' => $produtoId, 'quantidade' => 1, 'preco_unitario' => $precoLiquido, 'descricao' => $descricao,
                    ]],
                    'observacoes' => $observacoes,
                ], null, false);

                return $venda->id;
            }));
    }

    /** Encontra o cliente pelo NIF (nunca duplica) ou cria-o, já com a conta de clientes SaaS (classe 31). */
    private function clienteSaas(array $dadosCliente, string $contaCliente): Terceiro
    {
        if ($dadosCliente['nif'] === '') {
            throw new \RuntimeException('A empresa cliente não tem NIF: não é possível emitir a factura.');
        }
        $existente = Terceiro::query()->where('nif', $dadosCliente['nif'])->first();

        return $this->terceiros->guardar(Terceiro::CLIENTE, [
            'nome' => $dadosCliente['nome'], 'nif' => $dadosCliente['nif'], 'conta_cliente' => $contaCliente,
        ], $existente);
    }

    /**
     * Preço líquido de imposto tal que, recalculado por CalculadoraDocumento::calcular() (valor + imposto
     * arredondado AO CÊNTIMO POR EXCESSO — nunca por baixo, regra AGT), o total reconcilia o mais perto possível de
     * subscricao.valor. Uma divisão simples (bruto / (1+taxa%)) não basta: o arredondamento por excesso do IVA
     * desloca os totais possíveis para uma escada de cêntimos que, por vezes, salta exactamente o valor alvo (ex.:
     * a 14%, só 9999.99 ou 10000.01 são atingíveis — nunca 10000.00) — por isso a estimativa inicial é afinada em
     * ±0,01 (mesmo algoritmo de CalculadoraDocumento::calcularComIva(), para o preço com IVA do POS) até ao valor
     * mais próximo; a diferença residual (no máximo 1 cêntimo) é uma divergência de arredondamento AGT normal,
     * não um erro — e nunca se força noutra linha (prepararLinhas() rejeita preço negativo).
     */
    private function precoSemImposto(float $valorComImposto, float $taxaPercentagem): float
    {
        $bruto = number_format($valorComImposto, 2, '.', '');
        $taxa = (string) $taxaPercentagem;
        $totalDe = fn (string $preco) => bcadd($preco, CalculadoraDocumento::excessoCentimo(bcdiv(bcmul($preco, $taxa, 8), '100', 8)), 2);
        $distancia = fn (string $preco) => abs((float) bcsub($totalDe($preco), $bruto, 2));

        // A escada de totais possíveis (arredondamento do IVA por excesso) pode saltar o alvo exacto — percorre uma
        // pequena vizinhança e fica com o preço cujo total recalculado é o mais próximo (nunca um ajuste cego de sinal).
        $base = CalculadoraDocumento::arredondar(bcdiv($bruto, bcadd('1', bcdiv($taxa, '100', 8), 8), 8), 2);
        $melhor = $base;
        for ($i = -3; $i <= 3; $i++) {
            $candidato = bcadd($base, bcmul((string) $i, '0.01', 2), 2);
            if ($distancia($candidato) < $distancia($melhor)) {
                $melhor = $candidato;
            }
        }

        return (float) $melhor;
    }

    private function observacoesDesconto(SubscricaoEmpresa $s): string
    {
        $linhas = ["Valor bruto: {$s->valor_bruto} {$s->moeda}."];
        if ((float) $s->valor_desconto_ciclo > 0) {
            $linhas[] = "Desconto do ciclo ({$s->ciclo_codigo}, {$s->desconto_ciclo_percentagem}%): -{$s->valor_desconto_ciclo} {$s->moeda}.";
        }
        if ($s->cupom_codigo) {
            $linhas[] = "Cupão {$s->cupom_codigo}: -{$s->valor_desconto_cupom} {$s->moeda}.";
        }
        $linhas[] = "Referência da subscrição: {$s->referencia}.";

        return implode(' ', $linhas);
    }
}
