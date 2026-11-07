<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\LancamentoContabil;
use App\Models\NegocioImobiliario;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Ativos\ServicoCategoriasAtivos;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Vendas\ServicoContabilizacaoVendas;

/**
 * Lançamentos contabilísticos dos negócios de compra e venda (P4: mais-valia e menos-valia com contas configuráveis por empresa, nada inventado aqui).
 * Cada lançamento é equilibrado (Σ D = Σ C, validado pelo ServicoLancamentos), vai para o diário IMO com `tipo_origem = IMOBILIARIO` e é idempotente: o
 * número do lançamento fica no negócio (`lancamento_compra`, `lancamento_venda`), que se bloqueia antes de lançar; quem chama já tem a linha bloqueada.
 *
 * COMPRA (só quando o bem é criado pela compra): D conta do activo da categoria (11 a 14) = custo de aquisição (preço + impostos + encargos
 *  capitalizáveis) / C conta de contrapartida da compra (configuração) com o vendedor. O pagamento ao vendedor, ao Estado e ao notário regista-se na
 *  Tesouraria contra essa conta. A linha a débito é a aquisição que o bem referencia (`lancamento_contabil_id`), pelo que não aparece como «por inventariar».
 *
 * ALIENAÇÃO (venda de imóvel com bem no imobilizado), sobre a quota do bem vendida: a factura de Vendas reconhece o preço como proveito (conta do artigo);
 *  este lançamento retira o bem e deixa só o resultado: D amortização acumulada / C conta do activo / D proveito da factura (estorna o preço) / C mais-valia
 *  ou D menos-valia. O resultado contabilístico é o preço menos o valor líquido contabilístico; o encargo da venda (mediação, escritura) é gasto do
 *  período que as Compras e a Tesouraria já lançam, por isso aqui não se abate outra vez (o «resultado do legado», líquido de encargos, fica no negócio).
 */
final class ServicoContabilizacaoNegocios
{
    public function __construct(
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoContabilizacaoVendas $contabilizacaoVendas,
    ) {}

    /**
     * Lança a aquisição do bem. Devolve o número do lançamento e o id da linha a débito (a que o bem referencia).
     *
     * @return array{numero_lan: string, linha_id: int}
     */
    public function compra(NegocioImobiliario $n, AtivoImobilizado $ativo, ?CategoriaAtivo $categoria, string $custoTotal): array
    {
        if ($n->lancamento_compra !== null) {
            $linha = LancamentoContabil::query()->where('numero_lan', $n->lancamento_compra)->where('tipo_dc', 'D')->orderBy('id')->firstOrFail();

            return ['numero_lan' => (string) $n->lancamento_compra, 'linha_id' => (int) $linha->id];
        }
        $contraPartida = $this->config->actual()?->conta_compra_contrapartida
            ?? throw new ErroNegocio('Falta a conta de contrapartida da compra de imóveis na configuração do módulo imobiliário (Modelos e Taxas › Facturação e contas).', 'IMO_SEM_CONTA_COMPRA');
        $contaActivo = $categoria?->conta_ativo ?: throw new ErroNegocio('A categoria do activo não tem a conta do activo (classe 11 a 14). Complete-a em Activos › Categorias.', 'CATEGORIA_SEM_CONTAS');
        if (! preg_match('/^1[1-4]/', (string) $contaActivo)) {
            throw new ErroNegocio("A conta do activo {$contaActivo} não é uma conta de imobilizado (11 a 14).", 'CONTA_INVALIDA');
        }
        $this->plano->contaDeMovimento((string) $contaActivo);
        $texto = "Compra de imóvel {$n->numero} — {$ativo->descricao}";
        $linhas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('IMO', 'Imobiliário')->id, 'data_documento' => $this->data($n), 'numero_documento' => (string) $n->numero,
            'referencia' => (string) $n->numero, 'descricao' => mb_substr($texto, 0, 1000), 'tipo_origem' => ServicoCaucao::ORIGEM_LANCAMENTO, 'linhas' => [
                ['codigo_conta' => (string) $contaActivo, 'tipo_dc' => 'D', 'valor' => $custoTotal, 'descricao' => $texto, 'unidade_negocio_id' => $ativo->unidade_negocio_id, 'centro_custo_id' => $ativo->centro_custo_id],
                ['codigo_conta' => (string) $contraPartida, 'tipo_dc' => 'C', 'valor' => $custoTotal, 'terceiro_id' => $n->terceiro_id, 'descricao' => $texto],
            ]]);

        return ['numero_lan' => (string) $linhas->first()->numero_lan, 'linha_id' => (int) $linhas->first()->id];
    }

    /**
     * Lança a alienação do bem na quota vendida e devolve o número do lançamento.
     *
     * @param  array{custo: string, amortizacoes: string, valor_liquido: string, resultado_contabilistico: string}  $r  contas da quota vendida
     */
    public function alienacao(NegocioImobiliario $n, AtivoImobilizado $ativo, ?CategoriaAtivo $categoria, array $r, Venda $venda): string
    {
        if ($n->lancamento_venda !== null) {
            return (string) $n->lancamento_venda;
        }
        $cfg = $this->config->actual();
        $resultado = $r['resultado_contabilistico'];
        $cmp = bccomp($resultado, '0', 2);
        $contaResultado = $cmp > 0 ? $cfg?->conta_mais_valia : ($cmp < 0 ? $cfg?->conta_menos_valia : null);
        if ($cmp !== 0 && ! $contaResultado) {
            throw new ErroNegocio($cmp > 0 ? 'Falta a conta da mais-valia na configuração do módulo imobiliário (Modelos e Taxas › Facturação e contas).'
                : 'Falta a conta da menos-valia na configuração do módulo imobiliário (Modelos e Taxas › Facturação e contas).', 'IMO_SEM_CONTA_MAIS_VALIA');
        }
        $contaActivo = $categoria?->conta_ativo ?: LancamentoContabil::query()->whereKey($ativo->lancamento_contabil_id)->value('codigo_conta');
        if (! $contaActivo || ! preg_match('/^1[1-4]/', (string) $contaActivo)) {
            throw new ErroNegocio("Indique a conta do activo {$ativo->codigo} (classe 11 a 14): nem a categoria nem o lançamento de compra a indicam.", 'SEM_CONTA_ATIVO');
        }
        $proveitos = $this->proveitosDaFactura($venda);
        $dim = ['unidade_negocio_id' => $ativo->unidade_negocio_id, 'centro_custo_id' => $ativo->centro_custo_id];
        $texto = "Alienação do imóvel — venda {$n->numero} (activo {$ativo->codigo})";
        $linhas = [['codigo_conta' => (string) $contaActivo, 'tipo_dc' => 'C', 'valor' => $r['custo'], 'descricao' => $texto] + $dim];
        if (bccomp($r['amortizacoes'], '0', 2) > 0) {
            [, $acumulada] = ServicoCategoriasAtivos::exigirContasAmortizacao($categoria, (string) $ativo->codigo);
            $linhas[] = ['codigo_conta' => $acumulada, 'tipo_dc' => 'D', 'valor' => $r['amortizacoes'], 'descricao' => $texto] + $dim;
        }
        foreach ($proveitos as $p) {
            $linhas[] = ['codigo_conta' => $p['conta'], 'tipo_dc' => 'D', 'valor' => $p['valor'], 'terceiro_id' => $n->terceiro_id, 'descricao' => "Estorno do proveito do preço — {$texto}"];
        }
        if ($cmp !== 0) {
            $linhas[] = ['codigo_conta' => (string) $contaResultado, 'tipo_dc' => $cmp > 0 ? 'C' : 'D', 'valor' => ltrim($resultado, '-'),
                'descricao' => ($cmp > 0 ? 'Mais-valia' : 'Menos-valia')." na venda {$n->numero}"] + $dim;
        }
        $criadas = $this->lancamentos->criar(['diario_id' => $this->localizador->diario('IMO', 'Imobiliário')->id, 'data_documento' => $this->data($n), 'numero_documento' => 'ALI-'.$n->numero,
            'referencia' => (string) $n->numero, 'descricao' => mb_substr($texto, 0, 1000), 'tipo_origem' => ServicoCaucao::ORIGEM_LANCAMENTO, 'linhas' => $linhas]);

        return (string) $criadas->first()->numero_lan;
    }

    /**
     * Os proveitos que a factura da venda reconhece (conta do artigo, valor da linha), os mesmos que a contabilização de Vendas usa. A venda de imóvel
     * está isenta de IVA: uma factura com imposto não serve a este lançamento.
     *
     * @return list<array{conta: string, valor: string}>
     */
    private function proveitosDaFactura(Venda $venda): array
    {
        return $this->validarFactura($venda);
    }

    /**
     * Confirma, ao concluir a venda, que a factura tem contas de proveito utilizáveis pelo lançamento da alienação (para não deixar a venda concluída com um
     * artigo sem conta de proveitos).
     *
     * @return list<array{conta: string, valor: string}>
     */
    public function validarFactura(Venda $venda): array
    {
        $linhas = $this->contabilizacaoVendas->linhasProveitoEIva($venda);
        if ($linhas === []) {
            throw new ErroNegocio('A factura da venda não tem proveitos para estornar.', 'IMO_FACTURA_SEM_PROVEITO');
        }

        return $linhas;
    }

    private function data(NegocioImobiliario $n): string
    {
        return $n->data_escritura?->format('Y-m-d') ?? now()->toDateString();
    }

    /** Conta de cliente do comprador, para o registo do abate em Activos. */
    public function contaDoComprador(NegocioImobiliario $n): string
    {
        $c = Terceiro::query()->findOrFail($n->terceiro_id)->contaCliente();
        if ($c === '') {
            throw new ErroNegocio('O comprador não tem conta de cliente.', 'IMO_SEM_CONTA_CLIENTE');
        }

        return $c;
    }
}
