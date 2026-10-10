<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\Produto;
use App\Services\Vendas\ServicoImagemProduto;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Imagem do produto (paridade 4.1.5). Envio com validação (só JPEG/PNG/WebP até 2 MB), armazenamento privado por
 * empresa e leitura autenticada — também usada pelo POS (cartões dos produtos).
 *
 *   GET    /api/vendas/produtos/imagens              ids dos produtos com imagem e o URL de cada um (para o POS)
 *   GET    /api/vendas/produtos/{id}/imagem          bytes da imagem (autenticado, empresa activa)
 *   POST   /api/vendas/produtos/{id}/imagem          multipart, campo «imagem»
 *   DELETE /api/vendas/produtos/{id}/imagem
 */
final class ImagemProdutoController extends Controller
{
    /** Quem pode ver produtos (ficha, facturação, armazém, compras e POS) pode ver a imagem. */
    private const VER = ['vendas_produtos_view', 'vendas_faturacao_view', 'armazem_stock_view', 'compras_pedidos_view', 'pos_view', 'pos_venda'];

    public function __construct(private readonly ServicoImagemProduto $imagens) {}

    public function index(): JsonResponse
    {
        $this->exigir(...self::VER);
        $lista = Produto::query()->where(fn ($q) => $q->whereNotNull('imagem_caminho')->orWhereNotNull('imagem_base64'))
            ->orderBy('id')->get(['id', 'imagem_atualizada_em'])   // sem ler o base64 do legado (pode ser grande)
            ->map(fn (Produto $p) => ['id' => $p->id,
                'imagem_url' => "/vendas/produtos/{$p->id}/imagem?v=".($p->imagem_atualizada_em ? strtotime((string) $p->imagem_atualizada_em) : 0)])->values();

        return RespostaApi::sucesso($lista, 'Produtos com imagem.');
    }

    public function show(int $produto): Response
    {
        $this->exigir(...self::VER);
        $img = $this->imagens->conteudo(Produto::query()->findOrFail($produto)) ?? throw new NotFoundHttpException('O produto não tem imagem.');

        return response($img['conteudo'], 200, [
            'Content-Type' => $img['mime'],
            'Content-Disposition' => 'inline; filename="produto-'.$produto.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function store(Request $request, int $produto): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $request->validate([
            'imagem' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:'.ServicoImagemProduto::TAMANHO_MAXIMO_KB],
        ], ['imagem.mimetypes' => 'Só são aceites imagens JPEG, PNG ou WebP.', 'imagem.max' => 'A imagem não pode ter mais de 2 MB.'], ['imagem' => 'imagem do produto']);
        $p = $this->imagens->guardar(Produto::query()->findOrFail($produto), $request->file('imagem'));

        return RespostaApi::sucesso(['id' => $p->id, 'imagem_url' => ServicoImagemProduto::url($p)], 'Imagem do produto guardada.');
    }

    public function destroy(int $produto): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $p = $this->imagens->remover(Produto::query()->findOrFail($produto));

        return RespostaApi::sucesso(['id' => $p->id, 'imagem_url' => null], 'Imagem do produto removida.');
    }
}
