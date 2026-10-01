<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contabilidade\CriarLancamentoRequest;
use App\Http\Resources\Contabilidade\LancamentoResource;
use App\Models\LancamentoContabil;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Orcamento\ServicoControloOrcamental;
use App\Support\Api\RespostaApi;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** /api/contabilidade/lancamentos — ecrã "lancamentos" do legado. */
final class LancamentoController extends Controller
{
    public function __construct(private readonly ServicoLancamentos $lancamentos) {}

    /** GET — linhas de lançamentos com filtros (paridade: o legado exige pelo menos um critério e exclui a classe 9). */
    public function index(Request $request): JsonResponse
    {
        $this->exigir('lancamentos_view');
        $f = $request->validate([
            'diario_id' => ['nullable', 'integer'], 'codigo_conta' => ['nullable', 'string', 'max:20'], 'numero_lan' => ['nullable', 'string', 'max:30'],
            'numero_documento' => ['nullable', 'string', 'max:100'], 'terceiro_id' => ['nullable', 'integer'],
            'data_inicio' => ['nullable', 'date_format:Y-m-d'], 'data_fim' => ['nullable', 'date_format:Y-m-d'],
            'pesquisa' => ['nullable', 'string', 'max:200'], 'incluir_classe_9' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:500'], 'pagina' => ['nullable', 'integer', 'min:1'],
        ]);

        $pagina = LancamentoContabil::query()->with(self::comTerceiro())
            ->when($f['diario_id'] ?? null, fn ($q, $v) => $q->where('diario_id', $v))
            ->when($f['codigo_conta'] ?? null, fn ($q, $v) => $q->where('codigo_conta', 'like', str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when($f['numero_lan'] ?? null, fn ($q, $v) => $q->where('numero_lan', $v))
            ->when($f['numero_documento'] ?? null, fn ($q, $v) => $q->where('numero_documento', $v))
            ->when($f['terceiro_id'] ?? null, fn ($q, $v) => $q->where('terceiro_id', $v))
            ->when($f['data_inicio'] ?? null, fn ($q, $v) => $q->where('data_documento', '>=', $v))
            ->when($f['data_fim'] ?? null, fn ($q, $v) => $q->where('data_documento', '<=', $v))
            ->when($f['pesquisa'] ?? null, fn ($q, $v) => $q->where('descricao', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $v).'%'))
            ->when(empty($f['incluir_classe_9']), fn ($q) => $q->where('codigo_conta', 'not like', '9%'))
            ->orderByDesc('data_documento')->orderByDesc('id')
            ->paginate(perPage: (int) ($f['por_pagina'] ?? 50), page: (int) ($f['pagina'] ?? 1));

        return RespostaApi::paginado($pagina, LancamentoResource::class);
    }

    /** GET /{id} — o lançamento (documento) completo a que a linha pertence. */
    public function show(int $lancamento): JsonResponse
    {
        $this->exigir('lancamentos_view');
        $linhas = $this->lancamentos->documento(LancamentoContabil::query()->findOrFail($lancamento));

        return RespostaApi::sucesso($this->documento($linhas), 'Lançamento obtido com sucesso.');
    }

    /** POST — cria um lançamento equilibrado (tarefa "Gravar lançamentos no diário"). */
    public function store(CriarLancamentoRequest $request): JsonResponse
    {
        $this->exigir('lancamentos_post');
        // lançamento manual com controlo orçamental (ui_lancamentos.js:1686): débitos consomem, créditos abatem
        $linhas = DB::transaction(function () use ($request) {
            $linhas = $this->lancamentos->criar($request->validated());
            $l0 = $linhas->first();
            app(ServicoControloOrcamental::class)->avaliar('EXPLORACAO',
                ['origem' => 'LANCAMENTO', 'documento' => (string) $l0->numero_lan, 'data' => $l0->data_documento->toDateString()],
                $linhas->map(fn ($l) => ['codigo_conta' => $l->codigo_conta, 'valor' => ($l->tipo_dc === 'D' ? 1 : -1) * (float) $l->valor,
                    'unidade_negocio_id' => $l->unidade_negocio_id, 'centro_custo_id' => $l->centro_custo_id, 'projeto_id' => $l->projeto_id])->all(),
                ['numero_lan' => $l0->numero_lan]);

            return $linhas;
        });

        return RespostaApi::criado($this->documento($linhas), "Lançamento {$linhas->first()->numero_lan} gravado com sucesso.");
    }

    /** POST /{id}/estornar — estorno com rasto (ADR-016; tarefa "Transferir e estornar lançamentos"). */
    public function estornar(Request $request, int $lancamento): JsonResponse
    {
        $this->exigir('contab_lanc_transferir');
        $dados = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:500']], [], ['motivo' => 'motivo do estorno']);
        $estorno = $this->lancamentos->estornar(LancamentoContabil::query()->findOrFail($lancamento), $dados['motivo']);

        return RespostaApi::criado($this->documento($estorno), "Estorno {$estorno->first()->numero_lan} gravado com sucesso.");
    }

    /** Terceiro das linhas numa só consulta (sem N+1); os eliminados (soft delete) continuam a mostrar o nome. */
    private static function comTerceiro(): array
    {
        return ['terceiro' => fn ($q) => $q->withTrashed()->select(['id', 'nome', 'nif'])];
    }

    /** @param  Collection<int, LancamentoContabil>  $linhas */
    private function documento(Collection $linhas): array
    {
        if ($linhas instanceof EloquentCollection) {
            $linhas->loadMissing(self::comTerceiro());
        }
        $primeira = $linhas->first();
        $debito = $linhas->where('tipo_dc', 'D')->reduce(fn ($a, $l) => bcadd($a, (string) $l->valor, 2), '0.00');
        $credito = $linhas->where('tipo_dc', 'C')->reduce(fn ($a, $l) => bcadd($a, (string) $l->valor, 2), '0.00');

        return [
            'diario_id' => $primeira->diario_id, 'numero_lan' => $primeira->numero_lan, 'numero_documento' => $primeira->numero_documento,
            'data_documento' => $primeira->data_documento?->toDateString(), 'debito' => $debito, 'credito' => $credito,
            'equilibrado' => bccomp($debito, $credito, 2) === 0,
            'linhas' => LancamentoResource::collection($linhas)->resolve(),
        ];
    }
}
