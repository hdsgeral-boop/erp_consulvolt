<?php

namespace App\Support\Seguranca;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Encrypter do Laravel (AES-256-CBC+HMAC ou AES-256-GCM, com as chaves de `APP_KEY` e `APP_PREVIOUS_KEYS`) que sabe LER
 * também os payloads da outra cifra (ADR-085). Serve para trocar `APP_CIPHER` (CBC -> GCM) sem janela em que os segredos já
 * gravados fiquem ilegíveis: o que está gravado com a cifra antiga continua a decifrar-se, e tudo o que se grava de novo
 * usa a cifra configurada. `erp:seguranca:rodar-chave` recifra o que ficou com a cifra/chave antigas.
 *
 * Não implementa criptografia própria: delega sempre em dois `Illuminate\Encryption\Encrypter` (um por cifra).
 */
final class EncrypterCompativel extends Encrypter
{
    private ?Encrypter $alternativo = null;

    /** Constrói a partir do encrypter registado pelo Laravel (mesmas chaves, mesma cifra). */
    public static function de(Encrypter $base): self
    {
        $cifra = strtoupper((string) config('app.cipher', 'AES-256-CBC'));
        $novo = new self($base->getKey(), $cifra);
        $novo->previousKeys($base->getPreviousKeys());

        $outra = $cifra === 'AES-256-GCM' ? 'AES-256-CBC' : 'AES-256-GCM';
        $alt = new Encrypter($base->getKey(), $outra);
        $alt->previousKeys($base->getPreviousKeys());
        $novo->alternativo = $alt;

        return $novo;
    }

    public function decrypt($payload, $unserialize = true)
    {
        try {
            return parent::decrypt($payload, $unserialize);
        } catch (DecryptException $e) {
            if ($this->alternativo === null) {
                throw $e;
            }

            return $this->alternativo->decrypt($payload, $unserialize);   // payload da outra cifra (ou falha com a mesma excepção)
        }
    }

    /** O encrypter com a cifra e as chaves ACTUAIS apenas (sem as anteriores): para saber o que ainda falta recifrar. */
    public function soChaveActual(): Encrypter
    {
        return new Encrypter($this->getKey(), $this->cipherActual());
    }

    public function cipherActual(): string
    {
        return strtoupper((string) config('app.cipher', 'AES-256-CBC'));
    }
}
