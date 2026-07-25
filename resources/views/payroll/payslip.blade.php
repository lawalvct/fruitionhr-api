<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payslip — {{ $periodLabel }}</title>
    <style>
        @page { margin: 26px 28px; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #111827; font-size: 12px; margin: 0; }

        table.header-row { width: 100%; border-collapse: collapse; }
        table.header-row td { vertical-align: top; padding: 0; }
        table.brand { border-collapse: collapse; }
        table.brand td { padding: 0; vertical-align: middle; }
        table.brand .brand-mark { padding-right: 10px; }
        table.brand .brand-mark img { height: 36px; width: auto; }
        .company { font-size: 19px; font-weight: bold; color: #064e3b; }
        .doc-title { color: #64748b; font-size: 10.5px; margin-top: 2px; letter-spacing: .3px; text-transform: uppercase; }
        .header-right { text-align: right; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; color: #fff; }
        .ref { color: #94a3b8; font-size: 9.5px; margin-top: 6px; }
        .rule { border-bottom: 2px solid #047857; margin: 10px 0 16px; }

        .meta-box { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        table.meta { width: 100%; border-collapse: collapse; }
        .meta td { padding: 4px 0; vertical-align: top; width: 25%; }
        .meta .label { display: block; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: .3px; margin-bottom: 2px; }
        .meta .value { display: block; font-weight: bold; color: #111827; font-size: 11px; }

        .section-title { font-weight: bold; color: #064e3b; margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .3px; }
        table.lines { width: 100%; border-collapse: collapse; }
        table.lines th { background: #ecfdf5; color: #047857; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .2px; }
        table.lines th.amt, table.lines td.amt { text-align: right; }
        table.lines td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; font-size: 11px; }
        table.lines tbody tr:nth-child(even) td { background: #fbfdfd; }
        table.lines tfoot td { padding: 7px 8px; border-top: 1.5px solid #047857; border-bottom: none; font-weight: bold; background: #ecfdf5; }

        table.cols { width: 100%; border-collapse: collapse; margin-bottom: 4px; table-layout: fixed; }
        table.cols td.cell { width: 48%; vertical-align: top; }
        table.cols td.gap { width: 4%; }

        .employer-note { margin: 10px 0 0; padding: 8px 10px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; font-size: 9.5px; color: #64748b; }
        .employer-note strong { color: #475569; }

        table.summary { width: 100%; border-collapse: collapse; margin-top: 14px; table-layout: fixed; }
        table.summary td.cell { width: 32%; vertical-align: middle; }
        table.summary td.gap { width: 2%; }
        .sum-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; }
        .sum-box .label { color: #64748b; font-size: 9.5px; text-transform: uppercase; letter-spacing: .3px; }
        .sum-box .amount { font-size: 14px; font-weight: bold; color: #111827; margin-top: 3px; }
        .net-box { background-color: #047857; color: #fff; padding: 10px 12px; border-radius: 8px; }
        .net-box .label { font-size: 9.5px; opacity: .9; text-transform: uppercase; letter-spacing: .3px; }
        .net-box .amount { font-size: 20px; font-weight: bold; margin-top: 3px; }

        .footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #e5e7eb; color: #94a3b8; font-size: 9px; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
    @php
        $statusColors = ['paid' => '#047857', 'locked' => '#064e3b', 'approved' => '#16a34a'];
        $statusColor = $statusColors[$status] ?? '#047857';
        $statusLabel = ucfirst($status ?? '');
    @endphp

    <table class="header-row">
        <tr>
            <td>
                @if (!empty($logoDataUri))
                    <table class="brand">
                        <tr>
                            <td class="brand-mark"><img src="{{ $logoDataUri }}" alt=""></td>
                            <td>
                                <div class="company">{{ $company }}</div>
                                <div class="doc-title">Payroll Payslip</div>
                            </td>
                        </tr>
                    </table>
                @else
                    <div class="company">{{ $company }}</div>
                    <div class="doc-title">Payroll Payslip</div>
                @endif
            </td>
            <td class="header-right">
                <span class="badge" style="background: {{ $statusColor }}">{{ $statusLabel }}</span>
                <div class="ref">Ref: {{ $reference }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <div class="meta-box">
        <table class="meta">
            <tr>
                <td>
                    <span class="label">Employee</span>
                    <span class="value">{{ $employee['name'] }}</span>
                </td>
                <td>
                    <span class="label">Employee No.</span>
                    <span class="value">{{ $employee['employee_number'] }}</span>
                </td>
                <td>
                    <span class="label">Pay Period</span>
                    <span class="value">{{ $periodLabel }}</span>
                </td>
                <td>
                    <span class="label">Pay Date</span>
                    <span class="value">{{ $payDateFormatted ?? '—' }}</span>
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <span class="label">Pay Structure</span>
                    <span class="value">{{ $structure ?? '—' }}</span>
                </td>
                <td colspan="2">
                    <span class="label">Reference</span>
                    <span class="value">{{ $reference }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="cols">
        <tr>
            <td class="cell">
                <div class="section-title">Earnings</div>
                <table class="lines">
                    <thead><tr><th>Item</th><th class="amt">Amount</th></tr></thead>
                    <tbody>
                    @foreach ($earnings as $item)
                        <tr><td>{{ $item['name'] }}</td><td class="amt">{{ $item['formatted'] }}</td></tr>
                    @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td>Gross Pay</td><td class="amt">{{ $grossFormatted }}</td></tr>
                    </tfoot>
                </table>
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="section-title">Deductions</div>
                <table class="lines">
                    <thead><tr><th>Item</th><th class="amt">Amount</th></tr></thead>
                    <tbody>
                    @forelse ($deductions as $item)
                        <tr><td>{{ $item['name'] }}</td><td class="amt">{{ $item['formatted'] }}</td></tr>
                    @empty
                        <tr><td colspan="2" style="color:#94a3b8;">No deductions</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot>
                        <tr><td>Total Deductions</td><td class="amt">{{ $deductionsFormatted }}</td></tr>
                    </tfoot>
                </table>
            </td>
        </tr>
    </table>

    @if (!empty($employerContributions) && count($employerContributions))
        <div class="employer-note">
            <strong>Employer contributions</strong> (informational — does not affect net pay):
            {{ collect($employerContributions)->map(fn ($item) => "{$item['name']}: {$item['formatted']}")->implode('  ·  ') }}
        </div>
    @endif

    <table class="summary">
        <tr>
            <td class="cell">
                <div class="sum-box">
                    <div class="label">Gross Pay</div>
                    <div class="amount">{{ $grossFormatted }}</div>
                </div>
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="sum-box">
                    <div class="label">Total Deductions</div>
                    <div class="amount">{{ $deductionsFormatted }}</div>
                </div>
            </td>
            <td class="gap"></td>
            <td class="cell">
                <div class="net-box">
                    <div class="label">Net Pay</div>
                    <div class="amount">{{ $netFormatted }}</div>
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        Generated by FruitionHR on {{ $generatedAt }} · This payslip is computer-generated from a locked payroll snapshot and is valid without a signature.<br>
        This document is confidential and intended solely for {{ $employee['name'] }}.
    </div>
</body>
</html>
