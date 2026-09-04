<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>IDP — {{ $employee->fullname }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 10px; margin: 0; }
        .header { border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 14px; }
        .header h1 { margin: 0; font-size: 16px; color: #111827; }
        .header .sub { color: #6b7280; font-size: 11px; margin-top: 2px; }
        h2 { font-size: 12px; color: #111827; margin: 16px 0 6px; }
        h2 small { color: #9ca3af; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px; text-align: left;
             font-size: 9px; text-transform: uppercase; color: #6b7280; }
        td { border: 1px solid #e5e7eb; padding: 5px; vertical-align: top; }
        .muted { color: #9ca3af; }
        .footer { margin-top: 20px; font-size: 9px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Individual Development Plan</h1>
        <div class="sub">{{ $employee->fullname }} &middot; {{ $employee->employee_id }}
            &middot; {{ $employee->designation_name ?? '—' }}</div>
    </div>

    @foreach($developmentModels as $model)
        <h2>{{ $model['name'] }} <small>({{ $model['percentage'] }}%)</small></h2>
        @if(count($model['plans']) === 0)
            <p class="muted">No plans for this model.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width:22%">Competency</th>
                        <th style="width:22%">Program</th>
                        <th style="width:20%">Expected Outcome</th>
                        <th style="width:18%">Timeframe</th>
                        <th style="width:18%">Realization</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($model['plans'] as $plan)
                    <tr>
                        <td>{{ $plan['competency_name'] }}<br><span class="muted">{{ $plan['competency_type'] }}</span></td>
                        <td>{{ $plan['development_program'] }}</td>
                        <td>{!! $plan['expected_outcome'] ? nl2br(e($plan['expected_outcome'])) : '—' !!}</td>
                        <td>{{ $plan['time_frame_start'] ?? '—' }} – {{ $plan['time_frame_end'] ?? '—' }}</td>
                        <td>{{ $plan['realization_date'] ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
