<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\ItemProdutividadeRH;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Services\RH\ServicoAssiduidade;
use App\Services\RH\ServicoCalendarioRH;
use App\Services\RH\ServicoContratosTrabalho;
use App\Services\RH\ServicoProdutividade;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Fecho da Versão Actual — RH (ADR-071, caderno 04_rh_salarios.md):
 *   - #13/#26 itens de produtividade em massa nos contratos (contratos_produtividade.js);
 *   - #11 descrição das métricas/bases e explicação do cálculo da produtividade (produtividade.js:36-160);
 *   - #4 modelo Excel da efectividade já preenchido (assiduidade.js:618).
 * As permissões verificam-se SEMPRE antes da validação (OWASP A01); o isolamento por empresa vem dos models (PertenceEmpresa)
 * e das regras `exists` filtradas pela empresa activa.
 */
final class FiRhController extends Controller
{
    /** POST /rh/contratos/produtividade-massa — contratos[] e/ou colaboradores[], itens[{item_id, preco_unitario?}], modo, preco_existente, simular. */
    public function produtividadeMassa(Request $r, ServicoContratosTrabalho $contratos): JsonResponse
    {
        $this->exigir('contratos_new');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'contratos' => ['nullable', 'array', 'max:2000'], 'contratos.*' => ['integer', Rule::exists('contratos_trabalho', 'id')->where('empresa_id', $empresa)],
            'colaboradores' => ['nullable', 'array', 'max:2000'], 'colaboradores.*' => ['integer', Rule::exists('colaboradores', 'id')->where('empresa_id', $empresa)],
            'itens' => ['required', 'array', 'min:1', 'max:50'],
            'itens.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('itens_produtividade_rh', 'id')->where('empresa_id', $empresa)],
            'itens.*.preco_unitario' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'modo' => ['required', Rule::in([...ServicoContratosTrabalho::MODOS_PRODUTIVIDADE, 'SUBSTITUIR_LISTA'])],
            'preco_existente' => ['nullable', Rule::in(['SUBSTITUIR', 'MANTER'])], 'simular' => ['nullable', 'boolean'],
        ], [], ['itens' => 'itens de produtividade', 'contratos' => 'contratos', 'colaboradores' => 'colaboradores']);
        $res = $contratos->aplicarProdutividadeEmMassa(array_map('intval', $d['contratos'] ?? []), array_map('intval', $d['colaboradores'] ?? []), $d['itens'], $d['modo'],
            $d['preco_existente'] ?? 'SUBSTITUIR', (bool) ($d['simular'] ?? false));

        return RespostaApi::sucesso($res, ($res['simulacao'] ? 'Simulação: ' : '').'produtividade aplicada a '.count($res['actualizados']).' contrato(s); '
            .count($res['sem_alteracao']).' já estavam assim; '.(count($res['inactivos']) + count($res['sem_contrato'])).' ignorado(s).');
    }

    /** GET /rh/produtividade/metricas — rótulos, fórmula e exemplo de cada métrica e as bases de cálculo. */
    public function metricas(): JsonResponse
    {
        $this->exigir('rh_produtividade_view', 'rh_prod_config', 'contratos_view');

        return RespostaApi::sucesso(['metricas' => ServicoProdutividade::DESCRICAO_METRICAS, 'bases' => ServicoProdutividade::DESCRICAO_BASES],
            'Métricas e bases de cálculo da produtividade.');
    }

    /**
     * GET /rh/produtividade/explicar?item_id=&quantidade=&preco=&metrica=&base_calculo=&unidade=&minimo=&maximo= — a conta por extenso
     * de um caso, pelo mesmo motor que calcula os registos. Sem `item_id` (item novo) ou com valores do formulário por gravar, o
     * item é simulado com esses valores (pré-visualização no formulário do item); `quantidade` omitida = a de exemplo da métrica.
     */
    public function explicar(Request $r): JsonResponse
    {
        $this->exigir('rh_produtividade_view', 'rh_prod_config');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('itens_produtividade_rh', 'id')->where('empresa_id', $empresa)],
            'quantidade' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'preco' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'metrica' => ['nullable', Rule::in(ServicoProdutividade::METRICAS)],
            'base_calculo' => ['nullable', Rule::in(ServicoProdutividade::BASES)],
            'unidade' => ['nullable', 'string', 'max:10'],
            'minimo' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'maximo' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $gravado = isset($d['item_id']) ? ItemProdutividadeRH::query()->findOrFail($d['item_id']) : null;
        $simular = array_intersect_key($d, array_flip(['metrica', 'base_calculo', 'unidade', 'minimo', 'maximo', 'preco']));
        if (! $gravado && ! isset($d['metrica'])) {
            throw ValidationException::withMessages(['metrica' => 'Indique o item ou a métrica.']);
        }
        if (! $gravado && ! isset($d['preco'])) {
            throw ValidationException::withMessages(['preco' => 'Indique o preço.']);
        }
        // só os campos enviados alteram o item gravado (os ausentes mantêm-se); num item novo os ausentes ficam vazios
        $item = $gravado && ! $simular ? $gravado : ServicoProdutividade::itemSimulado($gravado, $simular + ($gravado ? [] : ['minimo' => null, 'maximo' => null, 'unidade' => null]));
        $metrica = ServicoProdutividade::metricaDe($item->metrica);
        $quantidade = (float) ($d['quantidade'] ?? ServicoProdutividade::DESCRICAO_METRICAS[$metrica]['quantidade_exemplo']);

        return RespostaApi::sucesso(ServicoProdutividade::explicar($item, $quantidade, (float) ($d['preco'] ?? $item->preco_unitario)), 'Explicação do cálculo.');
    }

    /**
     * GET /rh/produtividade/periodos/{periodo}/explicacao — por colaborador e item: total do período, preço, quantidade considerada,
     * valor e a conta por extenso (o mínimo e o tecto aplicam-se ao total, ADR-039).
     */
    public function explicacaoPeriodo(int $periodo): JsonResponse
    {
        $this->exigir('rh_produtividade_view');
        $p = PeriodoProdutividadeRH::query()->findOrFail($periodo);
        $itens = ItemProdutividadeRH::query()->get()->keyBy('id');
        $saida = [];
        foreach (RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->orderBy('id')->get()
            ->groupBy(fn ($x) => $x->colaborador_id.'|'.$x->item_produtividade_id) as $k => $regs) {
            [$colab, $id] = array_map('intval', explode('|', (string) $k));
            $item = $itens[$id] ?? null;
            if (! $item) {
                continue;
            }
            $quantidade = (float) $regs->sum(fn ($x) => (float) $x->quantidade);
            // preço do caso: o do registo (fixado na data); com preços diferentes mostra-se o do último registo e o valor gravado
            $preco = (float) ($regs->last()->preco_unitario ?? $item->preco_unitario);
            $exp = ServicoProdutividade::explicar($item, $quantidade, $preco);
            $saida[] = $exp + ['colaborador_id' => $colab, 'item_produtividade_id' => $id, 'codigo' => $item->codigo, 'registos' => $regs->count(),
                'valor_gravado' => number_format((float) $regs->sum(fn ($x) => (float) $x->valor), 2, '.', ''),
                'precos_diferentes' => $regs->pluck('preco_unitario')->map(fn ($v) => (string) $v)->unique()->count() > 1];
        }

        return RespostaApi::sucesso($saida, 'Explicação do cálculo do período.');
    }

    /** GET /rh/assiduidade/modelo?mes=AAAA-MM — modelo Excel pré-preenchido (uma linha por colaborador activo e dia de trabalho). */
    public function modeloEfectividade(Request $r, ServicoAssiduidade $assiduidade): BinaryFileResponse
    {
        $this->exigir('rh_assid_registar');
        $d = $r->validate(['mes' => ['required', 'string', 'regex:/^(\d{4}-(0[1-9]|1[0-2])|(0[1-9]|1[0-2])\/\d{4})$/']]);
        $mes = ServicoCalendarioRH::mes($d['mes']);
        $pasta = storage_path('app/tmp');
        File::ensureDirectoryExists($pasta);
        $caminho = $pasta.'/modelo_efectividade_'.Str::random(12).'.xlsx';
        $res = $assiduidade->modelo($mes, $caminho);

        return response()->download($caminho, "Modelo_Efectividade_{$mes}.xlsx", ['X-Linhas-Modelo' => (string) $res['linhas'],
            'X-Sem-Nif' => (string) count($res['sem_nif'])])->deleteFileAfterSend();
    }
}
