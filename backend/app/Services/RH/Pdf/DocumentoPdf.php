<?php

namespace App\Services\RH\Pdf;

/**
 * Gerador mínimo de PDF (PDF 1.4) sem dependências: páginas A4, texto em Helvetica/Helvetica-Bold (WinAnsiEncoding,
 * com os acentos do português), linhas e rectângulos. Serve os recibos de vencimento em lote (ZIP com um PDF por
 * colaborador, lacuna A-10), gerados no servidor sem navegador. Coordenadas em milímetros, origem no canto superior
 * esquerdo. Não é um motor de composição: o chamador posiciona cada elemento.
 */
final class DocumentoPdf
{
    public const LARGURA = 210.0;

    public const ALTURA = 297.0;

    /** Larguras AFM (1/1000 em) dos caracteres 32–126: Helvetica e Helvetica-Bold. */
    private const LARG_NORMAL = [278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667,
        611, 278, 278, 278, 469, 556, 333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556, 500, 722, 500, 500,
        500, 334, 260, 334, 584];

    private const LARG_NEGRITO = [278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556, 556, 556, 556,
        333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667,
        611, 333, 278, 333, 584, 556, 333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611, 556, 778, 556, 556,
        500, 389, 280, 389, 584];

    /** @var list<string> conteúdo de cada página */
    private array $paginas = [];

    private int $actual = -1;

    public function novaPagina(): self
    {
        $this->paginas[] = '';
        $this->actual = count($this->paginas) - 1;

        return $this;
    }

    /** Texto na posição (x, y = linha de base) em mm; alinhamento E (esquerda), D (direita) ou C (centro). */
    public function texto(float $x, float $y, string $texto, float $tamanho = 9, bool $negrito = false, string $alinhar = 'E', array $cor = [0, 0, 0]): self
    {
        $largura = $this->larguraTexto($texto, $tamanho, $negrito);
        $x = match ($alinhar) {
            'D' => $x - $largura, 'C' => $x - $largura / 2, default => $x
        };
        $this->escrever(sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $negrito ? 'F2' : 'F1', $tamanho, self::cor($cor), self::pt($x), self::pt(self::ALTURA - $y),
            self::escapar(self::winAnsi($texto))));

        return $this;
    }

    /** Texto cortado com reticências para caber na largura indicada (mm). */
    public function textoLimitado(float $x, float $y, string $texto, float $larguraMax, float $tamanho = 9, bool $negrito = false, string $alinhar = 'E', array $cor = [0, 0, 0]): self
    {
        if ($this->larguraTexto($texto, $tamanho, $negrito) > $larguraMax) {
            while (mb_strlen($texto) > 1 && $this->larguraTexto($texto.'…', $tamanho, $negrito) > $larguraMax) {
                $texto = mb_substr($texto, 0, -1);
            }
            $texto = rtrim($texto).'…';
        }

        return $this->texto($x, $y, $texto, $tamanho, $negrito, $alinhar, $cor);
    }

    public function linha(float $x1, float $y1, float $x2, float $y2, float $espessura = 0.2, array $cor = [0, 0, 0], bool $tracejada = false): self
    {
        $this->escrever(sprintf("%s%.2F w %s RG %.2F %.2F m %.2F %.2F l S%s\n", $tracejada ? "[3 2] 0 d\n" : '', self::pt($espessura), self::cor($cor),
            self::pt($x1), self::pt(self::ALTURA - $y1), self::pt($x2), self::pt(self::ALTURA - $y2), $tracejada ? "\n[] 0 d" : ''));

        return $this;
    }

    /** Rectângulo (x, y = canto superior esquerdo); preenchido e/ou com contorno. */
    public function rectangulo(float $x, float $y, float $l, float $a, ?array $preencher = null, ?array $contorno = [0, 0, 0], float $espessura = 0.2): self
    {
        $op = $preencher && $contorno ? 'B' : ($preencher ? 'f' : 'S');
        $this->escrever(sprintf("%s%s%.2F w %.2F %.2F %.2F %.2F re %s\n", $preencher ? self::cor($preencher).' rg ' : '', $contorno ? self::cor($contorno).' RG ' : '',
            self::pt($espessura), self::pt($x), self::pt(self::ALTURA - $y - $a), self::pt($l), self::pt($a), $op));

        return $this;
    }

    /** Largura do texto em mm. */
    public function larguraTexto(string $texto, float $tamanho, bool $negrito = false): float
    {
        $tabela = $negrito ? self::LARG_NEGRITO : self::LARG_NORMAL;
        $soma = 0;
        $base = self::semAcentos($texto);
        for ($i = 0, $n = strlen($base); $i < $n; $i++) {
            $c = ord($base[$i]);
            $soma += $c >= 32 && $c <= 126 ? $tabela[$c - 32] : 556;
        }

        return $soma / 1000 * $tamanho * 25.4 / 72;
    }

    public function conteudo(): string
    {
        if ($this->paginas === []) {
            $this->novaPagina();
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        foreach ($this->paginas as $conteudo) {
            $pagina = $n++;
            $fluxo = $n++;
            $kids[] = "{$pagina} 0 R";
            $objs[$pagina] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::pt(self::LARGURA), self::pt(self::ALTURA), $fluxo);
            $objs[$fluxo] = '<< /Length '.strlen($conteudo)." >>\nstream\n{$conteudo}endstream";
        }
        $objs[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[$i] = strlen($pdf);
            $pdf .= "{$i} 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $total = max(array_keys($objs)) + 1;
        $pdf .= "xref\n0 {$total}\n0000000000 65535 f \n";
        for ($i = 1; $i < $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }

        return $pdf."trailer\n<< /Size {$total} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function escrever(string $s): void
    {
        if ($this->actual < 0) {
            $this->novaPagina();
        }
        $this->paginas[$this->actual] .= $s;
    }

    private static function pt(float $mm): float
    {
        return $mm * 72 / 25.4;
    }

    private static function cor(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    private static function winAnsi(string $s): string
    {
        $s = str_replace(['—', '–', '…', '·', '’', '‘', '“', '”'], ['-', '-', '...', "\xB7", "'", "'", '"', '"'], $s);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);

        return $r === false ? self::semAcentos($s) : $r;
    }

    private static function semAcentos(string $s): string
    {
        $r = @iconv('UTF-8', 'ASCII//TRANSLIT', str_replace(['—', '–', '…'], ['-', '-', '...'], $s));

        return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    private static function escapar(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }
}
