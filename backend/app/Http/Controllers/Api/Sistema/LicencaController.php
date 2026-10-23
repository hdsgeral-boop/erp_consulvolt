<?php

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Models\PlanoLicenca;
use App\Services\Sistema\ServicoLicenca;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\DefinicoesPlataforma;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * /api/licenca — licença da empresa activa e cobrança por plano e tempo (ADR-074).
 *
 * Estado e planos activos: qualquer utilizador da empresa (alimentam o aviso do cabeçalho). Pedir renovação, ver e cancelar
 * pedidos: `config_licenca_gerir`. Confirmar pagamento e gerir o catálogo de planos: só super-administradores.
 * Estas rotas funcionam também com a licença expirada (é assim que se renova); ver ResolverEmpresaAtiva.
 */
final class LicencaController extends Controller
{
    public function __construct(private readonly ServicoLicenca $licenca) {}

    public function estado(): JsonResponse
    {
        return RespostaApi::sucesso($this->licenca->estado(), 'Licença da empresa.');
    }

    public function planos(Request $request): JsonResponse
    {
        $todos = $request->user()->eSuperAdministrador() && $request->boolean('todos');

        return RespostaApi::sucesso($this->licenca->planos($todos), 'Planos de licença.');
    }

    /** GET /api/sistema/planos-publicos (sem autenticação) */
    public function planosPublicos(): JsonResponse
    {
        return RespostaApi::sucesso($this->licenca->planos(false), 'Planos de subscrição.');
    }

    /**
     * GET /api/sistema/definicoes-publicas (sem autenticação): dados não sensíveis para a landing page e o registo
     * — hoje só a duração do período de demonstração, para a promessa comercial nunca divergir do que o registo concede.
     */
    public function definicoesPublicas(): JsonResponse
    {
        return RespostaApi::sucesso([
            'dias_teste_padrao' => DefinicoesPlataforma::diasTestePadrao(),
            // Rótulos em português dos módulos que planosPublicos() devolve em 'modulos' (códigos) — a landing page
            // traduz sem duplicar a lista à mão (erp.planos_catalogo.modulos, o mesmo catálogo que ModulosPlano usa).
            'modulos_catalogo' => (array) config('erp.planos_catalogo.modulos'),
        ], 'Definições públicas da plataforma.');
    }

    public function criarPlano(Request $request): JsonResponse
    {
        $this->exigirSuperAdministrador($request);

        return RespostaApi::criado($this->licenca->gravarPlano(null, $this->validarPlano($request)), 'Plano criado.');
    }

    public function actualizarPlano(Request $request, int $plano): JsonResponse
    {
        $this->exigirSuperAdministrador($request);
        $modelo = PlanoLicenca::query()->findOrFail($plano);

        return RespostaApi::sucesso($this->licenca->gravarPlano($modelo, $this->validarPlano($request, $modelo->id)), 'Plano actualizado.');
    }

    public function subscricoes(): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->subscricoes(), 'Pedidos de licença da empresa.');
    }

    public function renovar(Request $request): JsonResponse
    {
        $this->exigir('config_licenca_gerir');
        $d = $request->validate(['plano_id' => ['required', 'integer'], 'meses' => ['required', 'integer', 'between:1,36']]);

        return RespostaApi::criado($this->licenca->pedirRenovacao((int) $d['plano_id'], (int) $d['meses'], $request->user()), 'Pedido de renovação criado. Aguarda confirmação do pagamento.');
    }

    public function cancelar(int $subscricao): JsonResponse
    {
        $this->exigir('config_licenca_gerir');

        return RespostaApi::sucesso($this->licenca->cancelar($subscricao), 'Pedido cancelado.');
    }

    public function confirmarPagamento(Request $request, int $subscricao): JsonResponse
    {
        $this->exigirSuperAdministrador($request);
        $d = $request->validate(['metodo' => ['nullable', 'string', 'max:30'], 'notas' => ['nullable', 'string', 'max:1000']]);

        return RespostaApi::sucesso(
            $this->licenca->confirmarPagamento($subscricao, $d['metodo'] ?? null, $d['notas'] ?? null, $request->user()),
            'Pagamento confirmado. Licença prolongada.',
        );
    }

    private function exigirSuperAdministrador(Request $request): void
    {
        if (! $request->user()?->eSuperAdministrador()) {
            throw new AccessDeniedHttpException;
        }
    }

    /** @return array<string, mixed> */
    private function validarPlano(Request $request, ?int $ignorar = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('planos_licenca', 'codigo')->ignore($ignorar)],
            'nome' => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'preco_mensal' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'moeda' => ['required', 'string', 'size:3'],
            'max_utilizadores' => ['nullable', 'integer', 'min:1'],
            'max_empresas' => ['nullable', 'integer', 'min:1'],
            'ativo' => ['required', 'boolean'],
            'ordem' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
