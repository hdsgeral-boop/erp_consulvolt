<?php

namespace Tests\Feature;

use App\Models\ContaBancariaTerceiro;
use App\Models\DocumentoTesouraria;
use App\Models\Empresa;
use App\Models\LancamentoContabil;
use App\Models\PlanoConta;
use App\Models\Terceiro;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fase D (Versão Actual, ADR-071) — Tesouraria › Cartas de pagamento a terceiros (legado novo js/cartas_pagamento.js).
 * A carta nasce de um pagamento gravado (conta 42/43 com IBAN), usa a conta bancária predefinida do terceiro e não gera
 * lançamentos. Só dados fictícios (IBAN AO06… gerados aqui com os dígitos de controlo correctos).
 */
final class FdCartasTerceirosTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nome' => 'Empresa Ficticia, Lda', 'municipio' => 'Luanda']);
        $this->ids = $this->como(function () {
            foreach (['4311' => 'Banco Ficticio', '4312' => 'Banco Sem Iban', '4511' => 'Caixa', '3211' => 'Fornecedores', '611' => 'Vendas'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            PlanoConta::query()->where('codigo', '4311')->firstOrFail()->update(['banco_nome' => 'Banco Ficticio SA', 'banco_balcao' => 'Agência Teste',
                'banco_numero_conta' => '000000001.10.001', 'banco_iban' => self::iban('0055000000000000001'), 'banco_swift' => 'TSTFAOLU']);
            $alfa = Terceiro::create(['nome' => 'Alfa Fornecimentos', 'nif' => '5000000001', 'tipo' => 'FORNECEDOR']);
            $beta = Terceiro::create(['nome' => 'Beta Serviços', 'nif' => '5000000002', 'tipo' => 'FORNECEDOR']);
            $pref = ContaBancariaTerceiro::create(['terceiro_id' => $alfa->id, 'banco' => 'Banco Um', 'iban' => self::iban('0040000000000000011'), 'predefinida' => true]);
            $outra = ContaBancariaTerceiro::create(['terceiro_id' => $alfa->id, 'banco' => 'Banco Dois', 'iban' => self::iban('0006000000000000022'), 'predefinida' => false]);

            return ['alfa' => $alfa->id, 'beta' => $beta->id, 'pref' => $pref->id, 'outra' => $outra->id];
        });
        $this->s = $this->sessao(['teso_cartas_view', 'teso_carta_emitir', 'teso_carta_modelo', 'teso_doc_emitir', 'teso_gestao_pagamentos_view']);
    }

    public static function iban(string $prefixo19): string
    {
        return RHCadastrosTest::ibanValido($prefixo19);
    }

    private function como(callable $f, ?Empresa $e = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo(($e ?? $this->empresa)->id, $f);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    /** Pagamento a Alfa (900 − 100 de acerto = 800) e a Beta (700): total 1 500. */
    private function pagamento(string $conta = '4311', string $tipo = 'PAGAMENTO'): int
    {
        return $this->como(fn () => app(ServicoDocumentosTesouraria::class)->gravar(['tipo' => $tipo, 'data_documento' => now()->toDateString(), 'conta_financeira' => $conta,
            'descricao' => 'Pagamento ficticio a fornecedores', 'referencia' => 'REF-TESTE',
            'linhas' => $tipo === 'PAGAMENTO' ? [
                ['codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => '900', 'terceiro_id' => $this->ids['alfa']],
                ['codigo_conta' => '3211', 'tipo_dc' => 'C', 'valor' => '100', 'terceiro_id' => $this->ids['alfa']],
                ['codigo_conta' => '3211', 'tipo_dc' => 'D', 'valor' => '700', 'terceiro_id' => $this->ids['beta']],
            ] : [['codigo_conta' => '611', 'tipo_dc' => 'C', 'valor' => '500']]])->id);
    }

    #[Test]
    public function so_pagamentos_em_conta_bancaria_com_iban_emitem_carta(): void
    {
        $this->getJson("/api/tesouraria/cartas/documentos/{$this->pagamento('4312')}/elegibilidade", $this->s)->assertOk()
            ->assertJsonPath('dados.pode', false)->assertJsonPath('dados.codigo', 'CONTA_SEM_IBAN');
        $this->getJson("/api/tesouraria/cartas/documentos/{$this->pagamento('4511')}/elegibilidade", $this->s)->assertOk()
            ->assertJsonPath('dados.codigo', 'CONTA_NAO_BANCARIA');
        $rec = $this->pagamento('4311', 'RECEBIMENTO');
        $this->getJson("/api/tesouraria/cartas/documentos/{$rec}/elegibilidade", $this->s)->assertJsonPath('dados.codigo', 'DOCUMENTO_NAO_PAGAMENTO');
        $this->postJson("/api/tesouraria/cartas/documentos/{$rec}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'DOCUMENTO_NAO_PAGAMENTO');

        // a lista de pagamentos para emissão só tem os pagamentos 42/43 (a caixa e o recebimento ficam de fora)
        $docs = $this->getJson('/api/tesouraria/cartas/documentos', $this->s)->assertOk()->json('dados');
        $this->assertSame(['4312'], array_column($docs, 'conta_financeira'));
        $this->assertSame([false], array_column($docs, 'conta_com_iban'));

        // pagamento anulado não emite cartas
        $doc = $this->pagamento();
        $this->como(fn () => app(ServicoDocumentosTesouraria::class)->anular(DocumentoTesouraria::query()->findOrFail($doc), 'Teste'));
        $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $this->s)->assertJsonPath('dados.codigo', 'DOCUMENTO_ANULADO');
    }

    #[Test]
    public function emite_a_carta_do_pagamento_com_a_conta_do_terceiro_sem_lancamentos(): void
    {
        $doc = $this->pagamento();
        $el = $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $this->s)->assertOk()
            ->assertJsonPath('dados.pode', true)->assertJsonPath('dados.tipo', 'TERCEIRO')->assertJsonPath('dados.disponivel', '1500.00')
            ->assertJsonPath('dados.conta.titular_efectivo', 'Empresa Ficticia, Lda')->assertJsonPath('dados.local_padrao', 'Luanda')->json('dados');
        $benef = collect($el['beneficiarios'])->keyBy('nome');
        $this->assertSame('800.00', $benef['Alfa Fornecimentos']['valor']);
        $this->assertSame(self::iban('0040000000000000011'), $benef['Alfa Fornecimentos']['iban']);   // conta predefinida
        $this->assertCount(2, $benef['Alfa Fornecimentos']['contas']);
        $this->assertNull($benef['Beta Serviços']['iban']);

        // sem conta bancária do beneficiário: bloqueia e diz o que falta
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']], ['terceiro_id' => $this->ids['beta']]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'BENEFICIARIO_SEM_CONTA')->assertJsonPath('erros.beneficiarios', ['Beta Serviços']);
        // terceiro que não está no pagamento / conta de outro terceiro
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => 999999]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'BENEFICIARIO_INVALIDO');

        $lancamentos = $this->como(fn () => LancamentoContabil::query()->count());
        $ano = now()->year;
        $carta = $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa'], 'conta_bancaria_id' => $this->ids['outra']]],
            'nome_assinatura' => 'Director Ficticio', 'corpo' => '<script>alert(1)</script> {{TOTAL}}'], $this->s)->assertCreated()
            ->assertJsonPath('dados.numero', sprintf('CP %d/001', $ano))->assertJsonPath('dados.estado', 'EMITIDA')->assertJsonPath('dados.tipo', 'TERCEIRO')
            ->assertJsonPath('dados.montante_total', '800.00')->assertJsonPath('dados.banco_iban', self::iban('0055000000000000001'))
            ->assertJsonPath('dados.banco_titular', 'Empresa Ficticia, Lda')->assertJsonPath('dados.local', 'Luanda')
            ->assertJsonPath('dados.assunto', 'Ordem de transferência bancária')
            // o texto é guardado como texto (escapado só na impressão)
            ->assertJsonPath('dados.corpo', '<script>alert(1)</script> {{TOTAL}}')
            ->assertJsonPath('dados.itens.0.iban', self::iban('0006000000000000022'))->assertJsonPath('dados.itens.0.banco', 'Banco Dois')
            ->assertJsonPath('dados.itens.0.nif', '5000000001')->assertJsonPath('dados.documento.id', $doc)->json('dados.id');

        // nenhum lançamento e o pagamento continua como estava
        $this->assertSame($lancamentos, $this->como(fn () => LancamentoContabil::query()->count()));
        $this->assertSame('PENDENTE', $this->como(fn () => DocumentoTesouraria::query()->find($doc)->estado));

        // a fotografia do banco não muda quando o plano muda
        $this->como(fn () => PlanoConta::query()->where('codigo', '4311')->firstOrFail()->update(['banco_nome' => 'Outro nome']));
        $this->getJson("/api/tesouraria/cartas/{$carta}", $this->s)->assertOk()->assertJsonPath('dados.banco_nome', 'Banco Ficticio SA')
            ->assertJsonPath('dados.empresa.nome', 'Empresa Ficticia, Lda');

        // repetido: assinalado e só com confirmação; mesmo confirmado, não pode exceder o valor do pagamento
        $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $this->s)->assertJsonPath('dados.disponivel', '700.00')
            ->assertJsonPath('dados.beneficiarios.0.ja_na_carta', sprintf('CP %d/001', $ano));
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]]], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'BENEFICIARIO_REPETIDO');
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]], 'confirmar_repetidos' => true], $this->s)
            ->assertStatus(422)->assertJsonPath('codigo', 'CARTA_EXCEDE_PAGAMENTO');

        // anular (com motivo) liberta o valor; a numeração continua
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", [], $this->s)->assertStatus(422);
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", ['motivo' => 'IBAN trocado pelo fornecedor'], $this->s)->assertOk()
            ->assertJsonPath('dados.estado', 'ANULADA')->assertJsonPath('dados.motivo_anulacao', 'IBAN trocado pelo fornecedor');
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", ['motivo' => 'outra vez'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'CARTA_ANULADA');
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]]], $this->s)->assertCreated()
            ->assertJsonPath('dados.numero', sprintf('CP %d/002', $ano))->assertJsonPath('dados.itens.0.iban', self::iban('0040000000000000011'));

        $this->getJson('/api/tesouraria/cartas', $this->s)->assertOk()->assertJsonCount(2, 'dados')->assertJsonPath('dados.0.n_beneficiarios', 1);
        $this->getJson('/api/tesouraria/cartas?estado=ANULADA', $this->s)->assertOk()->assertJsonCount(1, 'dados')->assertJsonPath('dados.0.id', $carta);
        $this->assertSame($lancamentos, $this->como(fn () => LancamentoContabil::query()->count()));
    }

    #[Test]
    public function permissoes_e_isolamento_por_empresa(): void
    {
        $doc = $this->pagamento();
        $carta = $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]]], $this->s)->assertCreated()->json('dados.id');

        $consulta = $this->sessao(['teso_cartas_view']);
        $this->getJson('/api/tesouraria/cartas', $consulta)->assertOk();
        $this->getJson("/api/tesouraria/cartas/{$carta}", $consulta)->assertOk();
        // sem teso_carta_emitir: 403 antes de validar (corpo vazio)
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", [], $consulta)->assertForbidden();
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", [], $consulta)->assertForbidden();
        $this->postJson('/api/tesouraria/cartas/modelos', [], $consulta)->assertForbidden();
        $this->getJson('/api/tesouraria/cartas/documentos', $consulta)->assertForbidden();

        $nada = $this->sessao([]);
        $this->getJson('/api/tesouraria/cartas', $nada)->assertForbidden();
        $this->getJson('/api/tesouraria/cartas/modelos', $nada)->assertForbidden();

        // outra empresa: a carta e o pagamento não existem lá
        $outra = $this->criarEmpresa(['nome' => 'Outra Ficticia']);
        $fora = $this->sessao(['teso_cartas_view', 'teso_carta_emitir'], $outra);
        $this->getJson("/api/tesouraria/cartas/{$carta}", $fora)->assertNotFound();
        $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $fora)->assertNotFound();
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", ['motivo' => 'tentativa'], $fora)->assertNotFound();
        $this->getJson('/api/tesouraria/cartas', $fora)->assertOk()->assertJsonCount(0, 'dados');
    }

    #[Test]
    public function modelos_de_texto_com_um_predefinido_por_tipo(): void
    {
        $m = fn (array $d) => $this->postJson('/api/tesouraria/cartas/modelos', $d + ['tipo' => 'TERCEIRO', 'assunto' => 'Transferência {{NUMERO}}',
            'corpo' => 'Exmos. Senhores, transfiram {{TOTAL}} ({{TOTAL_EXTENSO}}) a {{BENEFICIARIO}}.', 'cargo_assinatura' => 'Gerência'], $this->s);
        $padrao = $this->getJson('/api/tesouraria/cartas/modelos', $this->s)->assertOk()->assertJsonCount(0, 'dados.modelos')
            ->assertJsonPath('dados.padrao.SALARIOS.assunto', 'Ordem de pagamento de salários — {{MES}}')->json('dados.variaveis');
        $this->assertArrayHasKey('IBAN_BENEFICIARIO', $padrao);

        $a = $m(['nome' => 'Modelo A'])->assertCreated()->assertJsonPath('dados.predefinido', true)->json('dados.id');   // o primeiro fica predefinido
        $b = $m(['nome' => 'Modelo B', 'assunto' => 'Assunto B'])->assertCreated()->assertJsonPath('dados.predefinido', false)->json('dados.id');
        $m(['nome' => 'Sem tipo', 'tipo' => 'OUTRO'])->assertStatus(422);
        $m(['nome' => 'Longo', 'assunto' => str_repeat('x', 51)])->assertStatus(422);

        // a emissão usa o predefinido; pode escolher-se outro modelo do mesmo tipo
        $doc = $this->pagamento();
        $this->getJson("/api/tesouraria/cartas/documentos/{$doc}/elegibilidade", $this->s)->assertJsonPath('dados.modelo.id', $a)
            ->assertJsonPath('dados.modelo.cargo_assinatura', 'Gerência');
        $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]], 'modelo_id' => $b], $this->s)
            ->assertCreated()->assertJsonPath('dados.assunto', 'Assunto B');

        $this->postJson("/api/tesouraria/cartas/modelos/{$b}/predefinir", [], $this->s)->assertOk()->assertJsonPath('dados.predefinido', true);
        $lista = collect($this->getJson('/api/tesouraria/cartas/modelos', $this->s)->json('dados.modelos'))->keyBy('id');
        $this->assertFalse($lista[$a]['predefinido']);
        $this->putJson("/api/tesouraria/cartas/modelos/{$a}", ['nome' => 'Modelo A2', 'assunto' => 'Novo', 'corpo' => 'Texto'], $this->s)->assertOk()
            ->assertJsonPath('dados.nome', 'Modelo A2')->assertJsonPath('dados.tipo', 'TERCEIRO');

        // eliminar o predefinido promove o que resta
        $this->deleteJson("/api/tesouraria/cartas/modelos/{$b}", [], $this->s)->assertOk();
        $this->getJson('/api/tesouraria/cartas/modelos', $this->s)->assertJsonCount(1, 'dados.modelos')->assertJsonPath('dados.modelos.0.predefinido', true);

        // só quem tem teso_carta_modelo altera o texto padrão (tarefa sensível)
        $emissor = $this->sessao(['teso_carta_emitir']);
        $this->getJson('/api/tesouraria/cartas/modelos', $emissor)->assertOk();
        $this->putJson("/api/tesouraria/cartas/modelos/{$a}", [], $emissor)->assertForbidden();
        $this->deleteJson("/api/tesouraria/cartas/modelos/{$a}", [], $emissor)->assertForbidden();
    }

    #[Test]
    public function pagamento_com_cartas_emitidas_nao_se_anula(): void
    {
        $doc = $this->pagamento();
        $carta = $this->postJson("/api/tesouraria/cartas/documentos/{$doc}", ['beneficiarios' => [['terceiro_id' => $this->ids['alfa']]]], $this->s)->assertCreated()->json('dados.id');
        $tesouraria = $this->sessao(['teso_doc_eliminar', 'teso_cartas_view', 'teso_carta_emitir']);

        $this->postJson("/api/tesouraria/documentos/{$doc}/anular", ['motivo' => 'Pagamento ficticio repetido'], $tesouraria)->assertStatus(422)
            ->assertJsonPath('codigo', 'PAGAMENTO_COM_CARTAS');
        // anulada a carta, o pagamento anula-se
        $this->postJson("/api/tesouraria/cartas/{$carta}/anular", ['motivo' => 'Carta emitida por engano'], $tesouraria)->assertOk();
        $this->postJson("/api/tesouraria/documentos/{$doc}/anular", ['motivo' => 'Pagamento ficticio repetido'], $tesouraria)->assertOk();
    }
}
