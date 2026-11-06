<?php

namespace App\Http\Controllers\Api\POS;

use App\Http\Controllers\Controller;
use App\Http\Requests\POS\Prestacao\RelatoriosRequest;
use App\Models\TerminalPOS;
use App\Services\POS\ServicoExportacaoRelatoriosPOS;
use App\Services\POS\ServicoRelatoriosPOS;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /api/pos/relatorios/excel (F7): o painel dos relatórios do POS em .xlsx (7 folhas), com os mesmos filtros e as mesmas
 * permissões de GET /api/pos/relatorios. Descarga do ficheiro temporário, apagado depois do envio.
 */
final class ExportacaoRelatoriosPOSController extends Controller
{
    public function __construct(private readonly ServicoExportacaoRelatoriosPOS $exportacao, private readonly ServicoRelatoriosPOS $relatorios) {}

    public function excel(RelatoriosRequest $r): BinaryFileResponse
    {
        $f = $r->validated();
        $terminal = ! empty($f['terminal_pos_id']) ? TerminalPOS::query()->withTrashed()->find((int) $f['terminal_pos_id']) : null;
        $periodo = ($f['data_inicio'] ?? null) || ($f['data_fim'] ?? null)
            ? ($f['data_inicio'] ?? 'início').' a '.($f['data_fim'] ?? 'hoje')
            : 'Todos os dados';
        [$caminho, $nome] = $this->exportacao->relatorios($this->relatorios->painel($f), $periodo, $terminal ? "{$terminal->codigo} · {$terminal->nome}" : 'Todos');

        return response()->download($caminho, $nome, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
