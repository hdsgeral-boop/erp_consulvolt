<?php

namespace App\Http\Controllers\Api\RH;

use App\Http\Controllers\Controller;
use App\Models\ContratoTrabalho;
use App\Services\RH\ServicoContratosTrabalho;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /api/rh/contratos — ecrã «Contratos» (js/app_v2.js:4261-4720). */
final class ContratoTrabalhoController extends Controller
{
    public function __construct(private readonly ServicoContratosTrabalho $contratos) {}

    public function index(Request $r): JsonResponse
    {
        $this->exigir('contratos_view', 'colaboradores_view');
        $f = $r->validate(['colaborador_id' => ['nullable', 'integer'], 'estado' => ['nullable', 'in:ACTIVO,INACTIVO,SUSPENSO']]);

        return RespostaApi::sucesso(ContratoTrabalho::query()->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where('colaborador_id', $c))
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))->orderBy('colaborador_id')->orderBy('data_inicio')->get(), 'Contratos de trabalho.');
    }

    public function show(int $contrato): JsonResponse
    {
        $this->exigir('contratos_view', 'colaboradores_view');

        return RespostaApi::sucesso(ContratoTrabalho::query()->findOrFail($contrato), 'Contrato obtido com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('contratos_new');

        return RespostaApi::criado($this->contratos->guardar($this->dados($r)), 'Contrato criado com sucesso.');
    }

    public function update(Request $r, int $contrato): JsonResponse
    {
        $c = ContratoTrabalho::query()->findOrFail($contrato);
        $d = $this->dados($r);
        ServicoContratosTrabalho::terminaContrato($c, $d) ? $this->exigir('contratos_terminate') : $this->exigir('contratos_new');

        return RespostaApi::sucesso($this->contratos->guardar($d, $c), 'Contrato actualizado com sucesso.');
    }

    public function terminar(Request $r, int $contrato): JsonResponse
    {
        $this->exigir('contratos_terminate');
        $d = $r->validate(['data_fim' => ['required', 'date']]);

        return RespostaApi::sucesso($this->contratos->terminar(ContratoTrabalho::query()->findOrFail($contrato), $d['data_fim']), 'Contrato terminado.');
    }

    public function destroy(int $contrato): JsonResponse
    {
        $this->exigir('contratos_new');
        ContratoTrabalho::query()->findOrFail($contrato)->delete();   // os períodos encerrados guardam a fotografia: nada muda para trás

        return RespostaApi::sucesso(null, 'Contrato eliminado com sucesso.');
    }

    /** @return array<string, mixed> */
    private function dados(Request $r): array
    {
        return $r->validate([
            'colaborador_id' => ['required', 'integer'], 'data_inicio' => ['required', 'date'], 'data_fim' => ['nullable', 'date'],
            'dias_contrato_mes' => ['sometimes', 'integer', 'min:1', 'max:31'], 'horas_por_dia' => ['sometimes', 'numeric', 'gt:0', 'max:24'],
            'estado' => ['sometimes', 'in:ACTIVO,INACTIVO,SUSPENSO'], 'codigo_moeda' => ['sometimes', 'string', 'size:3'],
            'remuneracoes' => ['required', 'array', 'min:1', 'max:50'], 'remuneracoes.*.infotipo_salarial_id' => ['required', 'integer'],
            'remuneracoes.*.valor_mes' => ['required', 'numeric', 'min:0', 'max:9999999999999.99'],
            'produtividade' => ['sometimes', 'nullable', 'array'], 'produtividade.*.item_id' => ['required', 'integer'],
            'produtividade.*.preco_unitario' => ['nullable', 'numeric', 'min:0'],
        ], [], ['remuneracoes' => 'remunerações', 'dias_contrato_mes' => 'dias do contrato', 'horas_por_dia' => 'horas por dia']);
    }
}
