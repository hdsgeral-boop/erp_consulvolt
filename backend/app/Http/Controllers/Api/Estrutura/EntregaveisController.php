<?php

namespace App\Http\Controllers\Api\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Api\RH\EstruturaController;
use App\Http\Controllers\Controller;
use App\Models\ElegibilidadeProdutividadeRH;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\ImpedimentoEntregavel;
use App\Models\PeriodoProdutividadeRH;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use App\Services\Estrutura\ServicoEntregaveis;
use App\Services\Estrutura\ServicoEntregaveisCRM;
use App\Services\Estrutura\ServicoEntregaveisProdutividade;
use App\Services\Estrutura\ServicoImportacaoEntregas;
use App\Services\RH\ServicoProdutividade;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /api/rh/estrutura — tarefas das unidades, entregáveis, execuções (entregas), validação, impedimentos, atrasos e ponte CRM;
 * /api/rh/produtividade — conversão dos entregáveis em produtividade e elegibilidade (Fase J, ADR-078).
 * Ver: as vistas da estrutura (EstruturaController::VER); editar: est_editar. Entregas: est_editar OU responsável da cadeia OU o
 * próprio executor (regra no serviço); validar/decidir: responsável da cadeia ou est_validar_entregas (regra no serviço).
 */
final class EntregaveisController extends Controller
{
    public function __construct(
        private readonly ServicoEntregaveis $entregaveis,
        private readonly ServicoEntregaveisCRM $crm,
        private readonly ServicoEntregaveisProdutividade $conversao,
        private readonly ServicoProdutividade $produtividade,
        private readonly ServicoImportacaoEntregas $importacao,
    ) {}

    // ───────────── Tarefas ─────────────

    /** GET /estrutura/unidades/{u}/tarefas — tarefas da unidade com os entregáveis (resumo do período, item, dono, impedimento aberto) e atrasos. */
    public function daUnidade(int $unidade): JsonResponse
    {
        $this->exigir(...EstruturaController::VER);
        $u = UnidadeOrganica::query()->findOrFail($unidade);

        return RespostaApi::sucesso($this->entregaveis->daUnidade($u->id) + ['unidade' => $u->only(['id', 'codigo', 'nome', 'colaborador_responsavel_id'])], 'Tarefas e entregáveis da unidade.');
    }

    public function gravarTarefa(Request $r, ?int $tarefa = null): JsonResponse
    {
        $this->exigir('est_editar');
        $d = $r->validate(['unidade_organica_id' => [$tarefa ? 'nullable' : 'required', 'integer'], 'codigo' => ['nullable', 'string', 'max:50'], 'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:5000'], 'url_instrucoes' => ['nullable', 'string', 'max:500'], 'periodicidade' => ['nullable', Rule::in(TarefaUnidadeOrganica::PERIODICIDADES)],
            'colaborador_responsavel_id' => ['nullable', 'integer'], 'posto_trabalho_id' => ['nullable', 'integer'], 'ativo' => ['nullable', 'boolean'], 'ordem' => ['nullable', 'integer']]);
        $t = $tarefa ? TarefaUnidadeOrganica::query()->findOrFail($tarefa) : null;
        $res = $this->entregaveis->gravarTarefa($d, $t);

        return $t ? RespostaApi::sucesso($res, 'Tarefa actualizada.') : RespostaApi::criado($res, 'Tarefa criada.');
    }

    public function eliminarTarefa(int $tarefa): JsonResponse
    {
        $this->exigir('est_editar');
        $this->entregaveis->eliminarTarefa(TarefaUnidadeOrganica::query()->findOrFail($tarefa));

        return RespostaApi::sucesso(null, 'Tarefa eliminada.');
    }

    // ───────────── Entregáveis ─────────────

    public function gravarEntregavel(Request $r, ?int $tarefa = null, ?int $entregavel = null): JsonResponse
    {
        $this->exigir('est_editar');
        $d = $r->validate(['nome' => ['required', 'string', 'max:255'], 'descricao' => ['nullable', 'string', 'max:5000'], 'metrica' => ['nullable', Rule::in(ServicoProdutividade::METRICAS)],
            'unidade' => ['nullable', 'string', 'max:20'], 'meta' => ['nullable', 'numeric', 'min:0'], 'item_produtividade_id' => ['nullable', 'integer'], 'colaborador_id' => ['nullable', 'integer'],
            'origem_dados' => ['nullable', Rule::in(EntregavelTarefaUnidade::ORIGENS_DADOS)], 'crm_filtro' => ['nullable', 'array'], 'crm_filtro.tipos' => ['nullable', 'array'],
            'crm_filtro.tipos.*' => ['string', 'max:20'], 'crm_filtro.funil_vendas_crm_id' => ['nullable', 'integer'], 'crm_filtro.so_ganhas' => ['nullable', 'boolean'],
            'prazo_dia' => ['nullable', 'integer', 'between:1,31'], 'prazo_mes' => ['nullable', 'integer', 'between:1,13'], 'paga_fora_prazo' => ['nullable', 'boolean'], 'alerta' => ['nullable', 'boolean'],
            'multa_valor' => ['nullable', 'numeric', 'min:0'], 'multa_descricao' => ['nullable', 'string', 'max:255'], 'avaliacao' => ['nullable', 'boolean'],
            'peso_avaliacao' => ['nullable', 'numeric', 'between:0,100'], 'ativo' => ['nullable', 'boolean'], 'ordem' => ['nullable', 'integer']]);
        $e = $entregavel ? EntregavelTarefaUnidade::query()->findOrFail($entregavel) : null;
        $t = $tarefa ? TarefaUnidadeOrganica::query()->findOrFail($tarefa) : null;
        $res = $this->entregaveis->gravarEntregavel($d, $e, $t);

        return $e ? RespostaApi::sucesso($res, 'Entregável actualizado.') : RespostaApi::criado($res, 'Entregável criado.');
    }

    /** PUT /estrutura/entregaveis/{e} */
    public function actualizarEntregavel(Request $r, int $entregavel): JsonResponse
    {
        return $this->gravarEntregavel($r, null, $entregavel);
    }

    public function eliminarEntregavel(int $entregavel): JsonResponse
    {
        $this->exigir('est_editar');
        $this->entregaveis->eliminarEntregavel(EntregavelTarefaUnidade::query()->findOrFail($entregavel));

        return RespostaApi::sucesso(null, 'Entregável eliminado.');
    }

    public function copiar(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir('est_editar');
        $d = $r->validate(['tarefa_destino_id' => ['nullable', 'integer'], 'nome' => ['nullable', 'string', 'max:255']]);

        return RespostaApi::criado($this->entregaveis->copiarEntregavel(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $d['tarefa_destino_id'] ?? null, $d['nome'] ?? null), 'Entregável copiado.');
    }

    public function criarItem(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir('est_editar', 'rh_prod_config');
        $d = $r->validate(['codigo' => ['nullable', 'string', 'max:20'], 'preco_unitario' => ['required', 'numeric', 'gt:0', 'max:99999999999'], 'infotipo_salarial_id' => ['required', 'integer'],
            'minimo' => ['nullable', 'numeric', 'min:0'], 'maximo' => ['nullable', 'numeric', 'gt:0']]);
        $e = EntregavelTarefaUnidade::query()->findOrFail($entregavel);
        $item = $this->entregaveis->criarItemDeProdutividade($e, $d);

        return RespostaApi::criado(['item' => $item, 'entregavel' => $e->refresh()], "Item {$item->codigo} criado e ligado ao entregável.");
    }

    /** GET /estrutura/entregaveis/sugerir-codigo?nome= — código sugerido para o item. */
    public function sugerirCodigo(Request $r): JsonResponse
    {
        $this->exigir(...EstruturaController::VER);
        $f = $r->validate(['nome' => ['required', 'string', 'max:255']]);

        return RespostaApi::sucesso(['codigo' => $this->entregaveis->sugerirCodigo($f['nome'])], 'Código sugerido.');
    }

    // ───────────── Execuções ─────────────

    public function execucoes(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir(...EstruturaController::VER);
        $f = $r->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d'], 'validacao' => ['nullable', Rule::in(ExecucaoEntregavel::VALIDACOES)]]);

        return RespostaApi::sucesso($this->entregaveis->execucoesDe(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $f), 'Entregas do entregável.');
    }

    /** PUT /estrutura/execucoes/{x} */
    public function actualizarExecucao(Request $r, int $execucao): JsonResponse
    {
        return $this->gravarExecucao($r, null, $execucao);
    }

    public function gravarExecucao(Request $r, ?int $entregavel = null, ?int $execucao = null): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);
        $x = $execucao ? ExecucaoEntregavel::query()->findOrFail($execucao) : null;
        $e = EntregavelTarefaUnidade::query()->findOrFail($entregavel ?? $x?->entregavel_id);
        $d = $r->validate(['colaborador_id' => [$x ? 'nullable' : 'required', 'integer'], 'quantidade' => ['required', 'numeric', 'gt:0'], 'data' => ['nullable', 'date_format:Y-m-d'],
            'observacoes' => ['nullable', 'string', 'max:2000'], 'url_evidencia' => ['nullable', 'string', 'max:500']]);
        $this->entregaveis->exigirPodeRegistar($e, (int) ($d['colaborador_id'] ?? $x?->colaborador_id));
        $res = $this->entregaveis->gravarExecucao($e, $d, $x);

        return $x ? RespostaApi::sucesso($res, 'Entrega actualizada (volta a ficar por validar).') : RespostaApi::criado($res, 'Entrega registada.');
    }

    /** GET /estrutura/entregaveis/{e}/execucoes/modelo — modelo Excel para importar entregas (Fase K). */
    public function modeloExecucoes(int $entregavel): BinaryFileResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);
        $e = EntregavelTarefaUnidade::query()->findOrFail($entregavel);
        $this->importacao->exigirPode($e);
        $pasta = storage_path('app/tmp');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/modelo_entregas_'.Str::random(12).'.xlsx';
        $this->importacao->modelo($e, $caminho);

        return response()->download($caminho, 'Modelo_Entregas_'.Str::slug($e->nome, '_').'.xlsx')->deleteFileAfterSend();
    }

    /** POST /estrutura/entregaveis/{e}/execucoes/importar — multipart `ficheiro`; `simular` (omissão true): tudo ou nada, origem EXCEL (Fase K). */
    public function importarExecucoes(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);   // antes da validação (OWASP A01); a regra fina está no serviço
        $e = EntregavelTarefaUnidade::query()->findOrFail($entregavel);
        $this->importacao->exigirPode($e);
        if ($r->has('simular')) {   // multipart: «true»/«false» também valem
            $r->merge(['simular' => filter_var($r->input('simular'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)]);
        }
        $d = $r->validate(['ficheiro' => ['required', 'file', 'max:5120', 'extensions:xlsx,xls,csv'], 'simular' => ['nullable', 'boolean']],
            ['ficheiro.extensions' => 'O ficheiro tem de ser XLSX, XLS ou CSV (use o modelo Excel).', 'ficheiro.max' => 'O ficheiro tem mais de 5 MB.']);
        $simular = array_key_exists('simular', $d) && $d['simular'] !== null ? (bool) $d['simular'] : true;
        $res = $this->importacao->importar($e, $r->file('ficheiro'), $simular);

        return RespostaApi::sucesso($res, $simular ? "Simulação: {$res['resumo']['validas']} válida(s), {$res['resumo']['erros']} com erro, {$res['resumo']['duplicadas']} duplicada(s)."
            : "{$res['importadas']} entrega(s) importada(s); {$res['resumo']['duplicadas']} duplicada(s) ignorada(s).");
    }

    public function eliminarExecucao(int $execucao): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);
        $x = ExecucaoEntregavel::query()->findOrFail($execucao);
        $this->entregaveis->exigirPodeRegistar(EntregavelTarefaUnidade::query()->findOrFail($x->entregavel_id), (int) $x->colaborador_id);
        $this->entregaveis->eliminarExecucao($x);

        return RespostaApi::sucesso(null, 'Entrega eliminada.');
    }

    public function validar(Request $r, int $execucao): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);   // antes da validação (OWASP A01); a regra fina está no serviço
        $d = $r->validate(['decisao' => ['required', Rule::in(ServicoEntregaveis::DECISOES)], 'motivo' => ['nullable', 'string', 'max:2000']]);
        $x = $this->entregaveis->validar(ExecucaoEntregavel::query()->findOrFail($execucao), $d['decisao'], $d['motivo'] ?? null);

        return RespostaApi::sucesso($x, ['VALIDADA' => 'Entrega validada.', 'DEVOLVIDA' => 'Entrega devolvida para correcção.', 'RECUSADA' => 'Entrega recusada.'][$d['decisao']]);
    }

    public function reenviar(int $execucao): JsonResponse
    {
        return RespostaApi::sucesso($this->entregaveis->reenviar(ExecucaoEntregavel::query()->findOrFail($execucao)), 'Entrega reenviada para validação.');
    }

    public function repor(int $execucao): JsonResponse
    {
        return RespostaApi::sucesso($this->entregaveis->reporValidacao(ExecucaoEntregavel::query()->findOrFail($execucao)), 'Entrega reposta por validar.');
    }

    // ───────────── Impedimentos ─────────────

    public function impedimentos(int $entregavel): JsonResponse
    {
        $this->exigir(...EstruturaController::VER);

        return RespostaApi::sucesso($this->entregaveis->impedimentosDe(EntregavelTarefaUnidade::query()->findOrFail($entregavel)), 'Impedimentos do entregável.');
    }

    public function registarImpedimento(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);   // antes da validação (OWASP A01); a regra fina está no serviço
        $d = $r->validate(['periodo_de' => ['required', 'date_format:Y-m-d'], 'prazo' => ['nullable', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:2000'], 'url_documento' => ['nullable', 'string', 'max:500']]);
        $eu = $this->entregaveis->colaboradorDe() ?? throw new ErroNegocio('O seu utilizador não está ligado a um colaborador desta empresa.', 'SEM_COLABORADOR', 403);

        return RespostaApi::criado($this->entregaveis->registarImpedimento(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $d, $eu), 'Impedimento registado.');
    }

    public function decidirImpedimento(Request $r, int $impedimento): JsonResponse
    {
        $this->exigir(...[...EstruturaController::VER, 'rh_portal_usar']);   // antes da validação (OWASP A01); a regra fina está no serviço
        $d = $r->validate(['estado' => ['required', Rule::in(['ACEITE', 'RECUSADO'])], 'nota' => ['nullable', 'string', 'max:2000']]);
        $i = $this->entregaveis->decidirImpedimento(ImpedimentoEntregavel::query()->findOrFail($impedimento), $d['estado'], $d['nota'] ?? null);

        return RespostaApi::sucesso($i, $d['estado'] === 'ACEITE' ? 'Impedimento aceite: o período fica justificado.' : 'Impedimento recusado.');
    }

    // ───────────── Atrasos ─────────────

    /** GET /estrutura/entregaveis/atrasos?unidade_id&so_com_alerta=1 */
    public function atrasos(Request $r): JsonResponse
    {
        $this->exigir(...EstruturaController::VER);
        $f = $r->validate(['unidade_id' => ['nullable', 'integer'], 'so_com_alerta' => ['nullable', 'boolean']]);
        $o = ['soComAlerta' => array_key_exists('so_com_alerta', $f) ? (bool) $f['so_com_alerta'] : true];
        if (! empty($f['unidade_id'])) {
            $o['unidadeIds'] = $this->entregaveis->descendentes([(int) $f['unidade_id']]);
        }

        return RespostaApi::sucesso($this->entregaveis->entregaveisEmAtraso($o), 'Entregáveis em atraso.');
    }

    // ───────────── CRM ─────────────

    public function crmEstado(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir('est_editar');
        $f = $r->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);

        return RespostaApi::sucesso($this->crm->analisar(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $f['de'] ?? null, $f['ate'] ?? null), 'Estado da ponte com o CRM.');
    }

    public function crmSincronizar(Request $r, int $entregavel): JsonResponse
    {
        $this->exigir('est_editar');
        $f = $r->validate(['de' => ['nullable', 'date_format:Y-m-d'], 'ate' => ['nullable', 'date_format:Y-m-d']]);
        $res = $this->crm->sincronizar(EntregavelTarefaUnidade::query()->findOrFail($entregavel), $f['de'] ?? null, $f['ate'] ?? null);

        return RespostaApi::sucesso($res, "{$res['criadas']} entrega(s) criada(s), {$res['actualizadas']} corrigida(s), {$res['removidas']} retirada(s)".($res['presas'] ? '; '.count($res['presas']).' presa(s)' : '').'.');
    }

    // ───────────── Produtividade: conversão e elegibilidade (/rh/produtividade) ─────────────

    public function analise(Request $r, int $periodo): JsonResponse
    {
        $this->exigir('rh_prod_registar', 'rh_produtividade_view');
        $f = $r->validate(['unidades' => ['nullable', 'array'], 'unidades.*' => ['integer']]);

        return RespostaApi::sucesso($this->conversao->analisar(PeriodoProdutividadeRH::query()->findOrFail($periodo), $f['unidades'] ?? null), 'Análise dos entregáveis do período.');
    }

    public function converter(Request $r, int $periodo): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $f = $r->validate(['unidades' => ['nullable', 'array'], 'unidades.*' => ['integer']]);
        $res = $this->conversao->converter(PeriodoProdutividadeRH::query()->findOrFail($periodo), $f['unidades'] ?? null);

        return RespostaApi::sucesso($res, "{$res['criados']} registo(s) novo(s), {$res['actualizados']} actualizado(s), {$res['marcadas']} entrega(s) convertida(s).");
    }

    public function anular(int $periodo): JsonResponse
    {
        $this->exigir('rh_prod_registar');
        $res = $this->conversao->anularConversao(PeriodoProdutividadeRH::query()->findOrFail($periodo));

        return RespostaApi::sucesso($res, "{$res['execucoes']} entrega(s) devolvida(s) a «por converter»; {$res['registos']} registo(s) de produtividade retirado(s).");
    }

    public function elegibilidade(Request $r): JsonResponse
    {
        $this->exigir('rh_produtividade_view', 'rh_prod_config', 'rh_prod_registar');
        $f = $r->validate(['colaborador_id' => ['nullable', 'integer'], 'item_id' => ['nullable', 'integer']]);

        return RespostaApi::sucesso($this->produtividade->elegibilidade($f['colaborador_id'] ?? null, $f['item_id'] ?? null), 'Elegibilidade de produtividade.');
    }

    public function gravarElegibilidade(Request $r): JsonResponse
    {
        $this->exigir('rh_prod_config');
        $d = $r->validate(['colaborador_id' => ['required', 'integer'], 'item_produtividade_id' => ['required', 'integer'], 'ativo' => ['nullable', 'boolean'],
            'preco_unitario' => ['nullable', 'numeric', 'min:0'], 'nota' => ['nullable', 'string', 'max:255']]);
        $r2 = $this->produtividade->tornarElegivel((int) $d['colaborador_id'], (int) $d['item_produtividade_id'], 'MANUAL', isset($d['preco_unitario']) ? (string) $d['preco_unitario'] : null, $d['nota'] ?? null);
        if (array_key_exists('ativo', $d) && ! $d['ativo']) {
            $r2['linha']->update(['ativo' => false]);
            $r2['linha']->refresh();
        }

        return $r2['novo'] ? RespostaApi::criado($r2['linha'], 'Elegibilidade criada.') : RespostaApi::sucesso($r2['linha'], 'Elegibilidade actualizada.');
    }

    public function eliminarElegibilidade(int $id): JsonResponse
    {
        $this->exigir('rh_prod_config');
        ElegibilidadeProdutividadeRH::query()->findOrFail($id)->delete();

        return RespostaApi::sucesso(null, 'Elegibilidade retirada.');
    }
}
