<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\DocumentoTesouraria;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\RendaArrendamento;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use Illuminate\Support\Facades\DB;

/**
 * Pagar rendas do lado inquilino (pagarRenda e desligarPagamento do legado, SB:36-157) sobre ServicoDocumentosTesouraria::gravar:
 * um PAGAMENTO pendente com D na conta de custo do contrato (base + extras) e C na conta de retenção (Imposto Predial retido ao
 * senhorio, a entregar à AGT); a saída de caixa é a diferença e tem de bater com o total a pagar da renda. Integrar o documento
 * (e a reconciliação) é da Tesouraria. Idempotente por renda: a linha é bloqueada e `documento_tesouraria_id` é único. Recusa
 * rendas com IVA (o IVA dedutível precisa de conta própria). Correcção (P3): desligar o pagamento anula o documento pendente em vez de
 * o deixar órfão (o legado deixava um pagamento pendente que se podia pagar duas vezes); um documento já integrado recusa-se.
 */
final class ServicoPagamentoRendas
{
    public function __construct(private readonly ServicoDocumentosTesouraria $tesouraria) {}

    /**
     * @param  array{data?: ?string, referencia?: ?string, conta_financeira?: ?string}  $o
     * @return array{renda: RendaArrendamento, documento_id: int, valor: string, retido: string, repetida: bool}
     */
    public function pagar(int $rendaId, array $o): array
    {
        return DB::transaction(function () use ($rendaId, $o) {
            $r = RendaArrendamento::query()->lockForUpdate()->find($rendaId) ?? throw new ErroNegocio('Renda não encontrada.', 'IMO_RENDA_INEXISTENTE', 404);
            $c = ContratoArrendamento::query()->findOrFail($r->contrato_id);
            if ($c->posicao !== 'INQUILINO') {
                throw new ErroNegocio('Neste contrato a empresa é a senhoria: a renda factura-se, não se paga.', 'IMO_RENDA_LADO');
            }
            if ($r->documento_tesouraria_id !== null) {
                $d = DocumentoTesouraria::query()->findOrFail($r->documento_tesouraria_id);

                return ['renda' => $r, 'documento_id' => (int) $d->id, 'valor' => number_format((float) $d->valor_total, 2, '.', ''), 'retido' => '0.00', 'repetida' => true];
            }
            if ($r->estado === 'ANULADA') {
                throw new ErroNegocio('Não se paga uma renda anulada.', 'IMO_RENDA_ANULADA');
            }
            if ($r->estado !== 'APROVADA') {
                throw new ErroNegocio('Aprove a renda antes de a pagar.', 'IMO_RENDA_NAO_APROVADA');
            }
            if (! $c->conta_custo) {
                throw new ErroNegocio('O contrato não tem conta de custo da renda.', 'IMO_CONTA_CUSTO');
            }
            $conta = Normaliza::texto($o['conta_financeira'] ?? null) ?? $c->conta_financeira ?? throw new ErroNegocio('Indique a conta de disponibilidades por onde sai o pagamento.', 'IMO_CONTA_FINANCEIRA');
            $retem = (bool) $c->retencao_ip && (float) $r->ip_valor > 0;
            if ($retem && ! $c->conta_retencao) {
                throw new ErroNegocio('O contrato diz que a empresa retém o Imposto Predial, mas não tem conta de retenção. Indique-a no contrato.', 'IMO_CONTA_RETENCAO');
            }
            if ((float) $r->iva_valor > 0) {
                throw new ErroNegocio('Esta renda tem IVA. O IVA dedutível precisa de conta própria: lance este pagamento directamente na Tesouraria.', 'IMO_RENDA_COM_IVA');
            }
            $debito = bcadd(number_format((float) $r->base, 2, '.', ''), number_format((float) $r->extras_total, 2, '.', ''), 2);
            $retido = $retem ? number_format((float) $r->ip_valor, 2, '.', '') : '0.00';
            $saida = bcsub($debito, $retido, 2);
            if (abs((float) bcsub($saida, number_format((float) $r->total_receber, 2, '.', ''), 2)) > 0.02) {
                throw new ErroNegocio("O valor a pagar não bate certo ({$saida} contra {$r->total_receber}). Recalcule a renda antes de a pagar.", 'IMO_PAGAMENTO_NAO_BATE');
            }
            $imovel = Imovel::query()->find($c->imovel_id, ['id', 'nome']);
            $fracao = Fracao::query()->find($c->fracao_id, ['id', 'designacao']);
            $descricao = "Renda de {$r->periodo} — contrato {$c->numero} (".($imovel?->nome ?? '').' · '.($fracao?->designacao ?? '').')';
            $data = Normaliza::texto($o['data'] ?? null) ?? $r->vencimento->format('Y-m-d');
            $linhas = [['codigo_conta' => $c->conta_custo, 'tipo_dc' => 'D', 'valor' => $debito, 'terceiro_id' => $c->terceiro_id, 'descricao' => $descricao, 'data_documento_original' => $data]];
            if ($retem) {
                $linhas[] = ['codigo_conta' => $c->conta_retencao, 'tipo_dc' => 'C', 'valor' => $retido, 'descricao' => "Imposto Predial retido ao senhorio — {$r->periodo} (contrato {$c->numero})"];
            }
            $doc = $this->tesouraria->gravar(['tipo' => 'PAGAMENTO', 'data_documento' => $data, 'conta_financeira' => $conta, 'descricao' => $descricao,
                'referencia' => Normaliza::texto($o['referencia'] ?? null) ?? $c->numero, 'linhas' => $linhas]);
            $r->update(['estado' => 'PAGA', 'documento_tesouraria_id' => $doc->id, 'numero_documento' => $doc->numero_documento, 'data_pagamento' => $data, 'atualizado_por' => Autor::nome()]);

            return ['renda' => $r->refresh(), 'documento_id' => (int) $doc->id, 'valor' => $saida, 'retido' => $retido, 'repetida' => false];
        });
    }

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $o
     * @return array{feitas: list<array<string, mixed>>, falhas: list<array{id: int, erro: string}>}
     */
    public function pagarVarias(array $ids, array $o): array
    {
        $feitas = [];
        $falhas = [];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
            try {
                $r = $this->pagar($id, $o);
                $feitas[] = ['id' => $id, 'documento_id' => $r['documento_id'], 'valor' => $r['valor'], 'retido' => $r['retido'], 'repetida' => $r['repetida']];
            } catch (ErroNegocio $e) {
                $falhas[] = ['id' => $id, 'erro' => $e->getMessage()];
            }
        }

        return ['feitas' => $feitas, 'falhas' => $falhas];
    }

    /** Desliga o pagamento: a renda volta a «aprovada» e o documento de tesouraria pendente é anulado (um integrado recusa-se). */
    public function desligar(int $rendaId, ?string $motivo): RendaArrendamento
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(function () use ($rendaId, $motivo) {
            $r = RendaArrendamento::query()->lockForUpdate()->find($rendaId) ?? throw new ErroNegocio('Renda não encontrada.', 'IMO_RENDA_INEXISTENTE', 404);
            if ($r->estado !== 'PAGA') {
                throw new ErroNegocio('Esta renda não está marcada como paga.', 'IMO_RENDA_NAO_PAGA');
            }
            $d = $r->documento_tesouraria_id ? DocumentoTesouraria::query()->find($r->documento_tesouraria_id) : null;
            if ($d && $d->estado === 'INTEGRADO') {
                throw new ErroNegocio('O documento já foi integrado na contabilidade: descontabilize-o na Tesouraria antes de o desligar daqui.', 'IMO_DOCUMENTO_INTEGRADO');
            }
            if ($d && $d->estado === 'PENDENTE') {
                $this->tesouraria->anular($d, "Pagamento de renda desligado: {$motivo}");
            }
            $r->update(['estado' => 'APROVADA', 'documento_tesouraria_id' => null, 'data_pagamento' => null, 'numero_documento' => null,
                'notas' => trim(($r->notas ?? '').' · pagamento desligado em '.now()->toDateString().': '.$motivo, ' ·'), 'atualizado_por' => Autor::nome()]);

            return $r->refresh();
        });
    }
}
