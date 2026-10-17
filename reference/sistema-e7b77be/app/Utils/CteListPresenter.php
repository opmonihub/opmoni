<?php

namespace App\Utils;

/** Valores textuais da listagem; o consumidor deve renderizá-los como texto, não HTML. */
final class CteListPresenter
{
    const MAX_SERIALIZED_LENGTH = 450;
    const ASCII_WHITESPACE = " \t\n\r\v\f";
    const ROLES = ['Remetente', 'Expedidor', 'Recebedor', 'Destinatário', 'Outros'];
    const OTHER_TYPES = ['00'=>'Declaração', '10'=>'Dutoviário', '59'=>'CF-e SAT', '65'=>'NFC-e', '99'=>'Outros'];

    public static function documents($cte, $previous)
    {
        $serialized = (string) ($cte['chave_nfe'] ?? '');
        if (mb_strlen($serialized, 'UTF-8') > self::MAX_SERIALIZED_LENGTH) {
            throw new \LengthException('chave_nfe excede o limite suportado de 450 caracteres; rever a consulta da listagem.');
        }
        $items = [];
        $seen = [];
        foreach (explode(';', str_replace(',', ';', $serialized)) as $reference) {
            self::appendKey($items, $seen, $reference, 'nfe_carga');
        }
        $description = trim((string) ($cte['descOutros'] ?? ''), self::ASCII_WHITESPACE);
        $number = (int) ($cte['nDoc'] ?? 0);
        $value = (string) ($cte['vDocFisc'] ?? '0');
        if ($description !== '' || $number !== 0 || (float) $value != 0) {
            $code = (string) ($cte['tpDoc'] ?? '');
            $type = self::OTHER_TYPES[$code] ?? 'Tipo desconhecido (' . $code . ')';
            $items[] = [
                'categoria'=>'outros_carga', 'tipo'=>$type, 'numero'=>$number !== 0 ? (string) $number : null,
                'serie'=>null, 'chave'=>null, 'descricao'=>$description !== '' ? $description : null, 'emitente'=>null,
                'status'=>isset(self::OTHER_TYPES[$code]) && $number >= 0 && (float) $value >= 0 ? 'valid' : 'malformed',
                'raw'=>['tpDoc'=>$code, 'nDoc'=>$cte['nDoc'] ?? null, 'descOutros'=>$cte['descOutros'] ?? null, 'vDocFisc'=>$value],
                'export'=>$type . ($number !== 0 ? ' ' . $number : '') . ($description !== '' ? ' / ' . $description : '') . ((float) $value != 0 ? ' / valor ' . self::money($value) : ''),
            ];
        }
        $previous = is_array($previous) ? $previous : $previous->all();
        usort($previous, function ($left, $right) { return $left['id'] <=> $right['id']; });
        foreach ($previous as $row) {
            $index = self::appendKey($items, $seen, $row['chave_cte'], 'cte_anterior');
            $issuer = trim((string) ($row['nome_emitente'] ?? ''), self::ASCII_WHITESPACE);
            if ($index !== null && $items[$index]['emitente'] === null && $issuer !== '') {
                $items[$index]['emitente'] = $issuer;
                $items[$index]['export'] .= ' / emitente ' . $issuer;
            }
        }
        $count = count($items);
        $malformed = count(array_filter($items, function ($item) { return $item['status'] === 'malformed'; }));
        return [
            'display'=>$count === 0 ? '—' : ($count === 1 ? explode(' / ', $items[0]['export'])[0] : $count . ' documentos'),
            'status'=>$count === 0 ? 'empty' : ($malformed === 0 ? 'valid' : ($malformed === $count ? 'malformed' : 'mixed')),
            'count'=>$count, 'raw'=>$count, 'items'=>$items, 'export'=>implode(' | ', array_column($items, 'export')),
        ];
    }

    private static function money($value)
    {
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) { return $value; }
        $parts = explode('.', $value);
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[0]) . ',' . str_pad($parts[1] ?? '', 2, '0');
    }

    private static function appendKey(array &$items, array &$seen, $reference, $category)
    {
        $raw = (string) $reference;
        $key = str_replace(str_split(self::ASCII_WHITESPACE), '', $raw);
        if ($key === '') { return null; }
        $identity = $category . ':' . $key;
        if (isset($seen[$identity])) { return $seen[$identity]; }
        $valid = preg_match('/^[0-9]{44}$/D', $key) === 1 && substr($key, 20, 2) === ($category === 'nfe_carga' ? '55' : '57');
        $number = $valid ? (ltrim(substr($key, 25, 9), '0') ?: '0') : null;
        $series = $valid ? (ltrim(substr($key, 22, 3), '0') ?: '0') : null;
        $type = $category === 'nfe_carga' ? 'NF-e' : 'CT-e anterior';
        $text = $valid ? $type . ' ' . $number . ' / série ' . $series . ' / chave ' . $key : $type . ' — referência inválida (' . $key . ')';
        $index = count($items);
        $seen[$identity] = $index;
        $items[] = [
            'categoria'=>$category, 'tipo'=>$type, 'numero'=>$number, 'serie'=>$series, 'chave'=>$key,
            'descricao'=>null, 'emitente'=>null, 'status'=>$valid ? 'valid' : 'malformed', 'raw'=>$raw, 'export'=>$text,
        ];
        return $index;
    }

    public static function weight($status, $kg)
    {
        $labels = ['missing'=>'—', 'multiple'=>'Múltiplas medidas', 'invalid'=>'Medida inválida'];
        if ($status !== 'single' || $kg === null) {
            $status = isset($labels[$status]) ? $status : 'missing';
            return ['display'=>$labels[$status], 'status'=>$status, 'raw'=>null, 'export'=>$status === 'missing' ? '' : $labels[$status]];
        }
        $parts = explode('.', (string) $kg);
        $raw = $parts[0] . '.' . str_pad($parts[1] ?? '', 4, '0');
        $display = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $parts[0]) . ',' . str_pad($parts[1] ?? '', 4, '0');
        return ['display'=>$display, 'status'=>'single', 'raw'=>$raw, 'export'=>$display];
    }

    public static function numericSearch($value)
    {
        $value = trim((string) $value, self::ASCII_WHITESPACE);
        // Entrada humana exclusivamente pt-BR: ponto agrupa milhares, vírgula
        // separa decimais. O valor canônico com ponto fica apenas no contrato interno.
        if (!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:\.[0-9]{3})+)(?:,[0-9]{1,4})?$/D', $value)) {
            return null;
        }
        return str_replace(',', '.', str_replace('.', '', $value));
    }

    public static function city($name, $uf)
    {
        $display = $name !== null ? $name . ' / ' . $uf : '—';
        return ['display'=>$display, 'status'=>$name !== null ? 'resolved' : 'missing', 'raw'=>$name !== null ? ['nome'=>$name,'uf'=>$uf] : null, 'export'=>$name !== null ? $display : ''];
    }

    public static function taker($code, $name)
    {
        if (!in_array($code, [0,1,2,3,4,'0','1','2','3','4'], true)) {
            return ['display'=>'Tomador inválido', 'status'=>'invalid', 'raw'=>null, 'export'=>'Tomador inválido'];
        }
        $role = self::ROLES[(int) $code];
        $display = $name !== null ? $name . ' — ' . $role : $role . ' — contato ausente';
        return ['display'=>$display, 'status'=>$name !== null ? 'resolved' : 'missing_contact', 'raw'=>['codigo'=>(string)$code,'papel'=>$role,'nome'=>$name], 'export'=>$display];
    }
}
