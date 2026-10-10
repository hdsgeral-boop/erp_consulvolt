<?php

namespace App\Http\Controllers\Api\RH;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\RH\ServicoParidadeCadastrosRH;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/rh/paridade/cadastros/{entidade} — Template, Importar Excel e Copiar de outra empresa para Funções, Infotipos e
 * Bancos (paridade com o sistema anterior, secção 6.5 do relatório de verificação). Permissões: as de gestão de cada
 * ecrã (rh_funcoes_gerir, rh_infotipos_gerir, rh_bancario_gerir) na empresa activa.
 */
final class ParidadeCadastrosRHController extends Controller
{
    private const PERMISSOES = ['funcoes' => ['rh_funcoes_gerir'], 'infotipos' => ['rh_infotipos_gerir'], 'bancos' => ['rh_bancario_gerir']];

    public function __construct(private readonly ServicoParidadeCadastrosRH $servico) {}

    /** GET /rh/paridade/cadastros/{entidade}/modelo — modelo XLSX (folha Template + «Como_Preencher»). */
    public function modelo(string $entidade): BinaryFileResponse
    {
        $this->exigirEntidade($entidade);
        $pasta = storage_path('app/tmp');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/modelo_rh_'.Str::random(16).'.xlsx';
        $this->servico->modeloExcel($entidade, $caminho);

        return response()->download($caminho, ServicoParidadeCadastrosRH::ENTIDADES[$entidade]['modelo'].'.xlsx')->deleteFileAfterSend();
    }

    /** POST /rh/paridade/cadastros/{entidade}/importar — multipart `ficheiro`, `simular`. */
    public function importar(Request $r, string $entidade, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigirEntidade($entidade);
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'], 'simular' => ['nullable', 'boolean']],
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo Excel).']);
        $res = $this->servico->importar($entidade, $folha->linhas($r->file('ficheiro')), (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, $res['simulacao'] ? 'Simulação da importação (nada foi gravado).'
            : "Importação concluída: {$res['criados']} criado(s), {$res['ignorados']} ignorado(s) (já existiam).");
    }

    /** GET /rh/paridade/empresas-origem — empresas a que o utilizador tem acesso (para «Copiar de outra empresa»). */
    public function empresasOrigem(Request $r): JsonResponse
    {
        $this->exigir(...array_merge(...array_values(self::PERMISSOES)));

        return RespostaApi::sucesso($this->servico->empresasOrigem($r->user()), 'Empresas de origem.');
    }

    /** POST /rh/paridade/cadastros/{entidade}/copiar — { empresa_origem_id, simular } */
    public function copiar(Request $r, string $entidade): JsonResponse
    {
        $this->exigirEntidade($entidade);
        // Bancos (com conta contabilística e NIF) não se copiam entre empresas: decisão do utilizador 2026-10-10 (não é seguro)
        if ($entidade === 'bancos') {
            throw new ErroNegocio('Os bancos não se copiam de outra empresa: registe-os nesta empresa ou importe-os do Excel.', 'RH_COPIA_BANCOS_PROIBIDA', 403);
        }
        $d = $r->validate(['empresa_origem_id' => ['required', 'integer', 'min:1'], 'simular' => ['nullable', 'boolean']]);
        $res = $this->servico->copiar($entidade, (int) $d['empresa_origem_id'], $r->user(), (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, ($res['simulacao'] ? 'Simulação (nada foi gravado): ' : '')
            ."{$res['criados']} registo(s) copiado(s) de {$res['origem']}, {$res['ignorados']} ignorado(s) (já existiam).");
    }

    /** GET /rh/paridade/regras-horas-extra — regras da empresa activa (gravadas em PUT /sistema/empresa-ativa/horas-extra). */
    public function regrasHorasExtra(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('infotipos_view', 'rh_infotipos_gerir');
        $e = Empresa::query()->findOrFail($contexto->obrigatorio());

        return RespostaApi::sucesso([
            'he_percentagem_1' => (float) ($e->he_percentagem_1 ?? 50), 'he_limite_horas' => (float) ($e->he_limite_horas ?? 30), 'he_percentagem_2' => (float) ($e->he_percentagem_2 ?? 75),
        ], 'Regras das horas extra.');
    }

    private function exigirEntidade(string $entidade): void
    {
        abort_unless(isset(self::PERMISSOES[$entidade]), 404);
        $this->exigir(...self::PERMISSOES[$entidade]);
    }
}
