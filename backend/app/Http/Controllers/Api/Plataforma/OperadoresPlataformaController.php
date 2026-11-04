<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Mail\OperadorPlataformaCriadoMail;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPerfis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Operadores do back-office da plataforma (Fase 4 da extensão SaaS, secção 12 de `segurança.txt`): tenant da
 * plataforma, papel OPERADOR_PLATAFORMA — entram na área da plataforma (ExigirPlataforma), mas cada operação
 * sensível (clientes, planos, pagamentos, auditoria…) exige ainda a chave `plataforma_*_gerir` correspondente no
 * seu perfil (ver ServicoPermissoes/ClientesController e afins) — nunca acesso irrestrito como um super-administrador.
 *
 * Gerir OUTROS operadores nunca é delegável por uma chave de perfil: quem gere operadores decide-lhes o perfil, ou
 * seja, decide as permissões que eles passam a ter — um operador com essa chave podia criar-se um colega com um
 * perfil mais amplo que o seu, ou alterar o seu próprio perfil para um mais amplo. Só mesmo um super-administrador
 * gere isto (`exigirSuperAdministrador`), nunca `$this->exigir('plataforma_*_gerir')`.
 */
final class OperadoresPlataformaController extends Controller
{
    public function __construct(private readonly ServicoPerfis $perfis) {}

    public function index(): JsonResponse
    {
        $this->exigir('plataforma_operadores_plataforma_view', 'plataforma_operadores_plataforma_gerir');

        return RespostaApi::sucesso($this->consulta()->orderBy('id')->get()->map(fn (Utilizador $u) => $this->apresentar($u))->all(), 'Operadores da plataforma obtidos com sucesso.');
    }

    /** Perfis restritos à plataforma, para o selector do formulário de operador (os mesmos de AgentesSuporteController). */
    public function perfis(): JsonResponse
    {
        $this->exigir('plataforma_operadores_plataforma_view', 'plataforma_operadores_plataforma_gerir');

        return RespostaApi::sucesso($this->perfis->listar(soSuporte: true), 'Perfis da plataforma obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $d = $r->validate([
            'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:150'],
            'perfil_utilizador_id' => ['required', 'integer', Rule::exists('perfis_utilizador', 'id')->where('restrito_plataforma', true)],
        ]);

        // A palavra-passe nunca é escolhida por um humano: é sempre gerada aleatoriamente e enviada por email, com
        // troca obrigatória no primeiro acesso (deve_alterar_password, ExigirTrocaPassword).
        $palavraPasse = Str::password(14);

        // Criado no tenant da plataforma (o do pedido): o hook de criação atribui-lhe o tenant.
        $novo = new Utilizador([
            'nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'] ?? null, 'email' => $d['email'] ?? null,
            'palavra_passe' => $palavraPasse, 'papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $d['perfil_utilizador_id'],
            'acesso_todas_empresas' => false, 'ativo' => true, 'deve_alterar_password' => true,
        ]);
        $novo->save();
        if ($novo->email) {
            Mail::to($novo->email)->send(new OperadorPlataformaCriadoMail($novo->nome_completo ?: $novo->nome_utilizador, $novo->nome_utilizador, $palavraPasse));
        }

        return RespostaApi::criado($this->apresentar($novo), 'Operador da plataforma criado com sucesso.');
    }

    public function estado(Request $r, int $operador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($operador)->firstOrFail();
        $d = $r->validate(['ativo' => ['required', 'boolean']]);
        $alvo->update(['ativo' => $d['ativo']]);
        if (! $d['ativo']) {
            $alvo->tokens()->delete();
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), $d['ativo'] ? 'Operador activado.' : 'Operador desactivado.');
    }

    public function atualizar(Request $r, int $operador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($operador)->firstOrFail();
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'perfil_utilizador_id' => ['sometimes', 'required', 'integer', Rule::exists('perfis_utilizador', 'id')->where('restrito_plataforma', true)],
        ]);

        $alteracoes = [];
        foreach (['nome_completo', 'email', 'perfil_utilizador_id'] as $campo) {
            if (array_key_exists($campo, $d)) {
                $alteracoes[$campo] = $d[$campo];
            }
        }
        if ($alteracoes) {
            $alvo->update($alteracoes);
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), 'Operador actualizado.');
    }

    /** A palavra-passe nunca é escolhida pelo administrador: é sempre gerada e enviada por email (ver store()). */
    public function reporPalavraPasse(Request $r, int $operador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($operador)->firstOrFail();
        $palavraPasse = Str::password(14);
        $alvo->update(['palavra_passe' => $palavraPasse, 'deve_alterar_password' => true]);
        $alvo->tokens()->delete();
        if ($alvo->email) {
            Mail::to($alvo->email)->send(new PalavraPasseRedefinidaMail(
                $alvo->nome_completo ?: $alvo->nome_utilizador, $alvo->nome_utilizador, $palavraPasse, 'Consulvolt — Back-office da Plataforma'
            ));
        }

        return RespostaApi::sucesso(null, 'Nova password gerada e enviada por email. As sessões abertas foram terminadas.');
    }

    public function eliminar(Request $r, int $operador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($operador)->firstOrFail();
        $alvo->tokens()->delete();
        $alvo->delete();

        return RespostaApi::sucesso(null, 'Operador eliminado.');
    }

    private function exigirSuperAdministrador(Request $r): void
    {
        if (! $r->user()?->eSuperAdministrador()) {
            throw new ErroNegocio('Só um super-administrador pode gerir operadores da plataforma.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    private function consulta()
    {
        return Utilizador::query()->with('perfil:id,nome')
            ->where('tenant_id', (int) config('erp.dominio.plataforma_tenant_id'))
            ->where('papel', Utilizador::PAPEL_OPERADOR_PLATAFORMA);
    }

    /** @return array<string, mixed> */
    private function apresentar(Utilizador $u): array
    {
        $p = $u->relationLoaded('perfil') ? $u->perfil : $u->perfil()->first(['id', 'nome']);

        return [
            'id' => $u->id, 'nome_utilizador' => $u->nome_utilizador, 'nome_completo' => $u->nome_completo, 'email' => $u->email,
            'ativo' => (bool) $u->ativo, 'ultimo_acesso_em' => $u->ultimo_acesso_em,
            'perfil' => $p instanceof PerfilUtilizador ? ['id' => $p->id, 'nome' => $p->nome] : null,
        ];
    }
}
