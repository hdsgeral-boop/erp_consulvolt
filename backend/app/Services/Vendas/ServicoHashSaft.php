<?php

namespace App\Services\Vendas;

use App\Models\Venda;
use App\Services\Vendas\Agt\ChavesAgt;

/**
 * Assinatura SAF-T(AO) dos documentos fiscais (Decreto Executivo n.º 312/18 — regras de certificação de software):
 *   Hash = base64( RSA-SHA1( "InvoiceDate;SystemEntryDate;InvoiceNo;GrossTotal;HashAnterior" ) )
 * encadeada por série (o HashAnterior é o do documento anterior da mesma série; vazio no primeiro).
 * Calculada NA EMISSÃO, dentro da transacção que bloqueia a série — o legado gerava na exportação um
 * "hash" falso (soma de caracteres + texto fixo), recalculado a cada exportação.
 * Sem chave SAF-T instalada (software ainda não certificado), o documento fica com HashControl "0".
 */
final class ServicoHashSaft
{
    public function __construct(private readonly ChavesAgt $chaves) {}

    public function assinar(Venda $venda): void
    {
        $chave = $this->chaves->saft();
        if (! $chave) {
            $venda->forceFill(['saft_hash' => null, 'saft_hash_controlo' => '0'])->save();

            return;
        }
        $anterior = Venda::query()->where('serie_faturacao_eletronica_id', $venda->serie_faturacao_eletronica_id)
            ->where('fe_numero', '<', $venda->fe_numero)->whereNotNull('saft_hash')
            ->orderByDesc('fe_numero')->value('saft_hash');

        openssl_sign(self::mensagem($venda, $anterior), $assinatura, $chave, OPENSSL_ALGO_SHA1);
        $venda->forceFill(['saft_hash' => base64_encode($assinatura), 'saft_hash_controlo' => (string) config('erp.agt.versao_chave_saft', '1')])->save();
    }

    public static function mensagem(Venda $venda, ?string $hashAnterior): string
    {
        return implode(';', [
            $venda->data_emissao->toDateString(),
            $venda->fe_data_entrada_sistema->format('Y-m-d\TH:i:s'),
            $venda->numero_documento,
            number_format((float) $venda->total_bruto, 2, '.', ''),
            $hashAnterior ?? '',
        ]);
    }

    /** Os 4 caracteres do Hash impressos no documento (1.º, 11.º, 21.º e 31.º). */
    public static function excerto(?string $hash): ?string
    {
        return $hash ? $hash[0].($hash[10] ?? '').($hash[20] ?? '').($hash[30] ?? '') : null;
    }
}
