<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\LancamentoContabil;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Contabilização de vendas e recibos (postSale / unpostSale do legado, js/ui_sales.js), corrigida:
 *   - contas do produto/cliente e, na falta, da configuração de vendas — nunca contas fixas no código;
 *   - um único lançamento equilibrado por construção, via ServicoLancamentos (exercício aberto, contas de
 *     movimento, numeração sem corrida); valores do próprio documento (sem recalcular o IVA por outra fórmula);
 *   - FT: D cliente / C proveitos + C IVA · NC: o inverso · FR: D disponibilidade / C proveitos + C IVA
 *     (o legado não permitia descontabilizar FR);
 *   - recibo: D disponibilidade / C cliente;
 *   - descontabilizar = estorno com rasto (ADR-016), nunca apagar; encomendas não são contabilizáveis;
 *   - CMV em inventário permanente (ADR-043): as linhas D custo / C inventário (o inverso nas devoluções) entram no
 *     lançamento do próprio documento; as guias GR/GD contabilizam só o CMV (sem proveitos).
 */
final class ServicoContabilizacaoVendas
{
    public const DIARIO_VENDAS = 'FC';

    public const DIARIO_RECIBOS = 'RC';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoLancamentos $lancamentos,
        private readonly ServicoConfigVendas $config,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoStockVendas $stockVendas,
    ) {}

    public function contabilizar(Venda $venda): Venda
    {
        $guia = in_array($venda->tipo_documento, ['GR', 'GD'], true);
        if (! $venda->eFiscal() && ! $guia) {
            throw new ErroNegocio("Documentos {$venda->tipo_documento} não são contabilizáveis (só FT, FR, NC, GR e GD).", 'NAO_CONTABILIZAVEL', 422);
        }
        if ($venda->contabilizado) {
            throw new ErroNegocio('O documento já está contabilizado.', 'JA_CONTABILIZADO', 422);
        }
        if ($venda->estado === 'ANULADO') {
            throw new ErroNegocio('Documento anulado: não é contabilizável.', 'DOCUMENTO_ANULADO', 422);
        }

        if ($guia) {
            return $this->contabilizarGuia($venda);
        }

        return DB::transaction(function () use ($venda) {
            $venda = Venda::query()->lockForUpdate()->findOrFail($venda->id);
            $cliente = Terceiro::query()->withTrashed()->findOrFail($venda->cliente_id);
            $nc = $venda->tipo_documento === 'NC';
            $dc = fn (string $natural) => $nc ? ($natural === 'D' ? 'C' : 'D') : $natural;
            $comum = ['terceiro_id' => $cliente->id, 'unidade_negocio_id' => $venda->unidade_negocio_id,
                'centro_custo_id' => $venda->centro_custo_id, 'projeto_id' => $venda->projeto_id];

            $creditos = $this->linhasProveitoEIva($venda);
            $soma = array_reduce($creditos, fn ($s, $l) => bcadd($s, $l['valor'], 2), '0.00');
            if (bccomp($soma, (string) $venda->total_bruto, 2) !== 0) {
                throw new ErroNegocio("As linhas do documento somam {$soma} mas o total é {$venda->total_bruto}: verifique o documento.",
                    'TOTAIS_INCONSISTENTES', 422, ['soma_linhas' => $soma, 'total_bruto' => (string) $venda->total_bruto]);
            }

            $recibo = null;
            if ($venda->tipo_documento === 'FR') {
                $recibo = ReciboVenda::query()->where('venda_origem_id', $venda->id)->first();
                $contaDebito = $recibo?->codigo_conta ?? throw new ErroNegocio('Factura-recibo sem recibo/conta de disponibilidade.', 'FR_SEM_RECIBO', 422);
            } else {
                $contaDebito = $cliente->codigo_conta ?: $this->config->exigir('clientes_default', 'O cliente não tem conta contabilística.');
            }

            // cliente em moeda estrangeira: a linha guarda o valor na moeda (saldo em moeda e diferenças de câmbio na liquidação)
            $moeda = $venda->codigo_moeda && $venda->codigo_moeda !== 'AOA' && $venda->tipo_documento !== 'FR'
                ? ['codigo_moeda' => $venda->codigo_moeda, 'valor_moeda' => $venda->total_bruto_moeda, 'taxa_cambio' => $venda->taxa_cambio] : [];
            $linhas = [['codigo_conta' => $contaDebito, 'tipo_dc' => $dc('D'), 'valor' => $soma] + $moeda + $comum];
            foreach ($creditos as $c) {
                $linhas[] = ['codigo_conta' => $c['conta'], 'tipo_dc' => $dc('C'), 'valor' => $c['valor']] + $comum;
            }
            foreach ($this->stockVendas->linhasCmv($venda) as $c) {   // custo das mercadorias vendidas (ou devolvidas)
                $linhas[] = $c + array_diff_key($comum, ['terceiro_id' => 1]);
            }

            $criadas = $this->lancamentos->criar([
                'diario_id' => $this->diario(self::DIARIO_VENDAS, 'Vendas')->id, 'data_documento' => $venda->data_emissao->toDateString(),
                'numero_documento' => $venda->numero_documento, 'descricao' => mb_substr("{$venda->numero_documento} - {$cliente->nome}", 0, 1000),
                'tipo_origem' => 'VENDAS', 'linhas' => $linhas,
            ]);
            $numeroLan = $criadas->first()->numero_lan;
            $venda->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $numeroLan]);
            $recibo?->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $numeroLan]);

            return $venda;
        });
    }

    /** GR/GD: só o CMV (a guia não tem proveitos). */
    private function contabilizarGuia(Venda $venda): Venda
    {
        return DB::transaction(function () use ($venda) {
            $venda = Venda::query()->lockForUpdate()->findOrFail($venda->id);
            $linhas = $this->stockVendas->linhasCmv($venda);
            if (! $linhas) {
                throw new ErroNegocio('A guia não tem mercadoria de stock com custo: não há nada a contabilizar.', 'NADA_A_CONTABILIZAR', 422);
            }
            $comum = ['unidade_negocio_id' => $venda->unidade_negocio_id, 'centro_custo_id' => $venda->centro_custo_id, 'projeto_id' => $venda->projeto_id];
            $cliente = Terceiro::query()->withTrashed()->find($venda->cliente_id);
            $criadas = $this->lancamentos->criar([
                'diario_id' => $this->diario('GR', 'Guias de remessa e devolução')->id, 'data_documento' => $venda->data_emissao->toDateString(),
                'numero_documento' => $venda->numero_documento, 'descricao' => mb_substr("{$venda->numero_documento} - {$cliente?->nome}", 0, 1000), 'tipo_origem' => 'VENDAS',
                'linhas' => array_map(fn ($l) => $l + $comum, $linhas),
            ]);
            $venda->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $criadas->first()->numero_lan]);

            return $venda;
        });
    }

    public function descontabilizar(Venda $venda, string $motivo): Venda
    {
        if (! $venda->contabilizado) {
            throw new ErroNegocio('O documento não está contabilizado.', 'NAO_CONTABILIZADO', 422);
        }
        if ($venda->tipo_documento === 'FT' && DB::table('itens_recibo_venda as i')->join('recibos_venda as r', 'r.id', '=', 'i.recibo_venda_id')
            ->where('i.empresa_id', $venda->empresa_id)->where('i.venda_id', $venda->id)
            ->where(fn ($q) => $q->whereNull('r.estado')->orWhere('r.estado', '<>', 'ANULADO'))->exists()) {
            throw new ErroNegocio('A factura tem recibos: anule/descontabilize primeiro os recibos.', 'FACTURA_COM_RECIBOS', 422);
        }

        return DB::transaction(function () use ($venda, $motivo) {
            $contaCliente = Terceiro::query()->withTrashed()->find($venda->cliente_id)?->codigo_conta;
            $linha = $this->localizar($venda->numero_lan_contabilizacao, $venda->numero_documento, $venda->sessao_pos_id || $venda->sessao_pos_legado_codigo,
                in_array($venda->tipo_documento, ['FR', 'GR', 'GD'], true) ? null : $contaCliente, $venda->tipo_documento === 'NC' ? 'C' : 'D');
            $this->lancamentos->estornar($linha, $motivo);
            $venda->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);
            if ($venda->tipo_documento === 'FR') {
                ReciboVenda::query()->where('venda_origem_id', $venda->id)->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);
            }

            return $venda;
        });
    }

    public function contabilizarRecibo(ReciboVenda $recibo): ReciboVenda
    {
        if ($recibo->contabilizado) {
            throw new ErroNegocio('O recibo já está contabilizado.', 'JA_CONTABILIZADO', 422);
        }
        if ($recibo->estado === 'ANULADO') {
            throw new ErroNegocio('Recibo anulado: não é contabilizável.', 'DOCUMENTO_ANULADO', 422);
        }
        if ($recibo->venda_origem_id) {
            throw new ErroNegocio('O recibo de uma factura-recibo é contabilizado com a própria factura-recibo.', 'RECIBO_DE_FR', 422);
        }
        $cliente = Terceiro::query()->withTrashed()->findOrFail($recibo->cliente_id);
        $contaCliente = $cliente->codigo_conta ?: $this->config->exigir('clientes_default', 'O cliente não tem conta contabilística.');
        $comum = ['terceiro_id' => $cliente->id, 'unidade_negocio_id' => $recibo->unidade_negocio_id,
            'centro_custo_id' => $recibo->centro_custo_id, 'projeto_id' => $recibo->projeto_id];

        return DB::transaction(function () use ($recibo, $cliente, $contaCliente, $comum) {
            $criadas = $this->lancamentos->criar([
                'diario_id' => $this->diario(self::DIARIO_RECIBOS, 'Recebimentos')->id, 'data_documento' => $recibo->data->toDateString(),
                'numero_documento' => $recibo->numero_recibo, 'descricao' => mb_substr("{$recibo->numero_recibo} - {$cliente->nome}", 0, 1000),
                'tipo_origem' => 'RECIBOS', 'linhas' => [
                    ['codigo_conta' => $recibo->codigo_conta, 'tipo_dc' => 'D', 'valor' => $recibo->montante_total] + $comum,
                    ['codigo_conta' => $contaCliente, 'tipo_dc' => 'C', 'valor' => $recibo->montante_total] + $comum,
                ],
            ]);
            $recibo->update(['contabilizado' => true, 'numero_lan_contabilizacao' => $criadas->first()->numero_lan]);

            return $recibo;
        });
    }

    public function descontabilizarRecibo(ReciboVenda $recibo, string $motivo): ReciboVenda
    {
        if (! $recibo->contabilizado) {
            throw new ErroNegocio('O recibo não está contabilizado.', 'NAO_CONTABILIZADO', 422);
        }
        if ($recibo->venda_origem_id) {
            throw new ErroNegocio('O recibo de uma factura-recibo descontabiliza-se com a própria factura-recibo.', 'RECIBO_DE_FR', 422);
        }

        return DB::transaction(function () use ($recibo, $motivo) {
            $this->lancamentos->estornar($this->localizar($recibo->numero_lan_contabilizacao, $recibo->numero_recibo, false), $motivo);
            $recibo->update(['contabilizado' => false, 'numero_lan_contabilizacao' => null]);

            return $recibo;
        });
    }

    /**
     * Linhas a crédito agrupadas por conta: proveitos (conta do produto → configuração) e IVA
     * (conta de IVA liquidado do produto → configuração). Valores das próprias linhas do documento.
     *
     * @return list<array{conta: string, valor: string}>
     */
    private function linhasProveitoEIva(Venda $venda): array
    {
        $contas = [];
        foreach ($venda->itensVenda()->with(['produto' => fn ($q) => $q->withTrashed()])->orderBy('id')->get() as $i => $item) {
            $p = $item->produto;
            $valor = $item->total_linha !== null ? (string) $item->total_linha
                : CalculadoraDocumento::arredondar(bcmul((string) $item->quantidade, (string) $item->preco_unitario, 8));
            $imposto = bcsub((string) ($item->total ?? $valor), $valor, 2);

            $contaProveito = $item->codigo_conta ?: $p?->codigo_conta
                ?: $this->config->exigir($p?->movimenta_stock ? 'proveitos_mercadorias' : 'proveitos_servicos', 'Linha '.($i + 1).': produto sem conta de proveitos.');
            $contas[$contaProveito] = bcadd($contas[$contaProveito] ?? '0.00', $valor, 2);

            if (bccomp($imposto, '0', 2) !== 0) {
                $contaIva = $p?->conta_iva_liquidado ?: $p?->conta_iva ?: $this->config->exigir('iva_vendas', 'Linha '.($i + 1).': produto sem conta de IVA liquidado.');
                $contas[$contaIva] = bcadd($contas[$contaIva] ?? '0.00', $imposto, 2);
            }
        }
        if (! $contas) {
            throw new ErroNegocio('O documento não tem linhas.', 'SEM_LINHAS', 422);
        }

        return array_values(array_map(fn ($c, $v) => ['conta' => (string) $c, 'valor' => $v],
            array_keys(array_filter($contas, fn ($v) => bccomp($v, '0', 2) !== 0)), array_filter($contas, fn ($v) => bccomp($v, '0', 2) !== 0)));
    }

    private function localizar(?string $numeroLan, string $numeroDocumento, bool $pos, ?string $contaCliente = null, string $dcCliente = 'D'): LancamentoContabil
    {
        if (! $numeroLan && $pos) {
            throw new ErroNegocio('Venda POS do legado: é contabilizada na sessão POS — descontabilize a sessão.', 'VENDA_POS', 422);
        }

        return $this->localizador->localizar($numeroLan, $numeroDocumento, $contaCliente, $dcCliente);
    }

    private function diario(string $codigo, string $nome): DiarioContabil
    {
        return $this->localizador->diario($codigo, $nome);
    }
}
