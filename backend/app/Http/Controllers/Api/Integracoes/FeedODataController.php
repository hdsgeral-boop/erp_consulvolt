<?php

namespace App\Http\Controllers\Api\Integracoes;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Services\Integracoes\PowerBI\ServicoFeedBI;
use App\Services\Integracoes\PowerBI\ServicoTokensBI;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response as RespostaBase;

/**
 * GET /api/bi/odata[/$metadata | /{conjunto}] — feed OData v4 de leitura para o Power BI (decisão 25).
 *
 * Fora do Sanctum: autentica com um token de leitura da empresa (tokens_bi), enviado como
 *   Authorization: Bearer erpbi_…   ou   Authorization: Basic (utilizador qualquer, palavra-passe = token)
 * — o Power BI Desktop/Serviço usa a autenticação «Básica». Sem token válido: 401 com WWW-Authenticate (o Power BI pede
 * as credenciais). Respostas no formato OData (JSON minimal ou CSDL), nunca no envelope da API. Limite: 120 pedidos por
 * minuto por token.
 */
final class FeedODataController extends Controller
{
    public const PEDIDOS_POR_MINUTO = 120;

    private const NAO_SUPORTADOS = ['$filter', '$orderby', '$expand', '$apply', '$search', '$compute'];

    public function __construct(
        private readonly ServicoTokensBI $tokens,
        private readonly ServicoFeedBI $feed,
    ) {}

    public function __invoke(Request $r, ContextoEmpresa $contexto, ?string $conjunto = null): RespostaBase
    {
        $token = $this->tokens->autenticar($this->lerToken($r));
        if (! $token) {
            return $this->erro(401, 'Unauthorized', 'Token de leitura BI em falta, inválido, expirado ou revogado.')
                ->header('WWW-Authenticate', 'Basic realm="ERP Consulvolt Power BI", charset="UTF-8"');
        }
        $chave = "bi-odata:{$token->id}";
        if (RateLimiter::tooManyAttempts($chave, self::PEDIDOS_POR_MINUTO)) {
            return $this->erro(429, 'TooManyRequests', 'Demasiados pedidos com este token. Tente dentro de um minuto.')->header('Retry-After', (string) RateLimiter::availableIn($chave));
        }
        RateLimiter::hit($chave, 60);

        $permitidos = $token->conjuntos ? json_decode($token->conjuntos, true) : null;
        $base = rtrim(url('/api/bi/odata'), '/');
        if ($conjunto === null || $conjunto === '') {
            return $this->json($this->feed->documentoServico($base, $permitidos));
        }
        if ($conjunto === '$metadata') {
            return response($this->feed->metadata($permitidos), 200, ['Content-Type' => 'application/xml; charset=utf-8', 'OData-Version' => '4.0']);
        }
        if ($permitidos && ! in_array($conjunto, $permitidos, true)) {
            return $this->erro(403, 'Forbidden', "Este token não dá acesso ao conjunto {$conjunto}.");
        }
        foreach (self::NAO_SUPORTADOS as $op) {
            if ($r->query->has($op)) {
                return $this->erro(501, 'NotImplemented', "A opção {$op} não é suportada por este feed: carregue o conjunto e filtre no Power Query (o período pode ser limitado com data_inicio/data_fim).");
            }
        }
        $q = $r->query();
        foreach (['$top', '$skip'] as $op) {
            if (isset($q[$op]) && ! preg_match('/^\d{1,9}$/', (string) $q[$op])) {
                return $this->erro(400, 'BadRequest', "Valor inválido em {$op}.");
            }
        }
        $props = array_keys(ServicoFeedBI::propriedades(ServicoFeedBI::conjuntos()[$conjunto] ?? ['dimensoes' => [], 'medidas' => []]));
        $selecionar = isset($q['$select']) ? array_values(array_filter(array_map('trim', explode(',', (string) $q['$select'])))) : [];
        if ($selecionar && ($fora = array_diff($selecionar, $props))) {
            return $this->erro(400, 'BadRequest', 'Propriedade desconhecida em $select: '.implode(', ', $fora).'.');
        }
        try {
            $pagina = $contexto->executarComo((int) $token->empresa_id, fn () => $this->feed->pagina($conjunto, (int) $token->empresa_id, [
                'skip' => (int) ($q['$skip'] ?? 0), 'top' => isset($q['$top']) ? (int) $q['$top'] : null,
                'data_inicio' => $q['data_inicio'] ?? null, 'data_fim' => $q['data_fim'] ?? null,
                'incluir_apuramento' => filter_var($q['incluir_apuramento'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'contar' => strtolower((string) ($q['$count'] ?? '')) === 'true', 'selecionar' => $selecionar,
            ]));
        } catch (ErroNegocio $e) {
            return $this->erro($e->estadoHttp, $e->codigo, $e->getMessage());
        }
        $corpo = ['@odata.context' => "{$base}/\$metadata#{$conjunto}".($selecionar ? '('.implode(',', $selecionar).')' : '')];
        if ($pagina['total'] !== null) {
            $corpo['@odata.count'] = $pagina['total'];
        }
        $corpo['value'] = $pagina['linhas'];
        if ($pagina['mais']) {
            $seguinte = $q;
            $seguinte['$skip'] = (int) ($q['$skip'] ?? 0) + count($pagina['linhas']);
            if (isset($q['$top'])) {
                $seguinte['$top'] = (int) $q['$top'] - count($pagina['linhas']);
            }
            unset($seguinte['$count']);
            $corpo['@odata.nextLink'] = "{$base}/{$conjunto}?".http_build_query($seguinte, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->json($corpo);
    }

    private function lerToken(Request $r): ?string
    {
        $cabecalho = (string) $r->header('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $cabecalho, $m)) {
            return $m[1];
        }
        if (preg_match('/^Basic\s+(\S+)$/i', $cabecalho, $m)) {
            $par = base64_decode($m[1], true);
            if ($par === false) {
                return null;
            }
            [$utilizador, $senha] = array_pad(explode(':', $par, 2), 2, '');

            return $senha !== '' ? $senha : $utilizador;
        }

        return null;
    }

    private function json(array $corpo): JsonResponse
    {
        return response()->json($corpo, 200, ['Content-Type' => 'application/json; odata.metadata=minimal; odata.streaming=true; charset=utf-8', 'OData-Version' => '4.0'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function erro(int $estado, string $codigo, string $mensagem): Response|JsonResponse
    {
        return response()->json(['error' => ['code' => $codigo, 'message' => $mensagem]], $estado, ['OData-Version' => '4.0'], JSON_UNESCAPED_UNICODE);
    }
}
