<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Mail\AgenteSuporteCriadoMail;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPerfis;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Agentes do backoffice de suporte (ADR-076, fase 4 fatia 3; especialização ADR-077): tenant da plataforma, papel
 * AGENTE_SUPORTE — só vêem e respondem a tickets (/api/suporte/*, middleware `suporte`), isolados de clientes,
 * planos e definições. A especialização (técnico/financeiro/marketing) vem do `perfil_utilizador_id`, com as chaves
 * do módulo `suporte` do catálogo — ver ServicoPermissoes/SuporteController. Geridos aqui pelos
 * super-administradores (middleware `plataforma`), tal como AdministradoresController.
 */
final class AgentesSuporteController extends Controller
{
    public function __construct(private readonly ServicoPerfis $perfis) {}

    public function index(): JsonResponse
    {
        return RespostaApi::sucesso($this->consulta()->orderBy('id')->get()->map(fn (Utilizador $u) => $this->apresentar($u))->all(), 'Agentes de suporte obtidos com sucesso.');
    }

    /** Perfis de especialização do backoffice (ADR-077), para o selector do formulário de agente. */
    public function perfis(): JsonResponse
    {
        return RespostaApi::sucesso($this->perfis->listar(soSuporte: true), 'Perfis de suporte obtidos com sucesso.');
    }

    public function store(Request $r): JsonResponse
    {
        $d = $r->validate([
            'nome_utilizador' => ['required', 'string', 'max:100', Rule::unique('utilizadores', 'nome_utilizador')],
            'nome_completo' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:150'],
            'palavra_passe' => ['required', 'string', 'min:10', 'max:255'],
            'perfil_utilizador_id' => ['required', 'integer', Rule::exists('perfis_utilizador', 'id')->where('restrito_plataforma', true)],
        ]);

        // Criado no tenant da plataforma (o do pedido): o hook de criação atribui-lhe o tenant.
        $novo = new Utilizador([
            'nome_utilizador' => $d['nome_utilizador'], 'nome_completo' => $d['nome_completo'] ?? null, 'email' => $d['email'] ?? null,
            'palavra_passe' => $d['palavra_passe'], 'papel' => Utilizador::PAPEL_AGENTE_SUPORTE, 'perfil_utilizador_id' => $d['perfil_utilizador_id'],
            'acesso_todas_empresas' => false, 'ativo' => true,
        ]);
        $novo->save();
        if ($novo->email) {
            Mail::to($novo->email)->send(new AgenteSuporteCriadoMail($novo->nome_completo ?: $novo->nome_utilizador, $novo->nome_utilizador, $d['palavra_passe']));
        }

        return RespostaApi::criado($this->apresentar($novo), 'Agente de suporte criado com sucesso.');
    }

    public function estado(Request $r, int $agente): JsonResponse
    {
        $alvo = $this->consulta()->whereKey($agente)->firstOrFail();
        $d = $r->validate(['ativo' => ['required', 'boolean']]);
        $alvo->update(['ativo' => $d['ativo']]);
        if (! $d['ativo']) {
            $alvo->tokens()->delete();
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), $d['ativo'] ? 'Agente activado.' : 'Agente desactivado.');
    }

    public function atualizar(Request $r, int $agente): JsonResponse
    {
        $alvo = $this->consulta()->whereKey($agente)->firstOrFail();
        $d = $r->validate([
            'nome_completo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'palavra_passe' => ['sometimes', 'nullable', 'string', 'min:10', 'max:255'],
            'perfil_utilizador_id' => ['sometimes', 'required', 'integer', Rule::exists('perfis_utilizador', 'id')->where('restrito_plataforma', true)],
        ]);

        $alteracoes = [];
        foreach (['nome_completo', 'email', 'perfil_utilizador_id'] as $campo) {
            if (array_key_exists($campo, $d)) {
                $alteracoes[$campo] = $d[$campo];
            }
        }
        if (! empty($d['palavra_passe'])) {
            $alteracoes['palavra_passe'] = $d['palavra_passe'];
            $alvo->tokens()->delete();
        }
        if ($alteracoes) {
            $alvo->update($alteracoes);
        }

        return RespostaApi::sucesso($this->apresentar($alvo->refresh()), 'Agente actualizado.');
    }

    public function eliminar(int $agente): JsonResponse
    {
        $alvo = $this->consulta()->whereKey($agente)->firstOrFail();
        $alvo->tokens()->delete();
        $alvo->delete();

        return RespostaApi::sucesso(null, 'Agente eliminado.');
    }

    private function consulta()
    {
        return Utilizador::query()->with('perfil:id,nome')
            ->where('tenant_id', (int) config('erp.dominio.plataforma_tenant_id'))
            ->where('papel', Utilizador::PAPEL_AGENTE_SUPORTE);
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
