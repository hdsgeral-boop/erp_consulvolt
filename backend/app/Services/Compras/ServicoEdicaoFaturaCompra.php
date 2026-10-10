<?php

namespace App\Services\Compras;

use App\Exceptions\ErroNegocio;
use App\Models\FaturaCompra;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * «Editar Fatura» da lista do legado (showNewDirectPurchaseInvoice(id), js/ui_compras_v2.js:624 — só em facturas por
 * contabilizar). Aqui corrige-se o cabeçalho: n.º da factura do fornecedor, data e vencimento. As linhas não se editam
 * (mexem em quantidades facturadas da encomenda e na conta transitória): para as corrigir anula-se e regista-se de novo.
 * Regras: factura não anulada, não contabilizada e não paga; exercício aberto na data antiga e na nova; n.º único por
 * fornecedor (como no registo); auditoria com os valores antes e depois.
 */
final class ServicoEdicaoFaturaCompra
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoExercicios $exercicios,
    ) {}

    /** @param  array{numero_fatura: string, data: string, data_vencimento?: ?string}  $d */
    public function editarCabecalho(FaturaCompra $fatura, array $d): FaturaCompra
    {
        $empresa = $this->contexto->obrigatorio();

        return DB::transaction(function () use ($fatura, $d, $empresa) {
            $f = FaturaCompra::query()->lockForUpdate()->findOrFail($fatura->id);
            if ($f->estado === 'ANULADA') {
                throw new ErroNegocio('A factura está anulada.', 'DOCUMENTO_ANULADO', 422);
            }
            if ($f->contabilizado) {
                throw new ErroNegocio('A factura já está contabilizada: descontabilize-a antes de a editar.', 'FATURA_CONTABILIZADA', 422);
            }
            if (in_array($f->estado, ['PAGO', 'PARCIAL'], true)) {
                throw new ErroNegocio('A factura já tem pagamentos: não se edita.', 'FATURA_PAGA', 422);
            }
            $numero = trim($d['numero_fatura']);
            $data = substr($d['data'], 0, 10);
            $this->exercicios->exigirAberto($empresa, $f->data?->toDateString() ?? $data);
            $this->exercicios->exigirAberto($empresa, $data);
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["fatura_compra:{$f->fornecedor_id}:".mb_strtoupper($numero)]);
            $repetida = FaturaCompra::query()->where('fornecedor_id', $f->fornecedor_id)->whereKeyNot($f->id)
                ->whereRaw('upper(trim(numero_fatura)) = upper(trim(?))', [$numero])
                ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADA'))->exists();
            if ($repetida) {
                throw new ErroNegocio("Já existe a factura {$numero} deste fornecedor.", 'FATURA_DUPLICADA', 422);
            }
            if (! empty($d['data_vencimento']) && $d['data_vencimento'] < $data) {
                throw new ErroNegocio('O vencimento não pode ser anterior à data da factura.', 'DATAS_INVALIDAS', 422);
            }
            $antes = ['numero_fatura' => $f->numero_fatura, 'data' => $f->data?->toDateString(), 'data_vencimento' => $f->data_vencimento?->toDateString()];
            $f->update(['numero_fatura' => $numero, 'data' => $data, 'data_vencimento' => $d['data_vencimento'] ?? null]);
            app(ServicoAuditoria::class)->registar('Compras', 'Factura de fornecedor editada', "Factura {$numero}: cabeçalho corrigido.", $f->getTable(), $f->id,
                $antes, ['numero_fatura' => $numero, 'data' => $data, 'data_vencimento' => $d['data_vencimento'] ?? null]);

            return $f->refresh();
        });
    }
}
