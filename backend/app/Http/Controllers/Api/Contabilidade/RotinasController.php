<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoRotinasContabeis;
use App\Services\Contabilidade\ServicoRotinasContabeisSelo;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/rotinas — capitalização de obras, compensação automática, transferência de saldos, Imposto de Selo, actualização em massa, histórico. */
final class RotinasController extends Controller
{
    private const MES = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    public function __construct(
        private readonly ServicoRotinasContabeis $rotinas,
        private readonly ServicoRotinasContabeisSelo $selo,
    ) {}

    public function obrasInternas(): JsonResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec');

        return RespostaApi::sucesso($this->rotinas->obrasInternas(), 'Obras internas activas.');
    }

    public function capitalizar(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_exec');
        $d = $r->validate(['mes' => self::MES, 'projetos' => ['required', 'array', 'min:1'], 'projetos.*' => ['integer'],
            'conta_debito' => ['required', 'string', 'max:20'], 'conta_credito' => ['required', 'string', 'max:20']]);
        $res = $this->rotinas->capitalizar($d['mes'], array_map('intval', $d['projetos']), $d['conta_debito'], $d['conta_credito']);

        return RespostaApi::sucesso($res, "Capitalização de {$res['total']} executada: lançamento {$res['numero_lan']}.");
    }

    public function paresCompensacao(): JsonResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec');
        $pares = $this->rotinas->paresCompensacao();

        return RespostaApi::sucesso($pares, count($pares).' pares identificados para compensação.');
    }

    public function compensar(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_exec');
        $d = $r->validate(['pares' => ['required', 'array', 'min:1', 'max:5000'], 'pares.*.debito_id' => ['required', 'integer'], 'pares.*.credito_id' => ['required', 'integer']]);
        $res = $this->rotinas->compensar($d['pares']);

        return RespostaApi::sucesso($res, count($res['compensados'])." pares compensados ({$res['regularizacoes']} com regularização na conta 3772).");
    }

    public function saldosATransferir(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec');
        $d = $r->validate(['mes' => self::MES, 'contas' => ['required', 'array', 'min:1', 'max:200'], 'contas.*' => ['string', 'max:20']]);

        return RespostaApi::sucesso($this->rotinas->saldosATransferir($d['mes'], $d['contas']), "Saldos de {$d['mes']}.");
    }

    public function transferirSaldos(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_exec');
        $d = $r->validate(['diario_id' => ['required', 'integer'], 'mes' => self::MES, 'linhas' => ['required', 'array', 'min:1', 'max:200'],
            'linhas.*.conta_origem' => ['required', 'string', 'max:20'], 'linhas.*.conta_destino' => ['required', 'string', 'max:20'],
            'linhas.*.nota_destino_id' => ['nullable', 'integer']]);
        $res = $this->rotinas->transferirSaldos((int) $d['diario_id'], $d['mes'], $d['linhas']);

        return RespostaApi::sucesso($res, count($res['transferencias']).' transferências realizadas.');
    }

    public function resumoSelo(Request $r): JsonResponse
    {
        $this->exigir('imposto_selo_view', 'selo_lancar', 'contab_rotinas_view');
        $d = $r->validate(['mes' => ['required', 'integer', 'between:1,12'], 'ano' => ['required', 'integer', 'between:1900,2100']]);

        return RespostaApi::sucesso($this->selo->resumo((int) $d['mes'], (int) $d['ano']), 'Resumo do Imposto de Selo.');
    }

    public function lancarSelo(Request $r): JsonResponse
    {
        $this->exigir('selo_lancar');
        $d = $r->validate(['mes' => ['required', 'integer', 'between:1,12'], 'ano' => ['required', 'integer', 'between:1900,2100']]);
        $res = $this->selo->lancar((int) $d['mes'], (int) $d['ano']);

        return RespostaApi::sucesso($res, "Lançamento de Imposto de Selo {$res['numero_lan']} gerado no diário AC.");
    }

    public function historicoSelo(): JsonResponse
    {
        $this->exigir('imposto_selo_view', 'selo_lancar', 'contab_rotinas_view');

        return RespostaApi::sucesso($this->selo->historico(), 'Lançamentos de Imposto de Selo.');
    }

    public function actualizarEmMassa(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_exec');
        $d = $r->validate(['linhas' => ['required', 'array', 'min:1', 'max:20000'], 'linhas.*.lancamento_id' => ['nullable'],
            'linhas.*.campo_a_modificar' => ['nullable', 'string', 'max:50'], 'linhas.*.novo_valor' => ['nullable']]);
        $res = $this->rotinas->actualizarEmMassa($d['linhas']);

        return RespostaApi::sucesso($res, "Processamento concluído: {$res['actualizadas']} actualizadas, ".count($res['erros']).' com erro.');
    }

    public function historico(): JsonResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec', 'contab_rotinas_anular');

        return RespostaApi::sucesso($this->rotinas->historico(), 'Rotinas executadas.');
    }

    public function anular(Request $r): JsonResponse
    {
        $this->exigir('contab_rotinas_anular');
        $d = $r->validate(['codigo' => ['required', 'string', 'max:50'], 'motivo' => ['required', 'string', 'max:500']]);

        return RespostaApi::sucesso($this->rotinas->anular($d['codigo'], $d['motivo']), "Rotina {$d['codigo']} anulada.");
    }

    public function limpezaReconciliacoes(): JsonResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec');

        return RespostaApi::sucesso($this->rotinas->previsualizarLimpezaReconciliacoes(), 'Linhas de tesouraria com falsas reconciliações.');
    }
}
