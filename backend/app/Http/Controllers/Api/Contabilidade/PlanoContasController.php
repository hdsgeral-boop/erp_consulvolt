<?php

namespace App\Http\Controllers\Api\Contabilidade;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contabilidade\GuardarContaRequest;
use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/contabilidade/plano-contas — ecrã "config_plano" do legado (leitura servida pela cache Redis). */
final class PlanoContasController extends Controller
{
    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /** GET — contas da empresa; filtros: pesquisa (código ou descrição), prefixo, tipo (M/T). */
    public function index(Request $request): JsonResponse
    {
        // O plano também é necessário para quem lança e consulta mapas.
        $this->exigir('config_plano_view', 'lancamentos_view', 'relatorios_contabeis_view');
        $f = $request->validate(['pesquisa' => ['nullable', 'string', 'max:100'], 'prefixo' => ['nullable', 'string', 'max:20'], 'tipo' => ['nullable', 'in:M,T']]);

        $contas = collect($this->plano->todas())
            ->when($f['prefixo'] ?? null, fn ($c, $p) => $c->filter(fn ($x) => str_starts_with($x['codigo'], $p)))
            ->when($f['tipo'] ?? null, fn ($c, $t) => $c->where('tipo', $t))
            ->when($f['pesquisa'] ?? null, fn ($c, $p) => $c->filter(fn ($x) => str_contains($x['codigo'], $p) || str_contains(mb_strtolower((string) $x['descricao']), mb_strtolower($p))))
            ->values();

        return RespostaApi::sucesso($contas, 'Plano de contas obtido com sucesso.', 200, ['total' => $contas->count()]);
    }

    public function store(GuardarContaRequest $request): JsonResponse
    {
        $this->exigir('contab_plano_gerir');

        return RespostaApi::criado($this->plano->criar($request->validated()), 'Conta criada com sucesso.');
    }

    public function update(GuardarContaRequest $request, int $conta): JsonResponse
    {
        $this->exigir('contab_plano_gerir');

        return RespostaApi::sucesso($this->plano->atualizar(PlanoConta::query()->findOrFail($conta), $request->validated()), 'Conta actualizada com sucesso.');
    }

    public function destroy(int $conta): JsonResponse
    {
        $this->exigir('contab_tabelas_del');
        $this->plano->eliminar(PlanoConta::query()->findOrFail($conta));

        return RespostaApi::sucesso(null, 'Conta eliminada com sucesso.');
    }
}
