<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\PerfilUtilizador;
use App\Services\Plataforma\ServicoEspecializacoesSuporte;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Gestão das especializações do backoffice de suporte (ADR-077): perfis restritos cujas permissões são só
 * `suporte_tickets_<grupo>_view/_gerir`, geradas a partir dos grupos de categorias escolhidos — o pedido nunca traz
 * chaves de permissão, por isso nada fora de `suporte_*` pode ser concedido por aqui. Delegável (como os agentes)
 * pela chave `plataforma_suporte_gerir`: um agente fica sempre limitado às categorias da especialização.
 */
final class EspecializacoesSuporteController extends Controller
{
    public function __construct(private readonly ServicoEspecializacoesSuporte $servico) {}

    public function index(): JsonResponse
    {
        $this->exigir('plataforma_suporte_view', 'plataforma_suporte_gerir');

        return RespostaApi::sucesso(['especializacoes' => $this->servico->listar(), 'grupos' => $this->servico->grupos()], 'Especializações de suporte obtidas com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigir('plataforma_suporte_gerir');
        $p = $this->servico->criar($this->validar($r));

        return RespostaApi::criado($this->servico->apresentar($p) + ['agentes' => 0, 'base' => false], 'Especialização criada com sucesso.');
    }

    public function atualizar(Request $r, int $perfil): JsonResponse
    {
        $this->exigir('plataforma_suporte_gerir');
        $p = $this->servico->atualizar(PerfilUtilizador::query()->findOrFail($perfil), $this->validar($r, parcial: true));

        return RespostaApi::sucesso($this->servico->apresentar($p), 'Especialização actualizada.');
    }

    public function eliminar(int $perfil): JsonResponse
    {
        $this->exigir('plataforma_suporte_gerir');
        $this->servico->eliminar(PerfilUtilizador::query()->findOrFail($perfil));

        return RespostaApi::sucesso(null, 'Especialização eliminada.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $r, bool $parcial = false): array
    {
        $s = $parcial ? 'sometimes' : 'required';

        return $r->validate([
            'nome' => [$s, 'string', 'max:100'],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:500'],
            'grupos' => [$s, 'array', 'min:1'],
            'grupos.*' => ['string', 'distinct', Rule::in(array_keys(ServicoEspecializacoesSuporte::GRUPOS))],
            'ativo' => ['sometimes', 'boolean'],
        ], ['grupos.min' => 'Escolha pelo menos uma categoria de tickets.', 'grupos.required' => 'Escolha pelo menos uma categoria de tickets.']);
    }
}
