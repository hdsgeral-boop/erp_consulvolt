<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\CRM\ServicoMigracaoCRM;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CRM (ADR-054): configuração inicial (funil «Vendas» e modelos), regras dos funis, contas e contactos, oportunidades com
 * tarefas automáticas e sequências, mudança de etapa (perda com motivo), saúde e quadro, prospect → cliente, conversão
 * e ligação a documentos de venda, emails e campanhas (sem envio real), previsão, indicadores e pós-carga da ETL.
 */
final class CRMTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private int $produto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000054', 'nome' => 'Empresa CRM, Lda']);
        $this->produto = app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            PlanoConta::create(['codigo' => '311', 'descricao' => 'Clientes', 'tipo' => 'M']);
            PlanoConta::create(['codigo' => '611', 'descricao' => 'Vendas', 'tipo' => 'M']);

            return Produto::create(['codigo' => 'SRV1', 'nome' => 'Serviço', 'preco_unitario' => 1000, 'taxa_imposto' => 14, 'codigo_conta' => '611'])->id;
        });
        $this->s = $this->sessao(['crm_pipeline_view', 'crm_agenda_view', 'crm_contas_view', 'crm_previsao_view', 'crm_campanhas_view', 'crm_config_view',
            'crm_editar', 'crm_converter', 'crm_configurar', 'crm_campanhas_enviar']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function funil(): array
    {
        return $this->getJson('/api/crm/funis', $this->s)->assertOk()->json('dados.0');
    }

    private function etapa(array $funil, string $nome): string
    {
        return collect($funil['etapas'])->firstWhere('nome', $nome)['id'];
    }

    private function prospect(string $nome = 'Cliente Potencial, SA', array $extra = []): int
    {
        return $this->postJson('/api/crm/contas', ['nome' => $nome, 'origem' => 'Website'] + $extra, $this->s)->assertCreated()->json('dados.id');
    }

    private function atividades(int $opp): array
    {
        return $this->getJson("/api/crm/atividades?oportunidade_crm_id={$opp}&por_pagina=100", $this->s)->assertOk()->json('dados');
    }

    #[Test]
    public function primeira_utilizacao_e_regras_dos_funis(): void
    {
        $s = $this->s;
        $f = $this->funil();
        $this->assertSame('Vendas', $f['nome']);
        $this->assertSame(['Lead', 'Qualificação', 'Proposta enviada', 'Negociação', 'Ganha', 'Perdida'], array_column($f['etapas'], 'nome'));
        $modelos = $this->getJson('/api/crm/modelos-email', $s)->assertOk()->json('dados');
        $this->assertSame(['Apresentação', 'Envio de proposta', 'Seguimento'], array_column($modelos, 'nome'));
        $this->assertSame(collect($modelos)->firstWhere('nome', 'Envio de proposta')['id'], $f['etapas'][2]['tarefas'][0]['modelo_email_crm_id']);
        $this->getJson('/api/crm/funis', $s)->assertJsonCount(1, 'dados');   // não volta a criar

        $etapas = $f['etapas'];
        $etapas[4]['tipo'] = 'ABERTA';
        $this->putJson("/api/crm/funis/{$f['id']}", ['nome' => 'Vendas', 'etapas' => $etapas], $s)->assertStatus(422)->assertJsonPath('codigo', 'ETAPAS_FECHO');
        $conta = $this->prospect();
        $this->postJson('/api/crm/oportunidades', ['titulo' => 'Projecto A', 'conta_crm_id' => $conta, 'funil_vendas_crm_id' => $f['id'], 'valor' => 100], $s)->assertCreated();
        $this->putJson("/api/crm/funis/{$f['id']}", ['nome' => 'Vendas', 'etapas' => array_slice($f['etapas'], 1)], $s)->assertStatus(422)->assertJsonPath('codigo', 'ETAPA_EM_USO');
        $this->deleteJson("/api/crm/funis/{$f['id']}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'FUNIL_COM_OPORTUNIDADES');
        $novo = $this->postJson('/api/crm/funis', ['nome' => 'Projectos', 'etapas' => [['nome' => 'Contacto', 'tipo' => 'ABERTA', 'probabilidade' => 20],
            ['nome' => 'Ganho', 'tipo' => 'GANHA', 'probabilidade' => 100], ['nome' => 'Perdido', 'tipo' => 'PERDIDA']]], $s)->assertCreated()->json('dados');
        $this->assertSame(8, strlen($novo['etapas'][0]['id']));
        $this->deleteJson("/api/crm/funis/{$novo['id']}", [], $s)->assertOk();
        $this->putJson('/api/crm/configuracao', ['motivos_perda' => "Preço\nPreço\nPrazo", 'origens' => ['Website', 'Feira'], 'dias_sem_atividade' => 5], $s)
            ->assertOk()->assertJsonPath('dados.motivos_perda', ['Preço', 'Prazo'])->assertJsonPath('dados.prazo_pagamento_dias', 30);
        $this->putJson('/api/crm/configuracao', ['motivos_perda' => ''], $s)->assertStatus(422);
        $this->putJson('/api/crm/configuracao', ['motivos_perda' => ['Preço']], $this->sessao(['crm_config_view']))->assertForbidden();
    }

    #[Test]
    public function contas_contactos_e_oportunidade_com_tarefas_sequencias_e_perda(): void
    {
        $s = $this->s;
        $f = $this->funil();
        $conta = $this->prospect('Alfa, Lda', ['nif' => '5000999999', 'email' => 'geral@alfa.test']);
        $this->postJson('/api/crm/contas', ['nome' => 'Outra', 'nif' => '5000999999'], $s)->assertStatus(422)->assertJsonPath('codigo', 'NIF_DUPLICADO');
        $this->postJson('/api/crm/contas', ['nome' => 'Outra', 'email' => 'x@'], $s)->assertStatus(422)->assertJsonPath('codigo', 'EMAIL_INVALIDO');
        $this->getJson("/api/crm/contas/{$conta}", $s)->assertOk()->assertJsonPath('dados.conta.tipo', 'PROSPECT')->assertJsonPath('dados.conta.origem', 'SITE')
            ->assertJsonPath('dados.conta.origem_original', 'Website');
        $c1 = $this->postJson("/api/crm/contas/{$conta}/contactos", ['nome' => 'Contacto Um', 'email' => 'um@alfa.test', 'principal' => true], $s)->assertCreated()->json('dados.id');
        $c2 = $this->postJson("/api/crm/contas/{$conta}/contactos", ['nome' => 'Contacto Dois', 'principal' => true], $s)->assertCreated()->json('dados.id');
        $this->assertSame([$c2 => true, $c1 => false], collect($this->getJson("/api/crm/contas/{$conta}/contactos", $s)->json('dados'))->pluck('principal', 'id')->all());

        // sequência na etapa «Proposta enviada»
        $modelo = collect($this->getJson('/api/crm/modelos-email', $s)->json('dados'))->firstWhere('nome', 'Seguimento')['id'];
        $this->postJson('/api/crm/sequencias', ['nome' => 'Seguimento de propostas', 'funil_vendas_crm_id' => $f['id'], 'etapa_codigo' => $this->etapa($f, 'Proposta enviada'),
            'passos' => [['dias' => 3, 'modelo_email_crm_id' => $modelo], ['dias' => 7, 'modelo_email_crm_id' => $modelo]]], $s)->assertCreated();

        // oportunidade com linhas: valor = Σ quantidade × preço; nasce em «Lead» com a tarefa automática
        $o = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Consultoria', 'conta_crm_id' => $conta, 'contacto_crm_id' => $c1, 'funil_vendas_crm_id' => $f['id'],
            'origem' => 'Recomendação', 'itens' => [['produto_id' => $this->produto, 'quantidade' => 2, 'preco' => 1500.5], ['descricao' => 'Deslocação', 'quantidade' => 1, 'preco' => 99]]], $s)
            ->assertCreated()->assertJsonPath('dados.valor', '3100.00')->assertJsonPath('dados.estado', 'ABERTA')->assertJsonPath('dados.origem', 'RECOMENDACAO')->json('dados');
        $this->assertSame([['CHAMADA', 'Primeiro contacto com o cliente']], array_map(fn ($a) => [$a['tipo'], $a['titulo']], $this->atividades($o['id'])));
        $this->getJson("/api/crm/oportunidades/{$o['id']}", $s)->assertOk()->assertJsonPath('dados.probabilidade_efectiva', '10.0000')
            ->assertJsonPath('dados.valor_ponderado', '310.00')->assertJsonPath('dados.saude.nivel', 'OK');
        $this->postJson('/api/crm/oportunidades', ['titulo' => 'X', 'conta_crm_id' => $this->prospect('Beta'), 'contacto_crm_id' => $c1, 'funil_vendas_crm_id' => $f['id']], $s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CONTACTO_INVALIDO');

        // «Proposta enviada»: 2 tarefas da etapa + 2 passos da sequência
        $this->postJson("/api/crm/oportunidades/{$o['id']}/etapa", ['etapa_codigo' => $this->etapa($f, 'Proposta enviada')], $s)->assertOk()->assertJsonPath('dados.tarefas', 4);
        $this->postJson("/api/crm/oportunidades/{$o['id']}/etapa", ['etapa_codigo' => $this->etapa($f, 'Perdida')], $s)->assertStatus(422)->assertJsonPath('codigo', 'MOTIVO_OBRIGATORIO');
        $this->postJson("/api/crm/oportunidades/{$o['id']}/etapa", ['etapa_codigo' => $this->etapa($f, 'Perdida'), 'motivo_perda' => 'Preço', 'concorrente' => 'Concorrente X'], $s)
            ->assertOk()->assertJsonPath('dados.oportunidade.estado', 'PERDIDA')->assertJsonPath('dados.oportunidade.motivo_perda', 'Preço');
        $acts = $this->atividades($o['id']);
        $this->assertCount(5, $acts);
        $this->assertTrue(collect($acts)->every(fn ($a) => $a['concluida'] && $a['resultado'] === 'Cancelada: oportunidade perdida'));
        $this->assertSame(2, collect($acts)->whereNotNull('sequencia_campanha_id')->count());
        // reabrir limpa o fecho
        $this->postJson("/api/crm/oportunidades/{$o['id']}/etapa", ['etapa_codigo' => $this->etapa($f, 'Negociação')], $s)->assertOk()
            ->assertJsonPath('dados.oportunidade.estado', 'ABERTA')->assertJsonPath('dados.oportunidade.motivo_perda', null)->assertJsonPath('dados.oportunidade.fechado_em', null);
        $this->assertSame(['Lead', 'Proposta enviada', 'Perdida', 'Negociação'], array_map(fn ($h) => collect($f['etapas'])->firstWhere('id', $h['etapa_codigo'])['nome'],
            $this->getJson("/api/crm/oportunidades/{$o['id']}", $s)->json('dados.oportunidade.historico')));

        // actividades: nota nasce concluída; concluir/reabrir; agenda
        $this->postJson('/api/crm/atividades', ['tipo' => 'NOTA', 'oportunidade_crm_id' => $o['id'], 'descricao' => 'Cliente pediu desconto'], $s)
            ->assertCreated()->assertJsonPath('dados.concluida', true)->assertJsonPath('dados.conta_crm_id', $conta)->assertJsonPath('dados.titulo', 'Nota');
        $t = $this->postJson('/api/crm/atividades', ['tipo' => 'CHAMADA', 'oportunidade_crm_id' => $o['id'], 'data_prevista' => now()->subDays(2)->toDateString()], $s)->json('dados.id');
        $this->assertSame([$t], array_column($this->getJson('/api/crm/agenda?dias=7', $s)->json('dados.em_atraso'), 'id'));
        $this->assertSame([$t], array_column($this->getJson('/api/crm/atividades?estado=VENCIDAS', $s)->json('dados'), 'id'));
        $this->getJson("/api/crm/oportunidades/{$o['id']}", $s)->assertJsonPath('dados.saude.nivel', 'ATENCAO');
        $this->postJson("/api/crm/atividades/{$t}/concluir", ['resultado' => 'Reunião marcada'], $s)->assertOk()->assertJsonPath('dados.concluida', true);
        $this->getJson("/api/crm/oportunidades/{$o['id']}", $s)->assertJsonPath('dados.saude.nivel', 'OK');   // a reunião da etapa continua agendada
        $this->postJson("/api/crm/atividades/{$t}/reabrir", [], $s)->assertOk()->assertJsonPath('dados.concluida', false);

        // quadro: abertas + fechadas há ≤ 30 dias, ponderado pela probabilidade da etapa (75 %)
        $q = $this->getJson("/api/crm/funis/{$f['id']}/quadro", $s)->assertOk()->json('dados');
        $this->assertSame(['abertas' => 1, 'valor' => '3100.00', 'ponderado' => '2325.00', 'em_risco' => 0, 'atencao' => 1], $q['resumo']);
        $this->assertSame(1, collect($q['etapas'])->firstWhere('etapa.nome', 'Negociação')['n']);
        $this->getJson("/api/crm/funis/{$f['id']}/quadro", $this->sessao(['crm_agenda_view']))->assertForbidden();
    }

    #[Test]
    public function prospect_passa_a_cliente_conversao_e_ligacao_ao_documento_de_venda(): void
    {
        $s = $this->s;
        $f = $this->funil();
        $conta = $this->prospect('Gama, SA', ['nif' => '5000888888', 'email' => 'compras@gama.test', 'morada' => 'Rua 1']);
        $o = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Fornecimento', 'conta_crm_id' => $conta, 'funil_vendas_crm_id' => $f['id'],
            'itens' => [['produto_id' => $this->produto, 'quantidade' => 3, 'preco' => 1000]]], $s)->json('dados.id');
        $this->getJson("/api/crm/oportunidades/{$o}/conversao?tipo_documento=FT", $s)->assertStatus(422)->assertJsonPath('codigo', 'PROSPECT_SEM_CLIENTE');
        $this->postJson("/api/crm/contas/{$conta}/converter-em-cliente", ['codigo_conta' => '611'], $s)->assertStatus(422)->assertJsonPath('codigo', 'CONTA_CLIENTE_INVALIDA');
        $cliente = $this->postJson("/api/crm/contas/{$conta}/converter-em-cliente", ['codigo_conta' => '311'], $s)->assertOk()
            ->assertJsonPath('dados.tipo', 'CLIENTE')->json('dados.terceiro_id');
        $t = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DB::table('terceiros')->where('id', $cliente)->first());
        $this->assertSame(['Gama, SA', '5000888888', '311', 'CLIENTE', 'Rua 1'], [$t->nome, $t->nif, $t->codigo_conta, $t->tipo, $t->endereco]);
        // a conta de um cliente existente não se duplica
        $this->assertSame($conta, $this->postJson('/api/crm/contas/do-cliente', ['terceiro_id' => $cliente], $s)->assertOk()->json('dados.id'));

        // factura em atraso do cliente: só avisa
        $ft = fn (string $n, string $tipo, string $total, string $pendente, string $venc) => DB::table('vendas')->insertGetId(['empresa_id' => $this->empresa->id, 'cliente_id' => $cliente,
            'tipo_documento' => $tipo, 'numero_documento' => $n, 'data_emissao' => now()->subDays(60), 'total_bruto' => $total, 'valor_pendente' => $pendente,
            'data_vencimento' => $venc, 'estado' => 'PENDENTE']);
        $ft('FT 2026/1', 'FT', '5000.00', '2000.00', now()->subDays(10)->toDateString());
        $c = $this->getJson("/api/crm/oportunidades/{$o}/conversao?tipo_documento=FT", $s)->assertOk()->json('dados');
        $this->assertSame(['FT', $cliente, $o], [$c['tipo_documento'], $c['cliente_id'], $c['oportunidade_crm_id']]);
        $this->assertSame([['produto_id' => $this->produto, 'quantidade' => 3, 'preco_unitario' => 1000, 'descricao' => null]], $c['linhas']);
        $this->assertSame(['em_atraso' => '2000.00', 'n_atrasadas' => 1, 'max_dias_atraso' => 10], $c['aviso_atraso']);
        $fin = $this->getJson("/api/crm/contas/{$conta}", $s)->json('dados.financeiro');
        $this->assertSame(['2000.00', '2000.00', '5000.00'], [$fin['em_aberto'], $fin['em_atraso'], $fin['facturado_12m']]);

        // orçamento emitido: liga-se sem ganhar; factura: ganha
        $or = $ft('OR 2026/9', 'OR', '3420.00', '3420.00', now()->toDateString());
        $this->postJson("/api/crm/oportunidades/{$o}/documentos", ['venda_id' => $or], $s)->assertOk()->assertJsonPath('dados.estado', 'ABERTA')
            ->assertJsonPath('dados.vendas.0.tipo_documento', 'OR');
        $ft2 = $ft('FT 2026/2', 'FT', '3420.00', '3420.00', now()->addDays(30)->toDateString());
        $this->postJson("/api/crm/oportunidades/{$o}/documentos", ['venda_id' => $ft2], $s)->assertOk()->assertJsonPath('dados.estado', 'GANHA');
        $this->assertSame([$o, $o], app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => DB::table('vendas')->whereIn('id', [$or, $ft2])->orderBy('id')->pluck('oportunidade_crm_id')->all()));
        $this->assertSame(2, collect($this->atividades($o))->where('tipo', 'NOTA')->count());
        $outra = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Outra', 'conta_crm_id' => $conta, 'funil_vendas_crm_id' => $f['id']], $s)->json('dados.id');
        $this->postJson("/api/crm/oportunidades/{$outra}/documentos", ['venda_id' => $ft2], $s)->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_LIGADO');
        $this->deleteJson("/api/crm/oportunidades/{$o}", [], $s)->assertStatus(422)->assertJsonPath('codigo', 'OPORTUNIDADE_COM_DOCUMENTOS');
        $this->deleteJson("/api/crm/oportunidades/{$outra}", [], $s)->assertOk();
        $this->postJson("/api/crm/oportunidades/{$o}/documentos", ['venda_id' => $ft2], $this->sessao(['crm_editar']))->assertForbidden();
    }

    #[Test]
    public function emails_campanhas_previsao_e_indicadores(): void
    {
        $s = $this->s;
        $f = $this->funil();
        $modelos = collect($this->getJson('/api/crm/modelos-email', $s)->json('dados'))->keyBy('nome');
        $a = $this->prospect('Delta, Lda', ['email' => 'geral@delta.test']);
        $this->postJson("/api/crm/contas/{$a}/contactos", ['nome' => 'Ana Teste', 'email' => 'ana@delta.test', 'principal' => true], $s)->assertCreated();
        $b = $this->prospect('Épsilon, Lda');
        $o = $this->postJson('/api/crm/oportunidades', ['titulo' => 'Auditoria', 'conta_crm_id' => $a, 'funil_vendas_crm_id' => $f['id'], 'valor' => 12500,
            'data_fecho_prevista' => now()->addMonth()->toDateString()], $s)->json('dados');

        $m = $this->postJson('/api/crm/emails/preparar', ['oportunidade_crm_id' => $o['id'], 'modelo_email_crm_id' => $modelos['Envio de proposta']['id']], $s)->assertOk()->json('dados');
        $this->assertSame(['ana@delta.test', 'Proposta comercial — Auditoria'], [$m['para'], $m['assunto']]);
        $this->assertStringContainsString('Caro(a) Ana Teste,', $m['corpo']);
        $this->assertStringContainsString('no valor de 12 500,00 Kz', $m['corpo']);
        $this->assertStringContainsString('Empresa CRM, Lda', $m['corpo']);
        $r = $this->postJson('/api/crm/emails', ['oportunidade_crm_id' => $o['id'], 'modelo_email_crm_id' => $modelos['Envio de proposta']['id']], $s)->assertOk()->json('dados');
        $this->assertSame('ABERTO_NO_CLIENTE', $r['envio']['estado']);
        $this->assertStringStartsWith('mailto:ana%40delta.test?subject=', $r['envio']['mailto']);
        $this->assertSame(['EMAIL', true, 'Aberto no programa de email'], [$r['atividade']['tipo'], $r['atividade']['concluida'], $r['atividade']['resultado']]);
        $this->postJson('/api/crm/emails', ['conta_crm_id' => $b, 'assunto' => 'Olá'], $s)->assertStatus(422)->assertJsonPath('codigo', 'SEM_EMAIL');

        // campanha: prospects; só a Delta tem email
        $d = $this->getJson("/api/crm/campanhas/destinatarios?modelo_email_crm_id={$modelos['Apresentação']['id']}&tipo=PROSPECT", $s)->assertOk()->json('dados');
        $this->assertSame(['Delta, Lda' => true, 'Épsilon, Lda' => false], collect($d)->pluck('tem_email', 'conta.nome')->all());
        $this->assertSame('Empresa CRM, Lda — apresentação', $d[0]['assunto']);
        $res = $this->postJson('/api/crm/campanhas/enviar', ['modelo_email_crm_id' => $modelos['Apresentação']['id'], 'modo' => 'INDIVIDUAL',
            'destinatarios' => [['conta_crm_id' => $a], ['conta_crm_id' => $b]]], $s)->assertOk()->json('dados');
        $this->assertSame([1, [$b]], [$res['registadas'], $res['sem_email']]);
        $bcc = $this->postJson('/api/crm/campanhas/enviar', ['modelo_email_crm_id' => $modelos['Apresentação']['id'], 'modo' => 'BCC', 'destinatarios' => [['conta_crm_id' => $a]]], $s)
            ->assertOk()->json('dados');
        $this->assertStringContainsString('bcc=ana%40delta.test', $bcc['envio']['mailto']);
        $this->postJson('/api/crm/campanhas/enviar', ['modelo_email_crm_id' => 1, 'modo' => 'BCC', 'destinatarios' => [['conta_crm_id' => $a]]], $this->sessao(['crm_campanhas_view']))->assertForbidden();

        // previsão: 12 500 a 10 % no mês do fecho; indicadores do funil
        $p = $this->getJson('/api/crm/previsao?meses=3', $s)->assertOk()->json('dados');
        $mes = collect($p['por_mes'])->firstWhere('mes', now()->addMonth()->format('Y-m'));
        $this->assertSame(['12500.00', '1250.00', 1, '0.00'], [$mes['bruto'], $mes['ponderado'], $mes['n'], $mes['compromisso']]);
        $this->postJson("/api/crm/oportunidades/{$o['id']}/etapa", ['etapa_codigo' => $this->etapa($f, 'Ganha')], $s)->assertOk();
        $k = $this->getJson('/api/crm/indicadores', $s)->assertOk()->json('dados');
        $this->assertSame([1, 1, 0, '12500.00', 100.0, 0], [$k['criadas'], $k['ganhas'], $k['perdidas'], $k['valor_ganho'], $k['taxa_ganho'], $k['ciclo_mediana']]);
        $this->assertSame([1, 1], [$k['conversao'][0]['entraram'], $k['conversao'][0]['avancaram']]);
        $this->getJson('/api/crm/indicadores', $this->sessao(['crm_pipeline_view']))->assertForbidden();
    }

    #[Test]
    public function pos_carga_traduz_as_chaves_do_legado_de_forma_idempotente(): void
    {
        $e = $this->empresa->id;
        $ids = app(ContextoEmpresa::class)->executarComo($e, function () use ($e) {
            $conta = DB::table('contas_crm')->insertGetId(['empresa_id' => $e, 'nome' => 'Legado', 'tipo' => 'PROSPECT']);
            $funil = DB::table('funis_vendas_crm')->insertGetId(['empresa_id' => $e, 'nome' => 'Vendas', 'ordem' => 1, 'ativo' => true,
                'etapas' => json_encode([['id' => 'a1', 'nome' => 'Lead', 'tipo' => 'ABERTA', 'tarefas' => [['tipo' => 'EMAIL', 'titulo' => 'x', 'dias' => 0, 'modelo_id' => 2]]]])]);
            $opp = DB::table('oportunidades_venda_crm')->insertGetId(['empresa_id' => $e, 'funil_vendas_crm_id' => $funil, 'conta_crm_id' => $conta, 'titulo' => 'T', 'estado' => 'ABERTA',
                'itens' => json_encode([['product_id' => 7, 'descricao' => 'a', 'quantidade' => 1, 'preco' => 5, 'taxa' => 14]]),
                'historico' => json_encode([['etapa_id' => 'a1', 'entrou_em' => '2026-09-19T17:34:29.561Z', 'por' => 'admin']]),
                'vendas' => json_encode([['sale_id' => 208, 'doc_type' => 'Orcamento', 'doc_number' => 'OR 2026/1', 'total' => 10, 'em' => '2026-09-19T19:13:44.480Z']])]);
            app(ServicoMigracaoCRM::class)->normalizar();
            app(ServicoMigracaoCRM::class)->normalizar();

            return [$funil, $opp];
        });
        $o = DB::table('oportunidades_venda_crm')->find($ids[1]);
        $this->assertEquals([['produto_id' => 7, 'descricao' => 'a', 'quantidade' => 1, 'preco' => 5, 'taxa' => 14]], json_decode($o->itens, true));
        $this->assertSame('a1', json_decode($o->historico, true)[0]['etapa_codigo']);
        $v = json_decode($o->vendas, true)[0];
        $this->assertSame([208, 'OR', 'Orcamento', 'OR 2026/1'], [$v['venda_id'], $v['tipo_documento'], $v['tipo_documento_original'], $v['numero_documento']]);
        $this->assertSame(2, json_decode(DB::table('funis_vendas_crm')->find($ids[0])->etapas, true)[0]['tarefas'][0]['modelo_email_crm_id']);
    }
}
