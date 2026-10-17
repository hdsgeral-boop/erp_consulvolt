<?php

namespace App\Http\Controllers\Api\Projetos;

use App\Http\Controllers\Controller;
use App\Models\Projeto;
use App\Services\Projetos\ServicoFolhaMedicaoProjetos;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/projetos/{projeto}/medicoes/modelo e /medicoes/importar — folha de medição por pessoa: modelo Excel (tarefas
 * medíveis da pessoa, listas de referência) e importação com simulação, validação linha a linha e transacção.
 * Ler o modelo exige ver o projecto; importar exige registar execução (proj_execucao ou proj_gerir), como a folha.
 */
final class FolhaMedicaoController extends Controller
{
    private const EXECUTAR = ['proj_execucao', 'proj_gerir'];

    public function __construct(private readonly ServicoFolhaMedicaoProjetos $folha) {}

    public function modelo(Request $r, int $projeto): BinaryFileResponse
    {
        $this->exigir(...ProjetoController::LER);
        $d = $r->validate(['membro_id' => ['nullable', 'integer', 'min:1']]);
        $p = Projeto::query()->findOrFail($projeto);
        $pasta = storage_path('app/tmp');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/folha_medicao_'.Str::random(12).'.xlsx';
        $this->folha->modeloExcel($p, $caminho, isset($d['membro_id']) ? (int) $d['membro_id'] : null);
        $nome = 'Folha_Medicao_'.(preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($p->codigo ?: $p->id)) ?: $p->id).'.xlsx';

        return response()->download($caminho, $nome)->deleteFileAfterSend();
    }

    /** POST multipart: `ficheiro` (XLSX/XLS/CSV) e `simular`. */
    public function importar(Request $r, int $projeto, ServicoLeituraFolha $leitura): JsonResponse
    {
        $this->exigir(...self::EXECUTAR);
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'extensions:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean']],
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo da folha de medição).']);
        $p = Projeto::query()->findOrFail($projeto);
        $res = $this->folha->importar($p, $leitura->linhas($r->file('ficheiro'), 'Medicoes'), (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $res['simulacao'] ? 'Simulação da importação (nada foi gravado).'
            : "Importação concluída: {$res['criados']} medição(ões) registada(s), {$res['ignorados']} já registada(s), {$res['repetidos']} repetida(s) no ficheiro.");
    }
}
