<?php

namespace App\Http\Controllers\Api\Tesouraria;

use App\Http\Controllers\Controller;
use App\Models\SessaoCaixa;
use App\Services\Tesouraria\ServicoImportacaoCaixa;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Folha de Caixa › Baixar modelo / Importar movimentos (ronda 3, r3_teso; showImportFolhaCaixaModal do legado,
 * js/ui_folha_caixa.js:1625-1830). Autorização antes da validação; a sessão é sempre da empresa activa (PertenceEmpresa).
 */
final class ImportacaoCaixaController extends Controller
{
    public function __construct(private readonly ServicoImportacaoCaixa $importacao) {}

    /** GET /tesouraria/caixa/modelo-importacao — Template_Importacao_Caixa.xlsx. */
    public function modelo(): BinaryFileResponse
    {
        $this->exigir('teso_caixa_operar');

        return response()->download($this->importacao->modelo(), 'Template_Importacao_Caixa.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** POST /tesouraria/caixa/sessoes/{id}/importar — {ficheiro, simular (por omissão true)}; tudo ou nada. */
    public function importar(Request $r, int $id): JsonResponse
    {
        $this->exigir('teso_caixa_operar');
        $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean']], [], ['ficheiro' => 'ficheiro']);
        $simular = $r->boolean('simular', true);
        $res = $this->importacao->importar(SessaoCaixa::query()->findOrFail($id), $r->file('ficheiro')->getRealPath(), $simular);
        $n = count($res['validas']);
        $e = count($res['erros']);

        return RespostaApi::sucesso($res, match (true) {
            $simular => "Simulação: {$res['linhas_lidas']} linha(s); {$n} válida(s), {$e} com erro. Nada foi gravado.",
            $res['importado'] => "{$n} movimento(s) importado(s) na sessão.",
            default => "Nada foi importado: {$e} linha(s) com erro (a importação é tudo ou nada).",
        });
    }
}
