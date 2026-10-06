<?php

namespace App\Http\Controllers\Api\RH;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\MotorSalarial;
use App\Services\RH\ServicoFolhaSalarial;
use App\Services\RH\ServicoTabelaIRT;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/salarios — ecrãs calcular e processamento do legado (js/app_v2.js:4981-6521). */
final class FolhaSalarialController extends Controller
{
    /** Consulta dos períodos e resultados: também os mapas e recibos de RH (ecrãs rh_rel_*), que os lêem. */
    private const VER_PERIODOS = ['calcular_view', 'processamento_view', 'rh_rel_remuneracoes_view', 'rh_rel_irt_view', 'rh_rel_inss_view', 'rh_rel_pagamentos_view', 'rh_rel_banco_view', 'rh_rel_recibos_view', 'rh_recibos_emitir', 'relatorios_view'];

    public function __construct(private readonly ServicoFolhaSalarial $folha) {}

    public function periodos(): JsonResponse
    {
        $this->exigir(...self::VER_PERIODOS);

        return RespostaApi::sucesso(PeriodoProcessamentoSalarial::query()->orderByRaw('substring(mes_ano from 4 for 4) DESC, substring(mes_ano from 1 for 2) DESC')->get(), 'Períodos de processamento.');
    }

    public function abrir(Request $r): JsonResponse
    {
        $this->exigir('calcular_folha');
        $d = $r->validate(['mes_ano' => ['required', 'regex:/^(0[1-9]|1[0-2])\/\d{4}$/']], [], ['mes_ano' => 'mês (MM/AAAA)']);

        return RespostaApi::criado($this->folha->abrir($d['mes_ano']), "Processamento de {$d['mes_ano']} aberto.");
    }

    /** GET /periodos/{id} — período, resultados (fotografia ou cálculo ao vivo) e totais. */
    public function periodo(int $id): JsonResponse
    {
        $this->exigir(...self::VER_PERIODOS);
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        // M13: decomposição oficial do IRT por linha (Grupo A; os avençados pagam a taxa fixa e ficam com null)
        $tabela = ($p->modo_calculo ?? 'ATUAL') === 'LEGADO' ? null : app(ServicoTabelaIRT::class)->atual();
        $res = array_map(fn (array $x) => $x + ['irt_escalao' => empty($x['avencado']) ? MotorSalarial::escalaoIrt((string) ($x['base_irt'] ?? '0'), $tabela) : null],
            $this->folha->resultados($p));
        $totais = [];
        foreach (['bruto', 'inss_trabalhador', 'inss_patronal', 'irt', 'descontos', 'liquido'] as $k) {
            $totais[$k] = array_reduce($res, fn ($s, $x) => bcadd($s, (string) $x[$k], 2), '0.00');
        }
        $totais['irt_devido'] = array_reduce($res, fn ($s, $x) => $x['irt_escalao'] ? bcadd($s, $x['irt_escalao']['devido'], 2) : $s, '0.00');

        return RespostaApi::sucesso($p->toArray() + ['resultados' => $res, 'totais' => $totais, 'fotografia' => $p->estado !== 'ABERTO'], 'Processamento obtido com sucesso.');
    }

    public function lancamentos(int $id): JsonResponse
    {
        $this->exigir('calcular_view');

        return RespostaApi::sucesso(LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $id)->orderBy('colaborador_id')->orderBy('id')->get(), 'Lançamentos do período.');
    }

    public function gravarLancamento(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_lancar', 'calcular_bulk');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'colaborador_id' => ['required', 'integer', Rule::exists('colaboradores', 'id')->where('empresa_id', $empresa)],
            'infotipo_salarial_id' => ['required', 'integer', Rule::exists('infotipos_salariais', 'id')->where('empresa_id', $empresa)],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'dias_trabalhados' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'horas' => ['nullable', 'numeric', 'min:0', 'max:744'],
        ]);

        return RespostaApi::criado($this->folha->gravarLancamento(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d), 'Lançamento gravado.');
    }

    public function removerLancamento(int $id, int $lancamento): JsonResponse
    {
        $this->exigir('rh_lanc_del');
        $this->folha->removerLancamento(LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $id)->findOrFail($lancamento));

        return RespostaApi::sucesso(null, 'Lançamento removido.');
    }

    public function importarContratos(int $id): JsonResponse
    {
        $this->exigir('calcular_folha');

        return RespostaApi::sucesso($this->folha->importarContratos(PeriodoProcessamentoSalarial::query()->findOrFail($id)), 'Lançamentos importados dos contratos.');
    }

    /** Decisão 3 do utilizador: com avisos no cálculo exige `confirmar_avisos` (422 AVISOS_POR_CONFIRMAR com a lista). */
    public function encerrar(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_folha');
        $d = $r->validate(['confirmar_avisos' => ['nullable', 'boolean']]);

        return RespostaApi::sucesso($this->folha->encerrar(PeriodoProcessamentoSalarial::query()->findOrFail($id), (bool) ($d['confirmar_avisos'] ?? false)),
            'Cálculo encerrado: resultados fotografados.');
    }

    // ───────────── A-08 ─────────────

    /** POST /periodos/{id}/copiar — copia os lançamentos de outro período (copyPreviousEntries; legado: calcular_folha). */
    public function copiar(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_folha');
        $d = $r->validate(['origem_id' => ['required', 'integer'], 'substituir' => ['nullable', 'boolean']]);
        $res = $this->folha->copiarDe(PeriodoProcessamentoSalarial::query()->findOrFail($id), (int) $d['origem_id'], (bool) ($d['substituir'] ?? false));

        return RespostaApi::sucesso($res, "{$res['copiados']} lançamento(s) copiado(s)".($res['substituidos'] ? ", {$res['substituidos']} substituído(s)" : '')
            .($res['ja_existentes'] ? " ({$res['ja_existentes']} já existiam)" : '').'.');
    }

    /** POST /periodos/{id}/lancamentos/lote — várias rubricas (valor ou horas) para vários colaboradores (calcular_bulk). */
    public function lote(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_bulk', 'calcular_lancar');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'colaboradores' => ['required', 'array', 'min:1', 'max:2000'], 'colaboradores.*' => ['integer', Rule::exists('colaboradores', 'id')->where('empresa_id', $empresa)],
            'rubricas' => ['required', 'array', 'min:1', 'max:100'],
            'rubricas.*.infotipo_salarial_id' => ['required', 'integer', Rule::exists('infotipos_salariais', 'id')->where('empresa_id', $empresa)],
            'rubricas.*.valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'rubricas.*.horas' => ['nullable', 'numeric', 'min:0', 'max:744'],
            'rubricas.*.dias_trabalhados' => ['nullable', 'numeric', 'min:0', 'max:31'],
        ]);
        $res = $this->folha->lancarEmLote(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d['colaboradores'], $d['rubricas']);

        return RespostaApi::sucesso($res, "{$res['gravados']} lançamento(s) gravado(s).");
    }

    /** PUT /periodos/{id}/lancamentos/lote — edição em massa dos seleccionados (calcular_bulk). */
    public function editarLote(Request $r, int $id): JsonResponse
    {
        $this->exigir('calcular_bulk');
        $empresa = app(ContextoEmpresa::class)->obrigatorio();
        $d = $r->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer'],
            'campos' => ['required', 'array'],
            'campos.infotipo_salarial_id' => ['sometimes', 'integer', Rule::exists('infotipos_salariais', 'id')->where('empresa_id', $empresa)],
            'campos.valor' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'campos.dias_trabalhados' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:31'],
            'campos.horas' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:744'],
        ]);
        $n = $this->folha->editarLancamentos(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d['ids'],
            array_intersect_key($d['campos'], array_flip(['infotipo_salarial_id', 'valor', 'dias_trabalhados', 'horas'])));

        return RespostaApi::sucesso(['alterados' => $n], "{$n} lançamento(s) alterado(s).");
    }

    /** DELETE /periodos/{id}/lancamentos/lote — eliminação dos seleccionados (rh_lanc_del). */
    public function removerLote(Request $r, int $id): JsonResponse
    {
        $this->exigir('rh_lanc_del');
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:5000'], 'ids.*' => ['integer']]);
        $n = $this->folha->removerLancamentos(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d['ids']);

        return RespostaApi::sucesso(['eliminados' => $n], "{$n} lançamento(s) eliminado(s).");
    }

    /** DELETE /periodos/{id} — elimina o período em aberto e os lançamentos (deleteOpenPeriod; calcular_folha + rh_lanc_del). */
    public function eliminarPeriodo(int $id): JsonResponse
    {
        $this->exigir('calcular_folha');
        $this->exigir('rh_lanc_del');
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        $this->folha->eliminarPeriodoAberto($p);

        return RespostaApi::sucesso(null, "Período {$p->mes_ano} eliminado com os seus lançamentos.");
    }

    public function validar(int $id): JsonResponse
    {
        $this->exigir('processamento_validate');

        return RespostaApi::sucesso($this->folha->validar(PeriodoProcessamentoSalarial::query()->findOrFail($id)), 'Processamento validado.');
    }

    public function reabrir(int $id): JsonResponse
    {
        $this->exigir('processamento_reopen');

        return RespostaApi::sucesso($this->folha->reabrir(PeriodoProcessamentoSalarial::query()->findOrFail($id)), 'Processamento reaberto.');
    }

    public function contabilizar(int $id): JsonResponse
    {
        $this->exigir('processamento_integrate');
        $p = $this->folha->contabilizar(PeriodoProcessamentoSalarial::query()->findOrFail($id));

        return RespostaApi::sucesso($p, "Processamento contabilizado (lançamento {$p->numero_lan_contabilizacao}).");
    }

    public function descontabilizar(Request $r, int $id): JsonResponse
    {
        $this->exigir('contab_lanc_del');
        $d = $r->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']]);

        return RespostaApi::sucesso($this->folha->descontabilizar(PeriodoProcessamentoSalarial::query()->findOrFail($id), $d['motivo']), 'Processamento descontabilizado (estorno registado).');
    }

    /** GET /periodos/{id}/recibos/{colaborador} — dados do recibo de vencimento (da fotografia; só períodos validados, como no legado). */
    public function recibo(int $id, int $colaborador): JsonResponse
    {
        $this->exigir('rh_recibos_emitir', 'rh_rel_recibos_view');
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        if ($p->estado !== 'VALIDADO') {
            throw new ErroNegocio('Os recibos só se emitem de processamentos validados.', 'PERIODO_NAO_VALIDADO', 422);
        }
        $res = collect($this->folha->resultados($p))->firstWhere('colaborador_id', $colaborador) ?? abort(404);
        [$mes, $ano] = explode('/', $p->mes_ano);

        return RespostaApi::sucesso($res + ['numero_recibo' => sprintf('%s%s-%04d', $ano, $mes, $colaborador), 'mes_ano' => $p->mes_ano], 'Recibo de vencimento.');
    }

    public function verificacaoLegado(): JsonResponse
    {
        $this->exigir('processamento_view');

        return RespostaApi::sucesso($this->folha->verificarContraDiario(), 'Verificação das folhas contra o diário.');
    }
}
