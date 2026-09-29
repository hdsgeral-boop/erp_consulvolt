<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\Venda;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QR code dos documentos no regime (js/facturacao_agt_qr.js do legado, agora gerado no servidor):
 *   https://quiosqueagt.minfin.gov.ao/facturacao-eletronica/consultar-fe?emissor=<NIF>&document=<documentNo>
 *   (espaços do documentNo → %20; a "/" fica como está) · QR Model 2, correcção M, modo byte, UTF-8, PNG 350×350.
 * A AGT indica a versão 4, mas o endereço (~110 caracteres) não cabe nela com correcção M: usa-se a menor versão
 * em que cabe (normalmente 7), como no legado. Logótipo oficial opcional em storage/app/agt/agt_logo.png (< 20% da área).
 */
final class ServicoQrAgt
{
    private const TAMANHO = 350;

    public static function url(string $nif, string $documentNo): string
    {
        return config('erp.agt.qr_consulta').'?emissor='.rawurlencode(preg_replace('/\s+/', '', $nif)).'&document='.str_replace(' ', '%20', $documentNo);
    }

    public function urlDoDocumento(Venda $venda): string
    {
        if (! $venda->fe_regime) {
            throw new ErroNegocio('O documento não foi emitido no regime de facturação electrónica: não tem QR code.', 'FORA_DO_REGIME', 422);
        }
        $nif = $venda->fe_documento['taxRegistrationNumber'] ?? Empresa::query()->whereKey($venda->empresa_id)->value('nif') ?? '';

        return self::url((string) $nif, $venda->fe_documento['documento']['documentNo'] ?? $venda->numero_documento);
    }

    /** @return array{conteudo: string, tipo: string} */
    public function imagem(Venda $venda, string $formato = 'png'): array
    {
        $url = $this->urlDoDocumento($venda);
        if ($formato === 'svg') {
            $svg = (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'eccLevel' => EccLevel::M, 'outputBase64' => false, 'quietzoneSize' => 4])))->render($url);

            return ['conteudo' => $svg, 'tipo' => 'image/svg+xml'];
        }
        $png = (new QRCode(new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG, 'eccLevel' => EccLevel::M, 'outputBase64' => false,
            'quietzoneSize' => 4, 'scale' => 10])))->render($url);

        return ['conteudo' => $this->ajustar($png), 'tipo' => 'image/png'];
    }

    /** Redimensiona para 350×350 e sobrepõe o logótipo da AGT (se existir), centrado com fundo branco (~6% da área). */
    private function ajustar(string $png): string
    {
        $origem = imagecreatefromstring($png);
        $img = imagecreatetruecolor(self::TAMANHO, self::TAMANHO);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagecopyresized($img, $origem, 0, 0, 0, 0, self::TAMANHO, self::TAMANHO, imagesx($origem), imagesy($origem));

        $logo = storage_path('app/agt/agt_logo.png');
        if (is_file($logo) && ($l = @imagecreatefrompng($logo))) {
            $caixa = (int) round(self::TAMANHO * 0.24);
            $x = (int) round((self::TAMANHO - $caixa) / 2);
            imagefilledrectangle($img, $x, $x, $x + $caixa, $x + $caixa, imagecolorallocate($img, 255, 255, 255));
            $pad = max(2, (int) round($caixa * 0.08));
            $escala = min(($caixa - 2 * $pad) / imagesx($l), ($caixa - 2 * $pad) / imagesy($l));
            $w = (int) (imagesx($l) * $escala);
            $h = (int) (imagesy($l) * $escala);
            imagecopyresampled($img, $l, $x + intdiv($caixa - $w, 2), $x + intdiv($caixa - $h, 2), 0, 0, $w, $h, imagesx($l), imagesy($l));
        }
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
