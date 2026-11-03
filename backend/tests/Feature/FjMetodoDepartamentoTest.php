<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Services\Metodo\ServicoAssuntosMetodo;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — Método de Trabalho: departamento (unidade orgânica) opcional no WHERE, opções com o caminho na árvore e o
 * contador «Assuntos à minha espera» no Início (ServicoAssuntosMetodo::paraMinhaDecisao).
 */
final class FjMetodoDepartamentoTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    /** @var array<string, array<string, mixed>> */
    private array $s = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJM', 'nif' => '5000888851']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n) => Colaborador::create(['nome_completo' => $n, 'nif' => $n, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22])->id;
            $this->ids = ['ana' => $mk('Ana'), 'chefe' => $mk('Chefe')];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true, 'ordem' => 10]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'ativo' => true, 'ordem' => 10]);
            $inactiva = UnidadeOrganica::create(['nome' => 'Extinta', 'codigo' => 'EXT', 'tipo' => 'DEPARTAMENTO', 'ativo' => false, 'ordem' => 20]);
            Colaborador::query()->whereKey($this->ids['ana'])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['chefe'])->update(['unidade_organica_id' => $dir->id]);
            $un = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Negócio'])->id;
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id, 'inactiva' => $inactiva->id, 'un' => $un];
        });
        $this->s = ['ana' => $this->sessao(['mt_assuntos_view', 'mt_criar'], $this->ids['ana']), 'chefe' => $this->sessao(['mt_assuntos_view', 'mt_criar'], $this->ids['chefe']),
            'gestor' => $this->sessao(['mt_assuntos_view', 'mt_aprovar'])];
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    #[Test]
    public function departamento_opcional_validado_na_empresa_e_contador_no_inicio(): void
    {
        $ana = $this->s['ana'];
        $opcoes = $this->getJson('/api/metodo/assuntos/opcoes', $ana)->assertOk()->json('dados.unidades_organicas');
        $this->assertSame(['Direcção', 'Direcção › Departamento'], array_column($opcoes, 'caminho'));   // sem a inactiva, com o caminho
        $base = ['titulo' => 'Renovar o contrato de limpeza', 'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['ana'], 'nome' => 'Ana']], 'responsavel_principal' => "COLABORADOR:{$this->ids['ana']}",
            'data_inicio' => now()->toDateString(), 'data_fim' => now()->addDays(10)->toDateString(), 'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => str_repeat('O contrato actual termina no fim do mês e a limpeza é indispensável. ', 2), 'recursos' => 'Proposta do fornecedor', 'custo_estimado' => 1000];
        $this->postJson('/api/metodo/assuntos', $base + ['unidade_organica_id' => 999999], $ana)->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        $a = $this->postJson('/api/metodo/assuntos', $base + ['unidade_organica_id' => $this->ids['dep']], $ana)->assertCreated()->json('dados.assunto');
        $this->assertSame([$this->ids['dep'], 'Departamento'], [$a['unidade_organica_id'], $a['unidade_organica_nome']]);
        $this->assertSame('Departamento', $this->getJson('/api/metodo/assuntos', $ana)->assertOk()->json('dados.assuntos.0.unidade_organica_nome'));
        $this->putJson("/api/metodo/assuntos/{$a['id']}", $base + ['unidade_organica_id' => null], $ana)->assertOk()->assertJsonPath('dados.assunto.unidade_organica_id', null);
        $this->assertTrue(collect($this->getJson("/api/metodo/assuntos/{$a['id']}/historico", $ana)->json('dados.registos'))->contains(fn ($r) => str_contains((string) ($r['detalhe'] ?? ''), 'Departamento')));

        // submeter: a chefia (responsável da Direcção) é a aprovadora → «Assuntos à minha espera» no Início dela e não no da Ana; o gestor conta as deliberações
        $this->postJson("/api/metodo/assuntos/{$a['id']}/submeter", [], $ana)->assertOk()->assertJsonPath('dados.aprovado', false);
        $pend = fn (array $h) => collect($this->getJson('/api/gestao/inicio', $h)->assertOk()->json('dados.pendentes'))->keyBy('id');
        $this->assertSame(1, $pend($this->s['chefe'])['metodo_decidir']['quantidade']);
        $this->assertArrayNotHasKey('metodo_decidir', $pend($ana)->all());
        $this->assertSame([1, 1], [$pend($this->s['gestor'])['metodo_decidir']['quantidade'], $pend($this->s['gestor'])['metodo_deliberacoes']['quantidade']]);
        $this->assertSame([$a['id']], array_map(fn ($x) => $x->id, $this->em(fn () => app(ServicoAssuntosMetodo::class)->paraMinhaDecisao())));   // sessão do gestor é a última autenticada
    }
}
