<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Lavandaria\ModeloImportacaoRequest;
use App\Http\Requests\POS\Lavandaria\RelatorioPeriodoRequest;
use App\Models\TerminalPOS;
use App\Services\POS\Lavandaria\ServicoExportacaoLavandaria;
use App\Services\POS\Lavandaria\ServicoRelatorioLavandaria;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ficheiros Excel da lavandaria (F5): GET /api/pos/lavandaria/modelos/{tipo} (modelo .xlsx de importação, lav_tabelas) e
 * GET /api/pos/lavandaria/relatorio/excel (relatório do período em 7 folhas, mesmas permissões do relatório).
 */
final class ExportacaoLavandariaController extends Controller
{
    public function __construct(private readonly ServicoExportacaoLavandaria $exportacao) {}

    public function modelo(ModeloImportacaoRequest $r, string $tipo): BinaryFileResponse
    {
        [$caminho, $nome] = $this->exportacao->modelo($tipo);

        return response()->download($caminho, $nome)->deleteFileAfterSend();
    }

    public function relatorio(RelatorioPeriodoRequest $r, ServicoRelatorioLavandaria $relatorio): BinaryFileResponse
    {
        $d = $r->validated();
        $terminalId = isset($d['terminal_pos_id']) ? (int) $d['terminal_pos_id'] : null;
        $terminal = $terminalId ? TerminalPOS::query()->withTrashed()->find($terminalId) : null;
        [$caminho, $nome] = $this->exportacao->relatorio($relatorio->gerar($d['de'], $d['ate'], $terminalId), $terminal ? "{$terminal->codigo} · {$terminal->nome}" : 'Todos');

        return response()->download($caminho, $nome)->deleteFileAfterSend();
    }
}
