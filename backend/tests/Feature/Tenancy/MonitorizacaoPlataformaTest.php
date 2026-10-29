<?php

namespace Tests\Feature\Tenancy;

use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use App\Support\Tenancy\ContextoTenant;
use Tests\TestCase;

/**
 * Fase 7 da extensão SaaS (segurança.txt, secção 16): `GET /api/plataforma/monitorizacao` reutiliza o ServicoSaude
 * do `/api/saude` público, mas só para perfis de back-office com a chave `plataforma_monitorizacao_ver`.
 */
final class MonitorizacaoPlataformaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.saas.ativo' => true]);
        app(ContextoTenant::class)->definir(1);
    }

    public function test_super_administrador_ve_o_estado_completo_das_dependencias(): void
    {
        $super = $this->entrar($this->criarUtilizador(['papel' => Utilizador::PAPEL_SUPER_ADMINISTRADOR, 'acesso_todas_empresas' => true]));

        $this->getJson('/api/plataforma/monitorizacao', $super)->assertOk()
            ->assertJsonPath('dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.base_dados.estado', 'OK')
            ->assertJsonPath('dados.componentes.redis.estado', 'OK')
            ->assertJsonStructure(['dados' => ['versao', 'componentes' => ['filas', 'armazenamento', 'processos'], 'informacao']]);
    }

    public function test_pedido_sem_a_chave_plataforma_monitorizacao_ver_e_rejeitado(): void
    {
        $perfil = PerfilUtilizador::create(['nome' => 'Sem monitorização', 'permissoes' => ['_v2' => true], 'restrito_plataforma' => true]);
        $operador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);

        $this->getJson('/api/plataforma/monitorizacao', $this->entrar($operador))->assertForbidden();
    }

    public function test_operador_com_a_chave_ve_o_estado(): void
    {
        $perfil = PerfilUtilizador::create(['nome' => 'Monitorização', 'permissoes' => ['_v2' => true, 'plataforma_monitorizacao_ver' => true], 'restrito_plataforma' => true]);
        $operador = $this->criarUtilizador(['papel' => Utilizador::PAPEL_OPERADOR_PLATAFORMA, 'perfil_utilizador_id' => $perfil->id]);

        $this->getJson('/api/plataforma/monitorizacao', $this->entrar($operador))->assertOk()->assertJsonPath('dados.estado', 'OK');
    }

    public function test_utilizador_comum_fora_da_plataforma_nao_acede(): void
    {
        $this->getJson('/api/plataforma/monitorizacao', $this->entrar($this->criarUtilizador()))->assertForbidden();
    }
}
