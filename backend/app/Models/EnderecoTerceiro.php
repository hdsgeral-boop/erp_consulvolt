<?php

namespace App\Models;

use App\Models\Base\EnderecoTerceiroBase;

/**
 * enderecos_terceiros — endereço estruturado do terceiro, ligado pelo NIF + empresa como no legado
 * (third_party_addresses; saveThirdParty, ui_aux.js:641-649). Gravado pela ficha do terceiro (ServicoTerceiros).
 */
class EnderecoTerceiro extends EnderecoTerceiroBase
{
    /** Campos do endereço, pela ordem da ficha (Rua, Nº Porta, Bairro, Município, Província, País). */
    public const CAMPOS = ['rua', 'numero_porta', 'bairro', 'municipio', 'provincia', 'pais'];

    /**
     * Texto do endereço para os documentos (campo terceiros.endereco), a partir das partes preenchidas.
     *
     * @param  array<string, mixed>  $partes
     */
    public static function textoDe(array $partes): string
    {
        $v = fn (string $c) => trim((string) ($partes[$c] ?? ''));
        $rua = trim($v('rua').($v('numero_porta') !== '' ? ', n.º '.$v('numero_porta') : ''), ', ');

        return implode(', ', array_filter([$rua, $v('bairro'), $v('municipio'), $v('provincia'), $v('pais')], fn ($x) => $x !== ''));
    }
}
