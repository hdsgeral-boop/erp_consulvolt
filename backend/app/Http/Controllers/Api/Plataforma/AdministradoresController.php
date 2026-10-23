<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Http\Controllers\Controller;
use App\Mail\AdministradorPlataformaCriadoMail;
use App\Models\Utilizador;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Administradores da plataforma: super-administradores do tenant que opera a plataforma (middleware `plataforma`).
 * Não se desactiva nem elimina a própria conta; desactivar, eliminar ou mudar a palavra-passe termina as sessões.
 */
final class AdministradoresController extends Controller
{
    public function index(): JsonResponse
    {
        return RespostaApi::sucesso($this->consulta()->orderBy('id')->get()->map(fn (Utilizador $u) => $this->apresentar($u))->all(), 'Administradores obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:150'],
            'palavra_passe' => ['required', 'string', 'min:10', 'max:255'],
        ]);

        // Criado no tenant da plataforma (o do pedido): o hook de criação atribui-lhe o tenant.
        $novo = new Utilizador([
            'nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'] ?? null, 'email' => $d['email'] ?? null,
            'palavra_passe' => $d['palavra_passe'], 'papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR,
            'acesso_todas_empresas' => true, 'ativo' => true,
        ]);
        $novo->save();
        if ($novo->email) {
            Mail::to($novo->email)->send(new AdministradorPlataformaCriadoMail($novo->nome_completo ?: $novo->nome_utilizador, $novo->nome_utilizador, $d['palavra_passe']));
        }

        return RespostaApi::criado($this->apresentar($novo), 'Administrador criado com sucesso.');
    }

    public function estado(Request $r, int $administrador): JsonResponse
    {
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
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'palavra_passe' => ['sometimes', 'nullable', 'string', 'min:10', 'max:255'],
        ]);

        $alteracoes = [];
        if (array_key_exists('nome_completo', $d)) {
            $alteracoes['nome_completo'] = $d['nome_completo'];
        }
        if (! empty($d['palavra_passe'])) {
            $alteracoes['palavra_passe'] = $d['palavra_passe'];
            $alvo->tokens()->delete();
        }
        if ($alteracoes) {
            $alvo->update($alteracoes);
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), 'Administrador actualizado.');
    }

    public function eliminar(Request $r, int $administrador): JsonResponse
    {
        if ($administrador === (int) $r->user()->getKey()) {
            throw new ErroNegocio('Não pode eliminar a sua própria conta.', 'ADMIN_PROPRIO', 422);
        }
        $alvo = $this->consulta()->whereKey($administrador)->firstOrFail();
        $alvo->tokens()->delete();
        $alvo->delete();

        return RespostaApi::sucesso(null, 'Administrador eliminado.');
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