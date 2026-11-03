<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Mail\AdministradorPlataformaCriadoMail;
use App\Mail\PalavraPasseRedefinidaMail;
use App\Models\Utilizador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Administradores da plataforma: super-administradores do tenant que opera a plataforma (middleware `plataforma`).
 * Não se desactiva nem elimina a própria conta; desactivar, eliminar ou mudar a palavra-passe termina as sessões.
 *
 * Gerir administradores nunca é delegável por uma chave de perfil (ao contrário de clientes/planos/etc.): dar essa
 * chave a um OPERADOR_PLATAFORMA equivaleria a dar-lhe poder para criar super-administradores novos, incluindo
 * para si próprio — uma escalada de privilégio completa da plataforma. Só mesmo um super-administrador gere isto
 * (`exigirSuperAdministrador`, nunca `$this->exigir('plataforma_*_gerir')`).
 */
final class AdministradoresController extends Controller
{
    public function index(): JsonResponse
    {
        return RespostaApi::sucesso($this->consulta()->orderBy('id')->get()->map(fn (Utilizador $u) => $this->apresentar($u))->all(), 'Administradores obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $d = $r->validate([
            'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:150'],
        ]);

        // A palavra-passe nunca é escolhida por um humano: é sempre gerada aleatoriamente e enviada por email, com
        // troca obrigatória no primeiro acesso (deve_alterar_password, ExigirTrocaPassword) — tal como para os
        // utilizadores de uma empresa (ServicoUtilizadores::criar).
        $palavraPasse = Str::password(14);

        // Criado no tenant da plataforma (o do pedido): o hook de criação atribui-lhe o tenant.
        $novo = new Utilizador([
            'nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'] ?? null, 'email' => $d['email'] ?? null,
            'palavra_passe' => $palavraPasse, 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR,
            'acesso_todas_empresas' => true, 'ativo' => true, 'deve_alterar_password' => true,
        ]);
        $novo->save();
        if ($novo->email) {
            Mail::to($novo->email)->send(new AdministradorPlataformaCriadoMail($novo->nome_completo ?: $novo->nome_utilizador, $novo->nome_utilizador, $palavraPasse));
        }

        return RespostaApi::criado($this->apresentar($novo), 'Administrador criado com sucesso.');
    }

    public function estado(Request $r, int $administrador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $d = $r->validate(['ativo' => ['required', 'boolean']]);

        if (! $d['ativo'] && (int) $alvo->getKey() === (int) $r->user()->getKey()) {
            throw new ErroNegocio('Não pode desactivar a sua própria conta.', 'ADMIN_PROPRIO', 422);
        }

        $alvo->update(['ativo' => $d['ativo']]);
        if (! $d['ativo']) {
            $alvo->tokens()->delete();
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), $d['ativo'] ? 'Administrador activado.' : 'Administrador desactivado.');
    }

    public function atualizar(Request $r, int $administrador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        if (array_key_exists('nome_completo', $d)) {
            $alvo->update(['nome_completo' => $d['nome_completo']]);
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), 'Administrador actualizado.');
    }

    /** A palavra-passe nunca é escolhida pelo administrador: é sempre gerada e enviada por email (ver store()). */
    public function reporPalavraPasse(Request $r, int $administrador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $palavraPasse = Str::password(14);
        $alvo->update(['palavra_passe' => $palavraPasse, 'deve_alterar_password' => true]);
        $alvo->tokens()->delete();
        if ($alvo->email) {
            Mail::to($alvo->email)->send(new PalavraPasseRedefinidaMail(
                $alvo->nome_completo ?: $alvo->nome_utilizador, $alvo->nome_utilizador, $palavraPasse, 'Consulvolt — Administração da Plataforma'
            ));
        }

        return RespostaApi::sucesso(null, 'Nova password gerada e enviada por email. As sessões abertas foram terminadas.');
    }

    public function eliminar(Request $r, int $administrador): JsonResponse
    {
        $this->exigirSuperAdministrador($r);
        if ($administrador === (int) $r->user()->getKey()) {
            throw new ErroNegocio('Não pode eliminar a sua própria conta.', 'ADMIN_PROPRIO', 422);
        }
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $alvo->tokens()->delete();
        $alvo->delete();

        return RespostaApi::sucesso(null, 'Administrador eliminado.');
    }

    private function exigirSuperAdministrador(Request $r): void
    {
        if (! $r->user()?->eSuperAdministrador()) {
            throw new ErroNegocio('Só um super-administrador pode gerir outros administradores da plataforma.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    private function consulta()
    {
        return Utilizador::query()
            ->where('tenant_id', (int) config('erp.dominio.plataforma_tenant_id'))
            ->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR);
    }

    /** @return array<string, mixed> */
    private function apresentar(Utilizador $u): array
    {
        return [
            'id' => $u->id, 'nome_utilizador' => $u->nome_utilizador, 'nome_completo' => $u->nome_completo, 'email' => $u->email,
            'ativo' => (bool) $u->ativo, 'ultimo_acesso_em' => $u->ultimo_acesso_em,
        ];
    }
}
