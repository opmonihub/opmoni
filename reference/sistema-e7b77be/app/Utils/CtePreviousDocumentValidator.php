<?php

namespace App\Utils;

use InvalidArgumentException;

class CtePreviousDocumentValidator
{
    public static function validate($json, $serviceType, $cteType = '0')
    {
        $documents = self::decodeList($json, 'documentos anteriores');
        // O complemento usa infCTeComp; normal e substituto usam infCTeNorm/docAnt.
        if ((string) $cteType !== '1' && in_array((string) $serviceType, ['1', '2', '3'], true) && !$documents) {
            throw new InvalidArgumentException('Informe ao menos um CT-e anterior para Subcontratação, Redespacho ou Redespacho Intermediário.');
        }

        $ufs = [
            '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA', '16' => 'AP', '17' => 'TO',
            '21' => 'MA', '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA',
            '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP',
            '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT', '52' => 'GO', '53' => 'DF',
        ];
        $validated = [];
        foreach ($documents as $document) {
            $key = is_object($document) ? ($document->chave ?? null) : null;
            $ie = is_object($document) ? ($document->ie ?? null) : null;
            if (!is_string($key) || !preg_match('/^[0-9]{44}$/D', $key) || substr($key, 20, 2) !== '57' || !isset($ufs[substr($key, 0, 2)])) {
                throw new InvalidArgumentException('Documento anterior inválido: informe chave de CT-e modelo 57 com 44 dígitos e UF válida.');
            }
            $sum = 0;
            for ($i = 42, $weight = 2; $i >= 0; --$i) {
                $sum += (int) $key[$i] * $weight;
                $weight = $weight === 9 ? 2 : $weight + 1;
            }
            $digit = 11 - ($sum % 11);
            $digit = $digit >= 10 ? 0 : $digit;
            if ((int) $key[43] !== $digit) {
                throw new InvalidArgumentException('Documento anterior inválido: dígito verificador da chave do CT-e incorreto.');
            }
            if (!is_string($ie) || !preg_match('/^(?:[0-9]{2,14}|ISENTO)$/D', trim($ie))) {
                throw new InvalidArgumentException('Informe a IE do emitente anterior com 2 a 14 dígitos numéricos ou ISENTO.');
            }
            $validated[] = [
                'chave' => $key,
                'cnpj' => substr($key, 6, 14),
                'uf' => $ufs[substr($key, 0, 2)],
                'ie' => trim($ie),
            ];
        }
        return $validated;
    }

    public static function decodeList($json, $label, array $fields = [])
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (!is_string($json)) {
            throw new InvalidArgumentException('Formato inválido para ' . $label . ': envie uma lista JSON.');
        }
        $items = json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
            throw new InvalidArgumentException('Formato inválido para ' . $label . ': envie uma lista JSON.');
        }
        foreach ($items as $item) {
            if (!is_object($item)) {
                throw new InvalidArgumentException('Item inválido na lista de ' . $label . '.');
            }
            foreach ($fields as $field) {
                if (!isset($item->$field) || !(is_string($item->$field) || is_int($item->$field) || is_float($item->$field))) {
                    throw new InvalidArgumentException('Campo inválido na lista de ' . $label . ': ' . $field . '.');
                }
            }
        }
        return $items;
    }
}
