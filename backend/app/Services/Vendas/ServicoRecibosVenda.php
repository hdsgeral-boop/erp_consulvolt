<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ItemReciboVenda;
use App\Models\ReciboVenda;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Recibos de clientes (no legado eram feitos na Tesouraria e não actualizavam a factura de forma fiável).
 *   - só facturas (FT) contabilizadas do próprio cliente; montante > 0 e ≤ pendente de cada factura;
 *   - conta de disponibilidade (classe 4, de movimento) obrigatória — o legado tinha caixa/banco trocados;
 *   - numeração por série "RE <SÉRIE>/<n>" sem colisões; tudo numa transacção;
 *   - actualiza pago/pendente/estado das facturas; anulação com motivo e rasto (nunca se apaga).
 * A contabilização (D disponibilidade / C cliente) é feita por ServicoContabilizacaoVendas.
 */
final class ServicoRecibosVenda
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoEstadoVenda $estado,
    ) {}

    /**
     * @param  array{cliente_id: int, data: string, codigo_conta: string, meio_pagamento?: string, referencia_pagamento?: ?string,
     *               alocacoes: list<array{venda_id: int, montante: string|float}>, unidade_negocio_id?: ?int, centro_custo_id?: ?int, projeto_id?: ?int}  $d
     */
    public function criar(array $d): ReciboVenda
    {
        $empresa = $this->contexto->obrigatorio();
        $data = substr($d['data'], 0, 10);
        if ($data > now()->toDateString()) {
            throw new ErroNegocio('A data do recibo não pode ser futura.', 'DATA_FUTURA', 422);
        }
        $this->exercicios->exigirAberto($empresa, $data);
        $cliente = Terceiro::query()->findOrFail($d['cliente_id']);
        $this->exigirContaDisponibilidade($d['codigo_conta']);
        if (! $d['alocacoes']) {
            throw new ErroNegocio('Indique pelo menos uma factura a liquidar.', 'RECIBO_SEM_FACTURAS', 422);
        }
        if (count(array_unique(array_column($d['alocacoes'], 'venda_id'))) !== count($d['alocacoes'])) {
            throw new ErroNegocio('A mesma factura aparece mais do que uma vez no recibo.', 'FACTURA_REPETIDA', 422);
        }

        return DB::transaction(function () use ($d, $data, $cliente, $empresa) {
            $vendas = Venda::query()->whereIn('id', array_column($d['alocacoes'], 'venda_id'))->lockForUpdate()->get()->keyBy('id');
            $total = '0.00';
            foreach ($d['alocacoes'] as $a) {
                $v = $vendas[$a['venda_id']] ?? throw new ErroNegocio('Factura inexistente.', 'FACTURA_INEXISTENTE', 422);
                $montante = number_format((float) $a['montante'], 2, '.', '');
                if ($v->tipo_documento !== 'FT' || $v->cliente_id !== $cliente->id) {
                    throw new ErroNegocio("{$v->numero_documento}: só se liquidam facturas (FT) do próprio cliente.", 'FACTURA_INVALIDA', 422);
                }
                if ($v->estado === 'ANULADO' || ! $v->contabilizado) {
                    throw new ErroNegocio("{$v->numero_documento}: a factura tem de estar contabilizada e não anulada.", 'FACTURA_NAO_CONTABILIZADA', 422);
                }
                if (bccomp($montante, '0', 2) <= 0 || bccomp($montante, (string) $v->valor_pendente, 2) > 0) {
                    throw new ErroNegocio("{$v->numero_documento}: o montante tem de ser positivo e não superior ao pendente ({$v->valor_pendente}).",
                        'MONTANTE_INVALIDO', 422, ['venda_id' => $v->id, 'pendente' => (string) $v->valor_pendente]);
                }
                $total = bcadd($total, $montante, 2);
            }

            $reserva = $this->series->reservar($empresa, 'RE', $data, false);
            $recibo = ReciboVenda::create([
                'cliente_id' => $cliente->id, 'numero_recibo' => $reserva['numero_documento'], 'data' => $data, 'montante_total' => $total,
                'meio_pagamento' => $d['meio_pagamento'] ?? 'TRANSFERENCIA', 'codigo_conta' => $d['codigo_conta'],
                'referencia_pagamento' => $d['referencia_pagamento'] ?? null, 'contabilizado' => false, 'estado' => 'EMITIDO',
                'serie_faturacao_eletronica_id' => $reserva['serie']->id,
                'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null, 'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null,
            ]);
            foreach ($d['alocacoes'] as $a) {
                $v = $vendas[$a['venda_id']];
                $montante = number_format((float) $a['montante'], 2, '.', '');
                ItemReciboVenda::create(['recibo_venda_id' => $recibo->id, 'venda_id' => $v->id, 'montante_pago' => $montante]);
                $v->update(['valor_pago' => bcadd((string) ($v->valor_pago ?? '0'), $montante, 2)]);
                $this->estado->recalcular($v);
            }

            return $recibo;
        });
    }

    /** Recibo automático da factura-recibo (o FR já nasce pago; o recibo documenta o recebimento). */
    public function criarDaFacturaRecibo(Venda $fr, string $conta, string $meio, ?string $referencia): ReciboVenda
    {
        $reserva = $this->series->reservar($fr->empresa_id, 'RE', $fr->data_emissao->toDateString(), false);
        $recibo = ReciboVenda::create([
            'cliente_id' => $fr->cliente_id, 'numero_recibo' => $reserva['numero_documento'], 'data' => $fr->data_emissao->toDateString(),
            'montante_total' => $fr->total_bruto, 'meio_pagamento' => $meio, 'codigo_conta' => $conta, 'referencia_pagamento' => $referencia,
            'referencia' => $fr->numero_documento, 'contabilizado' => false, 'estado' => 'EMITIDO', 'venda_origem_id' => $fr->id,
            'serie_faturacao_eletronica_id' => $reserva['serie']->id,
            'unidade_negocio_id' => $fr->unidade_negocio_id, 'centro_custo_id' => $fr->centro_custo_id, 'projeto_id' => $fr->projeto_id,
        ]);
        ItemReciboVenda::create(['recibo_venda_id' => $recibo->id, 'venda_id' => $fr->id, 'montante_pago' => $fr->total_bruto]);

        return $recibo;
    }

    /** Anula um recibo não contabilizado (os contabilizados descontabilizam-se primeiro, com estorno). */
    public function anular(ReciboVenda $recibo, string $motivo): ReciboVenda
    {
        if ($recibo->estado === 'ANULADO') {
            throw new ErroNegocio('O recibo já está anulado.', 'JA_ANULADO', 422);
        }
        if ($recibo->venda_origem_id) {
            throw new ErroNegocio('O recibo de uma factura-recibo não se anula isoladamente: emita uma nota de crédito sobre a factura-recibo.', 'RECIBO_DE_FR', 422);
        }
        if ($recibo->contabilizado) {
            throw new ErroNegocio('O recibo está contabilizado: descontabilize-o primeiro (estorno).', 'RECIBO_CONTABILIZADO', 422);
        }

        return DB::transaction(function () use ($recibo, $motivo) {
            foreach ($recibo->itensReciboVenda()->get() as $item) {
                $v = Venda::query()->lockForUpdate()->find($item->venda_id);
                if ($v) {
                    $pago = bcsub((string) ($v->valor_pago ?? '0'), (string) $item->montante_pago, 2);
                    $v->update(['valor_pago' => bccomp($pago, '0', 2) < 0 ? '0.00' : $pago]);
                    $this->estado->recalcular($v);
                }
            }
            $recibo->update(['estado' => 'ANULADO', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $recibo;
        });
    }

    public function exigirContaDisponibilidade(string $codigo): void
    {
        $this->plano->contaDeMovimento($codigo);
        if (! str_starts_with($codigo, '4')) {
            throw new ErroNegocio('A conta de disponibilidade tem de ser da classe 4 (meios monetários).', 'CONTA_DISPONIBILIDADE_INVALIDA', 422);
        }
    }
}
