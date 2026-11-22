<?php
/**
 * Generador de PDF ligero y nativo en PHP puro (Sin dependencias)
 * House & Ice • Sistema Administrativo
 */
class MiniPdfOrdenCompra
{
    private array $objetos = [];
    private string $buffer = '';
    private int $n = 0;
    private array $offsets = [];

    private float $ancho = 612; // Letter
    private float $alto = 792;
    private string $streamPagina = '';

    public function __construct()
    {
        $this->buffer = "%PDF-1.4\n";
    }

    private function agregarObjeto(): int
    {
        $this->n++;
        $this->offsets[$this->n] = strlen($this->buffer);
        $this->buffer .= "{$this->n} 0 obj\n";
        return $this->n;
    }

    // Comandos de dibujo
    private function escribirStream(string $s): void
    {
        $this->streamPagina .= $s . "\n";
    }

    public function rectanguloRelleno(float $x, float $y, float $w, float $h, float $r, float $g, float $b): void
    {
        $yPdf = $this->alto - $y - $h;
        $this->escribirStream(sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f", $r, $g, $b, $x, $yPdf, $w, $h));
    }

    public function rectanguloBorde(float $x, float $y, float $w, float $h, float $r, float $g, float $b, float $anchoLinea = 1.0): void
    {
        $yPdf = $this->alto - $y - $h;
        $this->escribirStream(sprintf("%.2f w %.3f %.3f %.3f RG %.2f %.2f %.2f %.2f re S", $anchoLinea, $r, $g, $b, $x, $yPdf, $w, $h));
    }

    public function linea(float $x1, float $y1, float $x2, float $y2, float $r, float $g, float $b, float $anchoLinea = 1.0): void
    {
        $y1Pdf = $this->alto - $y1;
        $y2Pdf = $this->alto - $y2;
        $this->escribirStream(sprintf("%.2f w %.3f %.3f %.3f RG %.2f %.2f m %.2f %.2f l S", $anchoLinea, $r, $g, $b, $x1, $y1Pdf, $x2, $y2Pdf));
    }

    private function escaparTexto(string $texto): string
    {
        // Reemplazar caracteres que causan '?' en fuentes PDF estándar
        $texto = str_replace(
            ['•', '·', '—', '–', '“', '”', '‘', '’'],
            [' - ', ' - ', '-', '-', '"', '"', "'", "'"],
            $texto
        );

        // Convertir a Windows-1252 compatible con WinAnsiEncoding de Type1
        $win = @iconv('UTF-8', 'windows-1252//TRANSLIT', $texto);
        if ($win === false || $win === '') {
            $win = mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8');
        }

        return strtr($win, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)'
        ]);
    }

    public function texto(float $x, float $y, string $texto, string $fuente = 'F1', float $tam = 10, float $r = 0, float $g = 0, float $b = 0): void
    {
        $yPdf = $this->alto - $y;
        $txtEsc = $this->escaparTexto($texto);
        $this->escribirStream(sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET", 
            $fuente, $tam, $r, $g, $b, $x, $yPdf, $txtEsc));
    }

    public function textoDerecha(float $xFin, float $y, string $texto, string $fuente = 'F1', float $tam = 10, float $r = 0, float $g = 0, float $b = 0, ?float $factor = null): void
    {
        $len = strlen(mb_convert_encoding($texto, 'ISO-8859-1', 'UTF-8'));
        if ($factor === null) {
            $factor = ($fuente === 'F2') ? 0.66 : 0.55;
        }
        $anchoEstimado = $len * $tam * $factor;
        $x = $xFin - $anchoEstimado;
        if ($x < 0) $x = 0;
        $this->texto($x, $y, $texto, $fuente, $tam, $r, $g, $b);
    }

    public function compilar(): string
    {
        // 1. Objeto Fuente Regular (Helvetica)
        $idFontRegular = $this->agregarObjeto();
        $this->buffer .= "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";

        // 2. Objeto Fuente Bold (Helvetica-Bold)
        $idFontBold = $this->agregarObjeto();
        $this->buffer .= "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // 3. Objeto Contenido (Stream)
        $idContenido = $this->agregarObjeto();
        $lenStream = strlen($this->streamPagina);
        $this->buffer .= "<< /Length {$lenStream} >>\nstream\n" . $this->streamPagina . "endstream\nendobj\n";

        // 4. Objeto Página
        $idPagina = $this->agregarObjeto();
        $this->buffer .= "<< /Type /Page /Parent 5 0 R /MediaBox [0 0 {$this->ancho} {$this->alto}] ";
        $this->buffer .= "/Resources << /Font << /F1 {$idFontRegular} 0 R /F2 {$idFontBold} 0 R >> >> ";
        $this->buffer .= "/Contents {$idContenido} 0 R >>\nendobj\n";

        // 5. Objeto Páginas (Catálogo de Páginas)
        $idPaginas = $this->agregarObjeto();
        $this->buffer .= "<< /Type /Pages /Kids [{$idPagina} 0 R] /Count 1 >>\nendobj\n";

        // 6. Objeto Catálogo Principal
        $idCatalogo = $this->agregarObjeto();
        $this->buffer .= "<< /Type /Catalog /Pages {$idPaginas} 0 R >>\nendobj\n";

        // Tabla XREF
        $startXref = strlen($this->buffer);
        $totalObjs = $this->n + 1;
        $this->buffer .= "xref\n0 {$totalObjs}\n";
        $this->buffer .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $this->n; $i++) {
            $this->buffer .= sprintf("%010d 00000 n \n", $this->offsets[$i]);
        }

        // Trailer
        $this->buffer .= "trailer\n<< /Size {$totalObjs} /Root {$idCatalogo} 0 R >>\n";
        $this->buffer .= "startxref\n{$startXref}\n%%EOF";

        return $this->buffer;
    }
}
