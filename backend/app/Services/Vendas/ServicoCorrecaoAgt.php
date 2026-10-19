<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\ConfigFaturacaoEletronica;
use App\Models\Venda;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Vendas\Agt\MaquinaEstadosAgt as E;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Documento de correcção de um documento recusado pela AGT (doc-agt «registar.html»):
 *   - documentStatus «C» — «Documento gerado para correcção de documento anteriormente gerado e rejeitado pela AGT»;
 *   - rejectedDocumentNo — o n.º do documento recusado;
 *   - E46 / FE-RNG-073 — «As correcções de documentos rejeitados deverão ser efectuados com a utilização de um novo
 *     número de documento».
 *
 * O ERP emite um documento novo (mesmo tipo e série, data de hoje) com as linhas e os valores do original e os dados
 * de origem já corrigidos (cliente, empresa, produto), selado, com Hash SAF-T encadeado e pronto a enviar. O original
 * nunca é renumerado nem alterado nos campos fiscais: fica ANULADO no ERP (no SAF-T sai com InvoiceStatus «A») e
 * SUBSTITUIDO na AGT, ligado ao novo. Os efeitos financeiros passam para o novo documento; por isso o original não
 * pode estar contabilizado nem ter recibos, NC ou movimentos de tesouraria (anule-os primeiro). Os movimentos de stock
 * do original mantêm-se (o novo documento não volta a movimentar stock; o custo das linhas é copiado para o CMV).
 */
final class ServicoCorrecaoAgt
{
    public function __construct(
        private readonly ServicoSeries $series,
        private readonly ServicoHashSaft $hash,
        private readonly ServicoSelagemAgt $selagem,
        private readonly ServicoEnvioAgt $envio,
    ) {}

    public function emitir(Venda $original): Venda
    {
        return DB::transaction(function () use ($original) {
            /** @var Venda $o */
            $o = Venda::query()->lockForUpdate()->findOrFail($original->id);
            $estado = ServicoEnvioAgt::estadoEnvio($o);
            if (! $o->fe_regime || ! in_array($estado, E::PARA_CORRIGIR, true)) {
                throw new ErroNegocio('Só um documento recusado pela AGT (inválido ou rejeitado) pode ser corrigido com um novo documento.', 'CORRECCAO_NAO_PERMITIDA', 422);
            }
            $this->exigirSemEfeitosFinanceiros($o);
            $config = ConfigFaturacaoEletronica::query()->first();
            $hoje = now()->toDateString();
            $origemSerie = (string) (DB::table('series_faturacao_eletronica')->where('id', $o->serie_faturacao_eletronica_id)->value('origem') ?: 'GERAL');
            $exigirAgt = $this->selagem->emRegimeNaData($config, $hoje) && ! empty($config?->servico['exigir_series_agt']);
            $reserva = $this->series->reservar((int) $o->empresa_id, $o->tipo_documento, $hoje, true, $origemSerie, $exigirAgt);

            $novo = $o->replicate(['numero_documento', 'fe_documento', 'fe_erros', 'fe_avisos', 'fe_estado', 'fe_validado_em', 'fe_selado_em', 'fe_envio',
                'fe_regime', 'fe_data_entrada_sistema', 'saft_hash', 'saft_hash_controlo', 'serie_faturacao_eletronica_id', 'fe_serie', 'fe_numero',
                'contabilizado', 'numero_lan_contabilizacao', 'anulado_em', 'anulado_por', 'motivo_anulacao', 'criado_em', 'atualizado_em',
                'saft_source_billing', 'sistema_origem', 'numero_documento_saft']);
            $novo->forceFill([
                'numero_documento' => $reserva['numero_documento'], 'data_emissao' => $hoje.' '.now()->format('H:i:s'),
                'serie_faturacao_eletronica_id' => $reserva['serie']->id, 'fe_serie' => $reserva['serie']->codigo, 'fe_numero' => $reserva['numero'],
                'fe_data_entrada_sistema' => now(), 'contabilizado' => false,
                'observacoes' => trim(($o->observacoes ? $o->observacoes."\n" : '')."Correcção do documento {$o->numero_documento}, recusado pela AGT."),
            ])->save();

            $itens = new Collection;
            foreach ($o->itensVenda()->orderBy('id')->get() as $i) {
                $c = $i->replicate(['venda_id', 'fe_selado', 'criado_em', 'atualizado_em']);
                $c->forceFill(['venda_id' => $novo->id, 'fe_selado' => false])->save();
                $itens->push($c);
            }
            foreach (DB::table('vendas_documentos_relacionados')->where('venda_id', $o->id)->get() as $rel) {
                DB::table('vendas_documentos_relacionados')->insert(['venda_id' => $novo->id, 'venda_relacionada_id' => $rel->venda_relacionada_id, 'empresa_id' => $o->empresa_id]);
            }
            $referencia = $o->tipo_documento === 'NC' ? DB::table('vendas_documentos_relacionados as r')->join('vendas as x', 'x.id', '=', 'r.venda_relacionada_id')
                ->where('r.empresa_id', $o->empresa_id)->where('r.venda_id', $o->id)->value('x.numero_documento') : null;

            $this->hash->assinar($novo);
            $this->selagem->selar($novo, $itens, $config, $referencia);
            $novo->refresh();
            if ($novo->fe_estado !== 'PRONTO') {
                throw new ErroNegocio('Corrija primeiro os dados de origem (cliente, empresa, produto): o documento de correcção ainda tem erros — '.implode(' | ', (array) $novo->fe_erros),
                    'DOCUMENTO_COM_ERROS', 422, ['erros' => $novo->fe_erros]);
            }
            if (! $novo->fe_regime) {
                throw new ErroNegocio('O documento de correcção ficaria fora do regime de facturação electrónica.', 'FORA_DO_REGIME', 422);
            }
            $this->envio->atualizar($novo, ['estado' => E::POR_ENVIAR, 'documento_rejeitado' => $o->numero_documento, 'corrige_venda_id' => $o->id],
                'corrigir', "correcção (C) do documento {$o->numero_documento}");

            $this->envio->atualizar($o, ['estado' => E::SUBSTITUIDO, 'substituido_por' => $novo->numero_documento, 'substituido_por_id' => $novo->id],
                'corrigir', "substituído por {$novo->numero_documento}");
            $o->permitirSubstituicaoAgt = true;
            try {
                $o->forceFill(['estado' => 'ANULADO', 'valor_pendente' => '0.00', 'anulado_em' => now(), 'anulado_por' => Auth::user()?->nome_utilizador,
                    'motivo_anulacao' => "Recusado pela AGT; substituído pelo documento de correcção {$novo->numero_documento}."])->save();
            } finally {
                $o->permitirSubstituicaoAgt = false;
            }
            app(ServicoAuditoria::class)->registar('Vendas/Facturação', 'Correcção AGT', "{$o->numero_documento} → {$novo->numero_documento} (documentStatus C)", 'vendas', $novo->id);

            return $novo;
        });
    }

    private function exigirSemEfeitosFinanceiros(Venda $o): void
    {
        $motivos = [];
        if ($o->contabilizado) {
            $motivos[] = 'está contabilizado (descontabilize-o)';
        }
        if (DB::table('itens_recibo_venda as i')->join('recibos_venda as r', 'r.id', '=', 'i.recibo_venda_id')->where('i.venda_id', $o->id)
            ->where(fn ($q) => $q->whereNull('r.estado')->orWhere('r.estado', '<>', 'ANULADO'))->exists()
            || DB::table('recibos_venda')->where('venda_origem_id', $o->id)->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->exists()) {
            $motivos[] = 'tem recibos activos (cancele os recebimentos)';
        }
        if (DB::table('vendas_documentos_relacionados as r')->join('vendas as nc', 'nc.id', '=', 'r.venda_id')->where('r.venda_relacionada_id', $o->id)
            ->where('nc.tipo_documento', 'NC')->where(fn ($q) => $q->whereNull('nc.estado')->orWhere('nc.estado', '<>', 'ANULADO'))->exists()) {
            $motivos[] = 'tem notas de crédito';
        }
        if (DB::table('movimentos_caixa')->where('venda_id', $o->id)->exists() || DB::table('itens_documento_tesouraria')->where('venda_id', $o->id)->exists()) {
            $motivos[] = 'tem movimentos de caixa ou de tesouraria';
        }
        if ($motivos) {
            throw new ErroNegocio("O documento {$o->numero_documento} ".implode('; ', $motivos).'. Trate esses efeitos antes de emitir a correcção.',
                'CORRECCAO_COM_EFEITOS', 422, ['motivos' => $motivos]);
        }
    }
}
