<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Services\Contabilidade\ServicoRotinasContabeis;
use App\Services\Contabilidade\ServicoRotinasContabeisSelo;
use App\Services\Sistema\ServicoLeituraFolha;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $d = $r->validate(['linhas' => ['required', 'array', 'min:1', 'max:20000'], 'linhas.*.lancamento_id' => ['nullable'], 'linhas.*.linha' => ['nullable', 'integer', 'min:1'],
            'linhas.*.campo_a_modificar' => ['nullable', 'string', 'max:50'], 'linhas.*.novo_valor' => ['nullable'],
            'simular' => ['nullable', 'boolean'], 'so_validas' => ['nullable', 'boolean']]);
        $simular = filter_var($d['simular'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $res = $this->rotinas->actualizarEmMassa($d['linhas'], $simular, filter_var($d['so_validas'] ?? true, FILTER_VALIDATE_BOOLEAN));

        return RespostaApi::sucesso($res, $simular
            ? "Pré-visualização: {$res['validas']} linha(s) válida(s), ".count($res['erros']).' com erro. Nada foi gravado.'
            : "Processamento concluído: {$res['actualizadas']} actualizadas, ".count($res['erros']).' com erro.');
    }

    /** GET /actualizacao-massa/modelo — modelo Excel com as colunas aceites, instruções e as listas de referência da empresa. */
    public function modeloActualizacao(): BinaryFileResponse
    {
        $this->exigir('contab_rotinas_view', 'contab_rotinas_exec');
        $pasta = storage_path('app/copias');
        if (! is_dir($pasta)) {
            mkdir($pasta, 0775, true);
        }
        $caminho = $pasta.'/actualizacao_'.Str::random(16).'.xlsx';
        $this->rotinas->modeloActualizacao($caminho);

        return response()->download($caminho, 'Template_Ajuste_Contabil.xlsx')->deleteFileAfterSend();
    }

    /**
     * POST /actualizacao-massa/importar — lê o modelo preenchido (folha «Ajustes»; XLSX, XLS ou CSV), valida linha a linha e
     * devolve a pré-visualização das alterações (nada é gravado) e as linhas lidas, para aplicar depois com POST /actualizacao-massa.
     */
    public function importarActualizacao(Request $r, ServicoLeituraFolha $leitura): JsonResponse
    {
        $this->exigir('contab_rotinas_exec');
        $r->validate(['ficheiro' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt']], [], ['ficheiro' => 'ficheiro']);
        $linhas = array_map(function (array $l, int $i) {
            $v = fn (array $nomes) => collect($nomes)->map(fn ($n) => $l[$n] ?? null)->first(fn ($x) => $x !== null && $x !== '');
            $id = $v(['lancamento_id', 'Lancamento_id', 'LANCAMENTO_ID', 'id', 'ID']);

            return ['linha' => $i + 2, 'lancamento_id' => is_numeric($id) ? (int) $id : $id, 'campo_a_modificar' => $v(['campo_a_modificar', 'Campo_a_modificar', 'CAMPO_A_MODIFICAR']),
                'novo_valor' => ($n = $v(['novo_valor', 'Novo_valor', 'NOVO_VALOR'])) === null ? null : (is_float($n) && floor($n) === $n ? (string) (int) $n : (string) $n)];
        }, $folha = $leitura->linhas($r->file('ficheiro'), 'Ajustes'), array_keys($folha));
        // linhas totalmente vazias no meio do ficheiro: ignoradas; cada linha leva o seu n.º no Excel (`linha`) para os erros
        $linhas = array_values(array_filter($linhas, fn ($l) => $l['lancamento_id'] !== null || $l['campo_a_modificar'] !== null || $l['novo_valor'] !== null));
        $res = $this->rotinas->actualizarEmMassa($linhas, true);

        return RespostaApi::sucesso($res + ['linhas' => $linhas], "Ficheiro lido: {$res['validas']} linha(s) válida(s), ".count($res['erros']).' com erro. Verifique e aplique.');
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
