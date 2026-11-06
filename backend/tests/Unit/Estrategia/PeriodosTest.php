<?php

namespace Tests\Unit\Estrategia;

use App\Services\Estrategia\Periodos;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** EST-C-06 (equivalente), EST-C-07 (anterior), EST-C-08 (intervalo) com datas conhecidas. */
final class PeriodosTest extends TestCase
{
    #[Test]
    public function chaves_validas_e_frequencia(): void
    {
        foreach (['2027', '2027-03', '2027-T1', '2027-S2', '1999-12'] as $k) {
            $this->assertTrue(Periodos::valido($k), $k);
        }
        foreach (['27', '2027-13', '2027-00', '2027-T5', '2027-S3', '2027-1', 'x', '2027-03-01', ''] as $k) {
            $this->assertFalse(Periodos::valido($k), $k);
        }
        $this->assertSame(['MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL', null], [
            Periodos::frequenciaDe('2027-03'), Periodos::frequenciaDe('2027-T1'), Periodos::frequenciaDe('2027-S1'), Periodos::frequenciaDe('2027'), Periodos::frequenciaDe('abc'),
        ]);
        $this->assertCount(12, Periodos::periodosDe('MENSAL', 2027));
        $this->assertSame(['2027-T1', '2027-T2', '2027-T3', '2027-T4'], Periodos::periodosDe('TRIMESTRAL', 2027));
        $this->assertSame(['2027-S1', '2027-S2'], Periodos::periodosDe('SEMESTRAL', 2027));
        $this->assertSame(['2027'], Periodos::periodosDe('ANUAL', 2027));
    }

    #[Test]
    public function equivalente_para_cada_frequencia(): void
    {
        $this->assertSame('2027-T2', Periodos::equivalente('TRIMESTRAL', '2027-05'));
        $this->assertSame('2027-T1', Periodos::equivalente('TRIMESTRAL', '2027-03'));
        $this->assertSame('2027-S1', Periodos::equivalente('SEMESTRAL', '2027-06'));
        $this->assertSame('2027-S2', Periodos::equivalente('SEMESTRAL', '2027-07'));
        $this->assertSame('2027-04', Periodos::equivalente('MENSAL', '2027-T2'));
        $this->assertSame('2027', Periodos::equivalente('ANUAL', '2027-S2'));
        $this->assertNull(Periodos::equivalente('MENSAL', 'lixo'));
        $this->assertSame('2026-T4', Periodos::actual('TRIMESTRAL', new \DateTimeImmutable('2026-11-06')));
    }

    #[Test]
    public function anterior_atravessa_o_ano(): void
    {
        $this->assertSame('2026-12', Periodos::anterior('2027-01'));
        $this->assertSame('2027-02', Periodos::anterior('2027-03'));
        $this->assertSame('2026-T4', Periodos::anterior('2027-T1'));
        $this->assertSame('2027-S1', Periodos::anterior('2027-S2'));
        $this->assertSame('2026-S2', Periodos::anterior('2027-S1'));
        $this->assertSame('2026', Periodos::anterior('2027'));
        $this->assertNull(Periodos::anterior('x'));
    }

    #[Test]
    public function intervalos_com_o_ultimo_dia_real_do_mes(): void
    {
        $this->assertSame(['2028-02-01', '2028-02-29'], Periodos::intervalo('2028-02'));
        $this->assertSame(['2027-02-01', '2027-02-28'], Periodos::intervalo('2027-02'));
        $this->assertSame(['2027-04-01', '2027-04-30'], Periodos::intervalo('2027-04'));
        $this->assertSame(['2027-10-01', '2027-12-31'], Periodos::intervalo('2027-T4'));
        $this->assertSame(['2027-01-01', '2027-06-30'], Periodos::intervalo('2027-S1'));
        $this->assertSame(['2027-07-01', '2027-12-31'], Periodos::intervalo('2027-S2'));
        $this->assertSame(['2027-01-01', '2027-12-31'], Periodos::intervalo('2027'));
    }

    #[Test]
    public function rotulos_e_ciclo(): void
    {
        $this->assertSame('Março de 2027', Periodos::rotulo('2027-03'));
        $this->assertSame('2.º trimestre de 2027', Periodos::rotulo('2027-T2'));
        $this->assertSame('1.º semestre de 2027', Periodos::rotulo('2027-S1'));
        $this->assertSame('2027', Periodos::rotulo('2027'));
        $this->assertTrue(Periodos::noCiclo('2029-T3', 2027, 2031));
        $this->assertFalse(Periodos::noCiclo('2032', 2027, 2031));
        $this->assertFalse(Periodos::noCiclo('2026-12', 2027, 2031));
        $this->assertFalse(Periodos::noCiclo('lixo', 2027, 2031));
    }
}
