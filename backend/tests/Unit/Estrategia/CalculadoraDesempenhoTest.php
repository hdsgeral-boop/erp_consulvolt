<?php

namespace Tests\Unit\Estrategia;

use App\Services\Estrategia\CalculadoraDesempenho as C;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Números calculados à mão para EST-C-01…05, EST-C-22 e o caso que a vírgula flutuante do legado falhava (8,1 ÷ 9 × 100). */
final class CalculadoraDesempenhoTest extends TestCase
{
    #[Test]
    public function meta_atingida_exactamente_a_noventa_por_cento_e_amarela_nunca_vermelha(): void
    {
        $this->assertSame(89.99999999999999, 8.1 / 9 * 100, 'o legado (vírgula flutuante) chegava a este valor');
        $this->assertSame('90.0000', C::cumprimento('SOBE', '8.1', '9'));
        $this->assertSame('AMARELO', C::semaforo('SOBE', '8.1', '9'));
        $this->assertSame('AMARELO', C::semaforo('SOBE', '8,1', '9'));
        $this->assertSame('VERMELHO', C::semaforo('SOBE', '8.09', '9'));
        $this->assertSame('VERDE', C::semaforo('SOBE', '9', '9'));
        $this->assertSame('VERDE', C::semaforo('SOBE', '10', '9'));
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function casos(): array
    {
        return [
            // polaridade, valor, meta, cumprimento esperado (%), cor
            'SOBE meta 0 e valor positivo' => ['SOBE', '5', '0', '100.0000', 'VERDE'],
            'SOBE meta 0 e valor negativo' => ['SOBE', '-1', '0', '0.0000', 'VERMELHO'],
            'SOBE 3 de 4' => ['SOBE', '3', '4', '75.0000', 'VERMELHO'],
            'SOBE 2 de 3' => ['SOBE', '2', '3', '66.6667', 'VERMELHO'],
            'SOBE meta negativa -10 com valor -9' => ['SOBE', '-9', '-10', '90.0000', 'AMARELO'],
            'DESCE no limite' => ['DESCE', '10', '10', '100.0000', 'VERDE'],
            'DESCE 11 para meta 10' => ['DESCE', '11', '10', '90.9091', 'AMARELO'],
            'DESCE 12 para meta 10' => ['DESCE', '12', '10', '83.3333', 'VERMELHO'],
            'DESCE valor zero' => ['DESCE', '0', '10', '100.0000', 'VERDE'],
            'DESCE valor negativo' => ['DESCE', '-5', '10', '100.0000', 'VERDE'],
            'DESCE meta 0 e valor 3' => ['DESCE', '3', '0', '0.0000', 'VERMELHO'],
            'ALVO exacto' => ['ALVO', '100', '100', '100.0000', 'VERDE'],
            'ALVO 10 % abaixo' => ['ALVO', '90', '100', '90.0000', 'AMARELO'],
            'ALVO 10 % acima' => ['ALVO', '110', '100', '90.0000', 'AMARELO'],
            'ALVO ligeiramente fora do amarelo' => ['ALVO', '89.99', '100', '89.9900', 'VERMELHO'],
            'ALVO muito longe' => ['ALVO', '250', '100', '0.0000', 'VERMELHO'],
            'ALVO meta 0 e valor 0' => ['ALVO', '0', '0', '100.0000', 'VERDE'],
            'ALVO meta 0 e valor 1' => ['ALVO', '1', '0', '0.0000', 'VERMELHO'],
            'ALVO meta negativa' => ['ALVO', '-90', '-100', '90.0000', 'AMARELO'],
        ];
    }

    #[Test]
    #[DataProvider('casos')]
    public function cumprimento_e_semaforo_por_polaridade(string $pol, string $valor, string $meta, string $pct, string $cor): void
    {
        $this->assertSame($pct, C::cumprimento($pol, $valor, $meta));
        $this->assertSame($cor, C::semaforo($pol, $valor, $meta));
    }

    #[Test]
    public function sem_meta_e_sem_medida(): void
    {
        $this->assertSame('SEM_META', C::semaforo('SOBE', '5', null));
        $this->assertSame('SEM_META', C::semaforo('SOBE', null, ''));
        $this->assertSame('SEM_MEDIDA', C::semaforo('SOBE', null, '10'));
        $this->assertSame('SEM_MEDIDA', C::semaforo('SOBE', '', '10'));
        $this->assertSame('SEM_MEDIDA', C::semaforo('SOBE', 'abc', '10'));
        $this->assertSame('VERDE', C::semaforo('SOBE', '0', '0'));
    }

    #[Test]
    public function tolerancias_proprias(): void
    {
        $this->assertSame('VERDE', C::semaforo('SOBE', '8.55', '9', '95', '80'), '8,55 / 9 = 95 %');
        $this->assertSame('AMARELO', C::semaforo('SOBE', '8.54', '9', '95', '80'));
        $this->assertSame('AMARELO', C::semaforo('SOBE', '7.2', '9', '95', '80'), '7,2 / 9 = 80 %');
        $this->assertSame('VERMELHO', C::semaforo('SOBE', '7.19', '9', '95', '80'));
        $this->assertSame('VERDE', C::semaforo('SOBE', '90', '100', '90', '50'));
    }

    #[Test]
    public function cor_do_objectivo_e_a_do_pior_indicador(): void
    {
        $this->assertSame('SEM_META', C::piorCor([]));
        $this->assertSame('VERMELHO', C::piorCor(['VERDE', 'VERMELHO', 'AMARELO']));
        $this->assertSame('AMARELO', C::piorCor(['VERDE', 'SEM_MEDIDA', 'AMARELO']));
        $this->assertSame('SEM_MEDIDA', C::piorCor(['VERDE', 'SEM_META', 'SEM_MEDIDA']));
        $this->assertSame('SEM_META', C::piorCor(['VERDE', 'SEM_META']));
        $this->assertSame('VERDE', C::piorCor(['VERDE', 'VERDE']));
    }

    #[Test]
    public function resumo_conta_por_cor_e_percentagem_de_cumpridos(): void
    {
        $r = C::resumo(['VERDE', 'VERDE', 'AMARELO', 'VERMELHO', 'SEM_MEDIDA', 'SEM_META']);
        $this->assertSame(6, $r['total']);
        $this->assertSame([2, 1, 1, 1, 1], [$r['VERDE'], $r['AMARELO'], $r['VERMELHO'], $r['SEM_MEDIDA'], $r['SEM_META']]);
        $this->assertSame('50.0', $r['cumpridos_pct'], '2 verdes entre 4 medidos');
        $this->assertNull(C::resumo(['SEM_MEDIDA'])['cumpridos_pct']);
        $this->assertSame('33.3', C::resumo(['VERDE', 'AMARELO', 'VERMELHO'])['cumpridos_pct']);
    }

    #[Test]
    public function escada_absoluta_e_percentual(): void
    {
        $this->assertSame(['100.00', '110.00', '120.00', '130.00'], C::escada('100', '10', 4, false));
        $this->assertSame(['100.00', '105.00', '110.25', '115.76'], C::escada('100', '5', 4, true), '115,7625 mostra-se 115,76');
        $this->assertSame(['10.00', '10.00'], C::escada('10', '0', 2, false));
        $this->assertSame(['1000.00', '900.00', '800.00'], C::escada('1000', '-100', 3, false));
    }

    #[Test]
    public function arredondamento_meio_para_longe_do_zero_e_numeros(): void
    {
        $this->assertSame('2.35', C::arredondar('2.345', 2));
        $this->assertSame('-2.35', C::arredondar('-2.345', 2));
        $this->assertSame('0.00', C::arredondar('-0.001', 2));
        $this->assertSame('-3', C::arredondar('-2.5', 0));
        $this->assertSame('8.1', C::numero('8,1'));
        $this->assertSame('0.5', C::numero('.5'));
        $this->assertSame('-0.5', C::numero('-.5'));
        $this->assertNull(C::numero('1e3'));
        $this->assertNull(C::numero(''));
        $this->assertNull(C::numero(true));
        $this->assertSame('0', C::numero(0));
    }
}
