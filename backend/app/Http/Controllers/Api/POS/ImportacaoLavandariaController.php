<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Services\POS\Lavandaria\ServicoImportacaoTabelasLavandaria;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** M-16 — POST /api/pos/lavandaria/importar: peças, serviços ou preços por peça e serviço (Excel/CSV), com simulação (lav_tabelas). */
final class ImportacaoLavandariaController extends Controller
{
    public function __invoke(Request $r, ServicoLeituraFolha $folha, ServicoImportacaoTabelasLavandaria $importacao): JsonResponse
    {
        $this->exigir('lav_tabelas');
        $d = $r->validate([
            'tipo' => ['required', Rule::in(ServicoImportacaoTabelasLavandaria::TIPOS)],
            'ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
            'simular' => ['nullable', 'boolean'],
        ], [], ['ficheiro' => 'ficheiro', 'tipo' => 'tabela a importar']);
        $simular = $r->has('simular') ? $r->boolean('simular') : true;
        $res = $importacao->importar($d['tipo'], $folha->linhas($r->file('ficheiro'), 'Modelo'), $simular);

        return RespostaApi::sucesso($res, $simular
            ? "Simulação: {$res['lidas']} linha(s) lida(s), {$res['validas']} válida(s), {$res['ignoradas']} com erros (nada foi gravado)."
            : "Importação concluída: {$res['criadas']} criada(s), {$res['actualizadas']} actualizada(s), {$res['ignoradas']} ignorada(s).");
    }
}
