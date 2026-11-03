<?php

namespace Tests\Feature;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase J (ADR-078) — estrutura: arrumar (trás/frente/subir/descer/apoio), utilizador_responsavel/utilizadores editáveis e
 * acesso só de leitura com est_organigrama_view à estrutura e aos colaboradores.
 */
final class FjEstruturaArrumarTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa FJE', 'nif' => '5000888861']);
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            Colaborador::create(['nome_completo' => 'Ana', 'nif' => 'Ana', 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22]);
            $raiz = UnidadeOrganica::create(['nome' => 'Raiz', 'codigo' => 'R', 'tipo' => 'DIRECCAO_GERAL', 'ativo' => true, 'ordem' => 10]);
            $mk = fn (string $n, int $ordem, int $apoio = 0, ?int $pai = null) => UnidadeOrganica::create(['nome' => $n, 'codigo' => $n, 'tipo' => 'DEPARTAMENTO', 'ativo' => true, 'ordem' => $ordem, 'apoio' => $apoio, 'unidade_organica_pai_id' => $pai ?? $raiz->id])->id;
            $this->ids = ['raiz' => $raiz->id, 'a' => $mk('A', 3), 'b' => $mk('B', 7), 'c' => $mk('C', 9), 'g' => $mk('G', 1, 1)];
            $this->ids['a1'] = $mk('A1', 10, 0, $this->ids['a']);
        });
        $this->s = $this->sessao(['est_estrutura_view', 'est_editar']);
    }

    private function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?int $colaborador = null, array $atributos = []): array
    {
        $u = $this->criarUtilizador($atributos + ['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function estado(): array
    {
        return $this->em(fn () => UnidadeOrganica::query()->orderBy('id')->get()->mapWithKeys(fn ($u) => [$u->nome => [$u->unidade_organica_pai_id, $u->ordem, (int) $u->apoio]])->all());
    }

    #[Test]
    public function arrumar_tras_frente_subir_descer_e_apoio(): void
    {
        $arrumar = fn (int $u, string $accao) => $this->postJson("/api/rh/estrutura/unidades/{$u}/arrumar", ['accao' => $accao], $this->s);
        $arrumar($this->ids['a'], 'APOIO')->assertOk()->assertJsonPath('dados.unidade.apoio', 1);
        $arrumar($this->ids['a'], 'APOIO')->assertOk()->assertJsonPath('dados.unidade.apoio', 0);
        // frente: renumera os irmãos (apoio primeiro: G=10, A=20, B=30, C=40) e troca A com B no grupo «não apoio»
        $arrumar($this->ids['a'], 'FRENTE')->assertOk()->assertJsonPath('dados.alterado', true);
        $e = $this->estado();
        $this->assertSame([[10, 1], [30, 0], [20, 0], [40, 0]], [[$e['G'][1], $e['G'][2]], [$e['A'][1], $e['A'][2]], [$e['B'][1], $e['B'][2]], [$e['C'][1], $e['C'][2]]]);
        $arrumar($this->ids['g'], 'TRAS')->assertOk()->assertJsonPath('dados.alterado', false)->assertJsonPath('mensagem', 'Já é a primeira unidade deste nível.');   // só há uma de apoio
        $arrumar($this->ids['c'], 'FRENTE')->assertOk()->assertJsonPath('dados.alterado', false);
        $arrumar($this->ids['raiz'], 'SUBIR')->assertOk()->assertJsonPath('dados.alterado', false)->assertJsonPath('mensagem', 'Esta unidade já está no topo da estrutura.');
        // subir: A1 passa a reportar ao avô (Raiz) com ordem = ordem do pai (A=30) + 5
        $arrumar($this->ids['a1'], 'SUBIR')->assertOk();
        $this->assertSame([$this->ids['raiz'], 35], [$this->estado()['A1'][0], $this->estado()['A1'][1]]);
        // descer: A1 (ordem 35) passa a reportar ao irmão anterior (B, ordem 20... irmãos: G10, B20, A30, A1 35, C40 → anterior = A)
        $arrumar($this->ids['a1'], 'DESCER')->assertOk();
        $this->assertSame([$this->ids['a'], 10], [$this->estado()['A1'][0], $this->estado()['A1'][1]]);
        $arrumar($this->ids['g'], 'DESCER')->assertOk()->assertJsonPath('dados.alterado', false);   // a primeira do nível não tem anterior
        $this->postJson("/api/rh/estrutura/unidades/{$this->ids['a']}/arrumar", ['accao' => 'SUBIR'], $this->sessao(['est_estrutura_view']))->assertForbidden();
        $this->postJson("/api/rh/estrutura/unidades/{$this->ids['a']}/arrumar", ['accao' => 'X'], $this->s)->assertStatus(422);
    }

    #[Test]
    public function utilizador_responsavel_e_utilizadores_editaveis_e_validados(): void
    {
        $u = $this->criarUtilizador(['nome_utilizador' => 'maria.silva']);
        $u->empresas()->attach($this->empresa->id);
        $this->criarUtilizador(['nome_utilizador' => 'intruso']);   // sem acesso à empresa
        $this->putJson("/api/rh/estrutura/unidades/{$this->ids['a']}", ['nome' => 'A', 'utilizador_responsavel' => 'intruso'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'UTILIZADOR_INVALIDO');
        $this->putJson("/api/rh/estrutura/unidades/{$this->ids['a']}", ['nome' => 'A', 'utilizador_responsavel' => 'MARIA.SILVA', 'utilizadores' => ['maria.silva', 'maria.silva']], $this->s)->assertOk()
            ->assertJsonPath('dados.utilizador_responsavel', 'maria.silva')->assertJsonPath('dados.utilizadores', ['maria.silva']);
        $this->putJson("/api/rh/estrutura/unidades/{$this->ids['a']}", ['nome' => 'A', 'utilizador_responsavel' => null, 'utilizadores' => []], $this->s)->assertOk()
            ->assertJsonPath('dados.utilizador_responsavel', null)->assertJsonPath('dados.utilizadores', []);
        $nova = $this->postJson('/api/rh/estrutura/unidades', ['nome' => 'Nova', 'utilizadores' => ['maria.silva']], $this->s)->assertCreated()->json('dados');
        $this->assertSame(['maria.silva'], $nova['utilizadores']);
    }

    #[Test]
    public function est_organigrama_view_le_a_estrutura_e_os_colaboradores_mas_nao_edita(): void
    {
        $leitor = $this->sessao(['est_organigrama_view']);
        $this->getJson('/api/rh/estrutura', $leitor)->assertOk()->assertJsonCount(6, 'dados.unidades');
        $this->getJson('/api/rh/colaboradores', $leitor)->assertOk()->assertJsonCount(1, 'dados');
        $this->getJson('/api/rh/estrutura/chefia/'.$this->em(fn () => Colaborador::query()->value('id')), $leitor)->assertOk();
        $this->postJson('/api/rh/estrutura/unidades', ['nome' => 'X'], $leitor)->assertForbidden();
        $this->getJson('/api/rh/estrutura', $this->sessao(['est_mapa_view']))->assertOk();
        $this->getJson('/api/rh/estrutura', $this->sessao(['rh_portal_usar']))->assertForbidden();
    }
}
