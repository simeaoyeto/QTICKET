<?php

/**
 * KIAMI — Gerador PDF (sem dependências externas)
 *
 * Produz PDF 1.4 com:
 *  - codificação WinAnsi (acentos, cedilha e traços portugueses corretos);
 *  - cabeçalho com título/subtítulo e régua de destaque;
 *  - secções, resumos chave→valor e tabelas com colunas alinhadas;
 *  - realce do cabeçalho da tabela e listras alternadas nas linhas;
 *  - suporte real a VÁRIAS páginas, com rodapé e numeração.
 */
class PdfExport
{
    /** @var string[] Páginas já fechadas (cada uma é um content stream completo) */
    private array $pages = [];
    private string $content = '';
    private float $y = 800;
    private int $pageNum = 0;

    private int $marginLeft = 40;
    private int $marginRight = 40;
    private int $pageWidth = 595;
    private int $pageHeight = 842;
    private int $marginBottom = 50;

    // Cor de destaque (azul Quality) para réguas do cabeçalho
    private array $accent = [0.24, 0.44, 1.0];

    public function __construct(private string $titulo, private string $subtitulo = '')
    {
        $this->novaPagina(true);
    }

    /**
     * Converte texto UTF-8 para Windows-1252 e escapa os caracteres especiais
     * do PDF. Assim os acentos (á, ã, ç, é, ó…) e o traço "—" surgem corretos;
     * caracteres não representáveis (ex: emojis) são descartados.
     */
    private function prepararTexto(string $s): string
    {
        $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($conv === false) {
            $conv = preg_replace('/[^\x20-\x7E]/', '', $s) ?? $s;
        }
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], $conv);
    }

    private function texto(float $x, float $y, string $texto, int $size = 10, bool $bold = false): void
    {
        $font = $bold ? '/F2' : '/F1';
        $t = $this->prepararTexto($texto);
        $this->content .= "BT {$font} {$size} Tf {$x} {$y} Td ({$t}) Tj ET\n";
    }

    /** Régua horizontal cinzenta (separadores) */
    private function linhaHorizontal(float $y, float $largura = 0.5): void
    {
        $x1 = $this->marginLeft;
        $x2 = $this->pageWidth - $this->marginRight;
        $this->content .= "0.6 0.6 0.6 RG {$x1} {$y} m {$x2} {$y} l {$largura} w S 0 0 0 RG\n";
    }

    /** Régua horizontal de destaque (azul), usada no topo */
    private function linhaDestaque(float $y): void
    {
        $x1 = $this->marginLeft;
        $x2 = $this->pageWidth - $this->marginRight;
        [$r, $g, $b] = $this->accent;
        $this->content .= "{$r} {$g} {$b} RG {$x1} {$y} m {$x2} {$y} l 1.6 w S 0 0 0 RG\n";
    }

    /** Retângulo preenchido (fundo de células/linhas) */
    private function retanguloPreenchido(float $x, float $y, float $largura, float $altura, float $cinza): void
    {
        $this->content .= "{$cinza} {$cinza} {$cinza} rg {$x} {$y} {$largura} {$altura} re f 0 0 0 rg\n";
    }

    private function desenharRodape(): void
    {
        $rodape = 'KIAMI  |  Quality Contact Center';
        $this->texto($this->marginLeft, 30, $rodape, 8);
        $paginaTxt = 'Página ' . $this->pageNum;
        $this->texto($this->pageWidth - $this->marginRight - 60, 30, $paginaTxt, 8);
        $this->linhaHorizontal(42);
    }

    private function novaPagina(bool $primeira = false): void
    {
        if (!$primeira) {
            $this->desenharRodape();
            $this->pages[] = $this->content;
            $this->content = '';
        }

        $this->pageNum++;
        $this->y = 800;

        if ($primeira) {
            $this->texto($this->marginLeft, $this->y, $this->titulo, 17, true);
            $this->y -= 22;
            if ($this->subtitulo !== '') {
                $this->texto($this->marginLeft, $this->y, $this->subtitulo, 9);
                $this->y -= 14;
            }
            $this->texto($this->marginLeft, $this->y, 'Gerado em: ' . date('d/m/Y H:i'), 9);
            $this->y -= 12;
            $this->linhaDestaque($this->y);
            $this->y -= 18;
        } else {
            $this->texto($this->marginLeft, $this->y, $this->titulo . ' (continuação)', 10, true);
            $this->y -= 14;
            $this->linhaDestaque($this->y);
            $this->y -= 16;
        }
    }

    private function verificarEspaco(float $necessario = 60): void
    {
        if ($this->y < $this->marginBottom + $necessario) {
            $this->novaPagina();
        }
    }

    public function secao(string $titulo): void
    {
        $this->verificarEspaco(46);
        $this->y -= 8;
        $this->texto($this->marginLeft, $this->y, $titulo, 12, true);
        $this->y -= 12;
        $this->linhaHorizontal($this->y, 0.8);
        $this->y -= 12;
    }

    public function linha(string $texto, int $size = 9): void
    {
        $this->verificarEspaco();
        $this->texto($this->marginLeft, $this->y, $texto, $size);
        $this->y -= 13;
    }

    /**
     * Resumo em duas colunas alinhadas (rótulo à esquerda, valor destacado).
     *
     * @param array<string,string> $pares
     */
    public function resumo(array $pares): void
    {
        foreach ($pares as $chave => $valor) {
            $this->verificarEspaco();
            $this->texto($this->marginLeft, $this->y, (string)$chave, 9, false);
            $this->texto($this->marginLeft + 220, $this->y, (string)$valor, 9, true);
            $this->y -= 14;
        }
        $this->y -= 4;
    }

    /**
     * Tabela com colunas alinhadas, cabeçalho realçado e listras alternadas.
     * O cabeçalho é repetido no topo de cada nova página.
     *
     * @param string[] $cabecalhos
     * @param array<int, string[]> $linhas
     * @param int[] $largurasColunas Largura de cada coluna em pontos
     */
    public function tabela(array $cabecalhos, array $linhas, array $largurasColunas): void
    {
        $alturaLinha = 17;
        $this->desenharLinhaTabela($cabecalhos, $largurasColunas, $alturaLinha, true, 0);

        $i = 0;
        foreach ($linhas as $row) {
            if ($this->y < $this->marginBottom + $alturaLinha + 6) {
                $this->novaPagina();
                $this->desenharLinhaTabela($cabecalhos, $largurasColunas, $alturaLinha, true, 0);
            }
            $this->desenharLinhaTabela($row, $largurasColunas, $alturaLinha, false, $i);
            $i++;
        }

        $this->y -= 10;
    }

    /**
     * @param string[] $celulas
     * @param int[] $larguras
     */
    private function desenharLinhaTabela(array $celulas, array $larguras, int $alturaLinha, bool $cabecalho, int $indice): void
    {
        $yTopo = $this->y;
        $yBase = $yTopo - $alturaLinha;
        $larguraTotal = array_sum($larguras);

        // Fundo: cabeçalho a cinzento médio; linhas de dados com listras alternadas
        if ($cabecalho) {
            $this->retanguloPreenchido($this->marginLeft, $yBase, $larguraTotal, $alturaLinha, 0.85);
        } elseif ($indice % 2 === 1) {
            $this->retanguloPreenchido($this->marginLeft, $yBase, $larguraTotal, $alturaLinha, 0.96);
        }

        // Texto das células (com corte inteligente e reticências)
        $x = $this->marginLeft;
        foreach ($celulas as $i => $cell) {
            $largura = $larguras[$i] ?? 50;
            $texto = $this->ajustarTexto((string)$cell, $largura - 6, 7);
            $this->texto($x + 3, $yTopo - 12, $texto, 7, $cabecalho);
            $x += $largura;
        }

        // Grelha: linhas verticais entre colunas
        $xVert = $this->marginLeft;
        $this->content .= "0.7 0.7 0.7 RG\n";
        foreach ($larguras as $largura) {
            $this->content .= "{$xVert} {$yTopo} m {$xVert} {$yBase} l 0.3 w S\n";
            $xVert += $largura;
        }
        $xFim = $this->marginLeft + $larguraTotal;
        $this->content .= "{$xFim} {$yTopo} m {$xFim} {$yBase} l 0.3 w S\n";
        // Linha horizontal inferior
        $this->content .= "{$this->marginLeft} {$yBase} m {$xFim} {$yBase} l 0.5 w S 0 0 0 RG\n";

        $this->y = $yBase - 1;
    }

    /** Corta o texto à largura disponível, acrescentando reticências se necessário */
    private function ajustarTexto(string $texto, float $larguraPt, int $size): string
    {
        // Estimativa de largura média de caractere na Helvetica
        $larguraChar = $size * 0.52;
        $maxChars = max(3, (int)floor($larguraPt / $larguraChar));
        if (mb_strlen($texto) <= $maxChars) {
            return $texto;
        }
        return rtrim(mb_substr($texto, 0, $maxChars - 1)) . '…';
    }

    public function output(): string
    {
        // Fechar a última página
        $this->desenharRodape();
        $this->pages[] = $this->content;

        $numPaginas = count($this->pages);

        // Numeração de objetos:
        // 1=Catalog, 2=Pages, 3=Font F1, 4=Font F2, depois por página: (dict, stream)
        $objetos = [];
        $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";

        $kids = [];
        $paginaObjs = [];
        for ($p = 0; $p < $numPaginas; $p++) {
            $pageObjNum = 5 + $p * 2;
            $contentObjNum = 6 + $p * 2;
            $kids[] = "{$pageObjNum} 0 R";
            $paginaObjs[$pageObjNum] = $contentObjNum;
        }

        $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count {$numPaginas} >>";
        $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // Objetos de página + streams
        for ($p = 0; $p < $numPaginas; $p++) {
            $pageObjNum = 5 + $p * 2;
            $contentObjNum = 6 + $p * 2;
            $stream = $this->pages[$p];
            $len = strlen($stream);

            $objetos[$pageObjNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageWidth} {$this->pageHeight}] "
                . "/Contents {$contentObjNum} 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> >>";
            $objetos[$contentObjNum] = "STREAM:" . $stream . ":" . $len;
        }

        // Montagem do ficheiro + tabela xref
        ksort($objetos);
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $maxObj = max(array_keys($objetos));

        foreach ($objetos as $num => $corpo) {
            $offsets[$num] = strlen($pdf);
            if (str_starts_with($corpo, 'STREAM:')) {
                $semPrefixo = substr($corpo, strlen('STREAM:'));
                $sep = strrpos($semPrefixo, ':');
                $stream = substr($semPrefixo, 0, $sep);
                $len = substr($semPrefixo, $sep + 1);
                $pdf .= "{$num} 0 obj<< /Length {$len} >>stream\n{$stream}endstream endobj\n";
            } else {
                $pdf .= "{$num} 0 obj{$corpo}endobj\n";
            }
        }

        $xref = strlen($pdf);
        $totalObjs = $maxObj + 1;
        $pdf .= "xref\n0 {$totalObjs}\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            if (isset($offsets[$i])) {
                $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
            } else {
                $pdf .= "0000000000 65535 f \n";
            }
        }
        $pdf .= "trailer<< /Size {$totalObjs} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }
}
