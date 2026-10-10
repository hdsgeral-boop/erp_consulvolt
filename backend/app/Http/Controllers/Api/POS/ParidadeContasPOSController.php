<?php

namespace App\Http\Controllers\Api\POS;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\PlanoConta;
use App\Models\SessaoPOS;
use App\Models\Terceiro;
use App\Services\POS\ServicoContabilizacaoPOS;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS › Integração contabilística como no sistema anterior (RELATORIO.md 3.5; js/pos_prestacao.js:225-331):
 *   - POST /api/pos/integracao/pre-visualizacao — lançamentos que a integração das sessões seleccionadas geraria, com os
 *     impedimentos (nada é gravado: ServicoContabilizacaoPOS::previsualizar reverte sempre a transacção);
 *   - POST /api/pos/integracao/contabilizar — integra as sessões seleccionadas, cada uma na sua transacção, com as mesmas
 *     regras do POST /api/pos/sessoes/{id}/contabilizar (uma sessão com erro não impede as outras).
 * Permissão pos_integrar (a mesma da integração individual); as sessões são lidas com o âmbito da empresa activa.
 */
final class ParidadeContasPOSController extends Controller
{
    private const MAXIMO = 50;

    public function __construct(private readonly ServicoContabilizacaoPOS $contabilizacao) {}

    public function previsualizar(Request $r): JsonResponse
    {
        $this->exigir('pos_integrar');
        $sessoes = $this->sessoes($r);
        $res = $sessoes->map(fn (SessaoPOS $s) => $this->contabilizacao->previsualizar($s))->values()->all();

        // nomes das contas e dos terceiros (só leitura) e totais gerais
        $linhas = collect($res)->flatMap(fn ($s) => collect($s['lancamentos'])->flatMap(fn ($l) => $l['linhas']));
        $contas = PlanoConta::query()->whereIn('codigo', $linhas->pluck('codigo_conta')->unique()->values())->pluck('descricao', 'codigo');
        $terceiros = Terceiro::query()->withTrashed()->whereIn('id', $linhas->pluck('terceiro_id')->filter()->unique()->values())->pluck('nome', 'id');
        $debito = $credito = '0.00';
        $impedimentos = 0;
        foreach ($res as &$s) {
            $impedimentos += count($s['impedimentos']);
            foreach ($s['lancamentos'] as &$l) {
                $debito = bcadd($debito, $l['total_debito'], 2);
                $credito = bcadd($credito, $l['total_credito'], 2);
                foreach ($l['linhas'] as &$x) {
                    $x['nome_conta'] = $contas[$x['codigo_conta']] ?? null;
                    $x['terceiro'] = $x['terceiro_id'] ? ($terceiros[$x['terceiro_id']] ?? null) : null;
                    unset($x['terceiro_id']);
                }
                unset($x);
            }
            unset($l);
        }
        unset($s);

        return RespostaApi::sucesso(['sessoes' => $res, 'total_debito' => $debito, 'total_credito' => $credito, 'impedimentos' => $impedimentos],
            'Pré-visualização da integração (nada foi gravado).');
    }

    public function contabilizar(Request $r): JsonResponse
    {
        $this->exigir('pos_integrar');
        $ok = $erros = [];
        foreach ($this->sessoes($r) as $s) {
            try {
                $feita = $this->contabilizacao->contabilizar($s);
                $ok[] = ['sessao_pos_id' => $s->id, 'numero_z' => $s->numero_z, 'lancamentos' => $feita->lans_contabilizacao ?? []];
            } catch (ErroNegocio $e) {
                $erros[] = ['sessao_pos_id' => $s->id, 'numero_z' => $s->numero_z, 'codigo' => $e->codigo, 'mensagem' => $e->getMessage()];
            }
        }

        return RespostaApi::sucesso(['contabilizadas' => $ok, 'erros' => $erros], count($ok).' sessão(ões) integrada(s) na contabilidade.');
    }

    /** Sessões pedidas (ids únicos, máximo 50), pela ordem do fecho, da empresa activa. */
    private function sessoes(Request $r)
    {
        $d = $r->validate(['ids' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO], 'ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        $ids = array_map('intval', $d['ids']);
        $sessoes = SessaoPOS::query()->whereIn('id', $ids)->orderBy('fechado_em')->orderBy('id')->get();
        if ($sessoes->count() !== count($ids)) {
            throw new ErroNegocio('Uma ou mais sessões não existem.', 'SESSAO_INEXISTENTE', 404);
        }

        return $sessoes;
    }
}
