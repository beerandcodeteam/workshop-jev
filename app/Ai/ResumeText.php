<?php

namespace App\Ai;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;
use UnexpectedValueException;

class ResumeText
{
    /**
     * The file extensions text can be extracted from.
     */
    public const array Extensions = ['pdf', 'docx', 'odt', 'txt'];

    /**
     * Extract the plain text of a resume file, with blank runs collapsed.
     *
     * @throws UnexpectedValueException if the extension is not supported or the file has no text.
     */
    public static function from(string $path, string $extension): string
    {
        $text = match (strtolower($extension)) {
            'pdf' => (new Parser)->parseFile($path)->getText(),
            'docx' => self::fromWord($path, 'Word2007'),
            'odt' => self::fromWord($path, 'ODText'),
            'txt' => (string) file_get_contents($path),
            default => throw new UnexpectedValueException("Formato .{$extension} não suportado."),
        };

        $text = trim((string) preg_replace(["/[ \t\x{00A0}]+/u", "/\s*\n\s*/"], [' ', "\n"], mb_convert_encoding($text, 'UTF-8', 'UTF-8')));

        if ($text === '') {
            throw new UnexpectedValueException('Não foi possível extrair texto do arquivo. PDF escaneado como imagem não tem texto.');
        }

        return $text;
    }

    /**
     * Read every section of a Word or OpenDocument file.
     */
    private static function fromWord(string $path, string $reader): string
    {
        $lines = [];

        foreach (IOFactory::load($path, $reader)->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $lines[] = self::textOf($element);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The text of a document element: runs are joined in place, table cells by spaces and rows by new lines.
     */
    private static function textOf(object $element): string
    {
        if ($element instanceof Table) {
            $rows = [];

            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cells[] = self::textOf($cell);
                }

                $rows[] = implode(' ', $cells);
            }

            return implode("\n", $rows);
        }

        if ($element instanceof AbstractContainer) {
            return implode('', array_map(self::textOf(...), $element->getElements()));
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();

            return is_object($text) ? self::textOf($text) : (string) $text;
        }

        if (method_exists($element, 'getTextObject')) {
            return self::textOf($element->getTextObject());
        }

        return '';
    }
}
