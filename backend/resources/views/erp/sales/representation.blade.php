<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
    <title>{{ $labels['title'] }} {{ $number }}</title>
    <style>
        body { margin: 0; padding: 24px; color: #18181b; background: #fff; font: 14px/1.5 system-ui, sans-serif; }
        main { max-width: 960px; margin: 0 auto; }
        h1 { font-size: 24px; margin: 0 0 8px; overflow-wrap: anywhere; }
        p { margin: 4px 0; overflow-wrap: anywhere; }
        .notice { padding: 12px 0; border-bottom: 1px solid #d4d4d8; }
        table { width: 100%; border-collapse: collapse; margin: 24px 0; }
        th, td { border-bottom: 1px solid #d4d4d8; padding: 8px 4px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .amount { text-align: right; font-variant-numeric: tabular-nums; }
        dl { display: grid; grid-template-columns: 1fr auto; gap: 8px 16px; margin-left: auto; max-width: 400px; }
        dt, dd { margin: 0; }
        dd { text-align: right; font-variant-numeric: tabular-nums; }
        footer { margin-top: 24px; }
        @media print { body { padding: 0; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<main>
    <h1>{{ $labels['title'] }}</h1>
    <p>{{ $labels['number'] }}: {{ $number }} · {{ $status }}</p>
    <p class="notice">Documento comercial sem validade fiscal. Não comprova pagamento.</p>
    <p><strong>{{ $labels['company'] }}:</strong> {{ $company['name'] ?? '' }} {{ $company['cnpj'] ?? '' }}</p>
    <p><strong>{{ $labels['establishment'] }}:</strong> {{ $establishment['name'] ?? '' }}</p>
    @if ($settings['show_customer'] && is_array($party))
        <p><strong>{{ $labels['customer'] }}:</strong> {{ $party['name'] ?? '' }}</p>
    @endif
    <table>
        <thead>
        <tr>
            <th scope="col">{{ $labels['description'] }}</th>
            <th scope="col" class="amount">{{ $labels['quantity'] }}</th>
            <th scope="col" class="amount">{{ $labels['unit_price'] }} ({{ $currency }})</th>
            <th scope="col" class="amount">{{ $labels['line_total'] }} ({{ $currency }})</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row['description'] }}</td>
                <td class="amount">{{ $row['quantity'] }}</td>
                <td class="amount">{{ $row['unit_price'] }}</td>
                <td class="amount">{{ $row['line_total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <dl>
        <dt>{{ $labels['subtotal'] }} ({{ $currency }})</dt><dd>{{ $totals['subtotal_amount'] }}</dd>
        <dt>{{ $labels['discount'] }} ({{ $currency }})</dt><dd>{{ $totals['discount_amount'] }}</dd>
        <dt>{{ $labels['taxes'] }} ({{ $currency }})</dt><dd>{{ $totals['tax_amount'] }}</dd>
        <dt><strong>{{ $labels['total'] }} ({{ $currency }})</strong></dt><dd><strong>{{ $totals['total_amount'] }}</strong></dd>
    </dl>
    @if ($settings['footer_text'] !== null && $settings['footer_text'] !== '')
        <footer><p>{{ $settings['footer_text'] }}</p></footer>
    @endif
</main>
</body>
</html>
