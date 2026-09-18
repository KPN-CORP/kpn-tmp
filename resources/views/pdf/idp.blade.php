@php
    /**
     * One employee's development plan for ONE cycle.
     *
     * The dates arrive as the model's array form (ISO 8601), so every one of
     * them goes through fmt() — printing them raw put "2026-01-01T00:00:00Z"
     * on the page. The approval columns mirror the manage screen: a plan is
     * only half the story now that planning and each result are signed off.
     */
    $fmt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d M Y') : '—';

    $planningLabel = [
        'draft' => 'Not submitted',
        'pending' => 'Awaiting approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'revision' => 'Needs approval again',
    ][$planning['status'] ?? 'draft'] ?? 'Not submitted';

    $resultLabel = [
        'locked' => '—',
        'open' => 'Not filed',
        'pending' => 'Awaiting approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>IDP — {{ $employee->fullname }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { color: #1f2937; font-size: 10px; margin: 0; }
        .header { border-bottom: 2px solid #dc2626; padding-bottom: 10px; margin-bottom: 10px; }
        .header h1 { margin: 0; font-size: 16px; color: #111827; }
        .header .sub { color: #6b7280; font-size: 11px; margin-top: 2px; }
        .cycle { background: #f9fafb; border: 1px solid #e5e7eb; padding: 6px 8px; margin-bottom: 14px; }
        .cycle strong { color: #111827; }
        .cycle .pill { float: right; font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        h2 { font-size: 12px; color: #111827; margin: 16px 0 6px; }
        h2 small { color: #9ca3af; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px; text-align: left;
             font-size: 9px; text-transform: uppercase; color: #6b7280; }
        td { border: 1px solid #e5e7eb; padding: 5px; vertical-align: top; }
        .muted { color: #9ca3af; }
        .state { font-size: 9px; text-transform: uppercase; letter-spacing: .03em; }
        .ok { color: #047857; }
        .wait { color: #b45309; }
        .bad { color: #b91c1c; }
        .footer { margin-top: 20px; font-size: 9px; color: #9ca3af; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Individual Development Plan</h1>
        <div class="sub">{{ $employee->fullname }} &middot; {{ $employee->employee_id }}
            &middot; {{ $employee->designation_name ?? '—' }}</div>
    </div>

    {{-- Which cycle this document covers, and where its plan stands. Without
         this two cycles' PDFs are indistinguishable. --}}
    <div class="cycle">
        <span class="pill">{{ $planningLabel }}</span>
        <strong>{{ $planning['package']['name'] ?? 'No active cycle' }}</strong>
        @if(($planning['package']['start_date'] ?? null))
            <span class="muted">
                &middot; {{ $fmt($planning['package']['start_date']) }} –
                {{ $planning['package']['end_date'] ? $fmt($planning['package']['end_date']) : '…' }}
            </span>
        @endif
    </div>

    @foreach($developmentModels as $model)
        <h2>{{ $model['name'] }} <small>({{ $model['percentage'] }}%)</small></h2>
        @if(count($model['plans']) === 0)
            <p class="muted">No plans for this model.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th style="width:20%">Competency</th>
                        <th style="width:20%">Program</th>
                        <th style="width:16%">Expected Outcome</th>
                        <th style="width:14%">Timeframe</th>
                        <th style="width:10%">Planning</th>
                        <th style="width:20%">Result</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($model['plans'] as $plan)
                    @php
                        $stage = $plan['stage'] ?? null;
                        $result = $stage['result'] ?? null;
                        $resultState = $result['status'] ?? 'locked';
                    @endphp
                    <tr>
                        <td>{{ $plan['competency_name'] }}<br><span class="muted">{{ $plan['competency_type'] }}</span></td>
                        <td>{{ $plan['development_program'] }}
                            @if($plan['review_tools'])
                                <br><span class="muted">{{ $plan['review_tools'] }}</span>
                            @endif
                        </td>
                        <td>{!! $plan['expected_outcome'] ? nl2br(e($plan['expected_outcome'])) : '—' !!}</td>
                        <td>{{ $fmt($plan['time_frame_start']) }} –<br>{{ $fmt($plan['time_frame_end']) }}</td>
                        <td class="state {{ ($stage['planning_approved'] ?? false) ? 'ok' : 'wait' }}">
                            {{ ($stage['planning_approved'] ?? false) ? 'Approved' : 'Not approved' }}
                        </td>
                        <td>
                            <span class="state {{ $resultState === 'approved' ? 'ok' : ($resultState === 'rejected' ? 'bad' : 'wait') }}">
                                {{ $resultLabel[$resultState] ?? '—' }}
                            </span>
                            @if($plan['realization_date'])
                                <br>{{ $fmt($plan['realization_date']) }}
                            @endif
                            @if($plan['result_evidence'])
                                <br><span class="muted">{{ $plan['result_evidence'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="footer">Generated {{ now()->format('d M Y H:i') }}</div>
</body>
</html>
