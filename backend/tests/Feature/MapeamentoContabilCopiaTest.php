<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\InfotipoSalarial;
use App\Models\MapeamentoContabilRH;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\PlanoConta;
use App\Models\TipoOrganizacaoRH;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Contabilidade › Mapeamento contábil › «Copiar de outra empresa» (legado: openCopyAccountingMaposModal /
 * executeCopyAccountingMapos, js/app_v2.js:2563-2702): correspondência por nome, sem sobrepor, só contas de movimento
 * que existam no destino, só empresas acessíveis e com a permissão de editar o mapeamento.
 */
final class MapeamentoContabilCopiaTest extends TestCase
{
    private Empresa $origem;

    private Empresa $destino;

    private Empresa $alheia;

    private array $o = [];

    private array $d = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->origem, $this->destino, $this->alheia] = [$this->criarEmpresa(), $this->criarEmpresa(), $this->criarEmpresa()];
        $ctx = app(ContextoEmpresa::class);

        $this->o = $ctx->executarComo($this->origem->id, function () {
            foreach ([['72', 'T'], ['7211', 'M'], ['7212', 'M'], ['3611', 'M'], ['3421', 'M']] as [$c, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => $t]);
            }
            $ids = [
                'col' => TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id,
                'soc' => TipoOrganizacaoRH::create(['nome' => 'Sociais'])->id,
                'base' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id,
                'sub' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de Férias', 'sujeito_inss' => true, 'irt' => 'true'])->id,
                'so_origem' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Prémio Origem', 'sujeito_inss' => false, 'irt' => 'true'])->id,
            ];
            foreach ([
                [$ids['base'], $ids['col'], false, '7211'],
                [$ids['base'], $ids['soc'], false, '7211'],   // no destino já está mapeada → não sobrepõe
                [$ids['base'], null, true, '7212'],           // coluna Avençado
                [$ids['sub'], $ids['col'], false, '7299'],    // conta inexistente no destino
                [$ids['so_origem'], $ids['col'], false, '7211'], // rubrica sem correspondência
            ] as [$inf, $org, $av, $conta]) {
                MapeamentoContabilRH::create(['infotipo_salarial_id' => $inf, 'tipo_organizacao_id' => $org, 'avencado' => $av, 'numero_conta' => $conta]);
            }
            MapeamentoContabilSistemaRH::create(['codigo' => 'NET_PAY_CREDIT', 'tipo_organizacao_id' => $ids['col'], 'avencado' => false, 'numero_conta' => '3611']);
            MapeamentoContabilSistemaRH::create(['codigo' => 'NET_PAY_CREDIT', 'tipo_organizacao_id' => null, 'avencado' => true, 'numero_conta' => '3611']);
            MapeamentoContabilSistemaRH::create(['codigo' => 'IRT_CREDIT', 'tipo_organizacao_id' => $ids['col'], 'avencado' => false, 'numero_conta' => '72']);   // totalizadora no destino

            return $ids;
        });

        $this->d = $ctx->executarComo($this->destino->id, function () {
            foreach ([['72', 'T'], ['7211', 'M'], ['7212', 'M'], ['3611', 'M']] as [$c, $t]) {
                PlanoConta::create(['codigo' => $c, 'descricao' => "Conta {$c}", 'tipo' => $t]);
            }
            $ids = [
                'soc' => TipoOrganizacaoRH::create(['nome' => 'sociais'])->id,          // nomes iguais sem distinguir maiúsculas
                'col' => TipoOrganizacaoRH::create(['nome' => ' Colaboradores '])->id,
                'base' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'SALÁRIO BASE', 'sujeito_inss' => true, 'irt' => 'true'])->id,
                'sub' => InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Subsídio de Férias', 'sujeito_inss' => true, 'irt' => 'true'])->id,
            ];
            MapeamentoContabilRH::create(['infotipo_salarial_id' => $ids['base'], 'tipo_organizacao_id' => $ids['soc'], 'avencado' => false, 'numero_conta' => '7212']);
            MapeamentoContabilRH::create(['infotipo_salarial_id' => $ids['base'], 'tipo_organizacao_id' => $ids['col'], 'avencado' => false, 'numero_conta' => '']);   // vazio herdado do legado

            return $ids;
        });

        $ctx->executarComo($this->alheia->id, fn () => TipoOrganizacaoRH::create(['nome' => 'Colaboradores']));
    }

    private function sessao(array $permissoes, array $empresas): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresas);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->destino->id];
    }

    #[Test]
    public function copia_por_nome_sem_sobrepor_e_so_contas_de_movimento_do_destino(): void
    {
        $s = $this->sessao(['contabilidade_view', 'contab_mapeamento'], [$this->origem->id, $this->destino->id]);
        $url = '/api/rh/mapeamentos-contabeis/copiar';

        // simulação: contagens sem gravar
        $this->postJson($url, ['empresa_origem_id' => $this->origem->id, 'simular' => true], $s)->assertOk()
            ->assertJsonPath('dados.simulacao', true)->assertJsonPath('dados.rubricas', 2)->assertJsonPath('dados.sistema', 2)
            ->assertJsonPath('dados.ja_mapeados', 1)->assertJsonPath('dados.sem_correspondencia', 1)->assertJsonPath('dados.contas_invalidas', ['7299', '72']);
        $this->getJson('/api/rh/mapeamentos-contabeis', $s)->assertJsonCount(1, 'dados.rubricas')->assertJsonCount(0, 'dados.sistema');

        $this->postJson($url, ['empresa_origem_id' => $this->origem->id], $s)->assertOk()->assertJsonPath('dados.simulacao', false)
            ->assertJsonPath('dados.rubricas', 2)->assertJsonPath('dados.sistema', 2);

        $ctx = app(ContextoEmpresa::class);
        $ctx->executarComo($this->destino->id, function () {
            $r = MapeamentoContabilRH::query()->where('infotipo_salarial_id', $this->d['base'])->get();
            $this->assertCount(3, $r, 'a célula vazia herdada é substituída (um mapeamento por chave)');
            $this->assertSame('7211', $r->firstWhere('tipo_organizacao_id', $this->d['col'])->numero_conta);
            $this->assertSame('7212', $r->firstWhere('tipo_organizacao_id', $this->d['soc'])->numero_conta, 'não sobrepõe');
            $this->assertSame('7212', $r->firstWhere('avencado', true)->numero_conta);
            $this->assertFalse(MapeamentoContabilRH::query()->where('infotipo_salarial_id', $this->d['sub'])->exists(), 'conta inexistente no destino fica de fora');
            $this->assertSame(['NET_PAY_CREDIT'], MapeamentoContabilSistemaRH::query()->pluck('codigo')->unique()->values()->all(), 'conta totalizadora fica de fora');
            $this->assertSame($this->d['col'], MapeamentoContabilSistemaRH::query()->where('avencado', false)->value('tipo_organizacao_id'));
        });
        // a origem não é alterada
        $this->assertSame(5, $ctx->executarComo($this->origem->id, fn () => MapeamentoContabilRH::query()->count()));

        // repetir não duplica
        $this->postJson($url, ['empresa_origem_id' => $this->origem->id], $s)->assertOk()->assertJsonPath('dados.rubricas', 0)->assertJsonPath('dados.sistema', 0);
    }

    #[Test]
    public function exige_permissao_e_empresa_acessivel_diferente_da_activa(): void
    {
        $url = '/api/rh/mapeamentos-contabeis/copiar';
        $leitura = $this->sessao(['contabilidade_view'], [$this->origem->id, $this->destino->id]);
        $this->postJson($url, ['empresa_origem_id' => $this->origem->id], $leitura)->assertForbidden();

        $s = $this->sessao(['contabilidade_view', 'contab_mapeamento'], [$this->origem->id, $this->destino->id]);
        // OWASP A01: empresa sem acesso (ou inexistente) → 403 sem revelar dados
        $this->postJson($url, ['empresa_origem_id' => $this->alheia->id], $s)->assertForbidden()->assertJsonPath('codigo', 'SEM_ACESSO_EMPRESA');
        $this->postJson($url, ['empresa_origem_id' => 999999], $s)->assertForbidden()->assertJsonPath('codigo', 'SEM_ACESSO_EMPRESA');
        $this->postJson($url, ['empresa_origem_id' => $this->destino->id], $s)->assertStatus(422)->assertJsonPath('codigo', 'ORIGEM_IGUAL_DESTINO');
        $this->postJson($url, ['empresa_origem_id' => 'x'], $s)->assertStatus(422);
        $this->assertSame(2, app(ContextoEmpresa::class)->executarComo($this->destino->id, fn () => MapeamentoContabilRH::query()->count()));
    }
}
