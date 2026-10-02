<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoImportacaoLancamentos;
use App\Services\Contabilidade\ServicoSaldosHistoricos;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** /api/contabilidade/lancamentos/importar e /saldos-historicos — importações do ecrã "lancamentos" (ADR-055). */
final class ImportacaoLancamentosController extends Controller
{
    public function importar(Request $request, ServicoImportacaoLancamentos $servico): JsonResponse
    {
        $this->exigir('lancamentos_import');
        $d = $request->validate(['ficheiro' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean'],
            'aceitar_avisos' => ['nullable', 'boolean']], [], ['ficheiro' => 'ficheiro de lançamentos']);
        $r = $servico->importar($request->file('ficheiro')->getRealPath(), (bool) ($d['simular'] ?? false), (bool) ($d['aceitar_avisos'] ?? false));

        return $r['simulacao']
            ? RespostaApi::sucesso($r, "Ficheiro válido: {$r['documentos']} lançamento(s), {$r['linhas']} linha(s).")
            : RespostaApi::criado($r, "{$r['linhas']} linha(s) de lançamento importada(s) em ".count($r['lancamentos']).' lançamento(s).');
    }

    public function saldosHistoricos(int $ano, ServicoSaldosHistoricos $servico): JsonResponse
    {
        $this->exigir('lancamentos_saldos', 'lancamentos_view');

        return RespostaApi::sucesso($servico->obter($ano), 'Saldos históricos obtidos com sucesso.');
    }

    public function gravarSaldosHistoricos(Request $request, int $ano, ServicoSaldosHistoricos $servico): JsonResponse
    {
        $this->exigir('lancamentos_saldos');
        $d = $request->validate(['demo' => ['present', 'array'], 'demo.*' => ['nullable', 'numeric'], 'fluxo' => ['present', 'array'], 'fluxo.*' => ['nullable', 'numeric']],
            [], ['demo' => 'valores das notas DEMO', 'fluxo' => 'valores das notas de fluxo']);

        return RespostaApi::sucesso($servico->gravar($ano, $d['demo'], $d['fluxo']), "Histórico de {$ano} guardado com sucesso.");
    }

    /** GET /saldos-historicos/{ano}/modelo — modelo Excel (TIPO, CÓDIGO, DESCRIÇÃO, VALOR) com os valores gravados do ano. */
    public function modeloSaldosHistoricos(int $ano, ServicoSaldosHistoricos $servico): BinaryFileResponse
    {
        $this->exigir('lancamentos_saldos');
        $pasta = storage_path('app/copias');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }
        $caminho = $pasta.'/historico_'.Str::random(12).'.xlsx';
        $servico->modeloExcel($ano, $caminho);

        return response()->download($caminho, "Template_Importacao_Historico_{$ano}.xlsx")->deleteFileAfterSend();
    }

    public function importarSaldosHistoricos(Request $request, ServicoSaldosHistoricos $servico): JsonResponse
    {
        $this->exigir('lancamentos_saldos');
        $request->validate(['ficheiro' => ['required', 'file', 'max:5120', 'mimes:xlsx,xls,csv,txt']]);
        $r = $servico->lerFicheiro($request->file('ficheiro')->getRealPath());

        return RespostaApi::sucesso($r, 'Foram lidos '.(count($r['demo']) + count($r['fluxo'])).' valores: verifique e grave.');
    }
}
