<?php

namespace App\Http\Middleware;

use App\Exceptions\ErroNegocio;
use App\Support\Seguranca\ValidadorUploadSeguro;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rede de segurança central: aplica `ValidadorUploadSeguro::validarMinimo` a TODOS os ficheiros de TODOS os pedidos
 * /api (ADR-083): nomes com traversal/nulos, extensões executáveis/script/HTML/SVG/macros em QUALQUER posição
 * (`.php.jpg`) e código embutido (`<?php`, `<script`) nos formatos não comprimidos — um endpoint novo que receba
 * ficheiros já fica protegido, mesmo que o programador se esqueça de validar. O tipo, o tamanho e o conteúdo exigidos
 * por cada caso (imagem, documento, folha, PDF sem JavaScript, XLSX sem macros/zip bomb…) são aplicados pelos
 * serviços que usam o ficheiro, com o perfil estrito de `ValidadorUploadSeguro::validar`.
 */
final class ValidarUploadsSeguros
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ($this->achatar($request->allFiles()) as $f) {
            try {
                ValidadorUploadSeguro::validarMinimo($f);
            } catch (ErroNegocio $e) {
                Log::warning('seguranca.upload_recusado', ['codigo' => $e->codigo, 'rota' => $request->path(), 'ip' => $request->ip()]);

                throw $e;
            }
        }

        return $next($request);
    }

    /** @return list<UploadedFile> */
    private function achatar(array $coisas): array
    {
        $saida = [];
        foreach ($coisas as $c) {
            if ($c instanceof UploadedFile) {
                $saida[] = $c;
            } elseif (is_array($c)) {
                $saida = array_merge($saida, $this->achatar($c));
            }
        }

        return $saida;
    }
}
