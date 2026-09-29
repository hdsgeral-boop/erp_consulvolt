<?php

namespace App\Services\Vendas\Agt;

/**
 * Serviços da facturação electrónica da AGT (quiosqueagt.minfin.gov.ao/doc-agt/faturacao-electronica/1/).
 * Cada operação devolve ['http' => int, 'resposta' => array] (+ 'pedido' quando simulada).
 */
interface ClienteAgt
{
    /** registarFactura — até 30 documentos (já construídos pela selagem, sem assinatura). */
    public function registar(string $nif, array $documentos, bool $simular = false): array;

    /** obterEstado de um pedido (requestID). */
    public function estado(string $nif, string $requestId): array;

    /** consultarFactura por número de documento. */
    public function consultar(string $nif, string $documentNo): array;

    /** solicitarSerie. */
    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array;

    /** Estado da ligação e da configuração, sem segredos. */
    public function saude(): array;
}
