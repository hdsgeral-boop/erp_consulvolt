<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use App\Services\Sistema\ServicoCambios;
use App\Services\Sistema\ServicoCambiosBAI;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Services\Sistema\ServicoMoedas;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/sistema/moedas e /api/sistema/cambios — config_moedas (consulta) e config_moedas_gerir (gestão).
 * A lista de moedas e a consulta do câmbio numa data servem todos os módulos (sem permissão específica, como o
 * Moedas.listar / obterCambio do legado).
 */
final class MoedaController extends Controller
{
    private const VER = ['config_moedas_view', 'config_moedas_gerir'];

    public function __construct(
        private readonly ServicoMoedas $moedas,
        private readonly ServicoCambiosBAI $bai,
        private readonly ServicoGestaoEmpresas $gestao,
    ) {}

    /** GET /api/sistema/moedas — lista de moedas; a moeda funcional da empresa activa segue em metadados.moeda_funcional. */
    public function index(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $d = $r->validate(['ativas' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->moedas->moedas((bool) ($d['ativas'] ?? false)), 'Moedas obtidas com sucesso.', 200,
            ['moeda_funcional' => $this->moedas->moedaFuncional($contexto->obrigatorio())['codigo_moeda']]);
    }

    /** GET /api/sistema/moedas/funcional — moeda funcional da empresa activa (config_moedas_view ou config_moedas_gerir). */
    public function obterFuncional(ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir(...self::VER);

        return RespostaApi::sucesso($this->moedas->moedaFuncional($contexto->obrigatorio()), 'Moeda funcional da empresa.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['codigo' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'], 'nome' => ['required', 'string', 'max:255'], 'simbolo' => ['required', 'string', 'max:10'],
            'casas_decimais' => ['nullable', 'integer', 'between:0,6']], ['codigo.regex' => 'O código da moeda tem 3 letras (ISO 4217).']);

        return RespostaApi::criado($this->moedas->criarMoeda($d), 'Moeda criada com sucesso.');
    }

    public function update(Request $r, int $moeda): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['nome' => ['sometimes', 'string', 'max:255'], 'simbolo' => ['sometimes', 'string', 'max:10'], 'ativo' => ['sometimes', 'boolean']]);

        return RespostaApi::sucesso($this->moedas->atualizarMoeda(Moeda::query()->findOrFail($moeda), $d), 'Moeda actualizada com sucesso.');
    }

    /** PUT /api/sistema/moedas/funcional — moeda funcional da empresa activa (guardarMoedaFuncional). */
    public function funcional(Request $r, ContextoEmpresa $contexto): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['codigo_moeda' => ['required', 'string', 'size:3']]);
        $empresa = $this->gestao->obter($contexto->obrigatorio(), $r->user());

        return RespostaApi::sucesso($this->gestao->apresentar($this->gestao->atualizar($empresa, ['moeda_funcional' => strtoupper($d['codigo_moeda'])], $r->user())),
            'Moeda funcional actualizada.');
    }

    public function cambios(Request $r): JsonResponse
    {
        $this->exigir(...self::VER);
        $f = $r->validate(['codigo_moeda' => ['nullable', 'string', 'size:3'], 'de' => ['nullable', 'date'], 'ate' => ['nullable', 'date'],
            'ambito' => ['nullable', Rule::in([ServicoMoedas::AMBITO_TODAS, ServicoMoedas::AMBITO_EMPRESA])], 'por_pagina' => ['nullable', 'integer', 'min:1', 'max:1000']]);

        return RespostaApi::paginado($this->moedas->listarCambios($f), null, 'Câmbios obtidos com sucesso.');
    }

    /** GET /api/sistema/cambios/consultar?codigo_moeda=USD&data=AAAA-MM-DD — câmbio válido na data (consultarCambio). */
    public function consultar(Request $r, ServicoCambios $cambios, ContextoEmpresa $contexto): JsonResponse
    {
        $d = $r->validate(['codigo_moeda' => ['required', 'string', 'size:3'], 'data' => ['nullable', 'date']]);
        $data = $d['data'] ?? now()->toDateString();
        $res = $cambios->obter($contexto->obrigatorio(), strtoupper($d['codigo_moeda']), $data);

        return RespostaApi::sucesso($res, $res ? ($res['exata'] ? 'Câmbio da data.' : 'Não há câmbio na data: é usado o último anterior.') : 'Não existe câmbio registado até à data.');
    }

    public function guardarCambio(Request $r, ?int $cambio = null): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['data_taxa' => ['required', 'date'], 'codigo_moeda' => ['required', 'string', 'size:3'], 'taxa' => ['required'],
            'ambito' => ['required', Rule::in([ServicoMoedas::AMBITO_TODAS, ServicoMoedas::AMBITO_EMPRESA])], 'fonte_dados' => ['nullable', 'string', 'max:50'],
            'substituir' => ['nullable', 'boolean']], [], ['data_taxa' => 'data', 'codigo_moeda' => 'moeda']);
        $existente = $cambio ? TaxaCambio::query()->findOrFail($cambio) : null;
        $t = $this->moedas->gravarCambio($d, $existente);

        return $t->wasRecentlyCreated
            ? RespostaApi::criado($this->moedas->apresentarCambio($t), 'Câmbio registado.')
            : RespostaApi::sucesso($this->moedas->apresentarCambio($t), 'Câmbio gravado.');
    }

    public function eliminarCambio(int $cambio): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $this->moedas->eliminarCambio(TaxaCambio::query()->findOrFail($cambio));

        return RespostaApi::sucesso(null, 'Câmbio eliminado.');
    }

    /**
     * POST /api/sistema/cambios/importar — linhas da folha (Data | Moeda | Taxa | Origem | Âmbito), em JSON `linhas`
     * ou num ficheiro XLSX/XLS/CSV em multipart (`ficheiro`), lido no servidor; decisao IGNORAR|ACTUALIZAR e simular.
     */
    public function importar(Request $r, ServicoLeituraFolha $folha): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['linhas' => ['required_without:ficheiro', 'array', 'min:1', 'max:20000'], 'linhas.*' => ['array'],
            'ficheiro' => ['required_without:linhas', 'file', 'max:20480', 'extensions:xlsx,xls,csv,txt'],
            'decisao' => ['nullable', Rule::in(['IGNORAR', 'ACTUALIZAR'])], 'simular' => ['nullable', 'boolean']],
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV.']);
        $simular = (bool) ($d['simular'] ?? false);
        $linhas = $r->hasFile('ficheiro') ? $folha->linhas($r->file('ficheiro'), 'Cambios') : $r->input('linhas');
        $res = $this->moedas->importarCambios($linhas, $d['decisao'] ?? 'IGNORAR', $simular);

        return RespostaApi::sucesso($res, $simular ? 'Simulação da importação de câmbios.'
            : "Importação concluída: {$res['importados']} novo(s), {$res['actualizados']} actualizado(s), ".count($res['rejeitadas']).' rejeitado(s).');
    }

    /** GET /api/sistema/cambios/bai — pré-visualização dos câmbios do BAI. */
    public function previsualizarBai(): JsonResponse
    {
        $this->exigir('config_moedas_gerir');

        return RespostaApi::sucesso($this->bai->previsualizar(), 'Câmbios do BAI (média de divisas).');
    }

    /** POST /api/sistema/cambios/bai — grava os câmbios do BAI das moedas indicadas (o servidor volta a ler a página). */
    public function gravarBai(Request $r): JsonResponse
    {
        $this->exigir('config_moedas_gerir');
        $d = $r->validate(['moedas' => ['required', 'array', 'min:1'], 'moedas.*' => ['string', 'size:3']]);
        $res = $this->bai->gravar($d['moedas']);

        return RespostaApi::sucesso($res, "Câmbios do BAI gravados: {$res['novos']} novo(s), {$res['substituidos']} substituído(s).");
    }
}
