<?php

namespace Tests\Unit;

use App\Support\Ids\CodificadorIds;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** ADR-072 — identificadores opacos: ida e volta, separação por tipo, resistência a adulteração. */
final class CodificadorIdsTest extends TestCase
{
    private function cod(string $segredo = 'segredo-de-teste'): CodificadorIds
    {
        return new CodificadorIds($segredo);
    }

    #[Test]
    public function ida_e_volta_para_muitos_ids(): void
    {
        $c = $this->cod();
        foreach ([1, 2, 72, 73, 999, 123456, 4294967295, CodificadorIds::ID_MAXIMO] as $id) {
            $ref = $c->codificar('colaborador', $id);
            $this->assertSame(CodificadorIds::COMPRIMENTO, strlen($ref));
            $this->assertMatchesRegularExpression(CodificadorIds::PADRAO, $ref);
            $this->assertSame($id, $c->descodificar('colaborador', $ref));
        }
    }

    #[Test]
    public function e_deterministico_e_nao_sequencial(): void
    {
        $c = $this->cod();
        $this->assertSame($c->codificar('venda', 72), $c->codificar('venda', 72));
        $a = $c->codificar('venda', 72);
        $b = $c->codificar('venda', 73);
        $iguais = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $iguais += $a[$i] === $b[$i] ? 1 : 0;
        }
        $this->assertLessThan(8, $iguais, 'ids vizinhos não podem dar referências parecidas');
    }

    #[Test]
    public function tipos_diferentes_dao_referencias_diferentes_e_nao_se_trocam(): void
    {
        $c = $this->cod();
        $col = $c->codificar('colaborador', 72);
        $ven = $c->codificar('venda', 72);
        $this->assertNotSame($col, $ven);
        $this->assertNull($c->descodificar('venda', $col));
        $this->assertNull($c->descodificar('colaborador', $ven));
    }

    #[Test]
    public function chave_diferente_nao_descodifica(): void
    {
        $ref = $this->cod('chave-a')->codificar('colaborador', 72);
        $this->assertNotSame($ref, $this->cod('chave-b')->codificar('colaborador', 72));
        $this->assertNull($this->cod('chave-b')->descodificar('colaborador', $ref));
    }

    #[Test]
    public function referencias_invalidas_dao_null_nunca_excepcao(): void
    {
        $c = $this->cod();
        $ref = $c->codificar('colaborador', 72);
        $adulterada = $ref;
        $adulterada[3] = $adulterada[3] === 'a' ? 'b' : 'a';
        foreach (['', '72', '0', '-1', 'abc', str_repeat('a', 15), str_repeat('z', 15), strtoupper($ref), $ref.'a', substr($ref, 1), $adulterada, "../../etc/passwd", str_repeat('é', 15), "{$ref}\0"] as $mau) {
            $this->assertNull($c->descodificar('colaborador', $mau), "devia recusar «{$mau}»");
        }
    }

    #[Test]
    public function alterar_uma_letra_quase_nunca_acerta_noutro_registo(): void
    {
        $c = $this->cod();
        $ref = $c->codificar('colaborador', 72);
        $acertos = 0;
        $tentativas = 0;
        for ($pos = 0; $pos < strlen($ref); $pos++) {
            foreach (str_split(CodificadorIds::ALFABETO) as $letra) {
                if ($letra === $ref[$pos]) {
                    continue;
                }
                $t = $ref;
                $t[$pos] = $letra;
                $tentativas++;
                $acertos += $c->descodificar('colaborador', $t) !== null ? 1 : 0;
            }
        }
        $this->assertSame(330, $tentativas);
        $this->assertLessThanOrEqual(1, $acertos, 'a etiqueta de 26 bits tem de travar a adulteração');
    }

    #[Test]
    public function recusa_ids_fora_do_intervalo_e_chave_vazia(): void
    {
        $c = $this->cod();
        foreach ([0, -5, CodificadorIds::ID_MAXIMO + 1] as $id) {
            try {
                $c->codificar('x', $id);
                $this->fail("devia recusar {$id}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        new CodificadorIds('');
    }

    #[Test]
    public function referencia_nunca_se_confunde_com_um_numero(): void
    {
        $c = $this->cod();
        for ($id = 1; $id <= 300; $id++) {
            $this->assertDoesNotMatchRegularExpression('/\d/', $c->codificar('produto', $id));
        }
        $this->assertTrue(CodificadorIds::pareceReferencia($c->codificar('produto', 5)));
        $this->assertFalse(CodificadorIds::pareceReferencia('72'));
    }
}
