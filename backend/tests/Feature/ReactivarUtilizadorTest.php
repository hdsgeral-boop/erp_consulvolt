<?php

namespace Tests\Feature;

use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A eliminação de um utilizador é lógica: o nome fica ocupado. Criar outro com o mesmo nome no mesmo cliente devolve
 * UTILIZADOR_ELIMINADO (o ecrã oferece reactivar); com `reactivar`, a conta volta com os dados novos e o mesmo id.
 */
final class ReactivarUtilizadorTest extends TestCase
{
    public function test_criar_com_o_nome_de_um_eliminado_oferece_e_faz_a_reactivacao(): void
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['all' => true], 'Total');
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);
        $h = $this->entrar($admin) + ['X-Empresa-Id' => $e->id];
        $corpo = ['nome_utilizador' => 'ana.antiga', 'palavra_passe' => 'Segredo#2026', 'perfil_utilizador_id' => $perfil->id, 'acesso_todas_empresas' => true, 'nome_completo' => 'Ana Nova'];

        $id = $this->postJson('/api/sistema/utilizadores', $corpo, $h)->assertCreated()->json('dados.id');
        $this->deleteJson("/api/sistema/utilizadores/{$id}", [], $h)->assertOk();

        $this->postJson('/api/sistema/utilizadores', $corpo, $h)->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_ELIMINADO');
        $this->postJson('/api/sistema/utilizadores', $corpo + ['reactivar' => true], $h)->assertCreated()->assertJsonPath('dados.id', $id);

        $u = Utilizador::query()->findOrFail($id);
        $this->assertNull($u->eliminado_em);
        $this->assertTrue((bool) $u->ativo);
        $this->assertSame('Ana Nova', $u->nome_completo);
        $this->assertTrue(DB::table('logs_auditoria')->where('acao', 'Reactivar utilizador')->exists());
    }

    public function test_nome_de_uma_conta_activa_continua_duplicado(): void
    {
        $e = $this->criarEmpresa();
        $perfil = $this->criarPerfil(['all' => true], 'Total');
        $admin = $this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true, 'perfil_utilizador_id' => $perfil->id]);
        $this->criarUtilizador(['nome_utilizador' => 'ja.existe']);

        $this->postJson('/api/sistema/utilizadores', ['nome_utilizador' => 'ja.existe', 'palavra_passe' => 'Segredo#2026', 'perfil_utilizador_id' => $perfil->id, 'reactivar' => true],
            $this->entrar($admin) + ['X-Empresa-Id' => $e->id])->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_DUPLICADO');
    }
}
