<?php

namespace Tests\Feature;

use App\Models\LogAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regressão: o Laravel grava datas sem offset; a sessão PostgreSQL tem de estar no fuso da aplicação
 * (Africa/Luanda), senão todas as colunas TIMESTAMPTZ ficam desfasadas 1 hora.
 */
final class FusoHorarioTest extends TestCase
{
    #[Test]
    public function sessao_da_base_de_dados_usa_o_fuso_da_aplicacao(): void
    {
        $this->assertSame(config('app.timezone'), DB::selectOne('SHOW timezone')->TimeZone);
    }

    #[Test]
    public function um_instante_gravado_e_lido_representa_o_mesmo_momento(): void
    {
        $instante = Carbon::parse('2026-09-29 10:00:00', 'Africa/Luanda');   // = 09:00 UTC

        $log = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::create(['modulo' => 'Teste', 'acao' => 'X', 'ocorrido_em' => $instante]));

        $utc = DB::selectOne("SELECT to_char(ocorrido_em AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI') AS utc FROM logs_auditoria WHERE id = ?", [$log->id])->utc;
        $this->assertSame('2026-09-29 09:00', $utc);
        $this->assertTrue($log->fresh()->ocorrido_em->equalTo($instante));
    }
}
