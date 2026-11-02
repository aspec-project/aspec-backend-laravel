<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Fatura {{ $number }}</title>
    <style>
        @page { margin: 2cm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
        .watermark {
            position: fixed; top: 40%; left: -10%; width: 120%;
            text-align: center; font-size: 46px; color: #e5e5e5;
            transform: rotate(-35deg); z-index: -1;
        }
        .warning { border: 2px solid #b00020; color: #b00020; padding: 8px; text-align: center; font-weight: bold; margin-bottom: 20px; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        h2 { font-size: 12px; margin: 18px 0 6px; text-transform: uppercase; color: #555; }
        table { width: 100%; border-collapse: collapse; }
        .items th, .items td { border-bottom: 1px solid #ccc; padding: 6px 4px; text-align: left; }
        .items .amount { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; }
        .small { color: #666; font-size: 9px; }
    </style>
</head>
<body>
    <div class="watermark">SEM VALIDADE FISCAL</div>

    <div class="warning">DOCUMENTO DE DEMONSTRAÇÃO — SEM VALIDADE FISCAL</div>

    <h1>Fatura {{ $number }}</h1>
    <div>Data de emissão: {{ $date }}</div>

    <h2>Emitente</h2>
    <div>ASPEC — Associação de Profissionais, Empreendedores e Empresários Cristãos</div>

    <h2>Cliente</h2>
    <div>{{ $customer['name'] }}</div>
    <div>NIF: {{ $customer['nif'] }}</div>
    <div>{{ $customer['address'] }}</div>
    <div>{{ $customer['postal_code'] }} {{ $customer['city'] }}</div>
    <div>Portugal</div>

    <h2>Detalhe</h2>
    <table class="items">
        <thead>
            <tr>
                <th>Descrição</th>
                <th class="amount">Base</th>
                <th class="amount">Taxa IVA</th>
                <th class="amount">IVA</th>
                <th class="amount">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $description }}</td>
                <td class="amount">{{ $base }}</td>
                <td class="amount">{{ $vat_rate }}</td>
                <td class="amount">{{ $vat_amount }}</td>
                <td class="amount">{{ $total }}</td>
            </tr>
            <tr class="total">
                <td colspan="4">{{ $exemption ? 'Total' : 'Total (IVA incluído)' }}</td>
                <td class="amount">{{ $total }}</td>
            </tr>
        </tbody>
    </table>

    @if ($exemption)
        <p>Motivo de isenção de IVA: {{ $exemption['reason'] }}{{ $exemption['code'] ? ' ('.$exemption['code'].')' : '' }}</p>
    @endif

    <p class="small">Referência do pagamento: {{ $reference }}</p>
</body>
</html>
