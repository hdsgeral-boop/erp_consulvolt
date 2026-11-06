<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Services\Imobiliario\CatalogoImobiliario;
use App\Services\Imobiliario\ServicoImoveis;
use App\Services\Imobiliario\ServicoTaxasImobiliario;
use App\Services\Sistema\CatalogoPermissoes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 1 (cadastro): permissões do catálogo, dados de fábrica, imóveis (código atómico, unicidades, papel e
 * proprietário, edifício do Activos), fracções (geração por piso, importação idempotente das portas, estados com trilho),
 * resumo de ocupação, isolamento por empresa e idempotência da migração. Os números são calculados à mão.
 */
final class ImobiliarioCadastroTest extends TestCase
{
    private Empresa $empresa;

    private array $gestor;

    private array $leitor;

    private array $semAcesso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000321']);
        $todas = ['imo_imoveis_view', 'imo_ocupacao_view', 'imo_imovel_editar', 'imo_fraccao_editar', 'imo_estado_mudar'];
        $this->gestor = $this->sessao($todas);
        $this->leitor = $this->sessao(['imo_imoveis_view', 'imo_ocupacao_view']);
        $this->semAcesso = $this->sessao(['dashboard_view']);
    }

    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function imovel(array $d = [], ?array $h = null): int
    {
        return $this->postJson('/api/imobiliario/imoveis', $d + ['nome' => 'Edifício Girassol', 'tipo' => 'PREDIO', 'papel' => 'PROPRIO', 'n_pisos' => 3], $h ?? $this->gestor)->assertCreated()->json('dados.id');
    }

    private function fracao(int $imovel, array $d = []): array
    {
        return $this->postJson("/api/imobiliario/imoveis/{$imovel}/fracoes", $d + ['designacao' => 'Loja A'], $this->gestor)->assertCreated()->json('dados');
    }

    private function terceiro(string $nome = 'Proprietário Fictício', ?string $nif = '5417000123', ?Empresa $e = null): int
    {
        return (int) DB::table('terceiros')->insertGetId(['empresa_id' => ($e ?? $this->empresa)->id, 'nome' => $nome, 'tipo' => 'CLIENTE', 'nif' => $nif]);
    }

    private function edificio(string $propriedade = 'PROPRIO', ?string $regime = null): int
    {
        return (int) DB::table('ativos_imobilizados')->insertGetId(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'EDIFICIO', 'propriedade' => $propriedade, 'regime_alheio' => $regime,
            'codigo' => 'ED'.random_int(1000, 9999), 'descricao' => 'Sede fictícia']);
    }

    // ---------------------------------------------------------------- catálogo, fábrica e funções puras

    #[Test]
    public function o_catalogo_tem_o_modulo_com_13_ecras_41_tarefas_20_sensiveis_5_pares_e_5_perfis(): void
    {
        $m = collect(CatalogoPermissoes::completo()['modulos'])->firstWhere('id', 'imobiliario');
        $this->assertCount(13, $m['ecras']);
        $tarefas = collect($m['ecras'])->flatMap(fn ($e) => $e['tarefas']);
        $this->assertCount(41, $tarefas);
        $this->assertSame(20, $tarefas->where('sensivel', true)->count());
        foreach (['imo_reserva_cancelar', 'imo_contrato_suspender', 'imo_contrato_renovar', 'imo_caucao_movimentar', 'imo_alertas_gerir', 'imo_exportar'] as $k) {
            $this->assertTrue($tarefas->firstWhere('chave', $k)['sensivel'], $k);
        }
        $pares = collect(CatalogoPermissoes::segregacao())->filter(fn ($s) => str_starts_with($s['a'], 'imo_'));
        $this->assertCount(5, $pares);

        $modelos = collect(CatalogoPermissoes::modelos());
        foreach (['Gestor Imobiliário', 'Técnico de Rendas', 'Responsável de Cobrança', 'Gestor de Obras do Imóvel', 'Fiscalista Predial'] as $nome) {
            $this->assertNotNull($modelos->firstWhere('nome', $nome), $nome);
        }
        foreach (['Auditor / Consulta', 'Direcção'] as $nome) {
            $p = $modelos->firstWhere('nome', $nome)['permissoes'];
            $this->assertCount(13, array_filter(array_keys($p), fn ($k) => str_starts_with($k, 'imo_') && str_ends_with($k, '_view')), $nome);
        }
        // nenhuma tarefa sensível no perfil de operador e nenhum perfil acumula um par de segregação
        $operador = $modelos->firstWhere('nome', 'Operador POS / Lavandaria')['permissoes'];
        $this->assertSame([], array_values(array_filter(array_keys($operador), fn ($k) => str_starts_with($k, 'imo_'))));
        foreach ($modelos as $mod) {
            foreach ($pares as $s) {
                $this->assertFalse(! empty($mod['permissoes'][$s['a']]) && ! empty($mod['permissoes'][$s['b']]), "{$mod['nome']} acumula {$s['a']} e {$s['b']}");
            }
        }
        foreach (CatalogoPermissoes::tarefas() as $chave => $t) {
            $this->assertTrue(CatalogoPermissoes::chaveValida($chave));
        }
    }

    #[Test]
    public function os_dados_de_fabrica_tem_as_contagens_do_legado(): void
    {
        $f = CatalogoImobiliario::fabrica();
        $this->assertCount(8, $f['tipos']);
        $this->assertCount(59, $f['variaveis']);
        $this->assertCount(8, $f['modelos']);
        $this->assertCount(21, $f['taxas_base']);
        $this->assertEquals(25.0, $f['taxas_base']['ip_taxa']);
        $this->assertEquals(60.0, $f['taxas_base']['ip_base']);
        $this->assertEquals(5000000.0, $f['taxas_base']['det_escalao1_ate']);
        $this->assertEquals(12.0, $f['taxas_base']['det_agravamento_meses']);
        $this->assertSame(array_keys($f['tipos']), array_column($f['modelos'], 'tipo'));
        // todas as variáveis usadas pelos modelos de fábrica existem
        $validas = array_column($f['variaveis'], 'chave');
        foreach ($f['modelos'] as $m) {
            preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $m['corpo'].' '.$m['assunto'], $x);
            $this->assertSame([], array_values(array_diff($x[1], $validas)), $m['nome']);
        }
    }

    #[Test]
    public function designacoes_pisos_e_taxa_de_ocupacao_batem_com_o_legado(): void
    {
        $this->assertSame('R/C', CatalogoImobiliario::nomePiso(0));
        $this->assertSame('Cave', CatalogoImobiliario::nomePiso(-1));
        $this->assertSame('2.ª cave', CatalogoImobiliario::nomePiso(-2));
        $this->assertSame('3.º andar', CatalogoImobiliario::nomePiso(3));
        $this->assertSame('203', CatalogoImobiliario::designacaoGerada('PORTA', 2, 3));
        $this->assertSame('01', CatalogoImobiliario::designacaoGerada('PORTA', 0, 1));
        $this->assertSame('C102', CatalogoImobiliario::designacaoGerada('PORTA', -1, 2));
        $this->assertSame('B · 101', CatalogoImobiliario::designacaoGerada('PORTA', 1, 1, 'B'));
        $this->assertSame('1.º Dto.', CatalogoImobiliario::designacaoGerada('ESQ_DTO', 1, 2));
        $this->assertSame('R/C Esq.', CatalogoImobiliario::designacaoGerada('ESQ_DTO', 0, 1));
        $this->assertSame('Cave Esq.', CatalogoImobiliario::designacaoGerada('ESQ_DTO', -1, 1));
        $this->assertSame('R/C · A', CatalogoImobiliario::designacaoGerada('LETRA', 0, 27));
        $this->assertSame('2.º andar · 3', CatalogoImobiliario::designacaoGerada('NUMERO', 2, 3));
        $this->assertSame('203', CatalogoImobiliario::numeroGerado('PORTA', 2, 3));
        $this->assertSame('3', CatalogoImobiliario::numeroGerado('NUMERO', 2, 3));
        $this->assertSame(43, ServicoImoveis::taxaOcupacao(3, 7));
        $this->assertSame(75, ServicoImoveis::taxaOcupacao(3, 4));
        $this->assertNull(ServicoImoveis::taxaOcupacao(0, 0));
        $this->assertEquals(15.0, app(ServicoTaxasImobiliario::class)->ipEfectivo(25, 60));
    }

    // ---------------------------------------------------------------- permissões

    #[Test]
    public function sem_permissao_recusa_com_403_antes_de_validar(): void
    {
        $this->getJson('/api/imobiliario/imoveis', $this->semAcesso)->assertForbidden();
        $this->getJson('/api/imobiliario/resumo', $this->semAcesso)->assertForbidden();
        $this->postJson('/api/imobiliario/imoveis', [], $this->leitor)->assertForbidden();   // 403 e não 422
        $this->getJson('/api/imobiliario/imoveis', $this->leitor)->assertOk();
        $id = $this->imovel();
        $this->putJson("/api/imobiliario/imoveis/{$id}", ['nome' => 'X'], $this->leitor)->assertForbidden();
        $this->deleteJson("/api/imobiliario/imoveis/{$id}", [], $this->leitor)->assertForbidden();
        $this->postJson("/api/imobiliario/imoveis/{$id}/fracoes", ['designacao' => 'A'], $this->leitor)->assertForbidden();
        $this->postJson("/api/imobiliario/imoveis/{$id}/fracoes/gerar", ['pisos' => 1], $this->leitor)->assertForbidden();
        $this->postJson("/api/imobiliario/imoveis/{$id}/fracoes/unica", [], $this->leitor)->assertForbidden();
        $this->postJson("/api/imobiliario/imoveis/{$id}/fracoes/importar-portas", [], $this->leitor)->assertForbidden();
        $f = $this->fracao($id)['id'];
        $this->postJson("/api/imobiliario/fracoes/{$f}/estado", ['estado' => 'RESERVADA'], $this->leitor)->assertForbidden();
        $this->deleteJson("/api/imobiliario/fracoes/{$f}", [], $this->leitor)->assertForbidden();
        // quem só mexe nas fracções não grava imóveis, e quem só muda estados não edita fracções
        $soEstado = $this->sessao(['imo_imoveis_view', 'imo_estado_mudar']);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'Y'], $soEstado)->assertForbidden();
        $this->putJson("/api/imobiliario/fracoes/{$f}", ['designacao' => 'Z'], $soEstado)->assertForbidden();
        $this->postJson("/api/imobiliario/fracoes/{$f}/estado", ['estado' => 'RESERVADA'], $soEstado)->assertOk();
    }

    // ---------------------------------------------------------------- imóveis

    #[Test]
    public function o_codigo_automatico_e_sequencial_e_salta_os_escritos_a_mao(): void
    {
        $a = $this->imovel();
        $this->assertSame('PR0001', $this->getJson("/api/imobiliario/imoveis/{$a}", $this->gestor)->json('dados.codigo'));
        $this->imovel(['nome' => 'Manual', 'codigo' => 'PR0002']);   // o utilizador escreve o código seguinte
        $c = $this->imovel(['nome' => 'Terceiro']);
        $this->assertSame('PR0003', $this->getJson("/api/imobiliario/imoveis/{$c}", $this->gestor)->json('dados.codigo'));
        $this->imovel(['nome' => 'Salto', 'codigo' => 'PR0004']);
        $e = $this->imovel(['nome' => 'Quinto']);
        $this->assertSame('PR0005', $this->getJson("/api/imobiliario/imoveis/{$e}", $this->gestor)->json('dados.codigo'));
        $this->assertSame(5, DB::table('imoveis')->where('empresa_id', $this->empresa->id)->count());
    }

    #[Test]
    public function validacoes_do_imovel_com_as_mensagens_do_legado(): void
    {
        $this->postJson('/api/imobiliario/imoveis', ['nome' => ''], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Dê uma designação ao imóvel.');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'A', 'papel' => 'ARRENDADO'], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Um imóvel «arrendado a terceiros» tem de dizer de quem é: escolha o proprietário.');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'A', 'papel' => 'MEDIACAO', 'proprietario_terceiro_id' => 999999], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'O proprietário escolhido não existe nesta empresa.');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'A', 'area_bruta' => 100, 'area_util' => 120], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'A área útil não pode ser maior do que a área bruta.');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'C', 'tipo' => 'CONDOMINIO'], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IMOVEL_HABITACOES');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'C', 'tipo' => 'CONDOMINIO', 'n_habitacoes' => 10], $this->gestor)->assertCreated();

        $this->imovel(['nome' => 'B', 'codigo' => 'Casa1', 'matriz_predial' => 'M-123']);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'B2', 'codigo' => 'CASA1'], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Já existe um imóvel com o código CASA1.');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'B3', 'matriz_predial' => 'M-123'], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Já existe um imóvel com o artigo matricial M-123. Verifique: a matriz identifica o prédio.');
        // as mensagens nunca trazem texto técnico
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'B4', 'documentos' => [['url' => 'javascript:alert(1)']]], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Documento: só se aceitam endereços http:// ou https://.');
    }

    #[Test]
    public function proprietario_obrigatorio_nos_arrendados_e_anulado_nos_proprios(): void
    {
        $t = $this->terceiro();
        $a = $this->imovel(['nome' => 'Tomado', 'papel' => 'ARRENDADO', 'proprietario_terceiro_id' => $t]);
        $this->getJson("/api/imobiliario/imoveis/{$a}", $this->gestor)->assertJsonPath('dados.proprietario_nome', 'Proprietário Fictício');
        $b = $this->imovel(['nome' => 'Próprio', 'papel' => 'PROPRIO', 'proprietario_terceiro_id' => $t]);
        $this->getJson("/api/imobiliario/imoveis/{$b}", $this->gestor)->assertJsonPath('dados.proprietario_terceiro_id', null);
        // o terceiro de outra empresa não vale
        $outra = $this->criarEmpresa(['nif' => '5417000654']);
        $alheio = $this->terceiro('Alheio', '5417000999', $outra);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'C', 'papel' => 'ARRENDADO', 'proprietario_terceiro_id' => $alheio], $this->gestor)->assertStatus(422);
    }

    #[Test]
    public function o_edificio_do_activos_liga_a_um_so_imovel_e_ao_papel_certo(): void
    {
        $bem = $this->edificio();
        $alheio = $this->edificio('ALHEIO', 'ARRENDADO');
        $cedido = $this->edificio('ALHEIO', 'CEDIDO');
        $normal = (int) DB::table('ativos_imobilizados')->insertGetId(['empresa_id' => $this->empresa->id, 'tipo_ativo' => 'BEM', 'codigo' => 'B1', 'descricao' => 'Cadeira']);
        $a = $this->imovel(['nome' => 'Sede', 'ativo_edificio_id' => $bem]);
        $this->getJson("/api/imobiliario/imoveis/{$a}", $this->gestor)->assertJsonPath('dados.edificio.id', $bem);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'Outro', 'ativo_edificio_id' => $bem], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IMOVEL_EDIFICIO_LIGADO');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'N', 'ativo_edificio_id' => $normal], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IMOVEL_EDIFICIO');
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'P', 'ativo_edificio_id' => $alheio], $this->gestor)->assertStatus(422)->assertJsonPath('codigo', 'IMOVEL_EDIFICIO');
        $t = $this->terceiro();
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'T', 'papel' => 'ARRENDADO', 'proprietario_terceiro_id' => $t, 'ativo_edificio_id' => $cedido], $this->gestor)->assertStatus(422);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'T', 'papel' => 'ARRENDADO', 'proprietario_terceiro_id' => $t, 'ativo_edificio_id' => $alheio], $this->gestor)->assertCreated();
        $lista = $this->getJson('/api/imobiliario/edificios', $this->gestor)->assertOk()->json('dados');
        $this->assertSame('PR0001', collect($lista)->firstWhere('id', $bem)['ligado_ao_imovel']);
        $this->assertNull(collect($lista)->firstWhere('id', $cedido)['ligado_ao_imovel']);
    }

    #[Test]
    public function os_avisos_do_imovel_usam_o_texto_do_legado_e_o_nif_do_proprietario(): void
    {
        $t = $this->terceiro('NIF mau', '123');
        $a = $this->imovel(['nome' => 'Avisos', 'papel' => 'MEDIACAO', 'proprietario_terceiro_id' => $t, 'n_pisos' => 0]);
        $avisos = $this->getJson("/api/imobiliario/imoveis/{$a}", $this->gestor)->json('dados.avisos');
        $this->assertContains('Sem fracções: os contratos prendem-se a fracções, crie pelo menos uma.', $avisos);
        $this->assertContains('Sem número de pisos: é o que permite gerar e contar as fracções por andar.', $avisos);
        $this->assertContains('Sem artigo matricial: é o que identifica o prédio perante a AGT.', $avisos);
        $this->assertContains('O NIF do proprietário registado em Terceiros não é válido: corrija-o antes de emitir documentos.', $avisos);
        $b = $this->imovel(['nome' => 'Condomínio', 'tipo' => 'CONDOMINIO', 'n_habitacoes' => 5, 'n_pisos' => 2, 'area_bruta' => 100]);
        $this->fracao($b, ['designacao' => 'A', 'area_bruta' => 60, 'andar' => 'R/C']);
        $this->fracao($b, ['designacao' => 'B', 'area_bruta' => 60]);
        $av = $this->getJson("/api/imobiliario/imoveis/{$b}", $this->gestor)->json('dados.avisos');
        $this->assertContains('Faltam 3 fracção(ões): o condomínio declara 5 habitações e só estão registadas 2.', $av);
        $this->assertContains('As fracções somam 120 m² de área bruta, mais do que os 100 m² do imóvel.', $av);
        $this->assertContains('1 fracção(ões) sem piso indicado: sem isso não se conta por andar.', $av);
        $this->assertContains('Sem valor patrimonial: é a base do Imposto Predial da detenção.', $av);
        $this->assertContains('Não está ligado a nenhum edifício do imobilizado: sem isso não há planta, inventário nem manutenção.', $av);
    }

    #[Test]
    public function eliminar_imovel_exige_que_nao_tenha_fracoes(): void
    {
        $a = $this->imovel();
        $this->fracao($a);
        $this->deleteJson("/api/imobiliario/imoveis/{$a}", [], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'O imóvel tem 1 fracção(ões). Apague-as primeiro, ou ponha o imóvel como inactivo.');
        $b = $this->imovel(['nome' => 'Vazio']);
        $this->deleteJson("/api/imobiliario/imoveis/{$b}", [], $this->gestor)->assertOk();
        $this->getJson("/api/imobiliario/imoveis/{$b}", $this->gestor)->assertNotFound();
    }

    #[Test]
    public function actualizar_imovel_mantem_o_codigo_e_a_lista_filtra(): void
    {
        $a = $this->imovel(['nome' => 'Girassol', 'matriz_predial' => 'M-1']);
        $this->putJson("/api/imobiliario/imoveis/{$a}", ['nome' => 'Girassol II', 'tipo' => 'PREDIO', 'papel' => 'PROPRIO', 'estado' => 'INACTIVO', 'matriz_predial' => 'M-1'], $this->gestor)
            ->assertOk()->assertJsonPath('dados.codigo', 'PR0001')->assertJsonPath('dados.nome', 'Girassol II')->assertJsonPath('dados.estado', 'INACTIVO');
        $this->imovel(['nome' => 'Mimosa', 'tipo' => 'MORADIA']);
        $this->assertCount(2, $this->getJson('/api/imobiliario/imoveis', $this->gestor)->json('dados'));
        $this->assertCount(1, $this->getJson('/api/imobiliario/imoveis?estado=INACTIVO', $this->gestor)->json('dados'));
        $this->assertCount(1, $this->getJson('/api/imobiliario/imoveis?texto=mimo', $this->gestor)->json('dados'));
        $this->assertCount(1, $this->getJson('/api/imobiliario/imoveis?tipo=MORADIA', $this->gestor)->json('dados'));
        $this->assertSame([], $this->getJson('/api/imobiliario/imoveis?texto=%27%20OR%201=1--', $this->gestor)->assertOk()->json('dados'));   // texto literal, sem efeito
    }

    // ---------------------------------------------------------------- fracções

    #[Test]
    public function o_codigo_da_fraccao_vem_do_maior_sufixo_e_nao_colide_apos_eliminar(): void
    {
        $i = $this->imovel();
        $a = $this->fracao($i, ['designacao' => 'A']);
        $b = $this->fracao($i, ['designacao' => 'B']);
        $this->assertSame(['PR0001-01', 'PR0001-02'], [$a['codigo'], $b['codigo']]);
        $this->deleteJson("/api/imobiliario/fracoes/{$a['id']}", [], $this->gestor)->assertOk();
        $c = $this->fracao($i, ['designacao' => 'C']);   // o legado contava as irmãs (1+1 = 02) e colidia
        $this->assertSame('PR0001-03', $c['codigo']);
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes", ['designacao' => 'D', 'codigo' => 'pr0001-02'], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Já existe uma fracção com o código pr0001-02 neste imóvel.');
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes", ['designacao' => 'b'], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Já existe a fracção «b» neste imóvel.');
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes", ['designacao' => ''], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Dê uma designação à fracção (ex.: «1.º Esq.», «Loja A», «Única»).');
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes", ['designacao' => 'V', 'natureza' => 'ESTACIONAMENTO'], $this->gestor)->assertStatus(422)
            ->assertJsonPath('codigo', 'FRACCAO_NATUREZA_INDISPONIVEL');
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes", ['designacao' => 'E', 'area_bruta' => 10, 'area_util' => 12], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'A área útil não pode ser maior do que a área bruta.');
        $this->assertSame(1, DB::table('historico_estado_fracao')->where('fracao_id', $c['id'])->count());
    }

    #[Test]
    public function gerar_por_piso_cria_designacoes_do_esquema_e_ignora_as_repetidas(): void
    {
        $i = $this->imovel(['n_pisos' => 3]);
        $r = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/gerar", ['pisos' => 3, 'por_piso' => 2, 'piso_inicial' => 1, 'esquema' => 'PORTA', 'tipologia' => 'T2',
            'area_bruta' => 80, 'area_util' => 70, 'renda_referencia' => 150000], $this->gestor)->assertOk();
        $this->assertSame(6, $r->json('dados.criadas'));
        $lista = $this->getJson("/api/imobiliario/imoveis/{$i}/fracoes", $this->gestor)->json('dados');
        $this->assertSame(['101', '102', '201', '202', '301', '302'], array_column($lista, 'designacao'));
        $this->assertSame(['1.º andar', '1.º andar', '2.º andar', '2.º andar', '3.º andar', '3.º andar'], array_column($lista, 'andar'));
        $this->assertSame(['PR0001-01', 'PR0001-02', 'PR0001-03', 'PR0001-04', 'PR0001-05', 'PR0001-06'], array_column($lista, 'codigo'));
        $this->assertSame('T2', $lista[0]['tipologia']);
        $this->assertEquals(80, $lista[0]['area_bruta']);
        $r2 = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/gerar", ['pisos' => 3, 'por_piso' => 2, 'piso_inicial' => 1, 'esquema' => 'PORTA'], $this->gestor)->assertOk();
        $this->assertSame([0, 6], [$r2->json('dados.criadas'), $r2->json('dados.repetidas')]);
        $this->assertSame(6, DB::table('fracoes')->where('imovel_id', $i)->count());
        // blocos: 2 pisos × 2 × 2 blocos = 8 novas, com o bloco no prefixo
        $r3 = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/gerar", ['pisos' => 2, 'por_piso' => 2, 'piso_inicial' => 1, 'esquema' => 'PORTA', 'blocos' => ['A', 'B']], $this->gestor)->assertOk();
        $this->assertSame(8, $r3->json('dados.criadas'));
        $this->assertSame(1, DB::table('fracoes')->where('imovel_id', $i)->where('designacao', 'B · 202')->count());
        $this->assertSame(14, DB::table('historico_estado_fracao')->where('empresa_id', $this->empresa->id)->count());
        // o resumo do imóvel: 14 fracções principais, nenhuma ocupada, área das fracções 6×80 + 8×0
        $f = $this->getJson("/api/imobiliario/imoveis/{$i}", $this->gestor)->json('dados');
        $this->assertSame([14, 0, 14], [$f['total_fracoes'], $f['ocupadas'], $f['livres']]);
        $this->assertEquals(480, $f['area_fracoes']);
        $this->assertEquals(900000, $f['renda_referencia']);
    }

    #[Test]
    public function o_limite_de_500_fraccoes_conta_os_blocos(): void
    {
        $i = $this->imovel();
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/gerar", ['pisos' => 10, 'por_piso' => 30, 'blocos' => ['A', 'B']], $this->gestor)->assertStatus(422)
            ->assertJsonPath('codigo', 'FRACCOES_DEMASIADAS');   // 600 > 500: o legado só multiplicava piso × por_piso (300) e deixava passar
        $this->assertSame(0, DB::table('fracoes')->where('imovel_id', $i)->count());
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/gerar", ['pisos' => 10, 'por_piso' => 25, 'blocos' => ['A', 'B']], $this->gestor)->assertOk()->assertJsonPath('dados.criadas', 500);
    }

    #[Test]
    public function fraccao_unica_so_num_imovel_sem_fracoes(): void
    {
        $i = $this->imovel(['nome' => 'Terreno', 'tipo' => 'TERRENO', 'area_bruta' => 500]);
        $u = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/unica", [], $this->gestor)->assertCreated()->assertJsonPath('dados.designacao', 'Única')
            ->assertJsonPath('dados.tipologia', 'TERRENO');
        $this->assertEquals(500, $u->json('dados.area_bruta'));
        $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/unica", [], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'Este imóvel já tem fracções.');
    }

    #[Test]
    public function importar_as_portas_do_edificio_e_idempotente(): void
    {
        $e = $this->edificio();
        $andar = (int) DB::table('andares_edificios')->insertGetId(['empresa_id' => $this->empresa->id, 'edificio_id' => $e, 'numero' => '1', 'nome' => '1.º andar']);
        $p1 = (int) DB::table('portas_edificios')->insertGetId(['empresa_id' => $this->empresa->id, 'edificio_id' => $e, 'andar_id' => $andar, 'numero' => '101', 'nome' => 'Sala 101', 'tipo' => 'Escritório', 'ordem' => 1]);
        DB::table('portas_edificios')->insert(['empresa_id' => $this->empresa->id, 'edificio_id' => $e, 'andar_id' => $andar, 'numero' => '102', 'ordem' => 2]);
        $semLigacao = $this->imovel(['nome' => 'Sem edifício']);
        $this->postJson("/api/imobiliario/imoveis/{$semLigacao}/fracoes/importar-portas", [], $this->gestor)->assertStatus(422)
            ->assertJsonPath('mensagem', 'Este imóvel não está ligado a nenhum edifício do imobilizado.');
        $i = $this->imovel(['nome' => 'Sede', 'ativo_edificio_id' => $e]);
        $r = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/importar-portas", [], $this->gestor)->assertOk();
        $this->assertSame([2, 2], [$r->json('dados.criadas'), $r->json('dados.total')]);
        $lista = $this->getJson("/api/imobiliario/imoveis/{$i}/fracoes", $this->gestor)->json('dados');
        $this->assertSame(['Sala 101', '1.º andar · Porta 102'], array_column($lista, 'designacao'));
        $this->assertSame($p1, $lista[0]['porta_id']);
        $this->assertSame('Tipo na planta: Escritório', $lista[0]['notas']);
        $r2 = $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/importar-portas", [], $this->gestor)->assertOk();
        $this->assertSame([0, 2], [$r2->json('dados.criadas'), $r2->json('dados.total')]);
        $this->assertSame(2, DB::table('fracoes')->where('imovel_id', $i)->count());
        // uma porta nova na planta entra na importação seguinte, sem tocar nas existentes
        DB::table('portas_edificios')->insert(['empresa_id' => $this->empresa->id, 'edificio_id' => $e, 'andar_id' => $andar, 'numero' => '103', 'ordem' => 3]);
        $this->assertSame(1, $this->postJson("/api/imobiliario/imoveis/{$i}/fracoes/importar-portas", [], $this->gestor)->json('dados.criadas'));
        // a ficha mostra a porta do Activos
        $this->getJson("/api/imobiliario/fracoes/{$lista[0]['id']}", $this->gestor)->assertJsonPath('dados.porta.numero', '101');
    }

    #[Test]
    public function estados_obedecem_as_transicoes_com_trilho_e_motivo(): void
    {
        $i = $this->imovel();
        $f = $this->fracao($i)['id'];
        $muda = fn (string $estado, ?string $motivo = null) => $this->postJson("/api/imobiliario/fracoes/{$f}/estado", ['estado' => $estado, 'motivo' => $motivo], $this->gestor);
        $muda('DISPONIVEL')->assertStatus(422)->assertJsonPath('mensagem', 'A fracção já está nesse estado.');
        $muda('INDISPONIVEL')->assertStatus(422)->assertJsonPath('mensagem', 'Indique o motivo desta mudança de estado.');
        $muda('ARRENDADA')->assertOk()->assertJsonPath('dados.estado', 'ARRENDADA')->assertJsonPath('dados.estado_motivo', null);
        $this->deleteJson("/api/imobiliario/fracoes/{$f}", [], $this->gestor)->assertStatus(422)->assertJsonPath('mensagem', 'A fracção está arrendada. Mude o estado antes de a apagar.');
        $muda('DISPONIVEL')->assertStatus(422)->assertJsonPath('codigo', 'FRACCAO_MOTIVO');
        $muda('VENDIDA', 'Venda ao inquilino')->assertOk();
        $muda('ARRENDADA', 'Engano')->assertStatus(422)->assertJsonPath('codigo', 'FRACCAO_TRANSICAO');
        $muda('RESERVADA', 'Engano')->assertStatus(422)->assertJsonPath('codigo', 'FRACCAO_TRANSICAO');
        $muda('DISPONIVEL', 'Venda desfeita')->assertOk()->assertJsonPath('dados.estado_motivo', 'Venda desfeita');
        $muda('EM_OBRA')->assertOk();
        $muda('XPTO')->assertStatus(422);
        $h = $this->getJson("/api/imobiliario/fracoes/{$f}", $this->gestor)->json('dados.historico');
        $this->assertSame(['EM_OBRA', 'DISPONIVEL', 'VENDIDA', 'ARRENDADA', 'DISPONIVEL'], array_column($h, 'estado_para'));
        $this->assertNull($h[4]['estado_de']);
        $this->assertSame('Venda desfeita', $h[1]['motivo']);
        // a edição não muda o estado (só mudarEstado, com trilho)
        $this->putJson("/api/imobiliario/fracoes/{$f}", ['designacao' => 'Loja A', 'estado' => 'RESERVADA'], $this->gestor)->assertOk()->assertJsonPath('dados.estado', 'EM_OBRA');
    }

    #[Test]
    public function o_resumo_de_ocupacao_so_conta_as_fracoes_principais(): void
    {
        $i = $this->imovel();
        $mudar = fn (int $f, string $e) => $this->postJson("/api/imobiliario/fracoes/{$f}/estado", ['estado' => $e, 'motivo' => 'x'], $this->gestor)->assertOk();
        $a = $this->fracao($i, ['designacao' => 'A', 'area_bruta' => 50])['id'];
        $b = $this->fracao($i, ['designacao' => 'B', 'area_bruta' => 50])['id'];
        $c = $this->fracao($i, ['designacao' => 'C', 'area_bruta' => 60])['id'];
        $this->fracao($i, ['designacao' => 'D', 'area_bruta' => 40]);
        $this->fracao($i, ['designacao' => 'Arrumos', 'natureza' => 'ARRECADACAO', 'area_bruta' => 5]);
        $mudar($a, 'ARRENDADA');
        $mudar($b, 'ARRENDADA');
        $mudar($c, 'VENDIDA');
        $r = $this->getJson('/api/imobiliario/resumo', $this->gestor)->assertOk()->json('dados');
        $this->assertSame([1, 4, 3, 75, 1, 0], [$r['imoveis'], $r['unidades'], $r['ocupadas'], $r['taxa_ocupacao'], $r['arrecadacoes'], $r['vagas']]);
        $this->assertEquals(200, $r['area_bruta']);
        $this->assertSame(['DISPONIVEL' => 1, 'RESERVADA' => 0, 'ARRENDADA' => 2, 'VENDIDA' => 1, 'EM_OBRA' => 0, 'INDISPONIVEL' => 0], $r['por_estado']);
        $this->assertSame(['PROPRIO' => 1, 'ARRENDADO' => 0, 'MEDIACAO' => 0], $r['por_papel']);
        $l = $this->getJson("/api/imobiliario/imoveis/{$i}", $this->gestor)->json('dados');
        $this->assertSame([4, 3, 1, 75, 1], [$l['total_fracoes'], $l['ocupadas'], $l['livres'], $l['taxa_ocupacao'], $l['acessorios']]);
    }

    #[Test]
    public function documentos_so_aceitam_ligacoes_http_e_descartam_as_vazias(): void
    {
        $i = $this->imovel(['documentos' => [['tipo' => 'MATRIZ', 'nome' => 'Caderneta', 'url' => 'exemplo.ao/matriz.pdf', 'data' => '2026-01-10'], ['tipo' => 'XPTO', 'url' => 'https://exemplo.ao/x'], ['nome' => 'Sem url', 'url' => '']]]);
        $d = $this->getJson("/api/imobiliario/imoveis/{$i}", $this->gestor)->json('dados.documentos');
        $this->assertCount(2, $d);
        $this->assertSame(['MATRIZ', 'https://exemplo.ao/matriz.pdf'], [$d[0]['tipo'], $d[0]['url']]);
        $this->assertSame('OUTRO', $d[1]['tipo']);
        $this->postJson('/api/imobiliario/imoveis', ['nome' => 'Mau', 'documentos' => [['url' => 'ftp://x.ao/a']]], $this->gestor)->assertStatus(422);
    }

    // ---------------------------------------------------------------- isolamento e migração

    #[Test]
    public function uma_empresa_nao_ve_nem_toca_nos_dados_de_outra(): void
    {
        $i = $this->imovel();
        $f = $this->fracao($i)['id'];
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $b = $this->sessao(['imo_imoveis_view', 'imo_ocupacao_view', 'imo_imovel_editar', 'imo_fraccao_editar', 'imo_estado_mudar', 'imo_modelos_view', 'imo_modelo_editar', 'imo_taxas_editar'], $outra);
        $this->assertSame([], $this->getJson('/api/imobiliario/imoveis', $b)->json('dados'));
        $this->getJson("/api/imobiliario/imoveis/{$i}", $b)->assertNotFound();
        $this->putJson("/api/imobiliario/imoveis/{$i}", ['nome' => 'X'], $b)->assertNotFound();
        $this->deleteJson("/api/imobiliario/imoveis/{$i}", [], $b)->assertNotFound();
        $this->getJson("/api/imobiliario/imoveis/{$i}/fracoes", $b)->assertNotFound();
        $this->getJson("/api/imobiliario/fracoes/{$f}", $b)->assertNotFound();
        $this->postJson("/api/imobiliario/fracoes/{$f}/estado", ['estado' => 'RESERVADA'], $b)->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/imobiliario/resumo', $b)->json('dados.imoveis'));
        // o código recomeça na outra empresa (sequência por empresa)
        $c = $this->imovel(['nome' => 'Da outra'], $b);
        $this->assertSame('PR0001', $this->getJson("/api/imobiliario/imoveis/{$c}", $b)->json('dados.codigo'));
        // a empresa A (cabeçalho trocado) é recusada
        $this->getJson('/api/imobiliario/imoveis', ['X-Empresa-Id' => $this->empresa->id] + $b)->assertForbidden();
    }

    #[Test]
    public function a_migracao_e_idempotente_e_as_restricoes_da_base_de_dados_protegem_os_dados(): void
    {
        $migracao = require database_path('migrations/2026_12_15_090000_imobiliario_onda1_cadastro_modelos.php');
        $migracao->up();
        $migracao->up();
        foreach (['imoveis', 'fracoes', 'historico_estado_fracao', 'modelos_documento_imo', 'taxas_imobiliario'] as $t) {
            $this->assertTrue(DB::selectOne('SELECT relrowsecurity FROM pg_class WHERE relname = ?', [$t])->relrowsecurity, "{$t} sem RLS");
        }
        $i = $this->imovel();
        $f = $this->fracao($i)['id'];
        $inserir = fn (string $tabela, array $d) => DB::table($tabela)->insert($d + ['empresa_id' => $this->empresa->id]);
        $rejeita = function (callable $c, string $msg): void {
            try {
                DB::transaction($c);
                $this->fail($msg);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $rejeita(fn () => $inserir('imoveis', ['codigo' => 'pr0001', 'nome' => 'Duplicado']), 'código repetido sem distinguir maiúsculas');
        $rejeita(fn () => $inserir('imoveis', ['codigo' => 'X1', 'nome' => 'Papel', 'papel' => 'ARRENDADO']), 'arrendado sem proprietário');
        $rejeita(fn () => $inserir('imoveis', ['codigo' => 'X2', 'nome' => 'Áreas', 'area_bruta' => 10, 'area_util' => 20]), 'área útil acima da bruta');
        $rejeita(fn () => $inserir('fracoes', ['imovel_id' => $i, 'codigo' => 'Z', 'designacao' => 'loja a']), 'designação repetida');
        $rejeita(fn () => $inserir('fracoes', ['imovel_id' => $i, 'codigo' => 'Z2', 'designacao' => 'Z2', 'estado' => 'XPTO']), 'estado inválido');
        $rejeita(fn () => $inserir('historico_estado_fracao', ['fracao_id' => $f, 'estado_para' => 'XPTO']), 'estado do histórico inválido');
        $rejeita(fn () => $inserir('taxas_imobiliario', ['ano' => 2026, 'ip_taxa' => 150]), 'percentagem acima de 100');
        $rejeita(fn () => $inserir('modelos_documento_imo', ['tipo' => 'XPTO', 'nome' => 'M', 'corpo' => 'x']), 'tipo de modelo inválido');
    }
}
