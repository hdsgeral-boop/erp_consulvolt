<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use OpenSSLAsymmetricKey;

/**
 * Chaves privadas RSA da facturação electrónica (servico_agt/servidor.js:236-260 do legado):
 *   - produtor de software (assina o softwareInfo);
 *   - contribuinte, uma por NIF (assina documentos e pedidos) — emitida pela AGT no Portal do Contribuinte;
 *   - chave SAF-T (Hash dos documentos).
 * Ficheiros PEM na pasta de segredos (volume só de leitura, fora do Git); RSA com pelo menos 2048 bits.
 */
final class ChavesAgt
{
    /** @var array<string, OpenSSLAsymmetricKey> */
    private array $cache = [];

    public function produtor(): OpenSSLAsymmetricKey
    {
        return $this->carregar(config('erp.agt.chave_produtor'))
            ?? throw new ErroNegocio('Falta a chave privada do produtor de software (configuração do servidor).', 'SEM_CHAVE_PRODUTOR', 503);
    }

    public function contribuinte(string $nif): OpenSSLAsymmetricKey
    {
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            throw new ErroNegocio('NIF da empresa inválido.', 'NIF_INVALIDO', 422);
        }

        return $this->carregar(config('erp.agt.pasta_contribuintes')."/{$nif}.pem")
            ?? throw new ErroNegocio("Não há chave do contribuinte para o NIF {$nif} no servidor. Descarregue-a no Portal do Contribuinte e coloque-a na pasta de chaves como {$nif}.pem.",
                'SEM_CHAVE_CONTRIBUINTE', 422);
    }

    /** Chave SAF-T (Hash); null quando o software ainda não está certificado. */
    public function saft(): ?OpenSSLAsymmetricKey
    {
        return $this->carregar(config('erp.agt.chave_saft'));
    }

    public function temProdutor(): bool
    {
        return $this->carregar(config('erp.agt.chave_produtor')) !== null;
    }

    /** @return list<string> NIFs com chave de contribuinte instalada */
    public function nifsComChave(): array
    {
        $pasta = $this->caminho(config('erp.agt.pasta_contribuintes'));
        $nifs = [];
        foreach (is_dir($pasta) ? scandir($pasta) : [] as $f) {
            if (preg_match('/^([0-9A-Za-z]{9,15})\.pem$/', $f, $m)) {
                $nifs[] = $m[1];
            }
        }
        sort($nifs);

        return $nifs;
    }

    private function carregar(?string $relativo): ?OpenSSLAsymmetricKey
    {
        if (! $relativo) {
            return null;
        }
        $ficheiro = $this->caminho($relativo);
        clearstatcache(true, $ficheiro);
        // o ficheiro é verificado a cada uso: chave retirada ou substituída (rotação) deixa logo de ser usada
        if (! is_file($ficheiro) || ! is_readable($ficheiro)) {
            unset($this->cache[$ficheiro]);

            return null;
        }
        $versao = $ficheiro.':'.filemtime($ficheiro).':'.filesize($ficheiro);
        if (isset($this->cache[$versao])) {
            return $this->cache[$versao];
        }
        $chave = openssl_pkey_get_private((string) file_get_contents($ficheiro));
        if ($chave === false) {
            throw new ErroNegocio('Chave privada inválida em '.basename($ficheiro).'.', 'CHAVE_INVALIDA', 503);
        }
        $det = openssl_pkey_get_details($chave);
        if (($det['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($det['bits'] ?? 0) < 2048) {
            throw new ErroNegocio('A chave '.basename($ficheiro).' tem de ser RSA com pelo menos 2048 bits.', 'CHAVE_INVALIDA', 503);
        }

        return $this->cache[$versao] = $chave;
    }

    private function caminho(string $relativo): string
    {
        $base = rtrim((string) config('erp.agt.pasta_chaves'), '/');

        return str_starts_with($relativo, '/') ? $relativo : "{$base}/{$relativo}";
    }
}
