<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FixturesImobiliarioF4;
use Tests\TestCase;

/**
 * Gestão Imobiliária, Onda 3 (F4): parqueamento. Gerar vagas por piso (numeração, repetição, limites), criar/editar/apagar sem perder o
 * código, afectar e libertar (recusa a reatribuição silenciosa, o uso comum e a vaga com contrato próprio), mapa com a ocupação por
 * contrato próprio ou por arrastamento da fracção, livres, avisos, encargos propostos ao contrato (origem PARQUE), veículos do contrato
 * (matrícula normalizada, cor e dístico preservados, porte, duplicados), isolamento por empresa, permissões e restrições/idempotência da
 * migração. Os números são calculados à mão. A data é fixada em 2026-03-20.
 */
final class ImobiliarioParqueamentoTest extends TestCase
{
    use FixturesImobiliarioF4;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montar();
    }

    /** @param  array<string, mixed>  $o */
    private function gerar(int $im, array $o = []): array
    {
        return $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", $o + ['pisos' => '-1', 'por_piso' => 3, 'prefixo' => 'G'], $this->s)->assertOk()->json('dados');
    }

    /** @return array<string, array<string, mixed>> as vagas do mapa, por designação */
    private function vagas(int $im, ?string $texto = null): array
    {
        $m = $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa".($texto ? '?texto='.urlencode($texto) : ''), $this->s)->assertOk()->json('dados');
        $v = [];
        foreach ($m['pisos'] as $p) {
            foreach ($p['vagas'] as $x) {
                $v[$x['designacao']] = $x;
            }
        }

        return $v;
    }

    private function mapa(int $im): array
    {
        return $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa", $this->s)->assertOk()->json('dados');
    }

    // ---------------------------------------------------------------- gerar

    #[Test]
    public function gerar_vagas_numera_por_piso_nao_duplica_e_respeita_os_limites(): void
    {
        $im = $this->imovel();
        $r = $this->gerar($im, ['pisos' => '-1,-2', 'por_piso' => 2, 'park_altura_max' => 2.1, 'renda_referencia' => 25000, 'park_tomada' => true]);
        $this->assertSame(['criadas' => 4, 'repetidas' => 0], $r);
        $v = $this->vagas($im);
        $this->assertSame(['G-101', 'G-102', 'G-201', 'G-202'], collect(array_keys($v))->sort()->values()->all());
        $this->assertSame(['PR0001-G101', 'SUBTERRANEO', 'LIGEIRO', 'AUTONOMA', 2.1, 25000.0, true], [DB::table('fracoes')->where('id', $v['G-101']['id'])->value('codigo'), $v['G-101']['tipo'], $v['G-101']['porte'],
            $v['G-101']['afectacao'], $v['G-101']['altura_max'], $v['G-101']['renda_referencia'], $v['G-101']['tomada']]);
        $this->assertSame('PR0001-G202', DB::table('fracoes')->where('id', $v['G-202']['id'])->value('codigo'));
        // gerar duas vezes não duplica: o que já existe é saltado e contado
        $this->assertSame(['criadas' => 0, 'repetidas' => 4], $this->gerar($im, ['pisos' => '-1,-2', 'por_piso' => 2]));
        // piso positivo e negativo com o mesmo módulo dão a mesma designação: o segundo é saltado
        $this->assertSame(['criadas' => 0, 'repetidas' => 1], $this->gerar($im, ['pisos' => '1', 'por_piso' => 1]));
        // ao R/C o número tem dois dígitos; fora da cave o tipo por omissão é descoberto
        $this->assertSame(['criadas' => 2, 'repetidas' => 0], $this->gerar($im, ['pisos' => [0], 'por_piso' => 2]));
        $v = $this->vagas($im);
        $this->assertArrayHasKey('G-01', $v);
        $this->assertSame(['DESCOBERTO', 'R/C'], [$v['G-02']['tipo'], $v['G-02']['andar']]);
        // outro prefixo, bloco e tipo
        $this->gerar($im, ['pisos' => '-3', 'por_piso' => 1, 'prefixo' => 'p', 'bloco' => 'A', 'park_tipo' => 'BOX', 'park_porte' => 'SUV']);
        $p = $this->vagas($im)['P-301'];
        $this->assertSame(['BOX', 'SUV'], [$p['tipo'], $p['porte']]);
        $this->assertSame(['A', '301'], [DB::table('fracoes')->where('id', $p['id'])->value('bloco'), DB::table('fracoes')->where('id', $p['id'])->value('numero')]);
        // limites e validações
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => '-1,-2', 'por_piso' => 300], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_DEMASIADAS');
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => '-1', 'por_piso' => 2, 'afectacao' => 'PERMANENTE'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_GERAR_PERMANENTE');
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => 'abc'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_PISOS');
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => '-1', 'park_tipo' => 'VOADOR'], $this->s)->assertStatus(422);
        // as vagas vivem na tabela das fracções, mas não contam para a taxa de ocupação
        $resumo = $this->getJson('/api/imobiliario/resumo', $this->s)->assertOk();
        $this->assertSame(0, $resumo->json('dados.unidades'));
        $this->assertSame(7, $resumo->json('dados.vagas'));
        $this->assertSame(7, DB::table('historico_estado_fracao')->count());
    }

    #[Test]
    public function editar_uma_vaga_nao_recalcula_o_codigo_nem_perde_o_bloco_e_o_numero(): void
    {
        $im = $this->imovel();
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 1, 'bloco' => 'A']);
        $id = $this->vagas($im)['G-101']['id'];
        $antes = (array) DB::table('fracoes')->where('id', $id)->first();
        $this->putJson("/api/imobiliario/parque/vagas/{$id}", ['designacao' => 'G-101', 'park_distico' => 'D-7', 'renda_referencia' => 30000, 'park_altura_max' => 1.95], $this->s)->assertOk();
        $depois = (array) DB::table('fracoes')->where('id', $id)->first();
        foreach (['codigo', 'bloco', 'numero', 'andar', 'piso', 'ordem', 'estado'] as $k) {
            $this->assertSame($antes[$k], $depois[$k], "o campo {$k} não devia mudar ao editar");
        }
        $this->assertSame(['D-7', '30000.00', '1.95'], [$depois['park_distico'], $depois['renda_referencia'], $depois['park_altura_max']]);
        // limpar a altura grava vazio; omitir não mexe
        $this->putJson("/api/imobiliario/parque/vagas/{$id}", ['designacao' => 'G-101', 'park_altura_max' => null], $this->s)->assertOk();
        $this->assertNull(DB::table('fracoes')->where('id', $id)->value('park_altura_max'));
        $this->putJson("/api/imobiliario/parque/vagas/{$id}", ['designacao' => 'G-101 bis'], $this->s)->assertOk();
        $this->assertSame('D-7', DB::table('fracoes')->where('id', $id)->value('park_distico'));
    }

    #[Test]
    public function vaga_avulsa_tem_codigo_proprio_designacao_unica_e_so_se_apaga_sem_contratos(): void
    {
        $im = $this->imovel();
        $a = $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'Lugar do gerente', 'piso' => 0, 'park_porte' => 'SUV'], $this->s)->assertCreated();
        $b = $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'Lugar do director', 'piso' => -1], $this->s)->assertCreated();
        $this->assertSame(['PR0001-V001', 'PR0001-V002'], [$a->json('dados.codigo'), $b->json('dados.codigo')]);
        $this->assertSame(['ESTACIONAMENTO', 'GARAGEM', 'ESTACIONAMENTO', 'DISPONIVEL', 'SUBTERRANEO'], [$b->json('dados.natureza'), $b->json('dados.tipologia'), $b->json('dados.finalidade'), $b->json('dados.estado'), $b->json('dados.park_tipo')]);
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'LUGAR DO GERENTE'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_DESIGNACAO');
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_SEM_DESIGNACAO');
        $this->putJson('/api/imobiliario/parque/vagas/'.$b->json('dados.id'), ['designacao' => 'Lugar do gerente'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_DESIGNACAO');
        // criar uma vaga por aqui nunca cria fracções: a fracção continua a recusar a natureza estacionamento
        $f = $this->fracao($im);
        $this->postJson("/api/imobiliario/imoveis/{$im}/fracoes", ['designacao' => 'Vaga X', 'natureza' => 'ESTACIONAMENTO'], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FRACCAO_NATUREZA_INDISPONIVEL');
        $this->putJson("/api/imobiliario/parque/vagas/{$f}", ['designacao' => 'x'], $this->s)->assertNotFound()->assertJsonPath('codigo', 'PARQUE_NAO_VAGA');
        // apagar: livre apaga; com contrato (mesmo terminado) não
        $this->deleteJson('/api/imobiliario/parque/vagas/'.$a->json('dados.id'), [], $this->s)->assertOk();
        $this->assertSame(1, DB::table('fracoes')->where('natureza', 'ESTACIONAMENTO')->count());
        $this->contrato($this->corpo($b->json('dados.id')));
        $this->deleteJson('/api/imobiliario/parque/vagas/'.$b->json('dados.id'), [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_EM_USO');
    }

    // ---------------------------------------------------------------- afectar e libertar

    #[Test]
    public function afectar_recusa_a_reatribuicao_silenciosa_o_uso_comum_e_a_vaga_com_contrato_proprio(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $fb = $this->fracao($im, '102');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 3, 'renda_referencia' => 25000]);
        $v = $this->vagas($im);
        $afectar = fn (int $f, array $ids, array $o = []) => $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $f, 'vaga_ids' => $ids] + $o, $this->s);
        $r = $afectar($fa, [$v['G-101']['id'], $v['G-102']['id']], ['incluida_na_renda' => true])->assertOk();
        $this->assertSame(2, $r->json('dados.afectadas'));
        $m = $this->vagas($im);
        $this->assertSame(['PERMANENTE', '101', true], [$m['G-101']['afectacao'], $m['G-101']['afecta_a'], $m['G-101']['incluida_na_renda']]);
        // o legado reatribuía em silêncio: agora recusa e diz de quem é
        $afectar($fb, [$v['G-101']['id']])->assertStatus(409)->assertJsonPath('codigo', 'PARQUE_JA_AFECTA')->assertJsonPath('erros.afecta_a', '101');
        // com confirmação passa, com aviso para rever os contratos
        $r = $afectar($fb, [$v['G-101']['id']], ['reatribuir' => true])->assertOk();
        $this->assertCount(1, $r->json('dados.avisos'));
        $this->assertSame('102', $this->vagas($im)['G-101']['afecta_a']);
        // vaga de uso comum não se afecta
        $visita = $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'Visitas 1', 'afectacao' => 'VISITA'], $this->s)->assertCreated()->json('dados.id');
        $afectar($fa, [$visita])->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_VAGA_USO_COMUM');
        // vaga com contrato próprio vivo: o mesmo lugar ficava prometido a duas pessoas
        $this->contrato($this->corpo($v['G-103']['id']));
        $afectar($fa, [$v['G-103']['id']])->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_PROMETIDA_DUAS');
        // a mãe tem de ser fracção principal do mesmo imóvel
        $afectar($v['G-102']['id'], [$v['G-101']['id']])->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_MAE_INVALIDA');
        $outro = $this->imovel('Outro prédio');
        $afectar($this->fracao($outro, '201'), [$v['G-101']['id']])->assertStatus(422)->assertJsonPath('codigo', 'PARQUE_VAGA_OUTRO_IMOVEL');
        $afectar($fa, [])->assertStatus(422);
        // a fracção com vagas afectas não se apaga
        $this->deleteJson("/api/imobiliario/fracoes/{$fb}", [], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'FRACCAO_COM_VAGAS');
        // uma vaga já afecta a uma fracção não passa a contrato próprio de uso comum nem a afectação de visita
        $this->putJson('/api/imobiliario/parque/vagas/'.$v['G-102']['id'], ['designacao' => 'G-102', 'afectacao' => 'VISITA'], $this->s)->assertOk();
        $this->assertNull(DB::table('fracoes')->where('id', $v['G-102']['id'])->value('park_fracao_id'));
    }

    #[Test]
    public function libertar_volta_a_autonoma_e_avisa_se_a_fraccao_estava_a_usar_a_vaga(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 2]);
        $v = $this->vagas($im);
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-101']['id'], $v['G-102']['id']]], $this->s)->assertOk();
        $c = $this->contrato($this->corpo($fa));   // a fracção arrendada leva as duas vagas
        $r = $this->postJson('/api/imobiliario/parque/libertar', ['vaga_ids' => [$v['G-101']['id'], 999999]], $this->s)->assertOk();
        $this->assertSame(1, $r->json('dados.libertadas'));   // conta só as que existem (o legado contava os ids recebidos)
        $this->assertStringContainsString("estava a ser usada pelo contrato {$c['numero']}", $r->json('dados.avisos.0'));
        $m = $this->vagas($im)['G-101'];
        $this->assertSame(['AUTONOMA', null, null], [$m['afectacao'], $m['fracao_id'], $m['incluida_na_renda']]);
        $this->postJson('/api/imobiliario/parque/libertar', ['vaga_ids' => []], $this->s)->assertStatus(422);
    }

    #[Test]
    public function encargos_propostos_vem_da_renda_de_referencia_e_o_contrato_guarda_a_origem_parque(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 2, 'renda_referencia' => 25000, 'park_altura_max' => 2.1, 'park_tomada' => true, 'park_tipo' => 'COBERTO']);
        $v = $this->vagas($im);
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-101']['id']], 'incluida_na_renda' => false], $this->s)->assertOk();
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-102']['id']]], $this->s)->assertOk();   // sugestão em aberto
        $e = $this->getJson("/api/imobiliario/parque/encargos-propostos?fracao_ids={$fa}", $this->s)->assertOk()->json('dados');
        $this->assertCount(2, $e);
        $this->assertSame(['PARQUE', 'Lugar de estacionamento G-101', 25000.0, false, 'Coberto · Ligeiro · com tomada · altura máx. 2.1 m'],
            [$e[0]['origem'], $e[0]['designacao'], $e[0]['valor'], $e[0]['incluido_na_renda'], $e[0]['detalhe']]);
        $this->assertNull($e[1]['incluido_na_renda']);
        // o contrato (F3) grava os encargos propostos com a origem PARQUE
        $c = $this->contrato($this->corpo($fa, ['encargos' => [['designacao' => $e[0]['designacao'], 'valor' => $e[0]['valor'], 'incluido_na_renda' => false, 'origem' => 'PARQUE', 'fracao_id' => $fa]]]), false);
        $ficha = $this->getJson("/api/imobiliario/contratos/{$c['id']}", $this->s)->assertOk()->json('dados.encargos');
        $this->assertSame(['PARQUE', 25000.0], [$ficha[0]['origem'], $ficha[0]['valor']]);
        $this->getJson('/api/imobiliario/parque/encargos-propostos?fracao_ids=', $this->s)->assertStatus(422);
        $this->assertSame([], $this->getJson('/api/imobiliario/parque/encargos-propostos?fracao_ids=999999', $this->s)->assertOk()->json('dados'));
    }

    // ---------------------------------------------------------------- mapa, ocupação e avisos

    #[Test]
    public function mapa_mostra_a_ocupacao_por_contrato_proprio_ou_por_arrastamento_e_o_resumo(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 4]);
        $this->gerar($im, ['pisos' => '-2', 'por_piso' => 1]);
        $v = $this->vagas($im);
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-101']['id']]], $this->s)->assertOk();
        $this->contrato($this->corpo($fa));                                         // G-101 ocupada por arrastamento
        $cp = $this->contrato($this->corpo($v['G-103']['id'], ['terceiro_id' => $this->cliente2]));   // G-103 com contrato próprio
        $visita = $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'Visitas 1', 'afectacao' => 'VISITA', 'piso' => -1], $this->s)->assertCreated();
        $m = $this->mapa($im);
        $this->assertSame(6, $m['total']);
        $this->assertSame(['Cave', '2.ª cave'], array_column($m['pisos'], 'andar'));   // do piso mais alto para o mais baixo
        $r = $m['resumo'];
        // 5 arrendáveis (as 4 da cave e a da 2.ª cave), 2 ocupadas, 3 livres: taxa 2/5 = 40 %; a de visitas fica de fora
        $this->assertSame([6, 5, 2, 3, 40, 1, 4, 1, 1, 1], [$r['total'], $r['arrendaveis'], $r['ocupadas'], $r['livres'], $r['taxa'], $r['afectas'], $r['autonomas'], $r['visita'], $r['por_contrato_proprio'], $r['por_fraccao']]);
        $x = $this->vagas($im);
        $this->assertSame(['FRACCAO', 'Inquilino Fictício', true], [$x['G-101']['via'], $x['G-101']['ocupante'], $x['G-101']['ocupada']]);
        $this->assertSame(['PROPRIO', 'Segundo Inquilino', $cp['numero']], [$x['G-103']['via'], $x['G-103']['ocupante'], $x['G-103']['contrato']]);
        $this->assertFalse($x['G-102']['ocupada']);
        $this->assertFalse($x['Visitas 1']['arrendavel']);
        // filtro de texto: o resumo conta todas, o mapa só as que casam (ocupante, contrato, fracção)
        $f = $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa?texto=".urlencode('segundo'), $this->s)->assertOk()->json('dados');
        $this->assertSame(['G-103'], collect($f['pisos'])->pluck('vagas')->flatten(1)->pluck('designacao')->all());
        $this->assertSame(6, $f['total']);
        $this->assertSame(['G-101'], array_keys($this->vagas($im, '101')));   // pela fracção a que está afecta
        // lugares livres: arrendáveis sem contrato vivo, fora de obra
        $livres = collect($this->getJson("/api/imobiliario/imoveis/{$im}/parque/livres", $this->s)->assertOk()->json('dados'))->pluck('designacao')->sort()->values()->all();
        $this->assertSame(['G-102', 'G-104', 'G-201'], $livres);
        $this->postJson('/api/imobiliario/fracoes/'.$x['G-102']['id'].'/estado', ['estado' => 'EM_OBRA'], $this->s)->assertOk();
        $this->assertNotContains('G-102', collect($this->getJson("/api/imobiliario/imoveis/{$im}/parque/livres", $this->s)->json('dados'))->pluck('designacao')->all());
        // uma vaga de visita não entra num contrato (F3)
        $this->postJson('/api/imobiliario/contratos', $this->corpo($visita->json('dados.id')), $this->s)->assertStatus(422)->assertJsonPath('codigo', 'IMO_FRACCAO_NAO_ARRENDAVEL');
        // avisos
        $av = $m['avisos'];
        $this->assertContains('5 vaga(s) arrendáveis sem renda de referência.', $av);
        $this->assertContains('6 vaga(s) em cave sem altura máxima — é o que evita prometer um lugar onde o carro não entra.', $av);
    }

    #[Test]
    public function sem_vagas_o_mapa_vem_vazio_e_o_mapa_de_ocupacao_das_fraccoes_nao_as_conta(): void
    {
        $im = $this->imovel();
        $m = $this->mapa($im);
        $this->assertSame([[], 0, null], [$m['pisos'], $m['total'], $m['resumo']]);
        $this->fracao($im, '101');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 2]);
        $imo = $this->getJson("/api/imobiliario/imoveis/{$im}", $this->s)->assertOk();
        $this->assertSame(1, $imo->json('dados.total_fracoes'));   // a taxa de ocupação só vê fracções principais
        $this->assertSame(2, $imo->json('dados.acessorios'));
    }

    // ---------------------------------------------------------------- veículos

    #[Test]
    public function veiculos_do_contrato_normalizam_a_matricula_e_preservam_cor_e_distico(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $fb = $this->fracao($im, '102');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 3]);
        $v = $this->vagas($im);
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-101']['id'], $v['G-102']['id']]], $this->s)->assertOk();
        $ca = $this->contrato($this->corpo($fa));
        $cb = $this->contrato($this->corpo($fb, ['terceiro_id' => $this->cliente2]));
        $url = fn (int $c) => "/api/imobiliario/contratos/{$c}/veiculos";
        $x = ['matricula' => ' ld 12 34 ab ', 'marca' => 'Toyota', 'modelo' => 'Hilux', 'cor' => 'Branco', 'porte' => 'SUV', 'distico' => 'D-9', 'condutor' => 'Ana', 'vaga_id' => $v['G-101']['id']];
        $this->putJson($url($ca['id']), ['veiculos' => [$x]], $this->s)->assertOk()->assertJsonPath('dados.gravados', 1);
        $g = $this->getJson($url($ca['id']), $this->s)->assertOk()->json('dados');
        $this->assertSame(2, $g['lugares']);   // o contrato dá direito às duas vagas afectas
        $this->assertSame(['LD-12-34-AB', 'Branco', 'D-9', 'Ana', $v['G-101']['id']], [$g['veiculos'][0]['matricula'], $g['veiculos'][0]['cor'], $g['veiculos'][0]['distico'], $g['veiculos'][0]['condutor'], $g['veiculos'][0]['vaga_id']]);
        // regravar substitui a lista e continua a preservar o que se envia
        $this->putJson($url($ca['id']), ['veiculos' => [$x, ['matricula' => 'abc-123', 'porte' => 'MOTA']]], $this->s)->assertOk()->assertJsonPath('dados.gravados', 2);
        $this->assertSame(['LD-12-34-AB', 'ABC123'], DB::table('veiculos_contrato')->where('contrato_id', $ca['id'])->orderBy('ordem')->pluck('matricula')->all());
        $this->assertSame(2, DB::table('veiculos_contrato')->where('contrato_id', $ca['id'])->count());
        // porte: o SUV não cabe num lugar de ligeiros; a mota cabe em qualquer um
        $m = $this->vagas($im);
        $this->assertSame(['LD-12-34-AB'], $m['G-101']['conflito_porte']);
        $this->assertSame(1, $this->mapa($im)['resumo']['conflitos']);
        $this->assertContains('1 vaga(s) com veículo maior do que o porte do lugar.', $this->mapa($im)['avisos']);
        $this->assertSame([], $m['G-102']['conflito_porte']);
        // o veículo sem lugar aplica-se a todas as vagas do contrato; o de G-101 só a essa
        $this->assertContains('ABC123', array_column($m['G-102']['veiculos'], 'matricula'));
        $this->assertNotContains('LD-12-34-AB', array_column($m['G-102']['veiculos'], 'matricula'));
        // validações
        $this->putJson($url($ca['id']), ['veiculos' => [['matricula' => 'LD1234AB'], ['matricula' => 'ld-12-34-ab']]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'VEICULOS_DUPLICADO');
        $this->putJson($url($ca['id']), ['veiculos' => [['marca' => 'Ford']]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'VEICULOS_SEM_MATRICULA');
        $this->putJson($url($ca['id']), ['veiculos' => [['matricula' => 'XX1', 'vaga_id' => $v['G-103']['id']]]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'VEICULOS_VAGA');
        $this->putJson($url($ca['id']), ['veiculos' => [['matricula' => 'XX1', 'porte' => 'NAVIO']]], $this->s)->assertStatus(422);
        // a mesma matrícula noutro contrato em vigor do imóvel pede confirmação (e a lista do primeiro não muda)
        $r = $this->putJson($url($cb['id']), ['veiculos' => [['matricula' => 'LD 12 34 AB']]], $this->s)->assertStatus(409)->assertJsonPath('codigo', 'VEICULOS_MATRICULA_REPETIDA');
        $this->assertSame([$ca['numero']], $r->json('erros.repetidas.0.contratos'));
        $this->putJson($url($cb['id']), ['veiculos' => [['matricula' => 'LD 12 34 AB']], 'confirmar_repetidas' => true], $this->s)->assertOk()->assertJsonPath('dados.gravados', 1);
        // lista vazia limpa; contrato terminado não se altera; contrato tomado não tem veículos
        $this->putJson($url($cb['id']), ['veiculos' => []], $this->s)->assertOk()->assertJsonPath('dados.gravados', 0);
        $this->postJson("/api/imobiliario/contratos/{$cb['id']}/terminar", ['data' => '2026-03-20', 'motivo' => 'Saída'], $this->s)->assertOk();
        $this->putJson($url($cb['id']), ['veiculos' => [['matricula' => 'ZZ1']]], $this->s)->assertStatus(422)->assertJsonPath('codigo', 'VEICULOS_CONTRATO_TERMINADO');
    }

    // ---------------------------------------------------------------- isolamento, permissões e base de dados

    #[Test]
    public function isolamento_por_empresa_no_parqueamento(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $this->gerar($im, ['pisos' => '-1', 'por_piso' => 2]);
        $v = $this->vagas($im);
        $c = $this->contrato($this->corpo($fa));
        $outra = $this->criarEmpresa(['nif' => '5417000777']);
        $h2 = $this->sessao(['imo_parque_view', 'imo_areas_view', 'imo_contratos_view', 'imo_vaga_editar', 'imo_vaga_afectar', 'imo_vaga_veiculos'], $outra);
        $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa", $h2)->assertNotFound();
        $this->getJson("/api/imobiliario/imoveis/{$im}/parque/livres", $h2)->assertNotFound();
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => '-1'], $h2)->assertNotFound();
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", ['designacao' => 'X'], $h2)->assertNotFound();
        $this->putJson('/api/imobiliario/parque/vagas/'.$v['G-101']['id'], ['designacao' => 'Roubada'], $h2)->assertNotFound();
        $this->deleteJson('/api/imobiliario/parque/vagas/'.$v['G-101']['id'], [], $h2)->assertNotFound();
        $this->postJson('/api/imobiliario/parque/afectar', ['fracao_id' => $fa, 'vaga_ids' => [$v['G-101']['id']]], $h2)->assertNotFound();
        $this->postJson('/api/imobiliario/parque/libertar', ['vaga_ids' => [$v['G-101']['id']]], $h2)->assertOk()->assertJsonPath('dados.libertadas', 0);
        $this->getJson("/api/imobiliario/contratos/{$c['id']}/veiculos", $h2)->assertNotFound();
        $this->putJson("/api/imobiliario/contratos/{$c['id']}/veiculos", ['veiculos' => [['matricula' => 'AB1']]], $h2)->assertNotFound();
        $this->assertSame([], $this->getJson("/api/imobiliario/parque/encargos-propostos?fracao_ids={$fa}", $h2)->assertOk()->json('dados'));
        $this->assertSame('G-101', $this->vagas($im)['G-101']['designacao']);   // nada foi tocado
    }

    #[Test]
    public function sem_permissao_recusa_com_403_antes_de_validar(): void
    {
        $im = $this->imovel();
        $leitor = $this->sessao(['imo_parque_view']);
        $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa", $leitor)->assertOk();
        $this->getJson('/api/imobiliario/parque/listas', $leitor)->assertOk();
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", [], $leitor)->assertForbidden();
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/vagas", [], $leitor)->assertForbidden();
        $this->putJson('/api/imobiliario/parque/vagas/1', [], $leitor)->assertForbidden();
        $this->deleteJson('/api/imobiliario/parque/vagas/1', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/parque/afectar', [], $leitor)->assertForbidden();
        $this->postJson('/api/imobiliario/parque/libertar', [], $leitor)->assertForbidden();
        $this->putJson('/api/imobiliario/contratos/1/veiculos', [], $leitor)->assertForbidden();
        $semAcesso = $this->sessao(['dashboard_view']);
        $this->getJson("/api/imobiliario/imoveis/{$im}/parque/mapa", $semAcesso)->assertForbidden();
        $this->getJson('/api/imobiliario/parque/listas', $semAcesso)->assertForbidden();
        $this->getJson('/api/imobiliario/parque/encargos-propostos?fracao_ids=1', $semAcesso)->assertForbidden();
        // quem só tem a afectação não gera vagas e vice-versa
        $afecta = $this->sessao(['imo_parque_view', 'imo_vaga_afectar']);
        $this->postJson("/api/imobiliario/imoveis/{$im}/parque/gerar", ['pisos' => '-1'], $afecta)->assertForbidden();
        $this->postJson('/api/imobiliario/parque/libertar', ['vaga_ids' => [1]], $afecta)->assertOk();
    }

    #[Test]
    public function restricoes_da_base_de_dados_e_idempotencia_da_migracao(): void
    {
        $im = $this->imovel();
        $fa = $this->fracao($im, '101');
        $c = $this->contrato($this->corpo($fa));
        $this->putJson("/api/imobiliario/contratos/{$c['id']}/veiculos", ['veiculos' => [['matricula' => 'LD-12-34-AB']]], $this->s)->assertOk();
        $linha = (array) DB::table('veiculos_contrato')->where('contrato_id', $c['id'])->first();
        unset($linha['id']);
        try {
            DB::transaction(fn () => DB::table('veiculos_contrato')->insert($linha));   // a mesma matrícula no mesmo contrato: UNIQUE
            $this->fail('A matrícula duplicada devia ser recusada.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_veiculos_contrato_matricula', $e->getMessage());
        }
        try {
            DB::transaction(fn () => DB::table('veiculos_contrato')->insert(['matricula' => 'ZZ-1', 'porte' => 'NAVIO'] + $linha));
            $this->fail('O porte desconhecido devia ser recusado.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ck_veiculos_contrato_porte', $e->getMessage());
        }
        $migracao = require base_path('database/migrations/2026_12_18_090000_imobiliario_onda3_parqueamento_reservas.php');
        $migracao->up();
        $migracao->up();
        $this->assertSame(1, DB::table('veiculos_contrato')->where('contrato_id', $c['id'])->count());
        // o ALTER aditivo da configuração existe, é anulável e não estragou a configuração existente
        $this->configurar();
        $this->assertSame($this->artigo, DB::table('configuracao_imobiliario')->value('produto_reserva_id'));
        // RLS: todas as tabelas novas têm política por empresa
        foreach (['veiculos_contrato', 'espacos_comuns', 'reservas_espaco', 'vistorias_reserva', 'historico_reserva_imo', 'movimentos_caucao_reserva'] as $t) {
            $this->assertTrue((bool) DB::scalar('select relrowsecurity from pg_class where relname = ?', [$t]), "{$t} sem RLS");
        }
    }
}
