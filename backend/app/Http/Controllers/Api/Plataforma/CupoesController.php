<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\CupomDesconto;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Backoffice de cupões e campanhas promocionais da subscrição SaaS (ADR-074, motor de precificação Módulo B).
 * Protegido por RBAC estrito: apenas super-administrador da plataforma (middleware `plataforma`).
 */
final class CupoesController extends Controller
{
    /** GET /api/plataforma/cupoes — com métricas de usos e receita descontada. */
    public function index(): JsonResponse
    {
        $cupoes = CupomDesconto::query()->withCount('usos')->orderByDesc('id')->get()
            ->map(fn (CupomDesconto $c) => $this->apresentar($c))->all();

        return RespostaApi::sucesso($cupoes, 'Cupões obtidos com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validarDados($request);
        $c = CupomDesconto::query()->create($d + ['criado_por' => $request->user()->getKey()]);

        return RespostaApi::criado($this->apresentar($c), 'Cupão criado.');
    }

    public function update(Request $request, int $cupom): JsonResponse
    {
        $c = CupomDesconto::query()->findOrFail($cupom);
        $d = $this->validarDados($request, $c->id);
        $c->fill($d)->save();

        return RespostaApi::sucesso($this->apresentar($c->refresh()), 'Cupão atualizado.');
    }

    public function estado(Request $request, int $cupom): JsonResponse
    {
        $c = CupomDesconto::query()->findOrFail($cupom);
        $d = $request->validate(['ativo' => ['required', 'boolean']]);
        $c->update(['ativo' => $d['ativo']]);

        return RespostaApi::sucesso($this->apresentar($c), $d['ativo'] ? 'Cupão ativado.' : 'Cupão desativado.');
    }

    /** @return array<string, mixed> */
    private function validarDados(Request $request, ?int $ignorar = null): array
    {
        // Maiúsculas antes de validar (não depois): o CHECK da BD e a regex exigem maiúsculas, mas o utilizador
        // pode escrever o código em qualquer capitalização.
        $request->merge(['codigo' => mb_strtoupper(trim((string) $request->input('codigo')))]);

        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('cupons_desconto', 'codigo')->ignore($ignorar)],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'tipo_desconto' => ['required', Rule::in([CupomDesconto::PERCENTAGEM, CupomDesconto::VALOR_FIXO])],
            'valor_desconto' => [
                'required', 'numeric', 'min:0.01',
                function (string $atributo, mixed $valor, callable $falhar) use ($request) {
                    if ($request->input('tipo_desconto') === CupomDesconto::PERCENTAGEM && (float) $valor > CupomDesconto::PERCENTAGEM_MAXIMA) {
                        $falhar('O desconto percentual não pode exceder '.CupomDesconto::PERCENTAGEM_MAXIMA.'%.');
                    }
                },
            ],
            'moeda' => ['nullable', 'string', 'size:3'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'limite_usos' => ['nullable', 'integer', 'min:1'],
            'limite_por_cliente' => ['required', 'integer', 'min:1'],
            'planos_aplicaveis' => ['nullable', 'array'],
            'planos_aplicaveis.*' => ['integer'],
            'ciclos_aplicaveis' => ['nullable', 'array'],
            'ciclos_aplicaveis.*' => ['string'],
            'ativo' => ['required', 'boolean'],
        ]);

        $d['moeda'] = $d['moeda'] ?? 'AOA';
        $d['planos_aplicaveis'] = $d['planos_aplicaveis'] ?? null;
        $d['ciclos_aplicaveis'] = $d['ciclos_aplicaveis'] ?? null;

        return $d;
    }

    /** @return array<string, mixed> */
    private function apresentar(CupomDesconto $c): array
    {
        $receitaDescontada = (float) (DB::table('usos_cupom')->where('cupom_id', $c->id)->sum('valor_desconto_aplicado') ?? 0);

        return [
            'id' => $c->id, 'codigo' => $c->codigo, 'descricao' => $c->descricao,
            'tipo_desconto' => $c->tipo_desconto, 'valor_desconto' => (float) $c->valor_desconto, 'moeda' => $c->moeda,
            'data_inicio' => $c->data_inicio->toDateString(), 'data_fim' => $c->data_fim->toDateString(),
            'limite_usos' => $c->limite_usos, 'usos_atuais' => $c->usos_atuais, 'limite_por_cliente' => $c->limite_por_cliente,
            'planos_aplicaveis' => $c->planos_aplicaveis, 'ciclos_aplicaveis' => $c->ciclos_aplicaveis, 'ativo' => $c->ativo,
            'usos_count' => $c->usos_count ?? 0, 'receita_descontada' => $receitaDescontada,
        ];
    }
}
