<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoVersoesPlano;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Planos e versões (rascunho -> publicada) e atribuição de plano a clientes. Acesso: middleware `plataforma`. */
final class PlanosPlataformaController extends Controller
{
    public function __construct(private readonly ServicoVersoesPlano $versoes) {}

    /** Planos com a versão em vigor, as versões e quantos clientes os usam; mais o catálogo de módulos. */
    public function index(): JsonResponse
    {
        $clientes = Tenant::query()->whereNotNull('plano')->selectRaw('plano, count(*) as total')->groupBy('plano')->pluck('total', 'plano');

        $planos = PlanoLicenca::query()->orderBy('ordem')->orderBy('id')->get()->map(function (PlanoLicenca $p) use ($clientes) {
            $todas = PlanoLicencaVersao::query()->where('plano_id', $p->id)->orderByDesc('numero')->get();

            return [
                'id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao, 'ordem' => (int) $p->ordem,
                'preco_mensal' => (float) $p->preco_mensal, 'moeda' => $p->moeda,
                'max_empresas' => $p->max_empresas, 'max_utilizadores' => $p->max_utilizadores, 'ativo' => $p->ativo,
                'clientes' => (int) ($clientes[$p->codigo] ?? 0),
                'tem_versao_publicada' => $todas->contains(fn (PlanoLicencaVersao $v) => $v->estado === PlanoLicencaVersao::PUBLICADA),
                'versoes' => $todas->map(fn (PlanoLicencaVersao $v) => $this->versoes->apresentar($v))->all(),
            ];
        })->all();

        return RespostaApi::sucesso(['planos' => $planos, 'modulos' => (array) config('erp.planos_catalogo.modulos')], 'Planos obtidos com sucesso.');
    }

    /** POST /plataforma/planos — cria o plano e a sua primeira versão (rascunho). Fica inactivo até haver versão publicada. */
    public function criarPlano(Request $r): JsonResponse
    {
        $d = $r->validate([
            'codigo' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/', Rule::unique('planos_licenca', 'codigo')],
            'nome' => ['required', 'string', 'max:100', Rule::unique('planos_licenca', 'nome')],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'preco_mensal' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'moeda' => ['required', 'string', 'regex:/^[A-Za-z]{3}$/'],
            'max_empresas' => ['nullable', 'integer', 'min:1'],
            'max_utilizadores' => ['nullable', 'integer', 'min:1'],
            'modulos' => ['nullable', 'array'],
            'modulos.*' => ['string', Rule::in(array_keys((array) config('erp.planos_catalogo.modulos')))],
            'ativo' => ['nullable', 'boolean'],
            'ordem' => ['nullable', 'integer', 'min:0'],
        ]);
        $d['moeda'] = strtoupper($d['moeda']);
        $plano = $this->versoes->criarPlano($d);

        return RespostaApi::criado(
            ['id' => $plano->id, 'codigo' => $plano->codigo, 'nome' => $plano->nome, 'ativo' => $plano->ativo],
            'Plano criado com a versão 1 em rascunho. Publique-a para o activar.',
        );
    }

    /** PUT /plataforma/planos/{plano} — nome, descrição, activo e ordem (o código não muda; preço e limites só por versão). */
    public function atualizarPlano(Request $r, int $plano): JsonResponse
    {
        $modelo = PlanoLicenca::query()->findOrFail($plano);
        $d = $r->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('planos_licenca', 'nome')->ignore($modelo->id)],
            'descricao' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'ativo' => ['sometimes', 'required', 'boolean'],
            'ordem' => ['sometimes', 'required', 'integer', 'min:0'],
            'confirmar_desativacao' => ['sometimes', 'boolean'],
        ]);
        $p = $this->versoes->atualizarPlano($modelo, $d);

        return RespostaApi::sucesso(
            ['id' => $p->id, 'codigo' => $p->codigo, 'nome' => $p->nome, 'descricao' => $p->descricao, 'ativo' => $p->ativo, 'ordem' => (int) $p->ordem, 'clientes' => $this->versoes->clientesDoPlano($p)],
            'Plano actualizado.',
        );
    }

    public function criarVersao(Request $r, int $plano): JsonResponse
    {
        $d = $r->validate(['copiar_de' => ['nullable', 'integer']]);
        $versao = $this->versoes->criarRascunho(PlanoLicenca::query()->findOrFail($plano), $d['copiar_de'] ?? null);

        return RespostaApi::criado($this->versoes->apresentar($versao), 'Rascunho criado.');
    }

    public function atualizarVersao(Request $r, int $versao): JsonResponse
    {
        $d = $r->validate([
            'preco_mensal' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'moeda' => ['sometimes', 'string', 'size:3'],
            'max_empresas' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_utilizadores' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'modulos' => ['sometimes', 'array'],
            'modulos.*' => ['string', Rule::in(array_keys((array) config('erp.planos_catalogo.modulos')))],
        ]);

        return RespostaApi::sucesso($this->versoes->apresentar($this->versoes->atualizarRascunho(PlanoLicencaVersao::query()->findOrFail($versao), $d)), 'Rascunho actualizado.');
    }

    public function publicar(int $versao): JsonResponse
    {
        return RespostaApi::sucesso($this->versoes->apresentar($this->versoes->publicar(PlanoLicencaVersao::query()->findOrFail($versao))), 'Versão publicada.');
    }

    /** PUT /plataforma/clientes/{cliente}/plano */
    public function atribuirPlano(Request $r, Tenant $cliente, ServicoAuditoria $auditoria): JsonResponse
    {
        $d = $r->validate(['plano' => ['nullable', 'string', Rule::exists('planos_licenca', 'codigo')]]);
        $anterior = $cliente->plano;
        $cliente->update(['plano' => $d['plano'] ?? null]);
        $auditoria->registar('Plataforma', 'Atribuiu plano', "Plano do cliente #{$cliente->id}: ".($anterior ?? 'nenhum').' -> '.($cliente->plano ?? 'nenhum'), 'tenants', $cliente->id, ['plano' => $anterior], ['plano' => $cliente->plano]);

        return RespostaApi::sucesso(['id' => $cliente->id, 'plano' => $cliente->plano], 'Plano do cliente actualizado.');
    }
}
