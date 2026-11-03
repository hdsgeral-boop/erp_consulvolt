<?php

namespace Tests\Unit;

use App\Services\Sistema\CatalogoPermissoes;
use App\Support\Tenancy\ModulosPlano;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ModulosPlano capa o menu e o Gate ao que o plano do cliente inclui. Um módulo do catálogo de permissões que não conste
 * de erp.planos_catalogo.modulos nunca pode ser incluído num plano e fica invisível para todos os clientes com plano
 * (foi o que aconteceu com Estrutura Orgânica, Método de Trabalho e Acréscimos e Diferimentos).
 */
final class CatalogoModulosPlanoTest extends TestCase
{
    /** Módulos que não são de negócio do cliente: sempre disponíveis ou do backoffice da plataforma. */
    private const FORA_DOS_PLANOS = [...ModulosPlano::SEMPRE_DISPONIVEIS, 'suporte'];

    #[Test]
    public function todo_o_modulo_de_negocio_do_catalogo_de_permissoes_pode_entrar_num_plano(): void
    {
        $noCatalogo = array_column(CatalogoPermissoes::completo()['modulos'], 'id');
        $deNegocio = array_values(array_diff($noCatalogo, self::FORA_DOS_PLANOS));
        $dosPlanos = array_keys((array) config('erp.planos_catalogo.modulos'));

        $this->assertSame([], array_values(array_diff($deNegocio, $dosPlanos)), 'Módulos sem lugar no catálogo de planos.');
        foreach (['estrutura', 'metodo', 'acrescimos'] as $m) {
            $this->assertContains($m, $dosPlanos);
        }
    }
}
