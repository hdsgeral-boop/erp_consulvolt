<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;

/**
 * Estados do envio de um documento à AGT (vendas.fe_envio.estado) e transições permitidas.
 *
 *   POR_ENVIAR ──► ENVIADO ──► VALIDO            (resultCode 0/1/2, documentStatus V; P = válida com penalização)
 *        │            │  └────► INVALIDO ──► SUBSTITUIDO   (correcção: novo n.º, documentStatus C + rejectedDocumentNo — E46)
 *        │            └───────► ERRO (resultCode 9: cancelado → reenviar)
 *        ├──► REJEITADO (recusado na recepção, HTTP 400 por documento) ──► POR_ENVIAR (revalidar) | SUBSTITUIDO
 *        ├──► CONTINGENCIA (AGT indisponível: sem ligação/5xx/429; reenvio automático com o mesmo submissionUUID)
 *        └──► ERRO (configuração/serviço; reenvio automático com recuo)
 *
 * A anulação de um documento fiscal comunicado faz-se por nota de crédito (NC, também comunicada, com referenceInfo):
 * o documento original mantém o estado VALIDO na AGT.
 */
final class MaquinaEstadosAgt
{
    public const POR_ENVIAR = 'POR_ENVIAR';

    public const ENVIADO = 'ENVIADO';

    public const VALIDO = 'VALIDO';

    public const INVALIDO = 'INVALIDO';

    public const REJEITADO = 'REJEITADO';

    public const ERRO = 'ERRO';

    public const CONTINGENCIA = 'CONTINGENCIA';

    public const SUBSTITUIDO = 'SUBSTITUIDO';

    public const ESTADOS = [self::POR_ENVIAR, self::ENVIADO, self::VALIDO, self::INVALIDO, self::REJEITADO, self::ERRO, self::CONTINGENCIA, self::SUBSTITUIDO];

    /** Estados de onde o documento (re)entra no envio. null = nunca tentado. */
    public const PARA_ENVIAR = [null, self::POR_ENVIAR, self::ERRO, self::CONTINGENCIA];

    /** Estados que exigem um documento de correcção com novo número (E46). */
    public const PARA_CORRIGIR = [self::INVALIDO, self::REJEITADO];

    private const TRANSICOES = [
        '' => [self::POR_ENVIAR, self::ENVIADO, self::REJEITADO, self::ERRO, self::CONTINGENCIA],
        self::POR_ENVIAR => [self::POR_ENVIAR, self::ENVIADO, self::REJEITADO, self::ERRO, self::CONTINGENCIA],
        self::ERRO => [self::POR_ENVIAR, self::ENVIADO, self::REJEITADO, self::ERRO, self::CONTINGENCIA],
        self::CONTINGENCIA => [self::POR_ENVIAR, self::ENVIADO, self::REJEITADO, self::ERRO, self::CONTINGENCIA],
        self::ENVIADO => [self::ENVIADO, self::VALIDO, self::INVALIDO, self::ERRO],
        self::REJEITADO => [self::POR_ENVIAR, self::SUBSTITUIDO],
        self::INVALIDO => [self::SUBSTITUIDO],
        self::VALIDO => [self::VALIDO],
        self::SUBSTITUIDO => [],
    ];

    public static function pode(?string $de, string $para): bool
    {
        return in_array($para, self::TRANSICOES[$de ?? ''] ?? [], true);
    }

    public static function exigir(?string $de, string $para, string $documento = ''): void
    {
        if (! self::pode($de, $para)) {
            throw new ErroNegocio("Transição de estado AGT não permitida ({$documento}: ".($de ?? 'sem estado')." → {$para}).", 'TRANSICAO_AGT_INVALIDA', 422,
                ['de' => $de, 'para' => $para]);
        }
    }
}
