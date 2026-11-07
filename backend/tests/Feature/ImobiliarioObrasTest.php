<?php

namespace Tests\Feature;

use App\Services\Sistema\CatalogoPermissoes;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixturesImobiliarioF5;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 4 (F5): fichas de apetrechamento, bens da fracção, ordens de trabalho (numeração, aprovação, necessidades), pedido de
 * material sobre a requisição de Activos e o pedido de Compras sem duplicar, recepção ligada (entrega do armazém e recepção de Compras), bens que
 * entram no inventário só pelo apetrechamento, estados, débito ao inquilino na renda (idempotente, desfazível), ordem dos danos de uma reserva,
 * isolamento por empresa, permissões e restrições/idempotência da migração. Os números são calculados à mão. A data é fixada em 2026-03-20.
 */
final class ImobiliarioObrasTest extends TestCase
{
    use FixturesImobiliarioF5;

    private int $im;

    private int $fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarObras();
        $this->im = $this->imovel();
        $this->fa = $this->fracao($this->im, '101');
    }

    /** @param  list<array<string, mixed>>  $itens */
    private function criarFicha(string $nome = 'T2 mobilado', array $itens = []): int
    {
        return $this->postJson('/api/imobiliario/fichas', ['nome' => $nome, 'tipologia' => 'T2', 'finalidade' => 'HABITACAO', 'itens' => $itens ?: [
            ['produto_id' => $this->cama, 'quantidade' => 2], ['produto_id' => $this->colchao, 'quantidade' => 1], ['descricao' => 'Mesa de jantar', 'quantidade' => 1, 'obrigatorio' => false]]], $this->s)
            ->assertCreated()->json('dados.id');
    }

    // ---------------------------------------------------------------- fichas

    #[Test]
    public function ficha_de_apetrechamento_grava_com_artigos_nome_unico_e_sem_linhas_repetidas(): void
    {
        $id = $this->criarFicha();
        $f = $this->getJson("/api/imobiliario/fichas/{$id}", $this->s)->assertOk()->json('dados');
        $this->assertSame(3, $f['n_itens']);
        $this->assertSame(['CAMA — Cama de casal', 'COLCH — Colchão', 'Mesa de jantar'], array_column($f['itens'], 'nome'));
        $this->assertSame([2, 1, 1], array_column($f['itens'], 'quantidade'));
        $this->assertSame([true, true, false], array_column($f['itens'], 'obrigatorio'));
        $this->assertSame([true, true, false], array_column($f['itens'], 'de_armazem'));
        // nome em branco e nome repetido (sem distinguir maiúsculas)
        $this->postJson('/api/imobiliario/fichas', ['nome' => '  '], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FICHA_NOME');
        $this->postJson('/api/imobiliario/fichas', ['nome' => 'T2 MOBILADO', 'itens' => [['descricao' => 'Sofá']]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FICHA_DUPLICADA');
        $this->assertSame(1, DB::table('fichas_apetrechamento')->count());
        // o mesmo artigo duas vezes na ficha recusa-se; uma linha vazia descarta-se sem erro
        $this->postJson('/api/imobiliario/fichas', ['nome' => 'Loja', 'itens' => [['produto_id' => $this->cama], ['produto_id' => $this->cama, 'quantidade' => 3]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'FICHA_ITEM_REPETIDO');
        $this->postJson('/api/imobiliario/fichas', ['nome' => 'Loja', 'itens' => [['descricao' => 'Balcão'], ['descricao' => 'BALCÃO']]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FICHA_ITEM_REPETIDO');
        $loja = $this->postJson('/api/imobiliario/fichas', ['nome' => 'Loja', 'itens' => [['descricao' => 'Balcão'], ['produto_id' => null, 'descricao' => ' ']]], $this->s)->assertCreated()->json('dados');
        $this->assertSame(1, $loja['n_itens']);
        // editar substitui os artigos numa só transacção; sem a chave `itens` mantém-nos
        $this->putJson("/api/imobiliario/fichas/{$id}", ['nome' => 'T2 mobilado', 'activo' => false], $this->s)->assertOk()->assertJsonPath('dados.n_itens', 3)->assertJsonPath('dados.activo', false);
        $this->putJson("/api/imobiliario/fichas/{$id}", ['nome' => 'T2 mobilado', 'itens' => [['produto_id' => $this->colchao, 'quantidade' => 4]]], $this->s)->assertOk()->assertJsonPath('dados.n_itens', 1);
        $this->assertSame(1, DB::table('itens_ficha_apetrechamento')->where('ficha_id', $id)->count());
        $this->assertSame(1, count($this->getJson('/api/imobiliario/fichas?activo=0', $this->s)->assertOk()->json('dados')));
        $this->postJson('/api/imobiliario/fichas', ['nome' => 'Com artigo falso', 'itens' => [['produto_id' => 999999]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FICHA_ARTIGO');
    }

    #[Test]
    public function ficha_em_uso_nao_se_apaga_e_a_que_nunca_foi_usada_apaga_se(): void
    {
        $id = $this->criarFicha();
        $livre = $this->criarFicha('Escritório', [['descricao' => 'Secretária']]);
        $this->deleteJson("/api/imobiliario/fichas/{$livre}", [], $this->s)->assertOk();
        $this->assertSame(0, DB::table('itens_ficha_apetrechamento')->where('ficha_id', $livre)->count());
        $this->postJson("/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento", ['ficha_id' => $id], $this->s)->assertCreated();
        $this->deleteJson("/api/imobiliario/fichas/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FICHA_EM_USO');
    }

    // ---------------------------------------------------------------- bens e comparação

    #[Test]
    public function bens_da_fracao_guardam_quantidade_decimal_e_a_edicao_nao_perde_a_origem_nem_o_activo(): void
    {
        $r = $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['produto_id' => $this->cama, 'quantidade' => 0.5, 'estado' => 'USADO', 'notas' => 'Meia cama'], $this->s)->assertCreated();
        $this->assertSame(0.5, $r->json('dados.quantidade'));   // o legado arredondava 0,5 a 1
        $id = $r->json('dados.id');
        $this->assertSame('MANUAL', $r->json('dados.origem'));
        $ativo = (int) DB::table('ativos_imobilizados')->insertGetId(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'BEM', 'codigo' => 'AT1', 'descricao' => 'Frigorífico']);
        DB::table('bens_fracao')->where('id', $id)->update(['origem' => 'INVENTARIO', 'ativo_id' => $ativo]);
        $this->putJson("/api/imobiliario/fracoes/{$this->fa}/bens/{$id}", ['produto_id' => $this->cama, 'quantidade' => 1.25, 'estado' => 'BOM'], $this->s)->assertOk();
        $b = (array) DB::table('bens_fracao')->where('id', $id)->first();
        $this->assertSame(['1.250', 'BOM', 'INVENTARIO', $ativo], [$b['quantidade'], $b['estado'], $b['origem'], (int) $b['ativo_id']]);   // origem e activo preservados (legado repunha-os)
        // validações
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['quantidade' => 1], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Indique o artigo ou descreva o bem.');
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['descricao' => 'Candeeiro', 'estado' => 'ESTRAGADO'], $this->s)->assertStatus(422);
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['descricao' => 'Candeeiro', 'quantidade' => 0], $this->s)->assertStatus(422);
        $lista = $this->getJson("/api/imobiliario/fracoes/{$this->fa}/bens", $this->s)->assertOk()->json('dados');
        $this->assertSame(['CAMA — Cama de casal'], array_column($lista['bens'], 'nome'));
        $this->assertFalse($lista['fracao']['ligada_a_porta']);
        // outro bem de outra fracção não se mexe por esta
        $fb = $this->fracao($this->im, '102');
        $this->putJson("/api/imobiliario/fracoes/{$fb}/bens/{$id}", ['descricao' => 'X'], $this->s)->assertNotFound();
        $this->deleteJson("/api/imobiliario/fracoes/{$fb}/bens/{$id}", [], $this->s)->assertNotFound();
        $this->deleteJson("/api/imobiliario/fracoes/{$this->fa}/bens/{$id}", [], $this->s)->assertOk();
        $this->assertSame(0, DB::table('bens_fracao')->count());
    }

    #[Test]
    public function comparar_com_a_ficha_diz_o_que_falta_e_so_o_que_esta_em_condicoes_conta(): void
    {
        $ficha = $this->criarFicha();
        $h = ['quantidade' => 1, 'estado' => 'BOM'];
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['produto_id' => $this->cama] + $h, $this->s)->assertCreated();
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['produto_id' => $this->cama, 'estado' => 'DANIFICADO', 'quantidade' => 1], $this->s)->assertCreated();
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['descricao' => ' mesa de jantar ', 'quantidade' => 1, 'estado' => 'USADO'], $this->s)->assertCreated();
        $c = $this->getJson("/api/imobiliario/fracoes/{$this->fa}/comparar-ficha?ficha_id={$ficha}", $this->s)->assertOk()->json('dados');
        $l = collect($c['linhas'])->keyBy('nome');
        $this->assertSame(['2.000', '1.000', '1.000', 1], [$l['CAMA — Cama de casal']['precisa'], $l['CAMA — Cama de casal']['tem'], $l['CAMA — Cama de casal']['falta'], $l['CAMA — Cama de casal']['danificados']]);
        $this->assertSame('1.000', $l['COLCH — Colchão']['falta']);
        $this->assertSame('0.000', $l['Mesa de jantar']['falta']);
        $this->assertSame([2, 2, false], [$c['faltam'], $c['faltam_obrigatorios'], $c['completa']]);   // a cama e o colchão são obrigatórios; a mesa (opcional) está completa
        $this->getJson("/api/imobiliario/fracoes/{$this->fa}/comparar-ficha", $this->s)->assertStatus(422);
    }

    #[Test]
    public function trazer_do_imobilizado_so_traz_o_que_esta_na_porta_e_e_idempotente(): void
    {
        $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens/trazer-imobilizado", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'BEM_SEM_PORTA');
        $edificio = (int) DB::table('ativos_imobilizados')->insertGetId(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'EDIFICIO', 'propriedade' => 'PROPRIO', 'codigo' => 'ED1', 'descricao' => 'Sede']);
        $andar = (int) DB::table('andares_edificios')->insertGetId(['empresa_id' => $this->empresa->id, 'edificio_id' => $edificio, 'numero' => '1', 'nome' => '1.º andar']);
        $porta = (int) DB::table('portas_edificios')->insertGetId(['empresa_id' => $this->empresa->id, 'edificio_id' => $edificio, 'andar_id' => $andar, 'numero' => '101']);
        DB::table('fracoes')->where('id', $this->fa)->update(['porta_id' => $porta]);
        foreach ([['FR1', 'Frigorífico'], ['AC1', 'Ar condicionado']] as [$c, $d]) {
            DB::table('ativos_imobilizados')->insert(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'BEM', 'codigo' => $c, 'descricao' => $d, 'porta_id' => $porta, 'estado' => 'ACTIVO']);
        }
        DB::table('ativos_imobilizados')->insert(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'BEM', 'codigo' => 'AB1', 'descricao' => 'Abatido', 'porta_id' => $porta, 'estado' => 'ABATIDO']);
        DB::table('ativos_imobilizados')->insert(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'EDIFICIO', 'codigo' => 'ED2', 'descricao' => 'Anexo', 'porta_id' => $porta]);
        $r = $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens/trazer-imobilizado", [], $this->s)->assertOk();
        $this->assertSame([2, 2], [$r->json('dados.criados'), $r->json('dados.encontrados')]);   // sem o abatido nem o edifício
        $r2 = $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens/trazer-imobilizado", [], $this->s)->assertOk();
        $this->assertSame([0, 2], [$r2->json('dados.criados'), $r2->json('dados.encontrados')]);
        $this->assertSame(2, DB::table('bens_fracao')->where('origem', 'INVENTARIO')->count());
        $this->assertSame(['Ar condicionado', 'Frigorífico'], DB::table('bens_fracao')->orderBy('descricao')->pluck('descricao')->all());
    }

    // ---------------------------------------------------------------- ordens

    #[Test]
    public function a_ordem_numera_por_serie_e_ano_deriva_o_imovel_da_fracao_e_valida_os_dados(): void
    {
        $a = $this->ordem(['fracao_id' => $this->fa, 'imovel_id' => null, 'quem_paga' => 'SENHORIO', 'debitavel' => true, 'prioridade' => 'ALTA']);
        $this->assertSame(['OB 2026/0001', 'ABERTA', $this->im, false], [$a['numero'], $a['estado'], $a['imovel_id'], $a['debitavel']]);   // senhorio nunca é debitável
        $b = $this->ordem(['tipo' => 'APETRECHAMENTO', 'fracao_id' => $this->fa]);
        $c = $this->ordem(['tipo' => 'PREVENTIVA']);
        $d = $this->ordem(['tipo' => 'VISTORIA']);
        $this->assertSame(['AP 2026/0001', 'OB 2026/0002', 'OB 2026/0003'], [$b['numero'], $c['numero'], $d['numero']]);
        $this->assertNull($c['fracao_id']);
        // a fracção não pode ser de outro imóvel
        $im2 = $this->imovel('Outro prédio');
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X', 'imovel_id' => $im2, 'fracao_id' => $this->fa], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_FRACCAO_IMOVEL');
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_LOCAL');
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => '  ', 'imovel_id' => $this->im], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Descreva o trabalho.');
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X', 'imovel_id' => $this->im, 'data_pedido' => '2026-03-20', 'data_prevista' => '2026-03-10'], $this->s)
            ->assertStatus(422)->assertJsonPath('mensagem', 'A data prevista é anterior à do pedido.');
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X', 'imovel_id' => $this->im, 'tipo' => 'DEMOLICAO'], $this->s)->assertStatus(422);
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X', 'imovel_id' => 999999], $this->s)->assertStatus(422);
        $this->postJson('/api/imobiliario/obras/ordens', ['descricao' => 'X', 'imovel_id' => $this->im, 'custo_estimado' => -1], $this->s)->assertStatus(422);
        // numeração de um novo ano recomeça (a sequência é por série e ano)
        $this->travelTo(Carbon::parse('2027-01-05 10:00:00'));
        $this->s = $this->sessao(array_merge($this->perms, self::PERMS_OBRAS));
        $this->assertSame('OB 2027/0001', $this->ordem()['numero']);
        // a lista
        $l = $this->getJson('/api/imobiliario/obras/ordens?tipo=APETRECHAMENTO', $this->s)->assertOk()->json('dados');
        $this->assertSame(['AP 2026/0001'], array_column($l, 'numero'));
        $this->assertSame(['OB 2027/0001'], array_column($this->getJson('/api/imobiliario/obras/ordens?texto=2027', $this->s)->json('dados'), 'numero'));
    }

    #[Test]
    public function aprovar_e_idempotente_e_alterar_o_custo_retira_a_aprovacao(): void
    {
        $o = $this->ordem(['custo_estimado' => 50000]);
        $this->assertFalse($o['aprovada']);
        $this->assertSame([], $o['necessidades_lista']);
        $r = $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/aprovar", [], $this->s)->assertOk();
        $quando = $r->json('dados.aprovada_em');
        $this->assertNotNull($quando);
        $this->assertNotNull($r->json('dados.aprovada_por'));
        $this->travelTo(Carbon::parse('2026-03-20 15:00:00'));
        $this->s = $this->sessao(array_merge($this->perms, self::PERMS_OBRAS));
        $this->assertSame($quando, $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/aprovar", [], $this->s)->assertOk()->json('dados.aprovada_em'));   // repetir não muda quem aprovou nem quando
        // mudar só a descrição mantém; mudar o custo retira
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}", ['descricao' => 'Reparar a canalização (revisto)'], $this->s)->assertOk()->assertJsonPath('dados.aprovada_em', $quando);
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}", ['descricao' => 'Reparar a canalização', 'custo_estimado' => 60000], $this->s)->assertOk()->assertJsonPath('dados.aprovada_em', null);
        $ev = array_column($this->ficha($o['id'])['historico'], 'evento');
        $this->assertSame(['CRIADA', 'APROVADA', 'EDITADA', 'EDITADA'], $ev);
        $this->assertSame(1, count(array_filter($this->ficha($o['id'])['historico'], fn ($h) => str_contains((string) $h['motivo'], 'Aprovação retirada'))));
    }

    #[Test]
    public function eliminar_so_a_ordem_aberta_sem_material_e_sem_historico_de_trabalho(): void
    {
        $a = $this->ordem();
        $this->deleteJson("/api/imobiliario/obras/ordens/{$a['id']}", [], $this->s)->assertOk();
        $this->assertSame(0, DB::table('ordens_trabalho_imo')->count());
        $c = $this->ordem();
        $this->postJson("/api/imobiliario/obras/ordens/{$c['id']}/concluir", [], $this->s)->assertOk();
        $this->deleteJson("/api/imobiliario/obras/ordens/{$c['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_CONCLUIDA');   // o legado apagava ordens concluídas
        $m = $this->ordem();
        $this->necessidades($m['id'], [['produto_id' => $this->servico, 'quantidade' => 1]]);
        $this->aprovar($m['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$m['id']}/pedir", [], $this->s)->assertOk();
        $this->deleteJson("/api/imobiliario/obras/ordens/{$m['id']}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_COM_MATERIAL');
        $this->putJson("/api/imobiliario/obras/ordens/{$c['id']}", ['descricao' => 'Alterar'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_FECHADA');
    }

    // ---------------------------------------------------------------- necessidades e pedido de material

    #[Test]
    public function necessidades_gravam_so_as_por_pedir_e_recusam_artigos_repetidos(): void
    {
        $o = $this->ordem();
        $r = $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->cama, 'quantidade' => 2.5], ['descricao' => 'Cortinas', 'quantidade' => 4], ['descricao' => '', 'produto_id' => null]]], $this->s)->assertOk();
        $this->assertSame(['CAMA — Cama de casal', 'Cortinas'], array_column($r->json('dados'), 'nome'));
        $this->assertSame('2.500', $r->json('dados.0.quantidade'));   // decimal (o legado cortava a 2 casas e arredondava em bens)
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->cama], ['produto_id' => $this->cama]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NECESSIDADE_REPETIDA');
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->cama, 'quantidade' => 0.0001]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NECESSIDADE_QUANTIDADE');
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => 999999]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NECESSIDADE_ARTIGO');
        // as linhas já pedidas ficam: grava-se de novo só o que está por pedir
        $this->darStock($this->cama, '10');
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->cama, 'quantidade' => 1]]], $this->s)->assertOk();
        $this->aprovar($o['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->colchao, 'quantidade' => 1]]], $this->s)->assertOk();
        $this->assertSame(['CAMA — Cama de casal', 'COLCH — Colchão'], array_column($this->ficha($o['id'])['necessidades_lista'], 'nome'));
        // o artigo já pedido não volta a entrar como linha nova
        $this->putJson("/api/imobiliario/obras/ordens/{$o['id']}/necessidades", ['linhas' => [['produto_id' => $this->cama, 'quantidade' => 1]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'NECESSIDADE_REPETIDA');
    }

    #[Test]
    public function o_pedido_de_material_reparte_pelo_stock_e_reutiliza_a_requisicao_e_o_pedido_de_compra_sem_duplicar(): void
    {
        $this->darStock($this->cama, '3');
        $o = $this->ordem(['fracao_id' => $this->fa]);
        $id = $o['id'];
        $this->necessidades($id, [['produto_id' => $this->cama, 'quantidade' => 5], ['produto_id' => $this->colchao, 'quantidade' => 2], ['produto_id' => $this->servico, 'quantidade' => 1], ['descricao' => 'Cortinas', 'quantidade' => 4]]);
        $plano = collect($this->getJson("/api/imobiliario/obras/ordens/{$id}/plano-pedido?armazem_id={$this->armazem}", $this->s)->assertOk()->json('dados'))->keyBy('nome');
        $this->assertSame(['3.000', '2.000', 'O stock só dá para 3 — a falta vai a compras'], [$plano['CAMA — Cama de casal']['para_armazem'], $plano['CAMA — Cama de casal']['para_compra'], $plano['CAMA — Cama de casal']['porque']]);
        $this->assertSame(['0.000', '2.000', 'Material sem stock'], [$plano['COLCH — Colchão']['para_armazem'], $plano['COLCH — Colchão']['para_compra'], $plano['COLCH — Colchão']['porque']]);
        $this->assertSame(['0.000', '1.000'], [$plano['MONT — Montagem de mobiliário']['para_armazem'], $plano['MONT — Montagem de mobiliário']['para_compra']]);
        $this->assertTrue($plano['Cortinas']['bloqueada']);
        // sem aprovação não se pede (e nada fica criado)
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_SEM_APROVACAO');
        $this->aprovar($id);
        // com stock por requisitar o armazém é obrigatório, e a falha não deixa nada criado
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'ARMAZEM_EM_FALTA');
        $this->assertSame([0, 0], [DB::table('requisicoes_manutencao')->count(), DB::table('pedidos_compra')->count()]);
        $r = $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem, 'requerente' => 'Rita Gestora'], $this->s)->assertOk();
        $this->assertSame([1, 3, 'RQ 2026/0001'], [$r->json('dados.armazem'), $r->json('dados.compra'), $r->json('dados.requisicao_numero')]);
        // a requisição é a de Manutenção de Activos (sem ordem de manutenção) e traz a referência da obra
        $req = (array) DB::table('requisicoes_manutencao')->first();
        $this->assertNull($req['ordem_manutencao_id']);
        $this->assertSame('PENDENTE', $req['estado']);
        $this->assertStringContainsString('Rita Gestora (Imobiliária OB 2026/0001 — PR0001 · 101)', $req['requerente']);
        $lr = (array) DB::table('linhas_requisicao_manutencao')->where('requisicao_manutencao_id', $req['id'])->first();
        $this->assertSame(['3.000', '0.000'], [$lr['quantidade'], $lr['quantidade_entregue']]);
        // o pedido de compra é o do circuito normal (3 linhas: 2 camas, 2 colchões, 1 montagem)
        $pc = (array) DB::table('pedidos_compra')->first();
        $this->assertSame('PENDENTE', $pc['estado']);
        $this->assertStringContainsString('OB 2026/0001', (string) $pc['descricao']);
        $linhas = DB::table('itens_compra')->where('pedido_compra_id', $pc['id'])->orderBy('id')->get(['produto_id', 'quantidade'])->map(fn ($x) => [(int) $x->produto_id, (float) $x->quantidade])->all();
        $this->assertSame([[$this->cama, 2.0], [$this->colchao, 2.0], [$this->servico, 1.0]], $linhas);
        // estados e ligações das necessidades
        $n = $this->porNome($id);
        $this->assertSame(['PEDIDO_COMPRA', '3.000', '2.000', $req['id']], [$n['CAMA — Cama de casal']['estado'], $n['CAMA — Cama de casal']['quantidade_armazem'], $n['CAMA — Cama de casal']['quantidade_compra'], $n['CAMA — Cama de casal']['requisicao_manutencao_id']]);
        $this->assertSame(['PEDIDO_COMPRA', null, $pc['id']], [$n['COLCH — Colchão']['estado'], $n['COLCH — Colchão']['requisicao_manutencao_id'], $n['COLCH — Colchão']['pedido_compra_id']]);
        $this->assertSame('POR_PEDIR', $n['Cortinas']['estado']);   // sem artigo: fica por pedir até haver artigo
        $this->assertSame('RQ 2026/0001', $n['CAMA — Cama de casal']['requisicao_numero']);
        $this->assertSame(['AGUARDA_MATERIAL', 1], [$this->ficha($id)['estado'], $this->ficha($id)['por_pedir']]);
        // repetir não duplica: só resta a linha bloqueada
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_SEM_NECESSIDADES');
        $this->assertSame([1, 1, 3], [DB::table('requisicoes_manutencao')->count(), DB::table('pedidos_compra')->count(), DB::table('itens_compra')->count()]);
        // uma linha só de serviço vai só a compras e dispensa o armazém
        $s = $this->ordem();
        $this->necessidades($s['id'], [['produto_id' => $this->servico, 'quantidade' => 2]]);
        $this->aprovar($s['id']);
        $r2 = $this->postJson("/api/imobiliario/obras/ordens/{$s['id']}/pedir", [], $this->s)->assertOk();
        $this->assertSame([0, 1, null], [$r2->json('dados.armazem'), $r2->json('dados.compra'), $r2->json('dados.requisicao_id')]);
    }

    #[Test]
    public function a_recepcao_e_ligada_a_entrega_do_armazem_e_a_recepcao_de_compras_e_o_custo_real_le_se_delas(): void
    {
        $this->darStock($this->cama, '3', '500');
        $o = $this->ordem(['fracao_id' => $this->fa]);
        $id = $o['id'];
        $this->necessidades($id, [['produto_id' => $this->cama, 'quantidade' => 5], ['produto_id' => $this->colchao, 'quantidade' => 2], ['produto_id' => $this->servico, 'quantidade' => 1]]);
        $this->aprovar($id);
        $r = $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $pedido = $r->json('dados.pedido_compra_id');
        // nada chegou: sincronizar não muda nada
        $x = $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $this->assertSame(['0.000', 0], [$x->json('dados.recebido'), $x->json('dados.bens_criados')]);
        // o armazém entrega as 3 camas (3 × 500 = 1 500 de custo médio), pelo ecrã de Manutenção de Activos
        $this->entregarRequisicao($r->json('dados.requisicao_id'));
        $x = $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $this->assertSame('3.000', $x->json('dados.recebido'));
        $n = $this->porNome($id);
        $this->assertSame(['3.000', 'PEDIDO_COMPRA', '2.000'], [$n['CAMA — Cama de casal']['quantidade_recebida'], $n['CAMA — Cama de casal']['estado'], $n['CAMA — Cama de casal']['falta_receber']]);
        $this->assertSame('1500.00', $this->ficha($id)['custo_real']['armazem']);
        // as Compras recebem 2 camas (a 450), 1 de 2 colchões (a 8 000) e a montagem (a 3 000)
        $this->receberCompras($pedido, [$this->cama => ['2', '450', '2'], $this->colchao => ['2', '8000', '1'], $this->servico => ['1', '3000', '1']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $n = $this->porNome($id);
        $this->assertSame(['5.000', 'RECEBIDO'], [$n['CAMA — Cama de casal']['quantidade_recebida'], $n['CAMA — Cama de casal']['estado']]);
        $this->assertSame(['1.000', 'PEDIDO_COMPRA', '1.000'], [$n['COLCH — Colchão']['quantidade_recebida'], $n['COLCH — Colchão']['estado'], $n['COLCH — Colchão']['falta_receber']]);
        $this->assertSame('RECEBIDO', $n['MONT — Montagem de mobiliário']['estado']);
        $this->assertSame('AGUARDA_MATERIAL', $this->ficha($id)['estado']);   // falta 1 colchão
        // custo real: armazém 1 500 + compras (2 × 450 + 1 × 8 000 + 1 × 3 000 = 11 900) = 13 400
        $this->assertSame(['1500.00', '11900.00', '13400.00'], array_values($this->ficha($id)['custo_real']));
        // repetir a sincronização não muda nada (idempotente)
        $antes = DB::table('necessidades_ordem_imo')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $this->assertSame($antes, DB::table('necessidades_ordem_imo')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all());
        // chega o último colchão: a ordem passa a «em curso»; reparação não põe bens no inventário (só o apetrechamento)
        $this->receberCompras($pedido, [$this->colchao => ['2', '8000', '2']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk()->assertJsonPath('dados.bens_criados', 0);
        $this->assertSame('EM_CURSO', $this->ficha($id)['estado']);
        $this->assertSame(0, DB::table('bens_fracao')->count());
        // concluir já não tem nada por receber
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CONCLUIDA')->assertJsonPath('dados.data_conclusao', '2026-03-20');
    }

    #[Test]
    public function o_apetrechamento_poe_no_inventario_so_a_diferenca_recebida_e_a_ficha_fica_completa(): void
    {
        $ficha = $this->criarFicha('T2 mobilado', [['produto_id' => $this->cama, 'quantidade' => 2], ['produto_id' => $this->colchao, 'quantidade' => 1]]);
        $this->postJson("/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento", [], $this->s)->assertStatus(422);
        $this->darStock($this->cama, '2');
        $r = $this->postJson("/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento", ['ficha_id' => $ficha, 'prioridade' => 'ALTA'], $this->s)->assertCreated();
        $this->assertSame(2, $r->json('dados.necessidades'));
        $o = $r->json('dados.ordem');
        $this->assertSame(['AP 2026/0001', 'APETRECHAMENTO', 'ALTA', $ficha], [$o['numero'], $o['tipo'], $o['prioridade'], $o['ficha_id']]);
        $this->assertSame('Apetrechar 101 segundo a ficha «T2 mobilado»', $o['descricao']);
        $id = $o['id'];
        $this->assertSame(['2.000', '1.000'], array_column($this->ficha($id)['necessidades_lista'], 'quantidade'));
        $this->aprovar($id);
        $p = $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->entregarRequisicao($p->json('dados.requisicao_id'));
        $x = $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $this->assertSame(1, $x->json('dados.bens_criados'));
        $bem = (array) DB::table('bens_fracao')->where('produto_id', $this->cama)->first();
        $this->assertSame(['2.000', 'ARMAZEM', 'NOVO', (int) $this->fa], [$bem['quantidade'], $bem['origem'], $bem['estado'], (int) $bem['fracao_id']]);
        $this->assertSame('AGUARDA_MATERIAL', $this->ficha($id)['estado']);   // falta o colchão
        // repetir não duplica o bem
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk()->assertJsonPath('dados.bens_criados', 0);
        $this->assertSame(1, DB::table('bens_fracao')->count());
        // o colchão chega por Compras
        $this->receberCompras($p->json('dados.pedido_compra_id'), [$this->colchao => ['1', '8000', '1']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk()->assertJsonPath('dados.bens_criados', 1);
        $colch = (array) DB::table('bens_fracao')->where('produto_id', $this->colchao)->first();
        $this->assertSame(['1.000', 'COMPRA', $this->ficha($id)['necessidades_lista'][1]['id']], [$colch['quantidade'], $colch['origem'], (int) $colch['necessidade_id']]);
        $this->assertSame('EM_CURSO', $this->ficha($id)['estado']);
        $this->assertSame([2.0, 1.0], DB::table('bens_fracao')->orderBy('id')->pluck('quantidade')->map(fn ($q) => (float) $q)->all());
        $c = $this->getJson("/api/imobiliario/fracoes/{$this->fa}/comparar-ficha?ficha_id={$ficha}", $this->s)->assertOk()->json('dados');
        $this->assertTrue($c['completa']);
        $this->postJson("/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento", ['ficha_id' => $ficha], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_NADA_FALTA');
        // uma recepção de Compras anulada faz recuar a quantidade recebida (a necessidade volta a «em pedido de compra»)
        $this->receberCompras($p->json('dados.pedido_compra_id'), [$this->colchao => ['1', '8000', '0']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $this->assertSame('PEDIDO_COMPRA', $this->porNome($id)['COLCH — Colchão']['estado']);
    }

    #[Test]
    public function a_requisicao_cancelada_e_o_pedido_anulado_libertam_a_parte_que_lhes_coube(): void
    {
        $this->darStock($this->cama, '3');
        $o = $this->ordem();
        $id = $o['id'];
        $this->necessidades($id, [['produto_id' => $this->cama, 'quantidade' => 3], ['produto_id' => $this->colchao, 'quantidade' => 2]]);
        $this->aprovar($id);
        $p = $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->assertSame([1, 1], [$p->json('dados.armazem'), $p->json('dados.compra')]);
        $this->postJson("/api/ativos/manutencao/requisicoes/{$p->json('dados.requisicao_id')}/cancelar", ['motivo' => 'Já não é preciso'], $this->sArm)->assertOk();
        DB::table('pedidos_compra')->where('id', $p->json('dados.pedido_compra_id'))->update(['estado' => 'ANULADO']);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/sincronizar", [], $this->s)->assertOk();
        $n = $this->porNome($id);
        $this->assertSame(['POR_PEDIR', null, null], [$n['CAMA — Cama de casal']['estado'], $n['CAMA — Cama de casal']['requisicao_manutencao_id'], $n['CAMA — Cama de casal']['pedido_compra_id']]);
        $this->assertSame('POR_PEDIR', $n['COLCH — Colchão']['estado']);
        // e podem pedir-se de novo
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk()->assertJsonPath('dados.armazem', 1);
    }

    // ---------------------------------------------------------------- estados

    #[Test]
    public function iniciar_concluir_e_cancelar_seguem_a_matriz_de_estados(): void
    {
        $o = $this->ordem(['data_pedido' => '2026-03-01']);
        $id = $o['id'];
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/iniciar", [], $this->s)->assertOk()->assertJsonPath('dados.estado', 'EM_CURSO');
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/iniciar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_ESTADO');
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/cancelar", [], $this->s)->assertStatus(422)->assertJsonPath('mensagem', 'Diga porque cancela a ordem.');
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", ['data' => '2026-03-21'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_DATAS');   // futura
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", ['data' => '2026-02-28'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_DATAS');   // antes do pedido
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", ['data' => '2026-03-05'], $this->s)->assertOk()->assertJsonPath('dados.data_conclusao', '2026-03-05');
        foreach (['iniciar', 'concluir', 'aprovar'] as $acao) {
            $this->postJson("/api/imobiliario/obras/ordens/{$id}/{$acao}", [], $this->s)->assertStatus(422);
        }
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/cancelar", ['motivo' => 'x'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_FECHADA');   // uma ordem fechada não reabre nem muda de estado
        $c = $this->ordem();
        $this->necessidades($c['id'], [['descricao' => 'Tinta', 'quantidade' => 3]]);
        $this->postJson("/api/imobiliario/obras/ordens/{$c['id']}/cancelar", ['motivo' => 'Inquilino desistiu'], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CANCELADA')->assertJsonPath('dados.motivo_cancelamento', 'Inquilino desistiu');
        $this->assertSame(['CANCELADO'], array_column($this->ficha($c['id'])['necessidades_lista'], 'estado'));
        $this->assertSame(['CRIADA', 'NECESSIDADES', 'CANCELADA'], array_column($this->ficha($c['id'])['historico'], 'evento'));
        $resumo = $this->getJson('/api/imobiliario/obras/resumo', $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 1, 0, 0], [$resumo['total'], $resumo['concluidas'], $resumo['abertas'], $resumo['por_debitar']]);
    }

    #[Test]
    public function concluir_com_material_por_receber_recusa_a_menos_que_se_cancele_o_que_falta(): void
    {
        $this->darStock($this->cama, '2');
        $o = $this->ordem();
        $id = $o['id'];
        $this->necessidades($id, [['produto_id' => $this->cama, 'quantidade' => 2], ['descricao' => 'Tinta', 'quantidade' => 3]]);
        $this->aprovar($id);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_PENDENTES')
            ->assertJsonPath('mensagem', 'A ordem tem 1 necessidade(s) por receber (requisição ou pedido de compra).');
        $this->assertSame('AGUARDA_MATERIAL', $this->ficha($id)['estado']);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", ['cancelar_pendentes' => true], $this->s)->assertOk()->assertJsonPath('dados.estado', 'CONCLUIDA');
        $this->assertSame(['CANCELADO', 'CANCELADO'], array_values(array_column($this->ficha($id)['necessidades_lista'], 'estado')));
        $h = collect($this->ficha($id)['historico'])->firstWhere('evento', 'CONCLUIDA');
        $this->assertSame('Concluída com necessidades por receber, canceladas.', $h['motivo']);
    }

    // ---------------------------------------------------------------- débito ao inquilino

    /** Contrato activo na fracção `fa` com a renda de Março (100 000, IVA 0) gerada. @return array{0: array<string, mixed>, 1: int} o contrato e a renda */
    private function comRenda(): array
    {
        $this->configurar();
        $c = $this->contrato($this->corpo($this->fa, ['inicio' => '2026-03-01']));
        $this->postJson("/api/imobiliario/contratos/{$c['id']}/rendas/gerar", [], $this->s)->assertOk();

        return [$c, (int) DB::table('rendas_arrendamento')->where('contrato_id', $c['id'])->value('id')];
    }

    /** @return array<string, mixed> a renda */
    private function renda(int $id): array
    {
        return (array) DB::table('rendas_arrendamento')->where('id', $id)->first();
    }

    #[Test]
    public function debitar_ao_inquilino_acrescenta_o_encargo_na_renda_uma_so_vez_e_desfaz_se(): void
    {
        [, $renda] = $this->comRenda();
        $o = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO', 'debitavel' => true, 'custo_estimado' => 30000, 'descricao' => 'Reparar a janela partida']);
        $id = $o['id'];
        $this->assertTrue($o['debitavel']);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_DEBITO_ESTADO');   // só concluída
        $this->aprovar($id);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/concluir", [], $this->s)->assertOk();
        $this->assertTrue($this->ficha($id)['por_debitar']);
        $r = $this->postJson("/api/imobiliario/obras/ordens/{$id}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.repetido', false);
        $this->assertSame([30000.0, $renda], [$r->json('dados.valor'), $r->json('dados.renda.id')]);
        $x = $this->renda($renda);
        $this->assertSame(['30000.00', '130000.00', '15000.00', 'PREVISTA'], [$x['extras_total'], $x['total_facturar'], $x['ip_valor'], $x['estado']]);   // IP e selo não crescem com o encargo
        $extras = json_decode($x['extras'], true);
        $this->assertSame([['Obra OB 2026/0001: Reparar a janela partida', '30000.00', 'OBRA:'.$id]], array_map(fn ($e) => [$e['designacao'], $e['valor'], $e['origem']], $extras));
        $d = (array) DB::table('ordens_trabalho_imo')->where('id', $id)->first();
        $this->assertSame([$renda, '30000.00'], [(int) $d['debitado_renda_id'], $d['debitado_valor']]);
        $this->assertFalse($this->ficha($id)['por_debitar']);
        // repetir (duplo clique, pedido repetido) devolve o débito existente e não acrescenta nada
        $r2 = $this->postJson("/api/imobiliario/obras/ordens/{$id}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.repetido', true);
        $this->assertSame(30000.0, $r2->json('dados.valor'));
        $this->assertSame(1, count(json_decode($this->renda($renda)['extras'], true)));
        $this->assertSame('130000.00', $this->renda($renda)['total_facturar']);
        // a ordem debitada não se cancela nem se apaga
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/cancelar", ['motivo' => 'x'], $this->s)->assertStatus(422);
        $this->deleteJson("/api/imobiliario/obras/ordens/{$id}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_CONCLUIDA');
        // desfazer: retira o encargo e a ordem volta a poder ser debitada; repetir desfazer não faz nada
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/desfazer-debito", [], $this->s)->assertOk()->assertJsonPath('dados.debitado_em', null);
        $x = $this->renda($renda);
        $this->assertSame(['0.00', '100000.00', 'PREVISTA'], [$x['extras_total'], $x['total_facturar'], $x['estado']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/desfazer-debito", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$id}/debitar", ['valor' => 12000.5, 'designacao' => 'Janela (parte do inquilino)'], $this->s)->assertOk()->assertJsonPath('dados.repetido', false);
        $this->assertSame(['12000.50', '112000.50'], [$this->renda($renda)['extras_total'], $this->renda($renda)['total_facturar']]);
    }

    #[Test]
    public function o_valor_por_omissao_e_o_custo_real_e_o_partilhado_paga_metade_e_o_senhorio_nao_se_debita(): void
    {
        [, $renda] = $this->comRenda();
        // custo real: 3 camas entregues pelo armazém a 500 = 1 500 (a estimativa de 99 999 não conta)
        $this->darStock($this->cama, '3', '500');
        $o = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO', 'custo_estimado' => 99999]);
        $this->necessidades($o['id'], [['produto_id' => $this->cama, 'quantidade' => 3]]);
        $this->aprovar($o['id']);
        $p = $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/pedir", ['armazem_id' => $this->armazem], $this->s)->assertOk();
        $this->entregarRequisicao($p->json('dados.requisicao_id'));
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.valor', 1500.0);
        // partilhado: metade da estimativa (25 001 / 2 = 12 500,50)
        $p2 = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'PARTILHADO', 'custo_estimado' => 25001]);
        $this->aprovar($p2['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$p2['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$p2['id']}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.valor', 12500.5);
        $this->assertSame(['14000.50', '114000.50'], [$this->renda($renda)['extras_total'], $this->renda($renda)['total_facturar']]);
        // senhorio paga: não se debita; sem aprovação não se debita; sem custo não se debita
        $s = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'SENHORIO', 'custo_estimado' => 5000]);
        $this->aprovar($s['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$s['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$s['id']}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_NAO_DEBITAVEL');
        $n = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO', 'custo_estimado' => 5000]);
        $this->postJson("/api/imobiliario/obras/ordens/{$n['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$n['id']}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_SEM_APROVACAO');
        $z = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO']);
        $this->aprovar($z['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$z['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$z['id']}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_DEBITO_VALOR');
        $this->assertSame(2, count(json_decode($this->renda($renda)['extras'], true)));
    }

    #[Test]
    public function o_debito_exige_contrato_e_renda_por_facturar_e_a_renda_facturada_nao_se_mexe(): void
    {
        $o = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO', 'custo_estimado' => 8000]);
        $this->aprovar($o['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_SEM_CONTRATO');   // sem inquilino
        [, $renda] = $this->comRenda();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.renda.id', $renda);
        // a renda debitada aprova-se e factura-se: o encargo vai na factura e já não se desfaz aqui
        $this->postJson('/api/imobiliario/rendas/aprovar', ['ids' => [$renda]], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/rendas/facturar', ['ids' => [$renda]], $this->s)->assertOk();
        $this->assertSame(['FACTURADA', '108000.00'], [$this->renda($renda)['estado'], $this->renda($renda)['total_facturar']]);
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/desfazer-debito", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_EM_DOCUMENTO');
        $this->assertNotNull(DB::table('ordens_trabalho_imo')->where('id', $o['id'])->value('debitado_em'));
        // outra obra: já não há renda por facturar; a renda facturada indicada à mão também se recusa
        $b = $this->ordem(['fracao_id' => $this->fa, 'quem_paga' => 'INQUILINO', 'custo_estimado' => 1000]);
        $this->aprovar($b['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$b['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$b['id']}/debitar", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_SEM_RENDA');
        $this->postJson("/api/imobiliario/obras/ordens/{$b['id']}/debitar", ['renda_id' => $renda], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_RENDA_EM_DOCUMENTO');
        $this->assertNull(DB::table('ordens_trabalho_imo')->where('id', $b['id'])->value('debitado_em'));
        // uma renda de outro inquilino não serve
        $fb = $this->fracao($this->im, '102');
        $c2 = $this->contrato($this->corpo($fb, ['inicio' => '2026-03-01', 'terceiro_id' => $this->cliente2]));
        $this->postJson("/api/imobiliario/contratos/{$c2['id']}/rendas/gerar", [], $this->s)->assertOk();
        $outra = (int) DB::table('rendas_arrendamento')->where('contrato_id', $c2['id'])->value('id');
        $this->postJson("/api/imobiliario/obras/ordens/{$b['id']}/debitar", ['renda_id' => $outra], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_RENDA_OUTRO_CONTRATO');
        $this->assertSame('0.00', $this->renda($outra)['extras_total']);
    }

    // ---------------------------------------------------------------- danos de reservas

    /** Regista uma vistoria e devolve o id dela (a resposta é a ficha da reserva: a vistoria é a última da lista). */
    private function vistoriar(int $reserva, array $d): int
    {
        $v = $this->postJson("/api/imobiliario/reservas/{$reserva}/vistoria", $d, $this->s)->assertCreated()->json('dados.vistorias');

        return (int) end($v)['id'];
    }

    /** Reserva gratuita de uma sala por uma fracção com contrato; devolve a reserva. */
    private function reservaComVistoria(array $vistoria, array $espaco = []): array
    {
        $this->configurar();
        $e = $this->postJson('/api/imobiliario/areas/espacos', $espaco + ['nome' => 'Sala de reuniões', 'imovel_id' => $this->im, 'tipo' => 'REUNIOES', 'regime' => 'RESERVA_GRATUITA', 'capacidade' => 20], $this->s)->assertCreated()->json('dados.id');
        $this->contrato($this->corpo($this->fa, ['inicio' => '2026-03-01']));
        $r = $this->postJson('/api/imobiliario/reservas', ['espaco_id' => $e, 'fracao_id' => $this->fa, 'data' => '2026-03-27', 'hora_inicio' => '14:00', 'hora_fim' => '16:00', 'pessoas' => 10, 'motivo' => 'Reunião'], $this->s)->assertCreated()->json('dados.id');
        $v = $this->vistoriar($r, $vistoria + ['quando' => 'DEPOIS', 'estado' => 'DANIFICADO', 'observacoes' => 'Mesa partida', 'custo_danos' => 5000]);

        return ['reserva' => $r, 'vistoria' => $v];
    }

    #[Test]
    public function os_danos_de_uma_reserva_abrem_uma_so_ordem_de_reparacao_por_vistoria(): void
    {
        ['reserva' => $r, 'vistoria' => $v] = $this->reservaComVistoria([]);
        $x = $this->postJson("/api/imobiliario/obras/vistorias/{$v}/ordem", [], $this->s)->assertOk();
        $this->assertTrue($x->json('dados.nova'));
        $o = $x->json('dados.ordem');
        $this->assertSame(['CORRECTIVA', 5000.0, 'INQUILINO', true, $this->im, null, $v], [$o['tipo'], $o['custo_estimado'], $o['quem_paga'], $o['debitavel'], $o['imovel_id'], $o['fracao_id'], $o['vistoria_reserva_id']]);
        $this->assertStringContainsString('Reparar os danos da reserva de Sala de reuniões em 27/03/2026', $o['descricao']);
        $this->assertStringContainsString('Mesa partida', $o['descricao']);
        // repetir devolve a mesma (idempotente)
        $y = $this->postJson("/api/imobiliario/obras/vistorias/{$v}/ordem", [], $this->s)->assertOk();
        $this->assertSame([false, $o['id']], [$y->json('dados.nova'), $y->json('dados.ordem.id')]);
        $this->assertSame(1, DB::table('ordens_trabalho_imo')->count());
        // a vistoria de entrada, ou sem danos, não dá ordem
        $e = $this->vistoriar($r, ['quando' => 'ANTES', 'estado' => 'BOM']);
        $this->postJson("/api/imobiliario/obras/vistorias/{$e}/ordem", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_VISTORIA_SEM_DANOS');
        $b = $this->vistoriar($r, ['quando' => 'DEPOIS', 'estado' => 'SUJO']);
        $this->postJson("/api/imobiliario/obras/vistorias/{$b}/ordem", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'OBRA_VISTORIA_SEM_DANOS');
        $this->postJson('/api/imobiliario/obras/vistorias/999999/ordem', [], $this->s)->assertNotFound();
        // a ordem do inquilino aprova-se, conclui-se e debita-se na renda (liga a F3 e a F4)
        $this->postJson('/api/imobiliario/contratos/'.DB::table('contratos_arrendamento')->value('id').'/rendas/gerar', [], $this->s)->assertOk();
        $this->aprovar($o['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/concluir", [], $this->s)->assertOk();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/debitar", [], $this->s)->assertOk()->assertJsonPath('dados.valor', 5000.0);
    }

    #[Test]
    public function com_caucao_retida_ou_sem_contrato_a_ordem_dos_danos_fica_por_conta_do_senhorio(): void
    {
        ['reserva' => $r, 'vistoria' => $v] = $this->reservaComVistoria([]);
        DB::table('reservas_espaco')->where('id', $r)->update(['caucao' => 5000, 'caucao_recebida' => 5000, 'caucao_retida' => 2000]);   // parte do custo já foi retida da caução (F4)
        $o = $this->postJson("/api/imobiliario/obras/vistorias/{$v}/ordem", [], $this->s)->assertOk()->json('dados.ordem');
        $this->assertSame(['SENHORIO', false], [$o['quem_paga'], $o['debitavel']]);
        $this->assertStringContainsString('não se debita outra vez', (string) $o['notas']);
    }

    // ---------------------------------------------------------------- listas, indicadores e avisos

    #[Test]
    public function listas_artigos_armazens_indicadores_e_avisos(): void
    {
        $l = $this->getJson('/api/imobiliario/obras/listas', $this->s)->assertOk()->json('dados');
        $this->assertSame(['APETRECHAMENTO', 'PREVENTIVA', 'CORRECTIVA', 'MELHORIA', 'VISTORIA'], array_column($l['tipos'], 'chave'));
        $this->assertSame(['ABERTA', 'AGUARDA_MATERIAL', 'EM_CURSO', 'CONCLUIDA', 'CANCELADA'], array_column($l['estados'], 'chave'));
        $this->assertSame('Aguarda material', $l['estados'][1]['rotulo']);
        $this->assertSame(['PREVENTIVA', 'VISTORIA'], $l['tipos_planeaveis']);
        $a = $this->getJson('/api/imobiliario/obras/artigos?texto=cama', $this->s)->assertOk()->json('dados');
        $this->assertSame([['id' => $this->cama, 'codigo' => 'CAMA', 'nome' => 'Cama de casal', 'de_armazem' => true]], $a);
        $this->assertSame([['id' => $this->armazem, 'nome' => 'Armazém central']], $this->getJson('/api/imobiliario/obras/armazens', $this->s)->assertOk()->json('dados'));
        // indicadores e avisos: 1 atrasada, 1 urgente por aprovar, 1 por debitar
        $this->ordem(['prioridade' => 'URGENTE', 'data_pedido' => '2026-03-01', 'data_prevista' => '2026-03-10']);
        $d = $this->ordem(['quem_paga' => 'INQUILINO', 'custo_estimado' => 10]);
        $this->aprovar($d['id']);
        $this->postJson("/api/imobiliario/obras/ordens/{$d['id']}/concluir", [], $this->s)->assertOk();
        $r = $this->getJson('/api/imobiliario/obras/resumo', $this->s)->assertOk()->json('dados');
        $this->assertSame([2, 1, 1, 1, 1, 1, 1], [$r['total'], $r['abertas'], $r['concluidas'], $r['urgentes'], $r['atrasadas'], $r['por_debitar'], $r['por_aprovar']]);
        $tipos = array_column($this->getJson('/api/imobiliario/obras/avisos', $this->s)->assertOk()->json('dados'), 'tipo');
        $this->assertEqualsCanonicalizing(['ORDEM_ATRASADA', 'URGENTE_SEM_APROVACAO', 'POR_DEBITAR'], $tipos);
        $lista = collect($this->getJson('/api/imobiliario/obras/ordens', $this->s)->json('dados'))->keyBy('id');
        $this->assertTrue($lista[$d['id'] - 1]['em_atraso']);
        $this->assertTrue($lista[$d['id']]['por_debitar']);
        $this->assertSame(1, count($this->getJson('/api/imobiliario/obras/ordens?por_debitar=1', $this->s)->json('dados')));
    }

    // ---------------------------------------------------------------- isolamento, permissões e base de dados

    #[Test]
    public function outra_empresa_nao_ve_nem_toca_nas_fichas_ordens_bens_e_planos(): void
    {
        $ficha = $this->criarFicha();
        $o = $this->ordem(['fracao_id' => $this->fa]);
        $this->necessidades($o['id'], [['descricao' => 'Tinta', 'quantidade' => 1]]);
        $plano = $this->postJson('/api/imobiliario/planos', ['nome' => 'Pintura', 'descricao' => 'Pintar', 'imovel_id' => $this->im, 'primeira_data' => '2026-06-01', 'quantas' => 2, 'periodicidade' => 'ANUAL'], $this->s)->assertCreated()->json('dados.id');
        $bem = $this->postJson("/api/imobiliario/fracoes/{$this->fa}/bens", ['descricao' => 'Candeeiro'], $this->s)->assertCreated()->json('dados.id');
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $h = $this->sessao(array_merge($this->perms, self::PERMS_OBRAS), $outra);
        $this->assertSame([], $this->getJson('/api/imobiliario/fichas', $h)->assertOk()->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/obras/ordens', $h)->assertOk()->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/planos', $h)->assertOk()->json('dados'));
        $this->assertSame(0, $this->getJson('/api/imobiliario/obras/resumo', $h)->json('dados.total'));
        foreach ([['get', "/api/imobiliario/fichas/{$ficha}"], ['put', "/api/imobiliario/fichas/{$ficha}"], ['delete', "/api/imobiliario/fichas/{$ficha}"],
            ['get', "/api/imobiliario/obras/ordens/{$o['id']}"], ['put', "/api/imobiliario/obras/ordens/{$o['id']}"], ['delete', "/api/imobiliario/obras/ordens/{$o['id']}"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/aprovar"], ['get', "/api/imobiliario/obras/ordens/{$o['id']}/necessidades"], ['put', "/api/imobiliario/obras/ordens/{$o['id']}/necessidades"],
            ['get', "/api/imobiliario/obras/ordens/{$o['id']}/plano-pedido"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/pedir"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/sincronizar"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/iniciar"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/concluir"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/cancelar"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/debitar"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/desfazer-debito"],
            ['get', "/api/imobiliario/planos/{$plano}"], ['put', "/api/imobiliario/planos/{$plano}"], ['delete', "/api/imobiliario/planos/{$plano}"], ['get', "/api/imobiliario/planos/{$plano}/calendario"],
            ['post', "/api/imobiliario/planos/{$plano}/gerar"], ['get', "/api/imobiliario/fracoes/{$this->fa}/bens"], ['post', "/api/imobiliario/fracoes/{$this->fa}/bens"],
            ['put', "/api/imobiliario/fracoes/{$this->fa}/bens/{$bem}"], ['delete', "/api/imobiliario/fracoes/{$this->fa}/bens/{$bem}"], ['post', "/api/imobiliario/fracoes/{$this->fa}/bens/trazer-imobilizado"],
            ['get', "/api/imobiliario/fracoes/{$this->fa}/comparar-ficha?ficha_id={$ficha}"], ['post', "/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento"]] as [$m, $url]) {
            ($m === 'get' ? $this->getJson($url, $h) : $this->{$m.'Json'}($url, $m === 'delete' ? [] : ['descricao' => 'x', 'nome' => 'x', 'ficha_id' => $ficha, 'linhas' => []], $h))->assertNotFound();
        }
        // a ficha de uma empresa não serve a ordem de apetrechamento de outra
        $fb = $this->fracao($this->imovel('Prédio B', $h), '201', $h);
        $this->postJson("/api/imobiliario/obras/fracoes/{$fb}/abrir-apetrechamento", ['ficha_id' => $ficha], $h)->assertNotFound();
        $this->assertSame(1, DB::table('ordens_trabalho_imo')->count());
    }

    #[Test]
    public function todas_as_accoes_recusam_quem_nao_tem_a_permissao_antes_de_validar(): void
    {
        $o = $this->ordem(['fracao_id' => $this->fa]);
        $ficha = $this->criarFicha();
        $plano = $this->postJson('/api/imobiliario/planos', ['nome' => 'Pintura', 'descricao' => 'Pintar', 'imovel_id' => $this->im, 'primeira_data' => '2026-06-01'], $this->s)->assertCreated()->json('dados.id');
        $nada = $this->sessao(['imo_imoveis_view']);
        foreach ([['get', '/api/imobiliario/obras/listas'], ['get', '/api/imobiliario/obras/artigos'], ['get', '/api/imobiliario/obras/armazens'], ['get', '/api/imobiliario/obras/resumo'],
            ['get', '/api/imobiliario/obras/avisos'], ['get', '/api/imobiliario/obras/ordens'], ['post', '/api/imobiliario/obras/ordens'], ['get', "/api/imobiliario/obras/ordens/{$o['id']}"],
            ['put', "/api/imobiliario/obras/ordens/{$o['id']}"], ['delete', "/api/imobiliario/obras/ordens/{$o['id']}"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/aprovar"],
            ['get', "/api/imobiliario/obras/ordens/{$o['id']}/necessidades"], ['put', "/api/imobiliario/obras/ordens/{$o['id']}/necessidades"], ['get', "/api/imobiliario/obras/ordens/{$o['id']}/plano-pedido"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/pedir"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/sincronizar"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/iniciar"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/concluir"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/cancelar"], ['post', "/api/imobiliario/obras/ordens/{$o['id']}/debitar"],
            ['post', "/api/imobiliario/obras/ordens/{$o['id']}/desfazer-debito"], ['post', "/api/imobiliario/obras/fracoes/{$this->fa}/abrir-apetrechamento"], ['post', '/api/imobiliario/obras/vistorias/1/ordem'],
            ['get', '/api/imobiliario/fichas'], ['post', '/api/imobiliario/fichas'], ['get', "/api/imobiliario/fichas/{$ficha}"], ['put', "/api/imobiliario/fichas/{$ficha}"], ['delete', "/api/imobiliario/fichas/{$ficha}"],
            ['get', "/api/imobiliario/fracoes/{$this->fa}/bens"], ['post', "/api/imobiliario/fracoes/{$this->fa}/bens"], ['put', "/api/imobiliario/fracoes/{$this->fa}/bens/1"],
            ['delete', "/api/imobiliario/fracoes/{$this->fa}/bens/1"], ['post', "/api/imobiliario/fracoes/{$this->fa}/bens/trazer-imobilizado"], ['get', "/api/imobiliario/fracoes/{$this->fa}/comparar-ficha"],
            ['get', '/api/imobiliario/planos'], ['post', '/api/imobiliario/planos'], ['get', '/api/imobiliario/planos/propor-datas'], ['post', '/api/imobiliario/planos/gerar-todos'],
            ['get', "/api/imobiliario/planos/{$plano}"], ['put', "/api/imobiliario/planos/{$plano}"], ['delete', "/api/imobiliario/planos/{$plano}"], ['get', "/api/imobiliario/planos/{$plano}/calendario"],
            ['post', "/api/imobiliario/planos/{$plano}/gerar"]] as [$m, $url]) {
            ($m === 'get' ? $this->getJson($url, $nada) : $this->{$m.'Json'}($url, [], $nada))->assertForbidden();
        }
    }

    #[Test]
    public function quem_aprova_nao_recebe_quem_debita_nao_aprova_rendas_e_os_perfis_modelo_respeitam_os_pares(): void
    {
        $pares = collect(CatalogoPermissoes::segregacao())->map(fn ($s) => $s['a'].'×'.$s['b'])->all();
        $this->assertContains('imo_obra_aprovar×imo_obra_receber', $pares);
        $this->assertContains('imo_sub_debitar×imo_renda_aprovar', $pares);
        $modelos = collect(CatalogoPermissoes::modelos())->keyBy('nome');
        $gi = $modelos['Gestor Imobiliário']['permissoes'];
        $go = $modelos['Gestor de Obras do Imóvel']['permissoes'];
        $this->assertTrue(! empty($gi['imo_obra_aprovar']) && ! empty($gi['imo_renda_aprovar']) && empty($gi['imo_obra_receber']) && empty($gi['imo_sub_debitar']));
        $this->assertTrue(! empty($go['imo_obra_receber']) && ! empty($go['imo_sub_debitar']) && ! empty($go['imo_obra_pedir']) && empty($go['imo_obra_aprovar']) && empty($go['imo_renda_aprovar']));
        foreach (['imo_obra_aprovar', 'imo_obra_pedir', 'imo_sub_debitar'] as $k) {
            $this->assertTrue(CatalogoPermissoes::tarefas()[$k]['sensivel'], $k);
        }
        // sem a permissão de aprovar não se aprova, mesmo com todas as outras das obras
        $sem = $this->sessao(array_values(array_diff(self::PERMS_OBRAS, ['imo_obra_aprovar'])));
        $o = $this->ordem();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/aprovar", [], $sem)->assertForbidden();
        $sem2 = $this->sessao(array_values(array_diff(self::PERMS_OBRAS, ['imo_sub_debitar'])));
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/debitar", [], $sem2)->assertForbidden();
        $this->postJson("/api/imobiliario/obras/ordens/{$o['id']}/sincronizar", [], $this->sessao(['imo_obras_view']))->assertForbidden();
    }

    #[Test]
    public function restricoes_da_base_de_dados_e_idempotencia_da_migracao(): void
    {
        $o = $this->ordem(['fracao_id' => $this->fa]);
        $plano = $this->postJson('/api/imobiliario/planos', ['nome' => 'Pintura', 'descricao' => 'Pintar', 'imovel_id' => $this->im, 'datas' => ['2026-06-01']], $this->s)->assertCreated()->json('dados.id');
        $linha = (array) DB::table('ordens_trabalho_imo')->where('id', $o['id'])->first();
        unset($linha['id']);
        $tenta = function (array $troca, string $esperado) use ($linha) {
            try {
                DB::transaction(fn () => DB::table('ordens_trabalho_imo')->insert($troca + $linha));
                $this->fail("Devia ter sido recusado: {$esperado}");
            } catch (QueryException $e) {
                $this->assertStringContainsString($esperado, $e->getMessage());
            }
        };
        $tenta([], 'uq_ordens_imo_numero');   // o mesmo número
        $tenta(['numero' => 'OB 2026/9001', 'estado' => 'ARQUIVADA'], 'ck_ordens_imo_valores');
        $tenta(['numero' => 'OB 2026/9002', 'quem_paga' => 'SENHORIO', 'debitavel' => true], 'ck_ordens_imo_valores');   // o senhorio nunca é debitável
        $tenta(['numero' => 'OB 2026/9003', 'data_pedido' => '2026-03-20', 'data_prevista' => '2026-03-10'], 'ck_ordens_imo_valores');
        $tenta(['numero' => 'OB 2026/9004', 'debitado_em' => now(), 'debitado_renda_id' => null], 'ck_ordens_imo_valores');   // debitada sem renda
        // nunca duas ordens para a mesma data do mesmo plano
        DB::table('ordens_trabalho_imo')->insert(['numero' => 'OB 2026/9005', 'plano_id' => $plano, 'plano_data' => '2026-06-01'] + $linha);
        $tenta(['numero' => 'OB 2026/9006', 'plano_id' => $plano, 'plano_data' => '2026-06-01'], 'uq_ordens_imo_plano_data');
        // as restantes tabelas
        $ficha = $this->criarFicha();
        try {
            DB::transaction(fn () => DB::table('itens_ficha_apetrechamento')->insert(['empresa_id' => $this->empresa->id, 'ficha_id' => $ficha, 'quantidade' => 1]));
            $this->fail('Um item sem artigo nem descrição devia ser recusado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ck_itens_ficha_valores', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('bens_fracao')->insert(['empresa_id' => $this->empresa->id, 'fracao_id' => $this->fa, 'descricao' => 'X', 'quantidade' => 0, 'data' => '2026-03-20']));
            $this->fail('Uma quantidade zero devia ser recusada.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ck_bens_fracao_valores', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('necessidades_ordem_imo')->insert(['empresa_id' => $this->empresa->id, 'ordem_id' => $o['id'], 'descricao' => 'X', 'quantidade' => 1, 'estado' => 'PERDIDO']));
            $this->fail('Um estado desconhecido devia ser recusado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ck_neces_imo_valores', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('planos_manutencao_imo')->insert(['empresa_id' => $this->empresa->id, 'nome' => 'X', 'tipo' => 'CORRECTIVA', 'descricao' => 'X', 'imovel_id' => $this->im]));
            $this->fail('Um plano de reparação devia ser recusado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ck_planos_imo_valores', $e->getMessage());
        }
        $antes = [DB::table('ordens_trabalho_imo')->count(), DB::table('fichas_apetrechamento')->count()];
        $migracao = require base_path('database/migrations/2026_12_19_090000_imobiliario_onda4_obras_apetrechamento.php');
        $migracao->up();
        $migracao->up();
        $this->assertSame($antes, [DB::table('ordens_trabalho_imo')->count(), DB::table('fichas_apetrechamento')->count()]);
        // RLS: todas as tabelas novas têm política por empresa; nenhuma tabela existente foi alterada (as ligações ficam nas necessidades)
        foreach (['fichas_apetrechamento', 'itens_ficha_apetrechamento', 'bens_fracao', 'planos_manutencao_imo', 'ordens_trabalho_imo', 'necessidades_ordem_imo', 'historico_ordem_imo'] as $t) {
            $this->assertTrue((bool) DB::scalar('select relrowsecurity from pg_class where relname = ?', [$t]), "{$t} sem RLS");
        }
        $this->assertSame([], DB::select("select column_name from information_schema.columns where table_name in ('requisicoes_manutencao', 'linhas_requisicao_manutencao', 'pedidos_compra', 'itens_compra', 'vistorias_reserva') and column_name in ('ordem_obra_imo_id', 'necessidade_imo_id', 'ordem_trabalho_id', 'ordem_trabalho_imo_id')"));
    }
}
