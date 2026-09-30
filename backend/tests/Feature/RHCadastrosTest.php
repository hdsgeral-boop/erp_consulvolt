<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Models\TipoOrganizacaoRH;
use App\Services\RH\ServicoColaboradores;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** RH (parte 1b): colaboradores e ficha, IBAN, contratos com histórico, rubricas, bancos e mapeamento contabilístico. */
final class RHCadastrosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::create(['codigo' => '72', 'descricao' => 'Custos com o pessoal', 'tipo' => 'T']);
            PlanoConta::create(['codigo' => '7211', 'descricao' => 'Remunerações', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '3611', 'descricao' => 'Remunerações a pagar', 'tipo' => 'M']);
            $this->ids = [
                'org' => TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id,
                'base' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id,
                'desc' => InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Adiantamento', 'sujeito_inss' => false, 'irt' => 'false'])->id,
            ];
        });
        $this->s = $this->sessao(['colaboradores_view', 'colaboradores_detail', 'rh_colab_del', 'contratos_view', 'contratos_new', 'contratos_terminate',
            'infotipos_view', 'rh_infotipos_gerir', 'rh_infotipo_del', 'bancario_view', 'rh_bancario_gerir', 'rh_banco_del', 'contabilidade_view', 'contab_mapeamento',
            'funcoes_view', 'rh_funcoes_gerir', 'rh_funcao_del']);
    }

    private function sessao(array $permissoes): array
    {
        $perfil = $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function colaborador(array $extra = []): int
    {
        return $this->postJson('/api/rh/colaboradores', $extra + ['nome_completo' => 'Ana Silva', 'nif' => '00123 4567 la045', 'tipo_organizacao_id' => $this->ids['org']], $this->s)
            ->assertCreated()->json('dados.id');
    }

    /** IBAN angolano válido (AO06 + NIB de 21 dígitos com os 2 últimos de controlo). */
    public static function ibanValido(string $prefixo19 = '0040000012345678901'): string
    {
        for ($i = 0; $i < 100; $i++) {
            $iban = 'AO06'.$prefixo19.sprintf('%02d', $i);
            if (ServicoColaboradores::ibanValido($iban)) {
                return $iban;
            }
        }
        throw new \RuntimeException('sem IBAN');
    }

    #[Test]
    public function colaborador_com_ficha_terceiro_nif_unico_e_eliminacao_protegida(): void
    {
        $id = $this->colaborador(['dependentes' => [['nome' => 'Filho', 'parentesco' => 'FILHO', 'data_nascimento' => '2015-03-01']],
            'habilitacoes' => [['nivel' => 'Licenciatura', 'ano_conclusao' => '2010'], ['nivel' => 'Mestrado', 'estado' => 'Em curso']]]);
        $this->getJson("/api/rh/colaboradores/{$id}", $this->s)->assertOk()->assertJsonPath('dados.nif', '001234567LA045')
            ->assertJsonPath('dados.estado', 'ACTIVO')->assertJsonPath('dados.dias_uteis_mes', 22)
            ->assertJsonPath('dados.habilitacao_maxima', 'Licenciatura')   // o mestrado em curso não conta
            ->assertJsonCount(1, 'dados.dependentes')->assertJsonCount(2, 'dados.habilitacoes');
        $this->assertTrue(app(ContextoEmpresa::class)->executarComo($this->empresa->id,
            fn () => Terceiro::query()->where('nif', '001234567LA045')->where('tipo', Terceiro::COLABORADOR)->exists()));

        // ficha gravada por substituição
        $this->putJson("/api/rh/colaboradores/{$id}", ['nome_completo' => 'Ana M. Silva', 'nif' => '001234567LA045', 'tipo_organizacao_id' => $this->ids['org'],
            'dependentes' => []], $this->s)->assertOk()->assertJsonCount(0, 'dados.dependentes')->assertJsonCount(2, 'dados.habilitacoes');
        $this->assertSame('Ana M. Silva', app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => Terceiro::query()->where('nif', '001234567LA045')->value('nome')));

        $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'Outra', 'nif' => '001234567la045', 'tipo_organizacao_id' => $this->ids['org']], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO');
        $this->postJson('/api/rh/colaboradores', ['nome_completo' => 'X', 'nif' => '999', 'tipo_organizacao_id' => $this->ids['org'], 'reformado' => true, 'avencado' => true], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFORMADO_E_AVENCADO');
        $this->putJson("/api/rh/colaboradores/{$id}", ['nome_completo' => 'Ana', 'nif' => '001234567LA045', 'tipo_organizacao_id' => $this->ids['org'], 'colaborador_gestor_id' => $id], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'GESTOR_INVALIDO');

        // com contrato não se elimina (o legado apagava e deixava o contrato órfão)
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $id, 'data_inicio' => '2026-01-01', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 200000]]], $this->s)
            ->assertCreated();
        $this->deleteJson("/api/rh/colaboradores/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $livre = $this->colaborador(['nif' => '777', 'nome_completo' => 'Sem uso']);
        $this->deleteJson("/api/rh/colaboradores/{$livre}", [], $this->s)->assertOk();
        $this->getJson("/api/rh/colaboradores/{$livre}", $this->s)->assertNotFound();
    }

    #[Test]
    public function coordenadas_bancarias_com_iban_validado_e_banco_protegido(): void
    {
        $id = $this->colaborador();
        $banco = $this->postJson('/api/rh/bancos', ['nome' => 'Banco A', 'codigo' => 'BA', 'codigo_conta' => '72'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_TOTALIZADORA');
        $banco = $this->postJson('/api/rh/bancos', ['nome' => 'Banco A', 'codigo' => 'BA'], $this->s)->assertCreated()->json('dados.id');
        $this->postJson('/api/rh/bancos', ['nome' => 'banco a'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'BANCO_DUPLICADO');

        $this->putJson("/api/rh/colaboradores/{$id}/coordenada-bancaria", ['banco_id' => $banco, 'iban' => 'AO06 0040 0000 1234 5678 9019 9'], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'IBAN_INVALIDO');
        $iban = self::ibanValido();
        $nib = substr($iban, 4);
        // o NIB (21 dígitos, com espaços) é aceite e convertido em IBAN
        $this->putJson("/api/rh/colaboradores/{$id}/coordenada-bancaria", ['banco_id' => $banco, 'iban' => implode(' ', str_split($nib, 4))], $this->s)
            ->assertOk()->assertJsonPath('dados.iban', $iban);
        $this->putJson("/api/rh/colaboradores/{$id}/coordenada-bancaria", ['banco_id' => $banco, 'iban' => $iban], $this->s)->assertOk();   // upsert: continua um
        $this->getJson('/api/rh/coordenadas-bancarias', $this->s)->assertJsonCount(1, 'dados');

        $this->deleteJson("/api/rh/bancos/{$banco}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/rh/colaboradores/{$id}/coordenada-bancaria", [], $this->s)->assertOk();
        $this->deleteJson("/api/rh/bancos/{$banco}", [], $this->s)->assertOk();
    }

    #[Test]
    public function contratos_com_historico_sem_sobreposicao_e_terminacao_com_permissao_propria(): void
    {
        $id = $this->colaborador();
        $base = ['colaborador_id' => $id, 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 220000]]];
        $c1 = $this->postJson('/api/rh/contratos', $base + ['data_inicio' => '2025-01-01'], $this->s)->assertCreated()
            ->assertJsonPath('dados.estado', 'ACTIVO')->assertJsonPath('dados.dias_contrato_mes', 22)->assertJsonPath('dados.remuneracoes.0.valor_dia', 10000)->json('dados.id');
        $this->postJson('/api/rh/contratos', $base + ['data_inicio' => '2026-01-01'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CONTRATO_SOBREPOSTO');

        // terminar pela edição exige contratos_terminate
        $so = $this->sessao(['contratos_view', 'contratos_new']);
        $this->putJson("/api/rh/contratos/{$c1}", $base + ['data_inicio' => '2025-01-01', 'data_fim' => '2025-12-31'], $so)->assertForbidden();
        $this->postJson("/api/rh/contratos/{$c1}/terminar", ['data_fim' => '2024-12-31'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
        $this->postJson("/api/rh/contratos/{$c1}/terminar", ['data_fim' => '2025-12-31'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'INACTIVO');

        // revisão salarial: novo contrato a seguir ao anterior
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $id, 'data_inicio' => '2026-01-01',
            'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 250000]]], $this->s)->assertCreated();
        $this->getJson("/api/rh/contratos?colaborador_id={$id}", $this->s)->assertJsonCount(2, 'dados');

        $outro = $this->colaborador(['nif' => '888', 'nome_completo' => 'Rui']);
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $outro, 'data_inicio' => '2026-01-01', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['desc'], 'valor_mes' => 5]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_NAO_VENCIMENTO');
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $outro, 'data_inicio' => '2026-01-01', 'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 0]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'SEM_REMUNERACAO');
        $this->postJson('/api/rh/contratos', ['colaborador_id' => $outro, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-01-01',
            'remuneracoes' => [['infotipo_salarial_id' => $this->ids['base'], 'valor_mes' => 1]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'DATAS_INVALIDAS');
    }

    #[Test]
    public function rubricas_unicas_e_protegidas_e_mapeamento_com_contas_de_movimento(): void
    {
        $this->postJson('/api/rh/infotipos', ['nome' => 'salário base'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'RUBRICA_DUPLICADA');
        $he = $this->postJson('/api/rh/infotipos', ['nome' => 'Horas Extras', 'calculo_horas' => 'EXTRA'], $this->s)->assertCreated()
            ->assertJsonPath('dados.tipo', 'VENCIMENTO')->assertJsonPath('dados.irt', 'true')->assertJsonPath('dados.calculo_horas', 'EXTRA')->json('dados.id');
        $this->postJson('/api/rh/infotipos', ['nome' => 'X', 'irt' => 'talvez'], $this->s)->assertStatus(422);

        // mapeamento: conta inexistente e totalizadora recusadas; gravação por chave; vazio apaga
        $org = $this->ids['org'];
        $this->putJson('/api/rh/mapeamentos-contabeis', ['rubricas' => [['infotipo_salarial_id' => $he, 'tipo_organizacao_id' => $org, 'numero_conta' => '9999']]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'MAPEAMENTO_INVALIDO');
        $this->putJson('/api/rh/mapeamentos-contabeis', ['rubricas' => [['infotipo_salarial_id' => $he, 'tipo_organizacao_id' => $org, 'numero_conta' => '72']]], $this->s)
            ->assertStatus(422);
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => MapeamentoContabilRH::create(['infotipo_salarial_id' => $he, 'tipo_organizacao_id' => $org,
            'avencado' => false, 'numero_conta' => '']));   // duplicado vazio herdado do legado
        $this->putJson('/api/rh/mapeamentos-contabeis', [
            'rubricas' => [['infotipo_salarial_id' => $he, 'tipo_organizacao_id' => $org, 'numero_conta' => '7211'], ['infotipo_salarial_id' => $he, 'avencado' => true, 'numero_conta' => '7211']],
            'sistema' => [['codigo' => 'NET_PAY_CREDIT', 'tipo_organizacao_id' => $org, 'numero_conta' => '3611']],
        ], $this->s)->assertOk()->assertJsonCount(2, 'dados.rubricas')->assertJsonCount(1, 'dados.sistema');
        $this->assertSame(2, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => MapeamentoContabilRH::query()->where('infotipo_salarial_id', $he)->count()));
        $this->putJson('/api/rh/mapeamentos-contabeis', ['rubricas' => [['infotipo_salarial_id' => $he, 'avencado' => true, 'numero_conta' => null]]], $this->s)
            ->assertOk()->assertJsonCount(1, 'dados.rubricas');

        // eliminar: em uso num contrato → recusado; livre → elimina (e os mapeamentos)
        $id = $this->colaborador();
        $c = $this->postJson('/api/rh/contratos', ['colaborador_id' => $id, 'data_inicio' => '2026-01-01', 'remuneracoes' => [['infotipo_salarial_id' => $he, 'valor_mes' => 1000]]], $this->s)->json('dados.id');
        $this->deleteJson("/api/rh/infotipos/{$he}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->deleteJson("/api/rh/contratos/{$c}", [], $this->s)->assertOk();
        $this->deleteJson("/api/rh/infotipos/{$he}", [], $this->s)->assertOk();
        $this->getJson('/api/rh/mapeamentos-contabeis', $this->s)->assertJsonCount(0, 'dados.rubricas');

        // tipo de organização em uso por colaboradores
        $this->deleteJson("/api/rh/tipos-organizacao/{$org}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'REGISTO_EM_USO');
        $this->postJson('/api/rh/tipos-organizacao', ['nome' => 'colaboradores'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'TIPO_ORGANIZACAO_DUPLICADO');
    }
}
