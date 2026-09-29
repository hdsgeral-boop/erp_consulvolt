<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;

/** Por omissão: a ligação à AGT não está configurada (AGT_DRIVER=desligado). Os documentos ficam POR_ENVIAR. */
final class ClienteAgtDesligado implements ClienteAgt
{
    public function registar(string $nif, array $documentos, bool $simular = false): array
    {
        $this->recusar();
    }

    public function estado(string $nif, string $requestId): array
    {
        $this->recusar();
    }

    public function consultar(string $nif, string $documentNo): array
    {
        $this->recusar();
    }

    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array
    {
        $this->recusar();
    }

    public function saude(): array
    {
        return ['driver' => 'desligado', 'pronto_para_enviar' => false,
            'mensagem' => 'A ligação à AGT não está configurada neste servidor (AGT_DRIVER=direto ou intermedio).'];
    }

    private function recusar(): never
    {
        throw new ErroNegocio('A ligação à AGT não está configurada neste servidor.', 'AGT_DESLIGADA', 503);
    }
}
