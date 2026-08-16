<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $tenant_name }} - {{ $report['title'] }} - {{ $report['year'] }}</title>
    <style>
        @page { margin: 30px 32px 40px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10.5px; line-height: 1.4; }
        h1, h2, h3, p { margin: 0; }
        .muted { color: #64748b; }
        .header { border-bottom: 2px solid #07845c; margin-bottom: 12px; padding-bottom: 10px; width: 100%; }
        .header td { vertical-align: middle; }
        .brand-logo { height: 36px; max-width: 118px; width: auto; }
        .brand-mark { background: #07845c; border-radius: 8px; color: #ffffff; font-size: 15px; font-weight: bold; height: 36px; line-height: 15px; padding-top: 10px; text-align: center; width: 36px; }
        .brand-copy { padding-left: 10px; }
        .tenant { color: #065f46; font-size: 11px; font-weight: bold; letter-spacing: .2px; }
        .report-title { color: #0f172a; font-size: 21px; margin-top: 2px; }
        .context { color: #64748b; font-size: 9px; margin-top: 3px; }
        .header-right { text-align: right; white-space: nowrap; }
        .module-pill { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 11px; color: #047857; display: inline-block; font-size: 8.5px; font-weight: bold; padding: 4px 8px; text-transform: uppercase; }
        .year { color: #0f172a; font-size: 16px; font-weight: bold; margin-top: 5px; }
        .filter-wrap { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 7px; margin-bottom: 14px; padding: 9px 11px; }
        .filter-title { color: #475569; font-size: 8px; font-weight: bold; letter-spacing: .5px; text-transform: uppercase; }
        .filter { border-left: 2px solid #a7f3d0; display: inline-block; margin: 5px 12px 0 0; padding-left: 6px; }
        .filter-label { color: #64748b; font-size: 8px; }
        .filter-value { color: #0f172a; font-size: 9.5px; font-weight: bold; }
        .section { margin-bottom: 15px; }
        .section-title { color: #0f172a; font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .section-subtitle { color: #64748b; font-size: 9px; margin-bottom: 9px; }
        .summary-note { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 7px; color: #065f46; font-size: 9.5px; margin-top: 9px; padding: 9px 11px; }
        .analysis-section { page-break-before: always; }
        .metric-row { border-collapse: separate; border-spacing: 5px 0; margin: 0 -5px 8px; table-layout: fixed; width: calc(100% + 10px); }
        .metric { background: #ffffff; border: 1px solid #dbe4ed; border-radius: 7px; padding: 10px 11px; vertical-align: top; }
        .metric-label { color: #64748b; font-size: 9px; }
        .metric-value { color: #0f172a; font-size: 15px; font-weight: bold; margin-top: 3px; }
        .metric-accent { background: #10b981; height: 2px; margin-bottom: 5px; width: 20px; }
        .dataset { border: 1px solid #dbe4ed; border-radius: 7px; margin-bottom: 12px; page-break-inside: avoid; padding: 10px; }
        .dataset-head { margin-bottom: 5px; width: 100%; }
        .dataset-name { color: #0f172a; font-size: 10.5px; font-weight: bold; }
        .dataset-kind { color: #64748b; font-size: 8px; text-align: right; text-transform: uppercase; }
        .chart-table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .chart-table th { background: #f1f5f9; border-bottom: 1px solid #cbd5e1; color: #475569; font-size: 8.5px; padding: 6px; text-align: left; }
        .chart-table td { border-bottom: 1px solid #edf2f7; color: #334155; font-size: 8.5px; padding: 5px 6px; vertical-align: middle; }
        .chart-table tr:last-child td { border-bottom: 0; }
        .chart-label { font-weight: bold; width: 13%; }
        .chart-value { font-size: 8.5px; font-weight: bold; white-space: nowrap; }
        .bar-shell { background: #e8edf3; height: 4px; margin-top: 3px; width: 100%; }
        .bar-fill { height: 4px; min-width: 0; }
        .limited-note { color: #64748b; font-size: 8px; margin-top: 6px; }
        .records { page-break-before: always; }
        .records-table { border-collapse: collapse; table-layout: fixed; width: 100%; }
        .records-table thead { display: table-header-group; }
        .records-table th { background: #075e43; color: #ffffff; font-size: 9.5px; font-weight: bold; line-height: 1.25; padding: 7px 6px; text-align: left; text-transform: uppercase; }
        .records-table td { border-bottom: 1px solid #e2e8f0; color: #334155; font-size: 10px; line-height: 1.35; overflow-wrap: break-word; padding: 6px; vertical-align: top; }
        .records-table tr:nth-child(even) td { background: #f8fafc; }
        .empty { background: #f8fafc; border: 1px dashed #cbd5e1; color: #64748b; font-size: 9.5px; padding: 13px; text-align: center; }
        .preview-note { background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; color: #78350f; font-size: 9.5px; margin-bottom: 9px; padding: 8px 10px; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 58px;">
                @if ($logo_data_uri)
                    <img class="brand-logo" src="{{ $logo_data_uri }}" alt="">
                @else
                    <div class="brand-mark">{{ $tenant_initial }}</div>
                @endif
            </td>
            <td class="brand-copy">
                <div class="tenant">{{ $tenant_name }}</div>
                <h1 class="report-title">{{ $report['title'] }}</h1>
                <div class="context">Generated {{ $report['generated_at'] }} | Live workspace data</div>
            </td>
            <td class="header-right" style="width: 125px;">
                <span class="module-pill">{{ $report['module'] }} report</span>
                <div class="year">{{ $report['year'] }}</div>
            </td>
        </tr>
    </table>

    <div class="filter-wrap">
        <div class="filter-title">Report scope</div>
        <div class="filter">
            <div class="filter-label">Reporting year</div>
            <div class="filter-value">{{ $report['year'] }}</div>
        </div>
        @forelse ($report['filters'] as $filter)
            <div class="filter">
                <div class="filter-label">{{ $filter['label'] }}</div>
                <div class="filter-value">{{ $filter['value'] }}</div>
            </div>
        @empty
            <div class="filter">
                <div class="filter-label">Additional filters</div>
                <div class="filter-value">All records in the selected year</div>
            </div>
        @endforelse
    </div>

    <div class="section">
        <h2 class="section-title">Key indicators</h2>
        <p class="section-subtitle">Summary of the selected reporting scope.</p>
        @foreach (array_chunk($report['metrics'], 3) as $metricRow)
            <table class="metric-row">
                <tr>
                    @foreach ($metricRow as $metric)
                        <td class="metric" style="width: 33.333%;">
                            <div class="metric-accent"></div>
                            <div class="metric-label">{{ $metric['label'] }}</div>
                            <div class="metric-value">{{ $metric['value'] }}</div>
                        </td>
                    @endforeach
                    @for ($empty = count($metricRow); $empty < 3; $empty++)
                        <td style="width: 33.333%;"></td>
                    @endfor
                </tr>
            </table>
        @endforeach
        <div class="summary-note">
            <strong>Report contents:</strong> {{ count($report['metrics']) }} key indicators, {{ count($report['datasets']) }} visual analyses, and {{ number_format($report['table']['meta']['count']) }} matching underlying records. Detailed analysis continues on the next page.
        </div>
    </div>

    <div class="section analysis-section">
        <h2 class="section-title">Trends and breakdowns</h2>
        <p class="section-subtitle">Compact visual analysis of changes and distributions in the selected scope.</p>
        @forelse ($report['datasets'] as $dataset)
            <div class="dataset">
                <table class="dataset-head">
                    <tr>
                        <td class="dataset-name">{{ $dataset['title'] }}</td>
                        <td class="dataset-kind">{{ $dataset['type'] }} | {{ count($dataset['rows']) }} data points</td>
                    </tr>
                </table>
                @if (count($dataset['rows']) > 0)
                    <table class="chart-table">
                        <thead>
                            <tr>
                                <th class="chart-label">Period / group</th>
                                @foreach ($dataset['series'] as $series)
                                    <th>{{ $series['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($dataset['rows'] as $row)
                                <tr>
                                    <td class="chart-label">{{ $row['label'] }}</td>
                                    @foreach ($row['values'] as $value)
                                        <td>
                                            <span class="chart-value">{{ $value['display'] }}</span>
                                            <div class="bar-shell"><div class="bar-fill" style="background: {{ $value['colour'] }}; width: {{ number_format($value['width'], 2, '.', '') }}%;"></div></div>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($dataset['limited'])
                        <div class="limited-note">Showing {{ count($dataset['rows']) }} of {{ $dataset['total_points'] }} groups to keep the printed analysis readable.</div>
                    @endif
                @else
                    <div class="empty">No activity matches the selected filters.</div>
                @endif
            </div>
        @empty
            <div class="empty">No trend or breakdown data is available for this report.</div>
        @endforelse
    </div>

    <div class="section records">
        <h2 class="section-title">{{ $report['table']['title'] }}</h2>
        <p class="section-subtitle">Underlying records used to prepare this analysis.</p>

        @if ($report['table']['meta']['limited'])
            <div class="preview-note">
                Previewing the first {{ count($report['table']['rows']) }} of {{ number_format($report['table']['meta']['count']) }} matching records. Download the Excel workbook for the full filtered dataset.
            </div>
        @elseif ($report['table']['meta']['count'] > 0)
            <div class="preview-note">
                This PDF includes all {{ number_format($report['table']['meta']['count']) }} matching records. Use the Excel workbook for further analysis.
            </div>
        @endif

        @if (count($report['table']['rows']) > 0 && count($report['table']['columns']) > 0)
            <table class="records-table">
                <thead>
                    <tr>
                        @foreach ($report['table']['columns'] as $column)
                            <th>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['table']['rows'] as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="empty">No underlying records match the selected filters.</div>
        @endif
    </div>
</body>
</html>
