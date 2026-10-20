<?php

namespace App\Erp;

use Illuminate\Validation\ValidationException;

final class ErpCatalogCsvParser
{
    public const MAX_BYTES = 524288;

    public const MAX_ROWS = 500;

    /** @param list<string> $allowed
     * @return list<array{record:int,line:int,values:array<string,string>}>
     */
    public function parse(string $content, array $allowed): array
    {
        if (strlen($content) > self::MAX_BYTES || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $this->invalid('CSV deve ser UTF-8 e ter até 524288 bytes.');
        }
        if (str_starts_with($content, "\xef\xbb\xbf")) {
            $content = substr($content, 3);
        }
        $records = [];
        $cells = [];
        $cell = '';
        $state = 'start';
        $line = $startLine = 1;
        $hasSyntax = false;
        $length = strlen($content);
        $finish = function () use (&$records, &$cells, &$cell, &$state, &$hasSyntax, &$startLine): void {
            if ($hasSyntax) {
                $records[] = ['line' => $startLine, 'cells' => [...$cells, $cell]];
                if (count($records) > self::MAX_ROWS + 1) {
                    $this->invalid('Limite de 500 registros excedido.');
                }
            }
            $cells = [];
            $cell = '';
            $state = 'start';
            $hasSyntax = false;
        };
        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($content[$i + 1] ?? null) === '"') {
                        $cell .= '"';
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                } else {
                    $cell .= $char;
                    if ($char === "\n") {
                        $line++;
                    }
                }

                continue;
            }
            if ($char === "\r" || $char === "\n") {
                if ($char === "\r") {
                    if (($content[$i + 1] ?? null) !== "\n") {
                        $this->invalid('Quebra de linha inválida.');
                    }
                    $i++;
                }
                $finish();
                $line++;
                $startLine = $line;

                continue;
            }
            $hasSyntax = true;
            if ($char === ',') {
                $cells[] = $cell;
                $cell = '';
                $state = 'start';
            } elseif ($char === '"' && $state === 'start') {
                $state = 'quoted';
            } elseif ($char === '"' || $state === 'closed') {
                $this->invalid('Aspas inválidas no CSV.');
            } else {
                $cell .= $char;
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            $this->invalid('Campo entre aspas não foi fechado.');
        }
        $finish();
        $header = array_shift($records);
        if ($header === null) {
            $this->invalid('Cabeçalho ausente.');
        }
        $headers = array_map(fn (string $value): string => strtolower(trim($value)), $header['cells']);
        if (! in_array('name', $headers, true) || in_array('', $headers, true) || count(array_unique($headers)) !== count($headers) || array_diff($headers, $allowed) !== []) {
            $this->invalid('Cabeçalho obrigatório, duplicado ou não suportado. Confira as colunas permitidas.');
        }
        if ($records === []) {
            $this->invalid('Informe pelo menos um registro.');
        }
        $result = [];
        foreach ($records as $index => $record) {
            if (count($record['cells']) !== count($headers)) {
                $this->invalid('Quantidade de colunas inválida na linha '.$record['line'].'.');
            }
            $result[] = ['record' => $index + 1, 'line' => $record['line'], 'values' => array_combine($headers, $record['cells'])];
        }

        return $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
