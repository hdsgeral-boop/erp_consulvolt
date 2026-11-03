<?php

namespace Tests\Feature;

use App\Models\PerfilUtilizador;
use App\Services\Sistema\CatalogoPermissoes;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase K (ADR-079) — `erp:perfis:actualizar-modelos`: acrescenta aos perfis existentes as permissões novas dos perfis-modelo (nunca
 * retira), deixa de fora o acesso total e os perfis fora do formato v2, é idempotente e `--simular` não grava.
 */
final class FkPerfisModelosTest extends TestCase
{
    private function perm(string $nome): array
    {
        return PerfilUtilizador::query()->where('nome', $nome)->firstOrFail()->permissoes;
    }

    #[Test]
    public function os_modelos_do_catalogo_trazem_as_permissoes_novas_da_fase_k(): void
    {
        $modelos = collect(CatalogoPermissoes::modelos())->keyBy('nome');
        foreach (['est_multas_gerir', 'est_delegar'] as $chave) {
            $this->assertTrue(CatalogoPermissoes::chaveValida($chave));
            $this->assertTrue($modelos['Gestor de Estrutura Orgânica']['permissoes'][$chave] ?? false, "Gestor sem {$chave}");
            $this->assertTrue($modelos['Técnico de RH']['permissoes'][$chave] ?? false, "Técnico de RH sem {$chave}");
        }
        $this->assertTrue($modelos['Responsável de Salários']['permissoes']['est_multas_gerir']);
        $this->assertArrayNotHasKey('est_delegar', $modelos['Responsável de Salários']['permissoes']);
        $this->assertTrue($modelos['Gestor de Estrutura Orgânica']['permissoes']['est_validar_entregas']);
        $sensiveis = array_keys(array_filter(CatalogoPermissoes::tarefas(), fn ($t) => $t['sensivel'] ?? false));
        $this->assertContains('est_multas_gerir', $sensiveis);
        $this->assertContains('est_delegar', $sensiveis);
    }

    #[Test]
    public function acrescenta_o_que_falta_nunca_retira_e_deixa_de_fora_acesso_total_e_formato_antigo(): void
    {
        // «Gestor de Estrutura Orgânica» antigo (sem est_validar_entregas e sem as novas) e com uma permissão que o modelo não tem
        PerfilUtilizador::create(['nome' => 'Gestor de Estrutura Orgânica', 'permissoes' => ['_v2' => true, 'dashboard_view' => true, 'est_estrutura_view' => true, 'est_editar' => true, 'compras_pedidos_view' => true]]);
        PerfilUtilizador::create(['nome' => 'Técnico de RH', 'permissoes' => ['all' => true, '_v2' => true]]);   // acesso total: fica de fora
        PerfilUtilizador::create(['nome' => 'Responsável de Salários', 'permissoes' => ['rh' => true]]);   // formato antigo: fica de fora
        PerfilUtilizador::create(['nome' => 'Perfil sem modelo', 'permissoes' => ['_v2' => true, 'dashboard_view' => true]]);
        $antes = PerfilUtilizador::query()->pluck('permissoes', 'nome')->all();

        // --simular mostra e não grava
        $this->assertSame(0, Artisan::call('erp:perfis:actualizar-modelos', ['--simular' => true]));
        $saida = Artisan::output();
        $this->assertStringContainsString('Gestor de Estrutura Orgânica', $saida);
        $this->assertStringContainsString('est_validar_entregas', $saida);
        $this->assertStringContainsString('acrescentaria', $saida);
        $this->assertStringNotContainsString('Técnico de RH', $saida);
        $this->assertStringNotContainsString('Responsável de Salários', $saida);
        $this->assertSame($antes, PerfilUtilizador::query()->pluck('permissoes', 'nome')->all());

        // gravar: o perfil recebe as permissões do modelo que lhe faltavam e fica com as que já tinha
        $this->assertSame(0, Artisan::call('erp:perfis:actualizar-modelos'));
        $saida = Artisan::output();
        $this->assertStringContainsString('acrescentou', $saida);
        $gestor = $this->perm('Gestor de Estrutura Orgânica');
        foreach (['est_validar_entregas', 'est_multas_gerir', 'est_delegar', 'est_eliminar', 'rh_portal_usar'] as $chave) {
            $this->assertTrue($gestor[$chave] ?? false, "falta {$chave}");
        }
        foreach (['compras_pedidos_view', 'est_editar', 'est_estrutura_view', 'dashboard_view'] as $chave) {
            $this->assertTrue($gestor[$chave] ?? false, "perdeu {$chave}");   // nunca se retira
        }
        // o que ficou de fora não mudou
        $this->assertSame($antes['Técnico de RH'], $this->perm('Técnico de RH'));
        $this->assertSame($antes['Responsável de Salários'], $this->perm('Responsável de Salários'));
        $this->assertSame($antes['Perfil sem modelo'], $this->perm('Perfil sem modelo'));

        // idempotente: nada mais a acrescentar
        $depois = PerfilUtilizador::query()->pluck('permissoes', 'nome')->all();
        $this->assertSame(0, Artisan::call('erp:perfis:actualizar-modelos'));
        $this->assertStringContainsString('já têm as permissões dos modelos', Artisan::output());
        $this->assertSame($depois, PerfilUtilizador::query()->pluck('permissoes', 'nome')->all());
    }
}
