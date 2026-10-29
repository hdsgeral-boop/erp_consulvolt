<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoLimitesPlano;
use App\Services\Plataforma\ServicoVersoesPlano;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Planos e versões (rascunho -> publicada) e atribuição de plano a clientes. Acesso: middleware `plataforma`. */
final class PlanosPlataformaController extends Controller
{
    public function __construct(
        private readonly ServicoVersoesPlano $versoes,
        private readonly ServicoLimitesPlano $limites,
    ) {}

    /** Planos com a versão em vigor, as versões e quantos clientes os usam; mais o catálogo de módulos. */
    public function index(): JsonResponse
    {
        $clientes = Tenant::query()->whereNotNull('plano')->selectRaw('plano, count(*) as total')->groupBy('plano')->pluck('total', 'plano');

        $planos = PlanoLicenca::query()->orderBy('ordem')->orderBy('id')->get()->map(function (PlanoLicenca $p) use ($clientes) {
            $todas = PlanoLicencaVersao::query()->where('plano_id', $p->id)->orderByDesc('numero')->get();

            return [
                'id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'preco_mensal' => (float) $p->preco_mensal, 'moeda' => $p->moeda,
                'max_empresas' => $p->max_empresas, 'max_utilizadores' => $p->max_utilizadores, 'dias_teste' => $p->dias_teste, 'ativo' => $p->ativo,
                'clientes' => (int) ($clientes[$p->codigo] ?? 0),
                'versoes' => $todas->map(fn (PlanoLicencaVersao $v) => $this->versoes->apresentar($v))->all(),
            ];
        })->all();

        return RespostaApi::sucesso(['planos' => $planos, 'modulos' => (array) config('erp.planos_catalogo.modulos')], 'Planos obtidos com sucesso.');
    }

    public function criarVersao(Request $r, int $plano): JsonResponse
    {
        $this->exigir('plataforma_planos_gerir');
        $d = $r->validate(['copiar_de' => ['nullable', 'integer']]);
        $versao = $this->versoes->criarRascunho(PlanoLicenca::query()->findOrFail($plano), $d['copiar_de'] ?? null);

        return RespostaApi::criado($this->versoes->apresentar($versao), 'Rascunho criado.');
    }

    public function atualizarVersao(Request $r, int $versao): JsonResponse
    {
        $this->exigir('plataforma_planos_gerir');
        $d = $r->validate([
            'preco_mensal' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'moeda' => ['sometimes', 'string', 'size:3'],
            'max_empresas' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_utilizadores' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'dias_teste' => ['sometimes', 'nullable', 'integer', 'between:1,90'],
            'modulos' => ['sometimes', 'array'],
            'modulos.*' => ['string', Rule::in(array_keys((array) config('erp.planos_catalogo.modulos')))],
        ]);

        return RespostaApi::sucesso($this->versoes->apresentar($this->versoes->atualizarRascunho(PlanoLicencaVersao::query()->findOrFail($versao), $d)), 'Rascunho actualizado.');
    }

    public function publicar(int $versao): JsonResponse
    {
        $this->exigir('plataforma_planos_gerir');

        return RespostaApi::sucesso($this->versoes->apresentar($this->versoes->publicar(PlanoLicencaVersao::query()->findOrFail($versao))), 'Versão publicada.');
    }

    /**
     * PUT /plataforma/clientes/{cliente}/plano
     *
     * Fase 8 da extensão SaaS (segurança.txt, secção 17): um downgrade (ou remoção do plano) que deixe o cliente
     * acima dos novos limites de empresas/utilizadores já não passa em silêncio — exige confirmação explícita
     * (`confirmar: true`), com o excesso explicado na mensagem. Não bloqueia nada por si só (sem efeito financeiro,
     * sem prorata/crédito — isso fica fora desta fase, por tocar directamente em facturação).
     */
    public function atribuirPlano(Request $r, Tenant $cliente): JsonResponse
    {
        $this->exigir('plataforma_planos_gerir');
        $d = $r->validate([
            'plano' => ['nullable', 'string', Rule::exists('planos_licenca', 'codigo')],
            'confirmar' => ['sometimes', 'boolean'],
        ]);

        if ($d['plano'] ?? null) {
            $novoPlano = PlanoLicenca::query()->where('codigo', $d['plano'])->firstOrFail();
            $excedidos = $this->limites->excedeNoNovoPlano($cliente, $novoPlano);
            if ($excedidos !== [] && ! ($d['confirmar'] ?? false)) {
                $partes = collect($excedidos)->map(fn ($v, $k) => "{$k}: {$v['usadas']}/{$v['limite']}")->implode(', ');
                throw new ErroNegocio("Este cliente já excede os limites do plano {$novoPlano->nome} ({$partes}). Confirme para atribuir mesmo assim.", 'PLANO_ABAIXO_DO_USO', 422);
            }
        }

        $cliente->update(['plano' => $d['plano'] ?? null]);
        // ModulosPlano tem tenant_id => código do plano em cache (ServicoPermissoes nunca consulta `tenants`).
        ModulosPlano::invalidar();

        return RespostaApi::sucesso(['id' => $cliente->id, 'plano' => $cliente->plano], 'Plano do cliente actualizado.');
    }
}