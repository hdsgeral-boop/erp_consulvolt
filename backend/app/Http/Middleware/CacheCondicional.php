<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respostas GET com imagens (logótipos em data URI): ETag forte do conteúdo e 304 sem corpo quando o browser já tem a
 * mesma versão, com o Cache-Control indicado na rota:
 *  - `publico`  — dados públicos do endereço (ecrã de entrada): 5 min em cache e revalidação em segundo plano;
 *  - `privado`  — dados da sessão: o browser guarda, mas revalida sempre (304 se não mudou);
 *  - `versao`   — o URL leva a versão do conteúdo (?v=…): guardado um ano sem revalidar; sem ?v cai em `privado`.
 */
final class CacheCondicional
{
    public function handle(Request $request, Closure $next, string $modo = 'privado'): Response
    {
        $resposta = $next($request);
        if (! $request->isMethod('GET') || $resposta->getStatusCode() !== 200) {
            return $resposta;
        }

        // só os dados: o envelope da API leva metadados.executado_em, que muda em cada pedido
        $corpo = json_decode((string) $resposta->getContent(), true);
        $resposta->setEtag(md5(is_array($corpo) && array_key_exists('dados', $corpo) ? json_encode($corpo['dados']) : (string) $resposta->getContent()));
        match (true) {
            $modo === 'publico' => $resposta->headers->set('Cache-Control', 'public, max-age=300, stale-while-revalidate=86400'),
            $modo === 'versao' && $request->filled('v') => $resposta->headers->set('Cache-Control', 'private, max-age=31536000, immutable'),
            default => $resposta->headers->set('Cache-Control', 'private, no-cache'),
        };
        $resposta->isNotModified($request);   // com If-None-Match igual: 304 sem corpo

        return $resposta;
    }
}
