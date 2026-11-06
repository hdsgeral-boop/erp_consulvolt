<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\LogAuditoria;
use App\Services\POS\ServicoPdfA4POS;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F7 do POS: PDF A4 no servidor. Cabeçalhos seguros da descarga, permissão por tipo de documento, validação, auditoria,
 * limpeza do HTML recebido (sem scripts, ligações, quadros nem ficheiros externos) e rejeição de documentos enormes.
 */
final class POSPdfA4Test extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Empresa $empresa;

    private array $relatorios;

    private array $balcao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000115']);
        $this->relatorios = $this->sessao(['pos_relatorios_view']);
        $this->balcao = $this->sessao(['pos_armazem_expedir']);
    }

    private function sessao(array $permissoes): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function pedido(string $tipo = 'relatorio', array $extra = []): array
    {
        return $extra + ['tipo' => $tipo, 'titulo' => 'Relatório X · Sessão T01', 'html' => '<table><tr><th>Meio</th><td>Numerário</td></tr></table><p>Acentuação: Ação, não, coração</p>',
            'orientacao' => 'retrato'];
    }

    #[Test]
    public function gera_um_pdf_para_descarregar_com_cabecalhos_seguros(): void
    {
        $r = $this->postJson('/api/pos/impressao/pdf', $this->pedido(), $this->relatorios)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', $r->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF-', $r->getContent());
        // A4 retrato (595 x 842 pt) e paisagem
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0(?:\.0+)? 0(?:\.0+)? 595\.\d+ 841\.\d+\]#', $r->getContent());
        $p = $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio', ['orientacao' => 'paisagem']), $this->relatorios)->assertOk();
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0(?:\.0+)? 0(?:\.0+)? 841\.\d+ 595\.\d+\]#', $p->getContent());
    }

    #[Test]
    public function a_permissao_depende_do_tipo_de_documento(): void
    {
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('guia_saida'), $this->relatorios)->assertStatus(403);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio'), $this->balcao)->assertStatus(403);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('guia_saida'), $this->balcao)->assertOk();
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio'), $this->sessao(['teso_view']))->assertStatus(403);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('inventado'), $this->relatorios)->assertStatus(422);
    }

    #[Test]
    public function valida_o_pedido_e_recusa_documentos_enormes(): void
    {
        $this->postJson('/api/pos/impressao/pdf', ['tipo' => 'relatorio'], $this->relatorios)->assertStatus(422);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio', ['orientacao' => 'diagonal']), $this->relatorios)->assertStatus(422);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio', ['titulo' => str_repeat('a', 151)]), $this->relatorios)->assertStatus(422);
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio', ['html' => str_repeat('x', ServicoPdfA4POS::TAMANHO_MAXIMO_HTML + 1)]), $this->relatorios)->assertStatus(422);
    }

    #[Test]
    public function regista_a_geracao_na_auditoria_sem_o_conteudo(): void
    {
        $this->postJson('/api/pos/impressao/pdf', $this->pedido('sessao', ['html' => '<p>SEGREDO-123</p>']), $this->sessao(['pos_fecho']))->assertOk();
        $logs = app(ContextoEmpresa::class)->semIsolamento(fn () => LogAuditoria::query()->where('modulo', 'POS')->where('acao', 'Gerar PDF')->get());
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('sessao', (string) $logs[0]->detalhes);
        $this->assertStringNotContainsString('SEGREDO-123', (string) $logs[0]->detalhes);
        $this->assertSame($this->empresa->id, (int) $logs[0]->empresa_id);
    }

    #[Test]
    public function o_html_recebido_e_limpo_de_scripts_ligacoes_e_ficheiros_externos(): void
    {
        $s = app(ServicoPdfA4POS::class);
        $sujo = '<p onclick="x()" class="a">Olá</p><script>alert(1)</script><iframe src="http://x"></iframe><a href="javascript:x()">l</a>'
            .'<img src="http://externo/x.png" alt="x"><img src="file:///etc/passwd"><img src="'.self::PNG.'" alt="ok" onerror="x()">'
            .'<div style="background:url(http://x/y.png);color:red;width:expression(1)">d</div><form action="/x"><input name="a"></form><object data="x"></object>'
            .'<svg><script>1</script></svg><link rel="stylesheet" href="http://x/a.css"><!-- comentário --><td colspan="2">c</td>';
        $limpo = $s->sanitizarHtml($sujo);
        foreach (['<script', 'onclick', 'onerror', '<iframe', 'javascript:', 'http://', 'file://', '<form', '<input', '<object', '<svg', '<link', 'expression', 'comentário'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $limpo, "ficou «{$proibido}»");
        }
        $this->assertStringContainsString('Olá', $limpo);
        $this->assertStringContainsString('class="a"', $limpo);
        $this->assertStringContainsString(self::PNG, $limpo);
        $this->assertStringContainsString('color:red', $limpo);
        $css = $s->sanitizarCss('@import url(http://x/a.css); .a { background: url("http://x/b.png"); color: #000 } @font-face { src: url(x) } .b{width:expression(alert(1))}');
        foreach (['@import', 'http://', '@font-face', 'expression'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $css);
        }
        $this->assertStringContainsString('color: #000', $css);
    }

    #[Test]
    public function o_qr_svg_do_talao_passa_mas_um_svg_com_recursos_ou_scripts_nao(): void
    {
        $s = app(ServicoPdfA4POS::class);
        $qr = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><rect width="1" height="1"/></svg>';
        $url = 'data:image/svg+xml;charset=utf-8,'.rawurlencode($qr);
        $limpo = $s->sanitizarHtml('<img class="qr" src="'.$url.'" alt="QR">');
        $this->assertStringContainsString('data:image/svg+xml;base64,'.base64_encode($qr), $limpo);
        foreach (['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://x/y.png"/></svg>',
            '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&x;</svg>', '<svg onload="x()" xmlns="http://www.w3.org/2000/svg"/>'] as $mau) {
            $this->assertStringNotContainsString('<img', $s->sanitizarHtml('<img src="data:image/svg+xml;charset=utf-8,'.rawurlencode($mau).'">'));
            $this->assertStringNotContainsString('<img', $s->sanitizarHtml('<img src="data:image/svg+xml;base64,'.base64_encode($mau).'">'));
        }
        $r = $this->postJson('/api/pos/impressao/pdf', $this->pedido('talao', ['html' => '<div class="talao"><b>FR T01/2026/9</b><img class="qr" src="'.$url.'" style="width:38mm"></div>']), $this->sessao(['pos_venda']))->assertOk();
        $this->assertStringStartsWith('%PDF-', $r->getContent());
    }

    #[Test]
    public function um_documento_com_marcacao_hostil_gera_o_pdf_sem_erro(): void
    {
        $html = '<script>alert(1)</script><img src="http://127.0.0.1:1/x.png"><div style="background:url(file:///etc/passwd)">x</div><table><tr><td>'.str_repeat('linha ', 2000).'</td></tr></table>';
        $r = $this->postJson('/api/pos/impressao/pdf', $this->pedido('relatorio', ['html' => $html, 'css' => '@import "http://x";']), $this->relatorios)->assertOk();
        $this->assertStringStartsWith('%PDF-', $r->getContent());
    }
}
