<?php

namespace Tests\Unit\Angola;

use App\Rules\NomeUtilizador;
use App\Support\Angola\NifAngolano;
use App\Support\Angola\ProvinciasAngola;
use App\Support\Angola\TelefoneAngolano;
use App\Support\Seguranca\PoliticaPalavraPasse;
use App\Support\Texto\TextoEntrada;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Validadores reutilizáveis da entrada no sistema (sem base de dados): NIF, telefone, província, utilizador, palavra-passe. */
final class ValidadoresAngolanosTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function nifsValidos(): array
    {
        return [
            'empresa 10 dígitos' => ['5417012345'],
            'empresa com espaços' => [' 5417 012 345 '],
            'singular formato BI' => ['000755848LA037'],
            'singular BI em minúsculas' => ['000755848la037'],
            'singular BI com hífenes' => ['000755848-LA-037'],
            '9 dígitos (antigo)' => ['541701234'],
            'zeros à esquerda' => ['0017012345'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function nifsInvalidos(): array
    {
        return [
            'vazio' => [''],
            'só espaços' => ['   '],
            'curto' => ['12345678'],
            '11 dígitos' => ['54170123456'],
            '14 dígitos (formato antigo do sistema)' => ['54170123456789'],
            'letras no meio' => ['54A7012345'],
            'BI com 1 letra' => ['000755848L037'],
            'BI com 3 letras' => ['000755848LAB037'],
            'BI com dígitos a menos' => ['000755848LA03'],
            'todos zeros' => ['0000000000'],
            'todos noves (10)' => ['9999999999'],
            'consumidor final' => ['999999999'],
            'texto livre' => ['sem nif'],
            'injecção' => ["5417012345'; DROP TABLE empresas;--"],
        ];
    }

    #[Test]
    #[DataProvider('nifsValidos')]
    public function aceita_nifs_validos(string $nif): void
    {
        $this->assertTrue(NifAngolano::valido($nif), $nif);
    }

    #[Test]
    #[DataProvider('nifsInvalidos')]
    public function recusa_nifs_invalidos(string $nif): void
    {
        $this->assertFalse(NifAngolano::valido($nif), $nif);
    }

    #[Test]
    public function consumidor_final_so_quando_permitido_e_o_tipo_e_reconhecido(): void
    {
        $this->assertFalse(NifAngolano::valido('999999999'));
        $this->assertTrue(NifAngolano::valido('999999999', true));
        $this->assertSame('colectiva', NifAngolano::tipo('5417012345'));
        $this->assertSame('singular', NifAngolano::tipo('000755848LA037'));
        $this->assertSame('nove_digitos', NifAngolano::tipo('541701234'));
        $this->assertNull(NifAngolano::tipo('abc'));
        $this->assertSame('000755848LA037', NifAngolano::normalizar(' 000 755848-la 037 '));
    }

    #[Test]
    public function regra_aceita_um_nif_antigo_inalterado_mas_valida_um_novo_ou_alterado(): void
    {
        $falhou = function (\App\Rules\NifAngolano $regra, string $valor): bool {
            $erro = false;
            $regra->validate('nif', $valor, function () use (&$erro) {
                $erro = true;
            });

            return $erro;
        };
        $this->assertFalse($falhou(new \App\Rules\NifAngolano(inalterado: '123456789012'), '123 456 789 012'), 'registo antigo repetido');
        $this->assertTrue($falhou(new \App\Rules\NifAngolano(inalterado: '123456789012'), '123456789013'), 'alterado e inválido');
        $this->assertTrue($falhou(new \App\Rules\NifAngolano, '123456789012'), 'novo e inválido');
        $this->assertFalse($falhou(new \App\Rules\NifAngolano, '5417012345'));
        $this->assertFalse($falhou(new \App\Rules\NifAngolano, ''), 'vazio é com o required');
    }

    /** @return array<string, array{string, string}> */
    public static function telefonesValidos(): array
    {
        return [
            'móvel' => ['923000111', '923000111'],
            'com espaços' => ['923 000 111', '923000111'],
            'com +244' => ['+244 923 000 111', '923000111'],
            'com 00244' => ['00244923000111', '923000111'],
            'com 244 sem sinal' => ['244923000111', '923000111'],
            'com hífenes e parênteses' => ['(244) 923-000-111', '923000111'],
            'fixo de Luanda' => ['222 123 456', '222123456'],
            'internacional' => ['+351 912 345 678', '+351912345678'],
            'internacional 00' => ['00351912345678', '+351912345678'],
        ];
    }

    #[Test]
    #[DataProvider('telefonesValidos')]
    public function aceita_e_normaliza_telefones(string $entrada, string $esperado): void
    {
        $this->assertTrue(TelefoneAngolano::valido($entrada), $entrada);
        $this->assertSame($esperado, TelefoneAngolano::normalizar($entrada));
    }

    /** @return array<string, array{string}> */
    public static function telefonesInvalidos(): array
    {
        return [
            'vazio' => [''], 'curto' => ['92300011'], 'longo' => ['9230001112'], 'começa por 1' => ['123456789'],
            'letras' => ['92300011a'], 'só +244' => ['+244'], '+244 com 8 dígitos' => ['+24492300011'], 'só +' => ['+'],
            'injecção' => ["923000111'; --"],
        ];
    }

    #[Test]
    #[DataProvider('telefonesInvalidos')]
    public function recusa_telefones_invalidos(string $telefone): void
    {
        $this->assertFalse(TelefoneAngolano::valido($telefone), $telefone);
    }

    #[Test]
    public function reconhece_as_provincias_sem_acentos_nem_maiusculas(): void
    {
        $this->assertCount(21, ProvinciasAngola::LISTA);
        $this->assertSame('Luanda', ProvinciasAngola::canonica('  LUANDA '));
        $this->assertSame('Uíge', ProvinciasAngola::canonica('uige'));
        $this->assertSame('Huíla', ProvinciasAngola::canonica('Huila'));
        $this->assertSame('Cuanza Sul', ProvinciasAngola::canonica('Kwanza Sul'));
        $this->assertSame('Icolo e Bengo', ProvinciasAngola::canonica('icolo e bengo'));
        $this->assertNull(ProvinciasAngola::canonica('Lisboa'));
        $this->assertNull(ProvinciasAngola::canonica(''));
        $this->assertNull(ProvinciasAngola::canonica("Luanda'; --"));
    }

    /** @return array<string, array{string, bool}> */
    public static function nomesUtilizador(): array
    {
        return [
            'simples' => ['jbaptista', true], 'com ponto' => ['joao.silva', true], 'com hífen e _' => ['ana_maria-2', true],
            'maiúsculas aceites (grava-se em minúsculas)' => ['Joao.Silva', true], 'mínimo 3' => ['abc', true], 'máximo 50' => [str_repeat('a', 50), true],
            'curto' => ['ab', false], 'longo' => [str_repeat('a', 51), false], 'espaço' => ['joao silva', false], 'com @' => ['joao@empresa.ao', false],
            'acento' => ['joão', false], 'ponto no início' => ['.joao', false], 'ponto no fim' => ['joao.', false], 'sinais repetidos' => ['joao..silva', false],
            'emoji' => ["joao\u{1F600}", false], 'reservado admin' => ['admin', false], 'reservado root' => ['ROOT', false], 'reservado suporte' => ['suporte', false],
            'injecção' => ["x' or '1'='1", false], 'script' => ['<script>', false],
        ];
    }

    #[Test]
    #[DataProvider('nomesUtilizador')]
    public function regra_do_nome_de_utilizador(string $nome, bool $valido): void
    {
        $erro = null;
        (new NomeUtilizador)->validate('nome_utilizador', $nome, function (string $m) use (&$erro) {
            $erro = $m;
        });
        $this->assertSame($valido, $erro === null, $nome.' => '.($erro ?? 'ok'));
    }

    /** @return array<string, array{string, bool}> */
    public static function palavrasPasse(): array
    {
        return [
            'forte' => ['Barcaça#2026x', true], 'forte simples' => ['NovaSenha2026', true], 'só minúsculas' => ['abcdefghij1', false],
            'sem algarismo' => ['Abcdefghij', false], 'sem maiúscula' => ['abcdefgh12', false], 'curta' => ['Ab1', false],
            'trivial password' => ['Password123', false], 'trivial qwerty' => ['Qwerty2026', false], 'trivial consulvolt' => ['Consulvolt1', false],
            'só espaços longa' => [str_repeat(' ', 12), false], 'demasiado longa' => [str_repeat('Aa1', 50), false],
            'com espaços e acentos' => ['Óptima Frase 2026', true],
        ];
    }

    #[Test]
    #[DataProvider('palavrasPasse')]
    public function politica_de_palavra_passe(string $p, bool $aceite): void
    {
        $this->assertSame($aceite, PoliticaPalavraPasse::problema($p, [], 10) === null, $p);
    }

    #[Test]
    public function a_palavra_passe_nao_pode_conter_o_utilizador_nem_o_email(): void
    {
        $this->assertNotNull(PoliticaPalavraPasse::problema('Joao.silva2026X', ['joao.silva', 'joao.silva']));
        $this->assertNotNull(PoliticaPalavraPasse::problema('MariaSantos#9', [null, 'maria.santos']));
        $this->assertNull(PoliticaPalavraPasse::problema('Barcaça#2026x', ['joao.silva', 'joao.silva']));
        $this->assertNull(PoliticaPalavraPasse::problema('Abcd#9Xyz', ['ab'], 8), 'partes curtas do utilizador não contam');
    }

    #[Test]
    public function limpeza_de_texto_de_entrada(): void
    {
        $this->assertSame("O'Brien", TextoEntrada::limpar("  O'Brien  "));
        $this->assertSame('João & Filhos, Lda', TextoEntrada::limpar("João  &\u{00A0}Filhos,   Lda"));
        $this->assertSame('ab', TextoEntrada::limpar("a\u{200B}b\x00"));
        $this->assertNull(TextoEntrada::limpar('   '));
        $this->assertNull(TextoEntrada::limpar(['x']));
    }
}
