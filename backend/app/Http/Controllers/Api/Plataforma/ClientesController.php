<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Utilizador;
use App\Services\Plataforma\ServicoClientes;
use App\Services\Plataforma\ServicoLicencaCliente;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Sistema\ServicoUtilizadores;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Clientes SaaS (/api/plataforma/clientes). Acesso: middleware `plataforma` (super-administrador do tenant da plataforma).
 */
final class ClientesController extends Controller
{
    public function __construct(private readonly ServicoClientes $clientes) {}

    public function index(Request $r): JsonResponse
    {
        // O tenant que opera a plataforma (onde vivem os administradores) não é um cliente: não aparece na lista de clientes.
        $lista = $this->clientes->listar(false)
            ->map(fn (array $i) => $this->apresentar($i['tenant'], $r) + ['empresas' => $i['empresas'], 'utilizadores' => $i['utilizadores']])
            ->values()->all();

        return RespostaApi::sucesso($lista, 'Clientes obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome' => ['required', 'string', 'max:255'],
            'slug' => $this->regrasSlug(),
            'administrador.nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'administrador.nome_completo' => ['nullable', 'string', 'max:200'],
            'administrador.palavra_passe' => ['required', 'string', 'min:10', 'max:255'],
            'empresa' => ['nullable', 'array'],
            'empresa.nome' => ['required_with:empresa', 'string', 'max:255'],
            'empresa.nif' => ['required_with:empresa', 'string', 'max:30'],
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
        $d = $r->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => array_merge(['sometimes'], $this->regrasSlug($cliente->id)),
            'nome_sistema' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], [], ['slug' => 'endereço (slug)']);

        return RespostaApi::sucesso($this->apresentar($this->clientes->atualizar($cliente, $d), $r), 'Cliente actualizado.');
    }

    public function estado(Request $r, Tenant $cliente): JsonResponse
    {
        $d = $r->validate(['estado' => ['required', Rule::in([Tenant::ESTADO_ATIVO, Tenant::ESTADO_SUSPENSO])]]);
        $actualizado = $this->clientes->definirEstado($cliente, $d['estado']);

        return RespostaApi::sucesso($this->apresentar($actualizado, $r), $actualizado->estaAtivo() ? 'Cliente activado.' : 'Cliente suspenso.');
    }

    public function arquivar(Request $r, Tenant $cliente): JsonResponse
    {
        return RespostaApi::sucesso($this->apresentar($this->clientes->arquivar($cliente), $r), 'Cliente arquivado.');
    }

    public function atualizarEmpresa(Request $r, Tenant $cliente, int $empresa): JsonResponse
    {
        $d = $r->validate([
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'nif' => ['sometimes', 'required', 'string', 'max:30'],
            'estado' => ['sometimes', 'required', Rule::in(['ATIVO', 'INATIVO'])],
        ]);
        $e = $this->clientes->atualizarEmpresa($cliente, $empresa, $d, $r->user());

        return RespostaApi::sucesso(['id' => $e->id, 'nome' => $e->nome, 'nif' => $e->nif, 'estado' => $e->estado], 'Empresa actualizada.');
    }

    public function utilizadores(Tenant $cliente): JsonResponse
    {
        $lista = Utilizador::query()->where('tenant_id', $cliente->id)->orderBy('id')->get()->map(fn (Utilizador $u) => [
            'id' => $u->id,
            'nome_utilizador' => $u->nome_utilizador,
            'email' => $u->email,
            'nome_completo' => $u->nome_completo,
            'papel' => $u->papel,
            'ativo' => (bool) $u->ativo,
        ])->values()->all();

        return RespostaApi::sucesso($lista, 'Utilizadores obtidos com sucesso.');
    }

    /** PUT clientes/{cliente}/utilizadores/{utilizador} — nome, e-mail, papel e estado de um utilizador do cliente. */
    public function atualizarUtilizador(Request $r, Tenant $cliente, int $utilizador, ServicoAuditoria $auditoria): JsonResponse
    {
        $alvo = Utilizador::query()->where('tenant_id', $cliente->id)->whereKey($utilizador)->firstOrFail();
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'papel' => ['sometimes', Rule::in([Utilizador::PAPEL_SUPER_ADMINISTRADOR, Utilizador::PAPEL_ADMINISTRADOR, Utilizador::PAPEL_UTILIZADOR])],
            'ativo' => ['sometimes', 'boolean'],
        ]);
        $deixaDeSerSuper = $alvo->eSuperAdministrador() && ((isset($d['papel']) && $d['papel'] !== Utilizador::PAPEL_SUPER_ADMINISTRADOR) || (isset($d['ativo']) && ! $d['ativo']));
        if ($deixaDeSerSuper && ! Utilizador::query()->where('tenant_id', $cliente->id)->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)
            ->where('ativo', true)->whereKeyNot($alvo->id)->exists()) {
            throw new ErroNegocio('É o último super-administrador activo deste cliente: não pode deixar de o ser.', 'ULTIMO_SUPER_ADMINISTRADOR', 422);
        }
        $antes = $alvo->only(['nome_completo', 'email', 'papel', 'ativo']);
        $alvo->fill($d)->save();
        if (isset($d['ativo']) && ! $d['ativo']) {
            $alvo->tokens()->delete();
        }
        $auditoria->registar('Plataforma', 'Alterar utilizador de cliente', "Utilizador '{$alvo->nome_utilizador}' do cliente #{$cliente->id} alterado.",
            'utilizadores', $alvo->id, $antes, $alvo->only(['nome_completo', 'email', 'papel', 'ativo']));

        return RespostaApi::sucesso($alvo->only(['id', 'nome_utilizador', 'nome_completo', 'email', 'papel', 'ativo']), 'Utilizador actualizado.');
    }

    public function redefinirSenha(Request $r, Tenant $cliente, int $utilizador): JsonResponse
    {
        $alvo = Utilizador::query()->where('tenant_id', $cliente->id)->whereKey($utilizador)->firstOrFail();
        $d = $r->validate(['palavra_passe' => ['required', 'string', 'min:10', 'max:255']], [], ['palavra_passe' => 'palavra-passe']);
        ServicoUtilizadores::exigirForca($d['palavra_passe']);

        // provisória: o utilizador é convidado a alterá-la no próximo acesso
        $alvo->forceFill(['palavra_passe' => $d['palavra_passe'], 'alterar_palavra_passe' => true, 'palavra_passe_alterada_em' => now()])->save();
        $alvo->tokens()->delete();

        return RespostaApi::sucesso(['id' => $alvo->id], 'Palavra-passe redefinida. As sessões deste utilizador terminaram.');
    }

    public function empresas(): JsonResponse
    {
        return RespostaApi::sucesso($this->clientes->empresas(false)->values()->all(), 'Empresas obtidas com sucesso.');
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
            'licenca' => app(ServicoLicencaCliente::class)->estado($c),
            'criado_em' => $c->criado_em,
            'endereco' => config('erp.dominio.base') ? $r->getScheme().'://'.$c->slug.'.'.config('erp.dominio.base') : null,
        ];
    }
}
