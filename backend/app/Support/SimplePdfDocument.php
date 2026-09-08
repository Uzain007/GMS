<?php

namespace App\Support;

final class SimplePdfDocument
{
    /** @param list<string> $lines */
    public static function fromLines(array $lines, string $title = 'IronCore'): string
    {
        $content = "0.38 0.22 0.78 rg\n40 790 515 34 re f\nBT /F1 18 Tf 1 1 1 rg 52 801 Td (".self::escape($title).") Tj ET\n";
        $y = 765;
        foreach ($lines as $line) {
            if ($y < 45) {
                break;
            }
            $content .= sprintf("BT /F1 9 Tf 0.16 0.14 0.2 rg 48 %d Td (%s) Tj ET\n", $y, self::escape(mb_substr($line, 0, 110)));
            $y -= 17;
        }

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($content)." >>\nstream\n{$content}endstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }

        return $pdf."trailer << /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private static function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
