<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Facecard — {{ $employee->fullname }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 11px; margin: 0; }
        .header { border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 16px; }
        .header h1 { margin: 0; font-size: 18px; color: #111827; }
        .header .sub { color: #6b7280; font-size: 12px; margin-top: 2px; }
        h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280;
             border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        table { width: 100%; border-collapse: collapse; }
        .details td { padding: 3px 6px; vertical-align: top; }
        .details .label { color: #9ca3af; width: 28%; }
        .grid td { border: 1px solid #e5e7eb; padding: 6px; }
        .grid th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 6px; text-align: left;
                   font-size: 10px; text-transform: uppercase; color: #6b7280; }
        .muted { color: #9ca3af; }
        .footer { margin-top: 24px; font-size: 9px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $employee->fullname }}</h1>
        <div class="sub">
            {{ $employee->designation_name ?? '—' }}
            @if($employee->designation_code) ({{ $employee->designation_code }}) @endif
            &middot; {{ $employee->group_company ?? '—' }} &middot; {{ $employee->job_level ?? '—' }}
        </div>
    </div>

    <h2>Employee Details</h2>
    <table class="details">
        <tr><td class="label">Employee ID</td><td>{{ $employee->employee_id }}</td>
            <td class="label">Email</td><td>{{ $employee->email ?? '—' }}</td></tr>
        <tr><td class="label">Company</td><td>{{ $employee->company_name ?? '—' }}</td>
            <td class="label">Unit</td><td>{{ $employee->unit ?? '—' }}</td></tr>
        <tr><td class="label">Employee Type</td><td>{{ $employee->employee_type ?? '—' }}</td>
            <td class="label">Office Area</td><td>{{ $employee->office_area ?? '—' }}</td></tr>
    </table>

    <h2>Competency Assessment</h2>
    @if($competencyAssessments->isEmpty())
        <p class="muted">No competency assessment recorded.</p>
    @else
        <table class="grid">
            <thead><tr><th>Period</th><th>Matrix Grade</th><th>Proposed Grade</th><th>Priority</th></tr></thead>
            <tbody>
            @foreach($competencyAssessments as $a)
                <tr>
                    <td>{{ $a->period }}</td>
                    <td>{{ $a->matrix_grade ?? '—' }}</td>
                    <td>{{ $a->proposed_grade ?? '—' }}</td>
                    <td>{{ $a->priority_for_development ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Talent Box (Year-on-Year)</h2>
    @if($appraisals->isEmpty())
        <p class="muted">No 9-box mapping recorded.</p>
    @else
        <table class="grid">
            <thead><tr><th>Year</th><th>Grade</th><th>Potential</th><th>Talent Box</th></tr></thead>
            <tbody>
            @foreach($appraisals as $a)
                <tr>
                    <td>{{ $a->appraisal_year }}</td>
                    <td>{{ $a->grade ?? '—' }}</td>
                    <td>{{ $a->potential ?? '—' }}</td>
                    <td>{{ $a->talent_box ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <h2>Succession Summary</h2>
    <table class="details">
        <tr><td class="label">Critical Position</td><td>{{ $resultSummary->critical_position ?? '—' }}</td></tr>
        <tr><td class="label">Successor Type</td><td>{{ $resultSummary->successor_type ?? '—' }}</td></tr>
        <tr><td class="label">Successor to Position</td><td>{{ $resultSummary->successor_to_position ?? '—' }}</td></tr>
    </table>

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
