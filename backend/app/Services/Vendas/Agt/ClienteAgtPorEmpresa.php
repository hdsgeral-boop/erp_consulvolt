<?php

namespace App\Services\Vendas\Agt;

use App\Support\Tenancy\ContextoEmpresa;

/**
 * Escolhe o cliente da AGT em cada chamada, para a empresa activa: com a ligação da empresa activa (Vendas › Facturação
 * electrónica › Ligação à AGT) fala directamente com a AGT; sem ela, segue o driver do servidor (AGT_DRIVER:
 * desligado, direto ou intermedio), como antes.
 */
final class ClienteAgtPorEmpresa implements ClienteAgt
{
    public function __construct(private readonly ServicoLigacaoAgt $ligacoes) {}

    public function driver(): ClienteAgt
    {
        if (app(ContextoEmpresa::class)->definida() && $this->ligacoes->empresaTemLigacaoAtiva()) {
            return app(ClienteAgtDireto::class);
        }

        return match (config('erp.agt.driver')) {
            'direto' => app(ClienteAgtDireto::class),
            'intermedio' => app(ClienteAgtIntermedio::class),
            default => app(ClienteAgtDesligado::class),
        };
    }

    public function registar(string $nif, array $documentos, bool $simular = false, ?string $submissionUuid = null): array
    {
        return $this->driver()->registar($nif, $documentos, $simular, $submissionUuid);
    }

    public function estado(string $nif, string $requestId): array
    {
        return $this->driver()->estado($nif, $requestId);
    }

    public function consultar(string $nif, string $documentNo): array
    {
        return $this->driver()->consultar($nif, $documentNo);
    }

    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array
    {
        return $this->driver()->solicitarSerie($nif, $ano, $tipo, $estabelecimento, $contingencia);
    }

    public function saude(): array
    {
        return $this->driver()->saude();
    }
}
