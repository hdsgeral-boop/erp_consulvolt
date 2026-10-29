<?php

namespace Tests\Feature\Tenancy;

use App\Models\ConfigAssiduidade;
use App\Services\Plataforma\ServicoSlaSuporte;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Fase 6 da extensão SaaS (segurança.txt, secção 14, pontos 263-264): o prazo de SLA desliza para o próximo dia
 * útil quando cai num fim-de-semana ou feriado, reaproveitando o calendário do RH (ServicoCalendarioRH) em vez de
 * inventar um horário de expediente que não existe em lado nenhum do sistema.
 */
final class ServicoSlaSuporteTest extends TestCase
{
    public function test_prazo_num_dia_util_nao_desliza(): void
    {
        // Quinta-feira 2026-10-29, 08:00 + 4h (URGENTE) = 12:00 do mesmo dia útil.
        $prazo = app(ServicoSlaSuporte::class)->calcularPrazo('URGENTE', Carbon::parse('2026-10-29 08:00:00'));

        $this->assertSame('2026-10-29 12:00:00', $prazo->toDateTimeString());
    }

    public function test_prazo_que_cai_num_fim_de_semana_desliza_para_segunda_sem_configuracao_nenhuma(): void
    {
        // Sem ConfigAssiduidade criada (nem contexto de empresa activo): usa o calendário por omissão (seg-sex).
        // Sexta 2026-10-30, 10:00 + 24h (ALTA) = Sábado 2026-10-31, 10:00 → desliza para Segunda 2026-11-02, 10:00.
        $prazo = app(ServicoSlaSuporte::class)->calcularPrazo('ALTA', Carbon::parse('2026-10-30 10:00:00'));

        $this->assertSame('2026-11-02 10:00:00', $prazo->toDateTimeString());
    }

    public function test_prazo_que_cai_num_feriado_configurado_desliza_para_o_dia_util_seguinte(): void
    {
        $empresa = $this->criarEmpresa();

        $prazo = app(ContextoEmpresa::class)->executarComo((int) $empresa->id, function () {
            ConfigAssiduidade::query()->create(['feriados' => ['2026-11-04']]);

            // Terça 2026-11-03, 10:00 + 24h (ALTA) = Quarta 2026-11-04 (feriado) → desliza para Quinta 2026-11-05.
            return app(ServicoSlaSuporte::class)->calcularPrazo('ALTA', Carbon::parse('2026-11-03 10:00:00'));
        });

        $this->assertSame('2026-11-05 10:00:00', $prazo->toDateTimeString());
    }

    public function test_prioridade_desconhecida_usa_o_sla_normal(): void
    {
        $prazo = app(ServicoSlaSuporte::class)->calcularPrazo('INEXISTENTE', Carbon::parse('2026-10-29 08:00:00'));

        // NORMAL = 72h; 2026-10-29 08:00 + 72h = 2026-11-01 (domingo) 08:00 → desliza para 2026-11-02 (segunda).
        $this->assertSame('2026-11-02 08:00:00', $prazo->toDateTimeString());
    }
}
