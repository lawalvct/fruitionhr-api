<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Employee directory</title>
    <style>
        @page { margin: 28px 24px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        h1 { color: #14532d; font-size: 18px; margin: 0 0 4px; }
        p { color: #64748b; margin: 0 0 16px; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #14532d; color: white; font-size: 8px; padding: 7px 5px; text-align: left; }
        td { border-bottom: 1px solid #e2e8f0; padding: 6px 5px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
        .footer { color: #94a3b8; font-size: 8px; margin-top: 12px; }
    </style>
</head>
<body>
    <h1>{{ $tenantName }} employee directory</h1>
    <p>Generated {{ now()->format('Y-m-d H:i') }} · {{ $employees->count() }} employee(s)</p>
    <table>
        <thead>
        <tr>
            <th>Employee</th><th>Number</th><th>Contact</th><th>Location</th><th>Department</th><th>Position</th><th>Status</th><th>Hire date</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($employees as $employee)
            <tr>
                <td>{{ $employee->full_name }}</td>
                <td>{{ $employee->employee_number }}</td>
                <td>{{ $employee->official_email ?: $employee->personal_email }}<br>{{ $employee->phone }}</td>
                <td>{{ collect([$employee->city, $employee->state, $employee->country])->filter()->implode(', ') }}</td>
                <td>{{ $employee->currentAssignment?->department?->name ?: '-' }}</td>
                <td>{{ $employee->currentAssignment?->position?->title ?: '-' }}</td>
                <td>{{ str_replace('_', ' ', $employee->employment_status) }}</td>
                <td>{{ $employee->hired_at?->format('Y-m-d') }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <div class="footer">FruitionHR · All rights reserved · Version 1.0.0</div>
</body>
</html>
