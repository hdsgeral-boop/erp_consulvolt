<?php

namespace Tests\Feature;

use App\Models\Banco;
use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase A (Versão Actual) — dados bancários das contas 42/43 do plano (legado novo app_v2.js:9050-9163).
 * Só valores fictícios: o IBAN é gerado aqui com os dígitos de controlo correctos (ISO 13616).
 */
final class FaTesourariaContasBancariasTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Ficticia, Lda']);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['43' => ['Depósitos à ordem', 'T'], '4311' => ['Banco Ficticio', 'M'], '4211' => ['Depósito a prazo', 'M'], '4511' => ['Caixa', 'M'], '611' => ['Vendas', 'M']] as $c => [$d, $t]) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => $t]);
            }
        });
        $this->s = $this->sessao(['config_plano_view', 'contab_plano_gerir']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** IBAN angolano fictício com os dígitos de controlo calculados (mod 97). */
    private function ibanFicticio(string $bban = '000000000000000000001'): string
    {
        $numerico = '';
        foreach (str_split($bban.'AO00') as $ch) {
            $numerico .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        return 'AO'.str_pad((string) (98 - (int) bcmod($numerico, '97')), 2, '0', STR_PAD_LEFT).$bban;
    }

    private function conta(string $codigo): PlanoConta
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::query()->where('codigo', $codigo)->firstOrFail());
    }

    private function dadosBancarios(array $extra = []): array
    {
        return array_merge(['banco_nome' => 'Banco Ficticio de Teste', 'banco_balcao' => 'Agência Ficticia', 'banco_numero_conta' => '000000001.10.001',
            'banco_iban' => chunk_split($this->ibanFicticio(), 4, ' '), 'banco_swift' => 'tstfaolu', 'banco_titular' => ''], $extra);
    }

    #[Test]
    public function grava_os_dados_bancarios_numa_conta_43_com_iban_normalizado_e_le_os_para_as_cartas(): void
    {
        $id = $this->conta('4311')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['descricao' => 'Banco Ficticio'] + $this->dadosBancarios(), $this->s)->assertOk()
            ->assertJsonPath('dados.banco_iban', $this->ibanFicticio())->assertJsonPath('dados.banco_swift', 'TSTFAOLU')->assertJsonPath('dados.banco_titular', null);

        $this->getJson('/api/tesouraria/contas-bancarias/4311', $this->s)->assertOk()
            ->assertJsonPath('dados.banco_nome', 'Banco Ficticio de Teste')->assertJsonPath('dados.banco_iban', $this->ibanFicticio())
            ->assertJsonPath('dados.titular_efectivo', 'Empresa Ficticia, Lda')->assertJsonPath('dados.completa', true);

        // conta 42 (depósitos a prazo) criada já com dados bancários; titular explícito
        $this->postJson('/api/contabilidade/plano-contas', ['codigo' => '4212', 'descricao' => 'Prazo 2', 'tipo' => 'M']
            + $this->dadosBancarios(['banco_titular' => 'Titular Ficticio', 'banco_iban' => $this->ibanFicticio('000000000000000000002')]), $this->s)->assertCreated();
        $lista = $this->getJson('/api/tesouraria/contas-bancarias', $this->s)->assertOk()->json('dados');
        // só contas 42/43 de movimento (a totalizadora 43, a caixa 4511 e a 611 ficam de fora)
        $this->assertSame(['4211', '4212', '4311'], array_column($lista, 'codigo'));
        $this->assertSame([false, true, true], array_column($lista, 'completa'));
        $this->assertSame('Titular Ficticio', $lista[1]['titular_efectivo']);
    }

    #[Test]
    public function recusa_iban_e_swift_invalidos(): void
    {
        $id = $this->conta('4311')->id;
        $iban = $this->ibanFicticio();
        $errado = substr($iban, 0, -1).(((int) substr($iban, -1) + 1) % 10);   // último dígito trocado: o mod 97 falha
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['banco_iban' => $errado], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IBAN_INVALIDO');
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['banco_iban' => 'AO06123'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IBAN_INVALIDO');
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['banco_swift' => 'ABC'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SWIFT_INVALIDO');
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['banco_titular' => str_repeat('x', 151)], $this->s)->assertStatus(422);
        $this->assertNull($this->conta('4311')->banco_iban);
    }

    #[Test]
    public function os_dados_bancarios_so_ficam_nas_contas_42_43_de_movimento_e_limpam_se_quando_deixa_de_ser_bancaria(): void
    {
        // caixa (45) e outras classes: os campos bancários enviados são ignorados (ficam vazios)
        $this->putJson('/api/contabilidade/plano-contas/'.$this->conta('4511')->id, $this->dadosBancarios(), $this->s)->assertOk()
            ->assertJsonPath('dados.banco_nome', null)->assertJsonPath('dados.banco_iban', null);
        $this->getJson('/api/tesouraria/contas-bancarias/4511', $this->s)->assertStatus(404);   // fora do padrão 42/43 da rota
        $this->getJson('/api/tesouraria/contas-bancarias/43', $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_NAO_BANCARIA');   // totalizadora

        // conta 43 com dados → muda de código para fora de 42/43 (sem movimentos): limpa
        $id = $this->conta('4311')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", $this->dadosBancarios(), $this->s)->assertOk();
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['codigo' => '4611'], $this->s)->assertOk()->assertJsonPath('dados.banco_iban', null)
            ->assertJsonPath('dados.banco_nome', null);

        // conta 42 com dados → passa a totalizadora: limpa
        $id = $this->conta('4211')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", $this->dadosBancarios(), $this->s)->assertOk()->assertJsonPath('dados.banco_nome', 'Banco Ficticio de Teste');
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['tipo' => 'T'], $this->s)->assertOk()->assertJsonPath('dados.banco_nome', null);

        // uma actualização sem campos bancários (ex.: importação do plano) não apaga os dados de uma conta bancária
        $id = $this->conta('4611')->id;
        $this->putJson("/api/contabilidade/plano-contas/{$id}", ['codigo' => '4321'] + $this->dadosBancarios(), $this->s)->assertOk();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => app(ServicoPlanoContas::class)->atualizar(PlanoConta::query()->findOrFail($id), ['descricao' => 'Banco renomeado', 'tipo' => 'M']));
        $this->assertSame($this->ibanFicticio(), $this->conta('4321')->banco_iban);
    }

    #[Test]
    public function autorizacao_e_isolamento_por_empresa(): void
    {
        // sem permissão de plano nem de tesouraria
        $this->getJson('/api/tesouraria/contas-bancarias', $this->sessao(['lancamentos_view']))->assertForbidden();
        $this->getJson('/api/tesouraria/contas-bancarias/4311', $this->sessao(['lancamentos_view']))->assertForbidden();
        // a tesouraria lê (cartas de pagamento), mas não grava no plano
        $teso = $this->sessao(['teso_gestao_pagamentos_view']);
        $this->getJson('/api/tesouraria/contas-bancarias/4311', $teso)->assertOk();
        $this->putJson('/api/contabilidade/plano-contas/'.$this->conta('4311')->id, $this->dadosBancarios(), $teso)->assertForbidden();
        $this->getJson('/api/tesouraria/contas-bancarias/sugestoes-bancos', $teso)->assertForbidden();

        // outra empresa: a conta 4399 não é visível na empresa activa
        $outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($outra->id, fn () => PlanoConta::create(['codigo' => '4399', 'descricao' => 'Banco de outra empresa', 'tipo' => 'M',
            'banco_iban' => $this->ibanFicticio('000000000000000000009')]));
        $this->getJson('/api/tesouraria/contas-bancarias/4399', $this->s)->assertStatus(404)->assertJsonPath('codigo', 'CONTA_INEXISTENTE');
        $this->assertNotContains('4399', array_column($this->getJson('/api/tesouraria/contas-bancarias', $this->s)->json('dados'), 'codigo'));
        $this->getJson('/api/tesouraria/contas-bancarias/4399', $this->sessao(['config_plano_view'], $outra))->assertOk()->assertJsonPath('dados.completa', true);
    }

    #[Test]
    public function sugere_os_nomes_dos_bancos_registados_na_empresa(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            Banco::create(['nome' => 'Banco Ficticio B']);
            Banco::create(['nome' => 'Banco Ficticio A']);
        });
        $outra = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($outra->id, fn () => Banco::create(['nome' => 'Banco de outra empresa']));
        $this->getJson('/api/tesouraria/contas-bancarias/sugestoes-bancos', $this->s)->assertOk()->assertJsonPath('dados', ['Banco Ficticio A', 'Banco Ficticio B']);
    }
}
