<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Support\Tenancy\ContextoEmpresa;
use DOMDocument;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Options;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * PDF A4 dos impressos do POS gerado no servidor (F7). O cliente envia o CORPO do documento (HTML já montado pelos mesmos
 * construtores do motor de impressão: tabelas e blocos) e o servidor acrescenta o cabeçalho com a identidade da empresa
 * activa (da BD, nunca do pedido), o título, o rodapé e «Página X de Y». O HTML recebido é NÃO FIABILIZADO: passa por uma lista
 * de elementos e atributos permitidos (sem scripts, ligações, formulários, quadros nem ficheiros externos) e o dompdf corre
 * sem PHP, sem JavaScript, sem rede e sem acesso ao sistema de ficheiros (só imagens `data:`).
 * Só A4 (retrato ou paisagem); o talão térmico fica no navegador (ESC/POS está fora de âmbito).
 */
final class ServicoPdfA4POS
{
    public const TAMANHO_MAXIMO_HTML = 1_500_000;

    private const ELEMENTOS_PROIBIDOS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta', 'base', 'form', 'input', 'button',
        'select', 'textarea', 'option', 'video', 'audio', 'source', 'canvas', 'svg', 'math', 'template', 'noscript', 'title', 'head', 'image', 'use', 'foreignobject'];

    private const ATRIBUTOS_PERMITIDOS = ['class', 'style', 'colspan', 'rowspan', 'align', 'valign', 'width', 'height', 'src', 'alt', 'scope', 'border', 'cellpadding', 'cellspacing'];

    private const IMAGEM_DATA = '#^data:image/(?:png|jpe?g|gif|svg\+xml);base64,[A-Za-z0-9+/=\s]+$#i';

    /** SVG em data URL com percentagens (o que o motor do cliente gera para o QR: encodeURIComponent). */
    private const IMAGEM_SVG_URL = '#^data:image/svg\+xml(?:;charset=utf-8)?,[A-Za-z0-9%._~!*\'()-]+$#i';

    /** Marcas de um SVG que puxa recursos ou executa algo: o SVG é recusado. */
    private const SVG_PERIGOSO = '#<!ENTITY|<!DOCTYPE|<script|<image|<foreignObject|<use[\s>]|<style|@import|href\s*=|on[a-z]+\s*=|javascript:#i';

    public function __construct(private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array{titulo: string, html: string, css?: ?string, subtitulo?: ?string, orientacao?: ?string, utilizador?: ?string, nome_ficheiro?: ?string}  $d
     * @return array{nome: string, binario: string, paginas: int}
     */
    public function gerar(array $d, ?string $utilizador = null): array
    {
        if (strlen($d['html']) > self::TAMANHO_MAXIMO_HTML) {
            throw new ErroNegocio('O documento é demasiado grande para gerar o PDF.', 'PDF_DEMASIADO_GRANDE', 422);
        }
        $titulo = $this->texto($d['titulo'], 150);
        $corpo = $this->sanitizarHtml($d['html']);
        $css = $this->sanitizarCss((string) ($d['css'] ?? ''));
        $paisagem = ($d['orientacao'] ?? 'retrato') === 'paisagem';
        $empresa = $this->identidade();
        $html = $this->documento($titulo, $this->texto((string) ($d['subtitulo'] ?? ''), 200), $corpo, $css, $empresa, $utilizador);

        try {
            $dompdf = new Dompdf($this->opcoes());
            $dompdf->setPaper('A4', $paisagem ? 'landscape' : 'portrait');
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();
            $this->rodape($dompdf, $paisagem, trim(($empresa['rodape'] ?? '').' · '.$titulo, ' ·'));
            $binario = (string) $dompdf->output();
            $paginas = $dompdf->getCanvas()->get_page_count();
        } catch (Throwable $e) {
            report($e);
            throw new ErroNegocio('Não foi possível gerar o PDF deste documento.', 'PDF_FALHOU', 422);
        }

        return ['nome' => $this->nomeFicheiro((string) ($d['nome_ficheiro'] ?? $titulo)), 'binario' => $binario, 'paginas' => $paginas];
    }

    private function opcoes(): Options
    {
        $vazio = storage_path('app/pdf-vazio');
        $cache = storage_path('framework/dompdf');
        File::ensureDirectoryExists($vazio);
        File::ensureDirectoryExists($cache);
        $o = new Options;
        $o->set('isRemoteEnabled', false);
        $o->set('isPhpEnabled', false);
        $o->set('isJavascriptEnabled', false);
        $o->set('chroot', [$vazio]);
        $o->set('fontDir', $cache);
        $o->set('fontCache', $cache);
        $o->set('tempDir', $cache);
        $o->set('logOutputFile', '');
        $o->set('allowedProtocols', ['data://' => ['rules' => []]]);
        $o->set('defaultFont', 'DejaVu Sans');
        $o->set('isFontSubsettingEnabled', true);

        return $o;
    }

    /** Rodapé de cada página («rodapé da empresa · título» e «Página X de Y»), desenhado pelo dompdf (sem scripts no HTML). */
    private function rodape(Dompdf $dompdf, bool $paisagem, string $texto): void
    {
        $canvas = $dompdf->getCanvas();
        $fonte = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $largura = $canvas->get_width();
        $alto = $canvas->get_height();
        $texto = mb_substr($texto, 0, 140);
        $canvas->page_text(34, $alto - 28, $texto, $fonte, 7, [0.33, 0.33, 0.33]);
        $canvas->page_text($largura - 34 - 60, $alto - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fonte, 7, [0.33, 0.33, 0.33]);
    }

    /** @return array{nome: string, linhas: string, rodape: string, logotipo: ?string} */
    private function identidade(): array
    {
        $e = DB::table('empresas')->where('id', $this->contexto->obrigatorio())
            ->first(['nome', 'nif', 'endereco', 'provincia', 'municipio', 'telefone', 'email', 'rodape_documento', 'logotipo']);
        $morada = implode(', ', array_filter([trim((string) ($e->endereco ?? '')), trim((string) ($e->municipio ?? '')), trim((string) ($e->provincia ?? ''))]));
        $linhas = array_filter([! empty($e->nif) ? 'NIF '.$e->nif : '', $morada, ! empty($e->telefone) ? 'Tel. '.$e->telefone : '', (string) ($e->email ?? '')]);
        $logo = is_string($e->logotipo ?? null) ? $this->imagemSegura($e->logotipo) : null;

        return ['nome' => (string) ($e->nome ?? ''), 'linhas' => implode(' · ', $linhas), 'rodape' => (string) ($e->rodape_documento ?? ''), 'logotipo' => $logo];
    }

    /** @param  array{nome: string, linhas: string, rodape: string, logotipo: ?string}  $empresa */
    private function documento(string $titulo, string $subtitulo, string $corpo, string $css, array $empresa, ?string $utilizador): string
    {
        $h = fn (string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = $empresa['logotipo'] ? '<img src="'.$h($empresa['logotipo']).'" style="max-height:16mm;max-width:44mm;" alt="">' : '';
        $emitido = now()->format('d/m/Y H:i').($utilizador ? '<br>por '.$h($utilizador) : '');

        return '<!doctype html><html lang="pt"><head><meta charset="utf-8"><title>'.$h($titulo).'</title><style>'
            .'@page { margin: 14mm 12mm 18mm 12mm; } body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #000; } '
            .'table { border-collapse: collapse; } h1 { font-size: 13pt; margin: 0; } .cab td { vertical-align: top; } '
            .'.cab-linha { border-bottom: 0.6mm solid #1f1f1f; } .peq { font-size: 7pt; color: #444; } '
            .'.talao { width: 100%; } .talao table { width: 100%; } .talao th { text-align: left; } '
            .$css.'</style></head><body>'
            .'<table class="cab" width="100%"><tr><td width="60">'.$logo.'</td><td><div style="font-size:13pt;font-weight:bold;">'.$h($empresa['nome']).'</div>'
            .'<div class="peq">'.$h($empresa['linhas']).'</div></td><td align="right" class="peq">Emitido em '.$emitido.'</td></tr></table>'
            .'<div class="cab-linha"></div><div style="height:3mm;"></div>'
            .'<h1>'.$h($titulo).'</h1>'.($subtitulo !== '' ? '<div style="font-size:9pt;color:#333;">'.$h($subtitulo).'</div>' : '')
            .'<div style="height:3mm;"></div>'.$corpo.'</body></html>';
    }

    /** Lista de elementos e atributos permitidos: devolve só o miolo seguro do HTML recebido. */
    public function sanitizarHtml(string $html): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $anterior = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="__raiz">'.$html.'</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        $xp = new DOMXPath($doc);

        foreach (self::ELEMENTOS_PROIBIDOS as $nome) {
            foreach (iterator_to_array($xp->query('//*[translate(local-name(), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'.$nome.'"]') ?: []) as $no) {
                $no->parentNode?->removeChild($no);
            }
        }
        foreach (iterator_to_array($xp->query('//comment() | //processing-instruction()') ?: []) as $no) {
            $no->parentNode?->removeChild($no);
        }
        foreach (iterator_to_array($xp->query('//*[@id="__raiz"]//*') ?: []) as $no) {
            if (! $no instanceof DOMElement || $no->parentNode === null) {
                continue;
            }
            foreach (iterator_to_array($no->attributes) as $a) {
                if (! in_array(strtolower($a->nodeName), self::ATRIBUTOS_PERMITIDOS, true)) {
                    $no->removeAttribute($a->nodeName);
                }
            }
            if ($no->hasAttribute('style')) {
                $no->setAttribute('style', $this->sanitizarCss($no->getAttribute('style')));
            }
            $imagem = strtolower($no->nodeName) === 'img' ? $this->imagemSegura($no->getAttribute('src')) : null;
            if (strtolower($no->nodeName) === 'img' && $imagem === null) {
                $no->parentNode->removeChild($no);
            } elseif ($imagem !== null) {
                $no->setAttribute('src', $imagem);
            } elseif (strtolower($no->nodeName) !== 'img') {
                $no->removeAttribute('src');
            }
        }
        $raiz = $doc->getElementById('__raiz') ?? $xp->query('//div[@id="__raiz"]')->item(0);
        $saida = '';
        foreach ($raiz?->childNodes ?? [] as $filho) {
            $saida .= $doc->saveHTML($filho);
        }

        return $saida;
    }

    /** Única origem de imagem aceite: data URL de png/jpeg/gif/svg (o SVG sem recursos externos nem scripts); devolve-a em base64. */
    private function imagemSegura(string $src): ?string
    {
        $src = trim($src);
        if (preg_match(self::IMAGEM_DATA, $src) === 1 && ! str_contains(strtolower($src), 'image/svg')) {
            return $src;
        }
        $svg = null;
        if (preg_match(self::IMAGEM_DATA, $src) === 1) {
            $svg = base64_decode((string) substr($src, (int) strpos($src, ',') + 1), true) ?: null;
        } elseif (preg_match(self::IMAGEM_SVG_URL, $src) === 1) {
            $svg = rawurldecode(substr($src, (int) strpos($src, ',') + 1));
        }
        if ($svg === null || preg_match(self::SVG_PERIGOSO, $svg) === 1 || ! str_contains($svg, '<svg')) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Sem @import, @font-face, url() externos, expressões nem ligações: só declarações visuais. */
    public function sanitizarCss(string $css): string
    {
        $css = mb_substr($css, 0, 20000);
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $css = str_replace(['<', '>', '\\'], '', $css);
        $css = preg_replace('/@(?:import|font-face|charset|namespace)\b[^;{]*(?:;|\{[^}]*\})?/i', '', $css) ?? '';
        $css = preg_replace('/url\s*\(\s*(["\']?)(?!data:image\/(?:png|jpe?g|gif);base64,)[^)]*\)/i', 'none', $css) ?? '';
        $css = preg_replace('/(?:expression|behaviou?r|-moz-binding|javascript|vbscript)\s*(?::|\()/i', 'x:', $css) ?? '';

        return $css;
    }

    private function texto(string $t, int $max): string
    {
        return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($t)) ?? ''), 0, $max);
    }

    private function nomeFicheiro(string $nome): string
    {
        $limpo = trim(preg_replace('/[^\p{L}\p{N} ._()-]+/u', ' ', $nome) ?? '');
        $limpo = trim(preg_replace('/\s+/', ' ', $limpo) ?? '');

        return ($limpo !== '' ? mb_substr($limpo, 0, 120) : 'Documento').'.pdf';
    }
}
