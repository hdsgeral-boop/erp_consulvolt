<?php

namespace Tests\Feature;

use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Do contrato ao banco: processamento, ordem de pagamento, carta e pagamento pela tesouraria (ADR-037). */
final class SalariosPagamentoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    #[Test]
    public function do_contrato_ao_pagamento_pela_tesouraria(): void
    {
        $this->empresa = $this->criarEmpresa();
        $ids = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            foreach (['7211' => 'Remunerações', '7221' => 'Encargos INSS', '3611' => 'Remunerações a pagar', '3421' => 'IRT', '3431' => 'INSS', '4311' => 'Banco',
                '4312' => 'Banco USD'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }

            return ['org' => TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id,
                'base' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id];
        });
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys(['colaboradores_detail', 'contratos_new',
            'rh_bancario_gerir', 'contab_mapeamento', 'calcular_view', 'calcular_folha', 'processamento_validate', 'processamento_integrate', 'processamento_view',
            'rh_rel_banco_view', 'teso_doc_emitir', 'teso_integrar', 'teso_gestao_pagamentos_view'], true))->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
        $mes = now()->format('m/Y');
        [$mm, $aaaa] = explode('/', $mes);

        $ana = $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'Ana', 'nif' => '111', 'tipo_organizacao_id' => $ids['org']], $this->s)->json('dados.id');
        $rui = $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'Rui', 'nif' => '222', 'tipo_organizacao_id' => $ids['org']], $this->s)->json('dados.id');
        foreach ([$ana => 300000, $rui => 150000] as $c => $v) {
            $this->postJson('/api/rh/contratos', ['colaborador_id' => $c, 'data_inicio' => now()->subYear()->toDateString(),
                'remuneracoes' => [['infotipo_salarial_id' => $ids['base'], 'valor_mes' => $v]]], $this->s)->assertCreated();
        }
        $this->putJson('/api/rh/mapeamentos-contabeis', ['rubricas' => [['infotipo_salarial_id' => $ids['base'], 'tipo_organizacao_id' => $ids['org'], 'numero_conta' => '7211']],
            'sistema' => collect(['NET_PAY_CREDIT' => '3611', 'IRT_CREDIT' => '3421', 'INSS_FUNC_CREDIT' => '3431', 'INSS_EMP_DEBIT' => '7221', 'INSS_EMP_CREDIT' => '3431'])
                ->map(fn ($conta, $codigo) => ['codigo' => $codigo, 'tipo_organizacao_id' => $ids['org'], 'numero_conta' => $conta])->values()->all()], $this->s)->assertOk();

        $p = $this->postJson('/api/rh/salarios/periodos', ['mes_ano' => $mes], $this->s)->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/importar-contratos", [], $this->s)->assertJsonPath('dados.criados', 2);
        $this->getJson("/api/rh/salarios/periodos/{$p}/ordem-pagamento", $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_VALIDADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/encerrar", [], $this->s)->assertOk();
        $this->postJson("/api/rh/salarios/periodos/{$p}/validar", [], $this->s)->assertOk();

        // Ana: 300 000 − 9 000 − IRT(291 000 → 47 630) = 243 370; Rui: 150 000 − 4 500 = 145 500 (isento de IRT)
        $this->getJson("/api/rh/salarios/periodos/{$p}/ordem-pagamento", $this->s)->assertOk()->assertJsonPath('dados.total', '388870.00')
            ->assertJsonPath('dados.sem_iban', 2);
        $carta = ['codigo_conta_bancaria' => '4311', 'data' => now()->toDateString(), 'nome_assinatura' => 'Director'];
        $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", $carta, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_SEM_IBAN');
        $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", ['codigo_conta_bancaria' => '7211'] + $carta, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_NAO_BANCARIA');

        $banco = $this->postJson('/api/rh/bancos', ['nome' => 'Banco A'], $this->s)->json('dados.id');
        foreach ([$ana => '0040000012345678901', $rui => '0040000098765432101'] as $c => $prefixo) {
            $this->putJson("/api/rh/colaboradores/{$c}/coordenada-bancaria", ['banco_id' => $banco, 'iban' => RHCadastrosTest::ibanValido($prefixo)], $this->s)->assertOk();
        }
        // carta só da Ana; depois a do Rui (ninguém entra em duas cartas)
        $c1 = $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", $carta + ['colaboradores' => [$ana]], $this->s)->assertCreated()
            ->assertJsonPath('dados.montante_total', '243370.00')->assertJsonCount(1, 'dados.itens')->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", $carta + ['colaboradores' => [$ana]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'COLABORADOR_INVALIDO');
        $c2 = $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", $carta, $this->s)->assertCreated()->assertJsonPath('dados.montante_total', '145500.00')->json('dados.id');
        $this->postJson("/api/rh/salarios/periodos/{$p}/cartas", $carta, $this->s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_SALARIOS_A_PAGAR');

        // pagamento exige o período contabilizado
        $this->postJson("/api/rh/salarios/cartas/{$c1}/pagamento", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PERIODO_NAO_CONTABILIZADO');
        $this->postJson("/api/rh/salarios/periodos/{$p}/contabilizar", [], $this->s)->assertOk();
        $doc = $this->postJson("/api/rh/salarios/cartas/{$c1}/pagamento", [], $this->s)->assertCreated()->assertJsonPath('dados.estado', 'PENDENTE')
            ->assertJsonPath('dados.valor_total', '243370.00')->assertJsonPath('dados.periodo_processamento_salarial_id', $p)->json('dados.id');
        $this->postJson("/api/rh/salarios/cartas/{$c1}/pagamento", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CARTA_COM_PAGAMENTO');
        $this->deleteJson("/api/rh/salarios/cartas/{$c1}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CARTA_COM_PAGAMENTO');
        $this->getJson("/api/rh/salarios/periodos/{$p}/ordem-pagamento", $this->s)->assertJsonPath('dados.sem_iban', 0)->assertJsonPath('dados.linhas.0.carta_pagamento_id', $c1);

        // integração na tesouraria: D 3611 (salários a pagar, SALMMAAAA) / C 4311
        $lan = $this->postJson("/api/tesouraria/documentos/{$doc}/integrar", [], $this->s)->assertOk()->json('dados.numero_lan_contabilizacao');
        $linhas = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => LancamentoContabil::query()->where('numero_lan', $lan)->get()
            ->map(fn ($l) => "{$l->tipo_dc} {$l->codigo_conta} {$l->valor} {$l->numero_documento}")->sort()->values()->all());
        $this->assertSame(['C 4311 243370.00 '.app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => DocumentoTesouraria::query()->find($doc)->numero_documento), "D 3611 243370.00 SAL{$mm}{$aaaa}"], $linhas);

        // a segunda carta paga o resto; a carta sem pagamento pode ser eliminada
        $this->postJson("/api/rh/salarios/cartas/{$c2}/pagamento", [], $this->s)->assertCreated();
        $this->getJson("/api/rh/salarios/cartas?periodo_id={$p}", $this->s)->assertJsonCount(2, 'dados');
    }
}
