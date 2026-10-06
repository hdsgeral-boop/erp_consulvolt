<?php

namespace App\Http\Controllers\Api\Logistica;

use App\Http\Controllers\Controller;
use App\Services\Logistica\ServicoImportacaoProdutos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** /api/logistica/importacao/{produtos|categorias} — importação Excel do ecrã Produtos (M-07, openImportModal do legado). */
final class ImportacaoProdutosController extends Controller
{
    public function __construct(private readonly ServicoImportacaoProdutos $importacao) {}

    public function modelo(string $entidade): BinaryFileResponse
    {
        $this->exigir('vendas_produtos_gerir');

        return response()->download($this->importacao->modelo($entidade), $entidade === 'categorias' ? 'Template_Categorias.xlsx' : 'Template_Produtos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** POST — {ficheiro, simular (por omissão true), actualizar_existentes}. */
    public function importar(Request $r, string $entidade): JsonResponse
    {
        $this->exigir('vendas_produtos_gerir');
        $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean'],
            'actualizar_existentes' => ['nullable', 'boolean']], [], ['ficheiro' => 'ficheiro']);
        $simular = $r->boolean('simular', true);
        $res = $this->importacao->importar($entidade, $r->file('ficheiro')->getRealPath(), $simular, $r->boolean('actualizar_existentes'));

        return RespostaApi::sucesso($res, ($simular ? 'Simulação: ' : '')."{$res['criados']} criado(s), {$res['actualizados']} actualizado(s), "
            .count($res['erros']).' com erro.'.($simular ? ' Nada foi gravado.' : ''));
    }
}
