<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $receiptNumber }}</title>
    <style>
        @page { margin: 30px 32px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111827; font-size: 12px; margin: 0; }

        table.header-row { width: 100%; border-collapse: collapse; }
        table.header-row td { vertical-align: top; padding: 0; }
        table.brand { border-collapse: collapse; }
        table.brand td { padding: 0; vertical-align: middle; }
        table.brand .brand-mark { padding-right: 10px; }
        table.brand .brand-mark img { height: 34px; width: auto; }
        .company { font-size: 19px; font-weight: bold; color: #064e3b; }
        .doc-title { color: #64748b; font-size: 10.5px; margin-top: 2px; letter-spacing: .3px; text-transform: uppercase; }
        .header-right { text-align: right; }
        .badge { display: inline-block; padding: 4px 11px; border-radius: 999px; font-size: 10px; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; color: #fff; background: #16a34a; }
        .ref { color: #94a3b8; font-size: 9.5px; margin-top: 6px; }
        .rule { border-bottom: 2px solid #047857; margin: 10px 0 18px; }

        table.parties { width: 100%; border-collapse: collapse; table-layout: fixed; margin-bottom: 16px; }
        table.parties td { width: 48%; vertical-align: top; }
        table.parties td.gap { width: 4%; }
        .party-label { color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 4px; }
        .party-name { font-weight: bold; color: #111827; font-size: 12px; }
        .party-line { color: #475569; font-size: 10.5px; margin-top: 2px; }

        .meta-box { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 18px; }
        table.meta { width: 100%; border-collapse: collapse; }
        .meta td { padding: 4px 0; vertical-align: top; width: 25%; }
        .meta .label { display: block; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 2px; }
        .meta .value { display: block; font-weight: bold; color: #111827; font-size: 11px; }

        .section-title { font-weight: bold; color: #064e3b; margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th { background: #ecfdf5; color: #047857; text-align: left; padding: 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .2px; }
        table.lines th.amt, table.lines td.amt { text-align: right; }
        table.lines td { padding: 8px; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
        table.lines .desc-sub { display: block; color: #64748b; font-size: 9.5px; margin-top: 2px; }
        table.lines tfoot td { padding: 9px 8px; border-top: 1.5px solid #047857; border-bottom: none; font-weight: bold; background: #ecfdf5; font-size: 13px; }

        .paid-note { margin-top: 16px; background: #ecfdf5; border-radius: 8px; padding: 11px 14px; color: #065f46; font-size: 11px; }
        .footer { margin-top: 22px; border-top: 1px solid #e5e7eb; padding-top: 10px; color: #94a3b8; font-size: 9.5px; text-align: center; }
    </style>
</head>
<body>
    <table class="header-row">
        <tr>
            <td>
                <table class="brand">
                    <tr>
                        @if (!empty($logoDataUri))
                            <td class="brand-mark"><img src="{{ $logoDataUri }}" alt=""></td>
                        @endif
                        <td>
                            <div class="company">{{ $brand['product'] }}</div>
                            <div class="doc-title">Payment receipt</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td class="header-right">
                <span class="badge">Paid</span>
                <div class="ref">{{ $receiptNumber }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="parties">
        <tr>
            <td>
                <div class="party-label">Billed to</div>
                <div class="party-name">{{ $company['name'] }}</div>
                @if (!empty($company['email']))
                    <div class="party-line">{{ $company['email'] }}</div>
                @endif
            </td>
            <td class="gap"></td>
            <td>
                <div class="party-label">From</div>
                <div class="party-name">{{ $brand['company'] }}</div>
                <div class="party-line">{{ $brand['address'] }}</div>
                <div class="party-line">{{ $brand['support_email'] }}</div>
            </td>
        </tr>
    </table>

    <div class="meta-box">
        <table class="meta">
            <tr>
                <td>
                    <span class="label">Receipt no.</span>
                    <span class="value">{{ $receiptNumber }}</span>
                </td>
                <td>
                    <span class="label">Date paid</span>
                    <span class="value">{{ $paidAtFormatted }}</span>
                </td>
                <td>
                    <span class="label">Method</span>
                    <span class="value">{{ $gatewayLabel }}</span>
                </td>
                <td>
                    <span class="label">Currency</span>
                    <span class="value">{{ $currency }}</span>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">What this covers</div>
    <table class="lines">
        <thead>
            <tr>
                <th>Description</th>
                <th class="amt">Seats</th>
                <th class="amt">Rate</th>
                <th class="amt">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    {{ $lineDescription }}
                    @if (!empty($periodLabel))
                        <span class="desc-sub">{{ $periodLabel }}</span>
                    @endif
                </td>
                <td class="amt">{{ $seats }}</td>
                <td class="amt">{{ $unitPriceFormatted }}</td>
                <td class="amt">{{ $amountFormatted }}</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total paid</td>
                <td class="amt">{{ $amountFormatted }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="paid-note">
        Paid in full on {{ $paidAtFormatted }} via {{ $gatewayLabel }}. Transaction
        reference {{ $reference }}.
    </div>

    <div class="footer">
        {{ $brand['product'] }} — {{ $brand['tagline'] }} · {{ $brand['website_url'] }}<br>
        Generated {{ $generatedAt }}. This receipt was produced electronically and is valid without a signature.
    </div>
</body>
</html>
