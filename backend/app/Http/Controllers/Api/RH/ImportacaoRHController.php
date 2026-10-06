<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use App\Services\RH\ServicoContratosTrabalho;
use App\Services\RH\ServicoFolhaSalarial;
use App\Services\RH\ServicoImportacaoRH;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/rh/importacoes — importações Excel do RH (A-08 cálculo, A-09 colaboradores e contratos, M-13 produtividade) e
 * /api/rh/contratos/massa (A-09 rubricas em massa). As tarefas são as do legado: colaboradores_import, contratos_import,
 * calcular_lancar, rh_prod_registar e contratos_new.
 */
final class ImportacaoRHController extends Controller
{
    private const PERMISSOES = ['calculo' => ['calcular_lancar', 'calcular_bulk'], 'colaboradores' => ['colaboradores_import'], 'contratos' => ['contratos_import'],
        'produtividade' => ['rh_prod_registar']];

    public function __construct(private readonly ServicoImportacaoRH $importacao) {}

    /** GET /rh/importacoes/modelos/{entidade} — modelo XLSX (folha de dados, «Como_Preencher» e referência). */
    public function modelo(string $entidade): BinaryFileResponse
    {
        abort_unless(isset(ServicoImportacaoRH::MODELOS[$entidade]), 404);
        $this->exigir(...self::PERMISSOES[$entidade]);
        $pasta = storage_path('app/tmp');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/modelo_rh_'.Str::random(12).'.xlsx';
        $this->importacao->modeloExcel($entidade, $caminho);
        $nomes = ['calculo' => 'Template_Calculo', 'colaboradores' => 'Template_Colaboradores', 'contratos' => 'Template_Contratos_Vertical', 'produtividade' => 'Template_Produtividade'];

        return response()->download($caminho, $nomes[$entidade].'.xlsx')->deleteFileAfterSend();
    }

    /** POST /rh/importacoes/colaboradores — multipart `ficheiro`, `decisao` IGNORAR|ACTUALIZAR, `simular`. */
    public function colaboradores(Request $r, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('colaboradores_import');
        $d = $this->validar($r, ['decisao' => ['nullable', Rule::in(['IGNORAR', 'ACTUALIZAR'])]]);
        $res = $this->importacao->importarColaboradores($folha->linhas($r->file('ficheiro'), 'Colaboradores'), $d['decisao'] ?? 'ACTUALIZAR', (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    /** POST /rh/importacoes/contratos — modelo vertical (uma linha por rubrica; o mesmo NIF agrupa num contrato). */
    public function contratos(Request $r, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('contratos_import');
        $d = $this->validar($r);
        $res = $this->importacao->importarContratos($folha->linhas($r->file('ficheiro')), (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    /** POST /rh/salarios/periodos/{id}/importar-excel — lançamentos do cálculo a partir de Excel (importCalculoExcel). */
    public function calculo(Request $r, int $id, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('calcular_lancar', 'calcular_bulk');
        $d = $this->validar($r);
        $res = $this->importacao->importarCalculo(PeriodoProcessamentoSalarial::query()->findOrFail($id), $folha->linhas($r->file('ficheiro')), (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    /** POST /rh/produtividade/periodos/{periodo}/importar — registos do período de produtividade (prodImportar). */
    public function produtividade(Request $r, int $periodo, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $d = $this->validar($r, ['decisao' => ['nullable', Rule::in(['IGNORAR', 'ACTUALIZAR'])]]);
        $res = $this->importacao->importarProdutividade(PeriodoProdutividadeRH::query()->findOrFail($periodo), $folha->linhas($r->file('ficheiro'), 'Produtividade'),
            $d['decisao'] ?? 'IGNORAR', (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $this->mensagem($res));
    }

    /** POST /rh/contratos/massa — aplicar rubricas em massa (contratosEmMassa/aplicarRubricasEmMassa). */
    public function contratosMassa(Request $r, ServicoContratosTrabalho $contratos): JsonResponse
    {
        $this->exigir('contratos_new');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'colaboradores' => ['required', 'array', 'min:1', 'max:2000'], 'colaboradores.*' => ['integer', Rule::exists('colaboradores', 'id')->where('empresa_id', $empresa)],
            'rubricas' => ['required', 'array', 'min:1', 'max:50'],
            'rubricas.*.infotipo_salarial_id' => ['required', 'integer', Rule::exists('infotipos_salariais', 'id')->where('empresa_id', $empresa)],
            'rubricas.*.valor_mes' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'modo' => ['nullable', Rule::in(['SUBSTITUIR', 'MANTER'])], 'criar' => ['nullable', 'boolean'], 'simular' => ['nullable', 'boolean'],
            'novos.data_inicio' => ['nullable', 'date'], 'novos.dias_contrato_mes' => ['nullable', 'integer', 'min:1', 'max:31'], 'novos.horas_por_dia' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'novos.codigo_moeda' => ['nullable', 'string', 'size:3'],
        ]);
        $res = $contratos->aplicarRubricasEmMassa($d['colaboradores'], $d['rubricas'], $d['modo'] ?? 'SUBSTITUIR', (bool) ($d['criar'] ?? false), $d['novos'] ?? [],
            (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, ($res['simulacao'] ? 'Simulação: ' : '').count($res['actualizados']).' contrato(s) actualizado(s) e '.count($res['criados']).' criado(s).');
    }

    /** GET /rh/contratos/simulacao?mes_ano=MM/AAAA — massa salarial a partir dos contratos, sem período nem gravação. */
    public function simularContratos(Request $r, ServicoFolhaSalarial $folha): JsonResponse
    {
        $this->exigir('contratos_view', 'calcular_view');
        $d = $r->validate(['mes_ano' => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{4}$/']]);

        return RespostaApi::sucesso($folha->simularContratos($d['mes_ano']), 'Simulação da massa salarial (nada foi gravado).');
    }

    private function validar(Request $r, array $extra = []): array
    {
        return $r->validate(['ficheiro' => ['required', 'file', 'max:20480', 'extensions:xlsx,xls,csv,txt'], 'simular' => ['nullable', 'boolean']] + $extra,
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo Excel).']);
    }

    private function mensagem(array $res): string
    {
        return $res['simulacao'] ? 'Simulação da importação (nada foi gravado).'
            : "Importação concluída: {$res['criados']} criado(s), {$res['actualizados']} actualizado(s), {$res['ignorados']} ignorado(s).";
    }
}
