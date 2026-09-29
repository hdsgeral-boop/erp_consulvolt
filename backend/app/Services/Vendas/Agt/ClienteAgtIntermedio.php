<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reutiliza o serviço intermédio do legado (servico_agt/servidor.js), que guarda as chaves e assina.
 * Útil enquanto esse serviço já estiver instalado e homologado. O token vem da configuração do servidor
 * (no legado ficava no localStorage do browser).
 */
final class ClienteAgtIntermedio implements ClienteAgt
{
    public function registar(string $nif, array $documentos, bool $simular = false): array
    {
        return $this->chamar('POST', 'facturas/registar', ['nif' => $nif, 'documentos' => array_values($documentos), 'simular' => $simular]);
    }

    public function estado(string $nif, string $requestId): array
    {
        return $this->chamar('POST', 'facturas/estado', ['nif' => $nif, 'requestID' => $requestId]);
    }

    public function consultar(string $nif, string $documentNo): array
    {
        return $this->chamar('POST', 'facturas/consultar', ['nif' => $nif, 'documentNo' => $documentNo]);
    }

    public function solicitarSerie(string $nif, int $ano, string $tipo, string $estabelecimento, bool $contingencia): array
    {
        return $this->chamar('POST', 'series/solicitar', ['nif' => $nif, 'ano' => $ano, 'tipo' => $tipo, 'estabelecimento' => $estabelecimento, 'contingencia' => $contingencia]);
    }

    public function saude(): array
    {
        return ['driver' => 'intermedio', 'url' => config('erp.agt.intermedio_url')] + $this->chamar('GET', 'saude');
    }

    private function chamar(string $metodo, string $rota, ?array $corpo = null): array
    {
        $url = rtrim((string) config('erp.agt.intermedio_url'), '/');
        $token = (string) config('erp.agt.intermedio_token');
        if (! $url || ! $token) {
            throw new ErroNegocio('Configure AGT_INTERMEDIO_URL e AGT_INTERMEDIO_TOKEN no servidor.', 'SEM_SERVICO', 503);
        }
        $local = in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost', 'host.docker.internal'], true);
        if (! str_starts_with($url, 'https://') && ! $local) {
            throw new ErroNegocio('Um serviço intermédio fora deste servidor tem de usar HTTPS.', 'CONFIG_AGT_INVALIDA', 503);
        }
        try {
            $r = Http::withToken($token)->acceptJson()->timeout((int) config('erp.agt.timeout') + 5)
                ->send($metodo, "{$url}/{$rota}", $corpo === null ? [] : ['json' => $corpo]);
        } catch (ConnectionException) {
            throw new ErroNegocio("O serviço intermédio não responde em {$url}.", 'SERVICO_INDISPONIVEL', 502);
        }
        $json = $r->json() ?? [];
        if (! $r->successful()) {
            throw new ErroNegocio($json['mensagem'] ?? "O serviço intermédio respondeu com erro {$r->status()}.", $json['erro'] ?? "HTTP_{$r->status()}", 502);
        }

        return $json;
    }
}
