<?php

namespace Tests\Feature;

use App\Models\CentroCusto;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Terceiro;
use App\Models\UnidadeNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase G (Versão Actual) — Método de Trabalho, Registo de Assunto 5W1H (js/modules/metodo/assuntos.js): rascunho só com
 * título, numeração por ano, validação completa e avisos, histórico campo a campo, referências da própria empresa, anexos,
 * estados (cancelar com motivo, reabrir), visibilidade por utilizador, permissões e isolamento. Dados fictícios.
 */
final class FgMetodoAssuntosTest extends TestCase
{
    private Empresa $empresa;

    private array $ids = [];

    private array $autor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417200011']);
        $this->naEmpresa(function () {
            $this->ids['un'] = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Manutenção fictícia'])->id;
            $this->ids['cc'] = CentroCusto::create(['codigo' => 'CC1', 'descricao' => 'Oficina fictícia'])->id;
            $this->ids['ana'] = Colaborador::create(['nome_completo' => 'Ana Fictícia', 'nif' => 'MT001', 'estado' => 'ACTIVO', 'dias_uteis_mes' => 22])->id;
            $this->ids['forn'] = Terceiro::create(['nome' => 'Fornecedor Fictício', 'nif' => '5000000711', 'tipo' => Terceiro::FORNECEDOR])->id;
            $this->ids['cli'] = Terceiro::create(['nome' => 'Cliente Fictício', 'nif' => '5000000722', 'tipo' => Terceiro::CLIENTE])->id;
        });
        $this->autor = $this->sessao(['mt_assuntos_view', 'mt_criar']);
    }

    private function naEmpresa(callable $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null, ?int $colaborador = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function completo(array $d = []): array
    {
        return $d + [
            'titulo' => 'Substituir o quadro eléctrico da oficina',
            'descricao' => 'Quadro antigo com disjuntores a disparar.',
            'responsaveis' => [['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['ana'], 'nome' => 'Ana Fictícia'], ['tipo' => 'EXTERNO', 'nome' => 'Electricista Externo']],
            'responsavel_principal' => "COLABORADOR:{$this->ids['ana']}",
            'data_inicio' => now()->addDays(1)->toDateString(), 'data_fim' => now()->addDays(10)->toDateString(),
            'local_tipo' => 'INTERNO', 'unidade_negocio_id' => $this->ids['un'],
            'fundamentacao' => 'O quadro actual não cumpre as normas e já causou duas paragens de produção este mês.',
            'recursos' => "Quadro novo\nCabos e disjuntores", 'custo_estimado' => 250000, 'codigo_moeda' => 'AOA',
            'metodo_resolucao' => 'ACCAO',
            'anexos' => [['nome' => 'Orçamento', 'url' => 'https://exemplo.invalid/orcamento.pdf']],
        ];
    }

    #[Test]
    public function rascunho_numeracao_validacao_e_historico(): void
    {
        $ano = now()->year;
        // em rascunho só o título é obrigatório
        $id = $this->postJson('/api/metodo/assuntos', ['titulo' => 'Rever o processo de recepção'], $this->autor)->assertCreated()
            ->assertJsonPath('dados.assunto.numero', "AS {$ano}/001")->assertJsonPath('dados.assunto.estado', 'RASCUNHO')
            ->assertJsonPath('dados.assunto.permissoes.editar', true)->json('dados.assunto.id');
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'Segundo assunto'], $this->autor)->assertCreated()->assertJsonPath('dados.assunto.numero', "AS {$ano}/002");
        $this->postJson('/api/metodo/assuntos', ['titulo' => ''], $this->autor)->assertStatus(422);
        $this->postJson('/api/metodo/assuntos', ['titulo' => str_repeat('x', 121)], $this->autor)->assertStatus(422);

        // validação completa: fundamentação curta, fornecedor sem pedido (aviso) e pedido sem fornecedor (erro)
        $r = $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['fundamentacao' => 'Curta demais', 'validar_tudo' => true]), $this->autor)->assertStatus(422)
            ->assertJsonPath('codigo', 'ASSUNTO_INVALIDO');
        $this->assertStringContainsString('WHY: a fundamentação tem de ter pelo menos 50 caracteres (tem 12)', $r->json('mensagem'));
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['gerar_pedido_compra' => true, 'validar_tudo' => true]), $this->autor)->assertStatus(422)
            ->assertJsonFragment(['HOW: para gerar pedido de compra é preciso um fornecedor em WHO.']);
        $comForn = $this->completo(['responsaveis' => [['tipo' => 'FORNECEDOR', 'ref_id' => $this->ids['forn'], 'nome' => 'Fornecedor Fictício']],
            'responsavel_principal' => "FORNECEDOR:{$this->ids['forn']}", 'data_fim' => now()->addDays(1)->toDateString()]);
        $avisos = $this->putJson("/api/metodo/assuntos/{$id}", $comForn, $this->autor)->assertOk()->json('dados.avisos');
        $this->assertCount(2, $avisos);   // prazo < 48 h e fornecedor sem pedido de compra
        // principal fora da lista
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['responsavel_principal' => 'COLABORADOR:999', 'validar_tudo' => true]), $this->autor)
            ->assertStatus(422)->assertJsonFragment(['WHO: o responsável principal tem de estar na lista de responsáveis.']);

        // gravar completo: histórico «Alterado» só com o que mudou
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(), $this->autor)->assertOk()->assertJsonPath('dados.assunto.responsavel_principal_nome', 'Ana Fictícia');
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['custo_estimado' => 260000]), $this->autor)->assertOk();
        $hist = $this->getJson("/api/metodo/assuntos/{$id}/historico", $this->autor)->assertOk()->json('dados.registos');
        $this->assertSame('Alterado', $hist[0]['acao']);
        $this->assertSame('Custo estimado', $hist[0]['detalhe']);
        $this->assertSame('Criado', end($hist)['acao']);

        // lista com alertas e nomes resolvidos
        $this->getJson('/api/metodo/assuntos', $this->autor)->assertOk()->assertJsonCount(2, 'dados.assuntos')
            ->assertJsonPath('dados.assuntos.1.unidade_negocio_nome', 'Manutenção fictícia');
    }

    #[Test]
    public function referencias_da_propria_empresa_e_anexos_seguros(): void
    {
        $outra = $this->criarEmpresa(['nif' => '5417200022']);
        $alheio = app(ContextoEmpresa::class)->executarComo($outra->id, fn () => Terceiro::create(['nome' => 'Fornecedor Alheio', 'nif' => '5000000733', 'tipo' => Terceiro::FORNECEDOR])->id);
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X', 'responsaveis' => [['tipo' => 'FORNECEDOR', 'ref_id' => $alheio, 'nome' => 'Fornecedor Alheio']]], $this->autor)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X', 'responsaveis' => [['tipo' => 'FORNECEDOR', 'ref_id' => $this->ids['cli'], 'nome' => 'Cliente Fictício']]], $this->autor)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X', 'anexos' => [['nome' => 'Mau', 'url' => 'javascript:alert(1)']]], $this->autor)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X', 'local_tipo' => 'EXTERNO', 'cliente_id' => $this->ids['forn']], $this->autor)
            ->assertStatus(422)->assertJsonPath('codigo', 'REFERENCIA_INVALIDA');
        // projecto novo interno precisa de centro de custo; externo da NE
        $id = $this->postJson('/api/metodo/assuntos', $this->completo(['metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'NOVO']), $this->autor)->assertCreated()->json('dados.assunto.id');
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'NOVO', 'validar_tudo' => true]), $this->autor)
            ->assertStatus(422)->assertJsonFragment(['HOW: o projecto interno precisa de centro de custo.']);
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['metodo_resolucao' => 'PROJECTO', 'projeto_modo' => 'NOVO', 'centro_custo_id' => $this->ids['cc'],
            'validar_tudo' => true]), $this->autor)->assertOk()->assertJsonPath('dados.assunto.centro_custo_id', $this->ids['cc']);
    }

    #[Test]
    public function estados_cancelar_com_motivo_reabrir_e_em_curso_so_pela_aprovacao(): void
    {
        $id = $this->postJson('/api/metodo/assuntos', $this->completo(), $this->autor)->assertCreated()->json('dados.assunto.id');
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'EM_CURSO'], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CONCLUIDO'], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'TRANSICAO_INVALIDA');
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CANCELADO'], $this->autor)->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_OBRIGATORIO');
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'CANCELADO', 'nota' => 'Já não é necessário'], $this->autor)->assertOk()
            ->assertJsonPath('dados.estado', 'CANCELADO')->assertJsonPath('dados.motivo_cancelamento', 'Já não é necessário')
            ->assertJsonPath('dados.permissoes.editar', false)->assertJsonPath('dados.permissoes.reabrir', true);
        // cancelado não se edita; reabre-se para rascunho (o registo nunca se apaga)
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(), $this->autor)->assertForbidden();
        $this->postJson("/api/metodo/assuntos/{$id}/estado", ['estado' => 'RASCUNHO'], $this->autor)->assertOk()->assertJsonPath('dados.estado', 'RASCUNHO');
        $acoes = array_column($this->getJson("/api/metodo/assuntos/{$id}/historico", $this->autor)->json('dados.registos'), 'acao');
        $this->assertSame(['Estado: Rascunho', 'Estado: Cancelado', 'Criado'], $acoes);
        $this->deleteJson("/api/metodo/assuntos/{$id}", [], $this->autor)->assertStatus(405);   // não há rota de eliminar
    }

    #[Test]
    public function visibilidade_permissoes_e_isolamento(): void
    {
        $id = $this->postJson('/api/metodo/assuntos', ['titulo' => 'Assunto do autor'], $this->autor)->assertCreated()->json('dados.assunto.id');

        // sem permissões do ecrã: 403 em tudo
        $nada = $this->sessao([]);
        $this->getJson('/api/metodo/assuntos', $nada)->assertForbidden();
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X'], $nada)->assertForbidden();
        // só ver: não cria; não vê assuntos de outros (não é criador, responsável nem aprovador)
        $leitor = $this->sessao(['mt_assuntos_view']);
        $this->postJson('/api/metodo/assuntos', ['titulo' => 'X'], $leitor)->assertForbidden();
        $this->getJson('/api/metodo/assuntos', $leitor)->assertOk()->assertJsonCount(0, 'dados.assuntos');
        $this->getJson("/api/metodo/assuntos/{$id}", $leitor)->assertForbidden();
        $this->putJson("/api/metodo/assuntos/{$id}", ['titulo' => 'Mudado'], $leitor)->assertForbidden();
        // o responsável (colaborador ligado) vê
        $ana = $this->sessao(['mt_assuntos_view'], null, $this->ids['ana']);
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['titulo' => 'Assunto do autor']), $this->autor)->assertOk();
        $this->getJson("/api/metodo/assuntos/{$id}", $ana)->assertOk()->assertJsonPath('dados.permissoes.editar', false);
        // o gestor (mt_aprovar) vê e edita tudo
        $gestor = $this->sessao(['mt_aprovar']);
        $this->getJson('/api/metodo/assuntos', $gestor)->assertOk()->assertJsonCount(1, 'dados.assuntos');
        $this->putJson("/api/metodo/assuntos/{$id}", $this->completo(['titulo' => 'Revisto pelo gestor']), $gestor)->assertOk();
        // outra empresa: 404
        $outra = $this->criarEmpresa(['nif' => '5417200033']);
        $this->getJson("/api/metodo/assuntos/{$id}", $this->sessao(['mt_aprovar'], $outra))->assertNotFound();
    }
}
