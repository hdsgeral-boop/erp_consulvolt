<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Impressao\GerarPdfPosRequest;
use App\Services\POS\ServicoPdfA4POS;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * POST /api/pos/impressao/pdf — PDF A4 dos impressos do POS (F7). Descarga (Content-Disposition: attachment, nosniff, sem cache),
 * isolada pela empresa activa, com permissão por tipo de documento, limite de pedidos por minuto e registo de auditoria
 * (tipo, título e tamanho; nunca o conteúdo).
 */
final class PdfPOSController extends Controller
{
    public function __construct(private readonly ServicoPdfA4POS $pdf, private readonly ServicoAuditoria $auditoria) {}

    public function gerar(GerarPdfPosRequest $r): Response
    {
        $d = $r->validated();
        $u = Auth::user();
        $r = $this->pdf->gerar($d, $u ? ($u->nome_completo ?: $u->nome_utilizador) : null);
        $this->auditoria->registar('POS', 'Gerar PDF', "PDF A4 «{$d['titulo']}» ({$d['tipo']}, {$r['paginas']} pág., ".strlen($r['binario']).' bytes).');
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $r['nome']) ?: 'documento.pdf');

        return response($r['binario'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($r['nome']),
            'Content-Length' => (string) strlen($r['binario']),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
