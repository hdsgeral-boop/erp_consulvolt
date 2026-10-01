<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Database\Seeders\E2E\E2ESeeder;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/** Protecção do ambiente E2E: o comando e o seeder de dados fictícios só correm numa base *_e2e. */
final class PrepararE2ETest extends TestCase
{
    #[Test]
    public function comando_recusa_bases_que_nao_terminam_em_e2e_sem_tocar_em_nada(): void
    {
        $empresa = $this->criarEmpresa();
        $this->artisan('erp:e2e:preparar', ['--force' => true])
            ->expectsOutputToContain('RECUSADO')
            ->assertFailed();
        $this->assertTrue(Empresa::query()->whereKey($empresa->id)->exists());
    }

    #[Test]
    public function seeder_recusa_correr_fora_de_uma_base_e2e(): void
    {
        $this->expectException(RuntimeException::class);
        (new E2ESeeder)->run();
    }
}
