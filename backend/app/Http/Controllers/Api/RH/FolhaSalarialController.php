<?php

namespace App\Http\Controllers\Api\RH;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Services\RH\ServicoFolhaSalarial;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /api/rh/salarios — ecrãs calcular e processamento do legado (js/app_v2.js:4981-6521). */
final class FolhaSalarialController extends Controller
{
    public function __construct(private readonly ServicoFolhaSalarial $folha) {}

    public function periodos(): JsonResponse
    {
        $this->exigir('calcular_view', 'processamento_view');

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
        $this->exigir('calcular_view', 'processamento_view');
        $p = PeriodoProcessamentoSalarial::query()->findOrFail($id);
        $res = $this->folha->resultados($p);
        $totais = [];
        foreach (['bruto', 'inss_trabalhador', 'inss_patronal', 'irt', 'descontos', 'liquido'] as $k) {
            $totais[$k] = array_reduce($res, fn ($s, $x) => bcadd($s, (string) $x[$k], 2), '0.00');
        }

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

    public function encerrar(int $id): JsonResponse
    {
        $this->exigir('calcular_folha');

        return RespostaApi::sucesso($this->folha->encerrar(PeriodoProcessamentoSalarial::query()->findOrFail($id)), 'Cálculo encerrado: resultados fotografados.');
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
