<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Rules\NifAngolano;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Api\RespostaApi;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Clientes SaaS (/api/plataforma/clientes). Acesso: middleware `plataforma` (super-administrador do tenant da plataforma).
 */
final class ClientesController extends Controller
{
    public function __construct(private readonly ServicoClientes $clientes) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'estado' => ['sometimes', 'nullable', Rule::in([Tenant::ESTADO_ATIVO, Tenant::ESTADO_SUSPENSO, Tenant::ESTADO_ARQUIVADO])],
            'plano' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        $lista = $this->clientes->listar($d)->map(fn (array $i) => $this->apresentar($i['tenant'], $r) + ['empresas' => $i['empresas'], 'utilizadores' => $i['utilizadores']])->all();

        return RespostaApi::sucesso($lista, 'Clientes obtidos com sucesso.');
    }

    /** A palavra-passe do administrador é sempre gerada pelo sistema (ServicoClientes::criar), nunca escolhida aqui. */
    public function store(Request $r): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        NifAngolano::normalizarNoPedido($r, 'empresa.nif');
        $d = $r->validate([
            'nome' => ['required', 'string', 'max:255'],
            'slug' => $this->regrasSlug(),
            'administrador.nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'administrador.nome_completo' => ['nullable', 'string', 'max:200'],
            'administrador.email' => ['nullable', 'email', 'max:150'],
            'empresa' => ['nullable', 'array'],
            'empresa.nome' => ['required_with:empresa', 'string', 'max:255'],
            'empresa.nif' => ['required_with:empresa', 'string', 'max:30', new NifAngolano],
        ], [], ['slug' => 'endereço (slug)']);

        $criado = $this->clientes->criar($d);

        return RespostaApi::criado([
            ...$this->apresentar($criado['cliente'], $r),
            'administrador' => ['nome_utilizador' => $criado['administrador']->nome_utilizador],
            'empresa' => $criado['empresa'] ? ['id' => $criado['empresa']->id, 'nome' => $criado['empresa']->nome] : null,
        ], 'Cliente criado com sucesso.');
    }

    public function mostrar(Request $r, Tenant $cliente): JsonResponse
    {
        $detalhe = $this->clientes->detalhe($cliente);

        return RespostaApi::sucesso([
            ...$this->apresentar($cliente, $r),
            'utilizadores' => $detalhe['utilizadores'],
            'empresas' => $detalhe['empresas']->map(fn ($e) => [
                'id' => $e->id, 'nome' => $e->nome, 'nif' => $e->nif, 'estado' => $e->estado,
                'licenca_origem' => $e->licenca_origem, 'licenca_fim' => $e->licenca_fim?->toDateString(),
            ])->values()->all(),
        ], 'Cliente obtido com sucesso.');
    }

    public function atualizar(Request $r, Tenant $cliente): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        $d = $r->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => array_merge(['sometimes'], $this->regrasSlug($cliente->id)),
            'nome_sistema' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], [], ['slug' => 'endereço (slug)']);

        return RespostaApi::sucesso($this->apresentar($this->clientes->atualizar($cliente, $d), $r), 'Cliente actualizado.');
    }

    public function estado(Request $r, Tenant $cliente): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        $d = $r->validate(['estado' => ['required', Rule::in([Tenant::ESTADO_ATIVO, Tenant::ESTADO_SUSPENSO])]]);
        $actualizado = $this->clientes->definirEstado($cliente, $d['estado']);

        return RespostaApi::sucesso($this->apresentar($actualizado, $r), $actualizado->estaAtivo() ? 'Cliente activado.' : 'Cliente suspenso.');
    }

    public function arquivar(Request $r, Tenant $cliente): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');

        return RespostaApi::sucesso($this->apresentar($this->clientes->arquivar($cliente), $r), 'Cliente arquivado.');
    }

    public function atualizarEmpresa(Request $r, Tenant $cliente, int $empresa): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        NifAngolano::normalizarNoPedido($r);
        $d = $r->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'nif' => ['sometimes', 'required', 'string', 'max:30', new NifAngolano],
            'estado' => ['sometimes', 'required', Rule::in(['ATIVO', 'INATIVO'])],
        ]);
        $e = $this->clientes->atualizarEmpresa($cliente, $empresa, $d, $r->user());

        return RespostaApi::sucesso(['id' => $e->id, 'nome' => $e->nome, 'nif' => $e->nif, 'estado' => $e->estado], 'Empresa actualizada.');
    }

    public function utilizadores(Tenant $cliente): JsonResponse
    {
        return RespostaApi::sucesso($this->clientes->utilizadores($cliente, request()->user()), 'Utilizadores obtidos com sucesso.');
    }

    /** PUT clientes/{cliente}/utilizadores/{utilizador} — parciais: {ativo} ou {nome_completo, email, papel}. */
    public function atualizarUtilizador(Request $r, Tenant $cliente, int $utilizador): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'papel' => ['sometimes', 'string', Rule::in([Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_UTILIZADOR])],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        return RespostaApi::sucesso($this->clientes->atualizarUtilizador($cliente, $utilizador, $d), 'Utilizador actualizado.');
    }

    /** A nova palavra-passe é sempre gerada pelo sistema e enviada por email — nunca escolhida por quem a repõe. */
    public function redefinirSenha(Tenant $cliente, int $utilizador): JsonResponse
    {
        $this->exigir('plataforma_clientes_gerir');
        // utilizadores tem RLS por tenant_id: lê-se e grava-se no contexto do cliente
        $palavraPasse = Str::password(14);
        $alvo = app(ContextoTenant::class)->executarComo((int) $cliente->id, function () use ($cliente, $utilizador, $palavraPasse) {
            $alvo = Utilizador::query()->where('tenant_id', $cliente->id)->whereKey($utilizador)->firstOrFail();
            $alvo->update(['palavra_passe' => $palavraPasse, 'deve_alterar_password' => true]);
            $alvo->tokens()->delete();

            return $alvo;
        });
        if ($alvo->email) {
            Mail::to($alvo->email)->send(new PalavraPasseRedefinidaMail($alvo->nome_completo ?: $alvo->nome_utilizador, $alvo->nome_utilizador, $palavraPasse, $cliente->nome));
        }

        return RespostaApi::sucesso(['id' => $alvo->id], 'Palavra-passe redefinida e enviada por email. As sessões deste utilizador terminaram.');
    }

    public function empresas(): JsonResponse
    {
        return RespostaApi::sucesso($this->clientes->empresas()->values()->all(), 'Empresas obtidas com sucesso.');
    }

    /** @return list<mixed> */
    private function regrasSlug(?int $ignorar = null): array
    {
        return ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z][a-z0-9-]*[a-z0-9]$/',
            Rule::notIn((array) config('erp.dominio.slugs_reservados')), Rule::unique('tenants', 'slug')->ignore($ignorar)];
    }

    /** @return array<string, mixed> */
    private function apresentar(Tenant $c, Request $r): array
    {
        return [
            'id' => $c->id,
            'nome' => $c->nome,
            'slug' => $c->slug,
            'estado' => $c->estado,
            'plano' => $c->plano,
            'nome_sistema' => $c->nome_sistema,
            'criado_em' => $c->criado_em,
            'endereco' => config('erp.dominio.base') ? $r->getScheme().'://'.$c->slug.'.'.config('erp.dominio.base') : null,
        ];
    }
}
