<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>IDP Master Data Reference</title>
    <style>
        @page { margin: 25px; }
        body {
            font-family: 'Helvetica', sans-serif;
            font-size: 9px;
            color: #333;
            line-height: 1.4;
        }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 18px; color: #AB2F2B; margin: 0; text-transform: uppercase; }
        .header .publish-date { font-size: 9px; color: #6c757d; margin-top: 5px; }
        h2 {
            font-size: 12px;
            color: white;
            background-color: #AB2F2B;
            padding: 6px 10px;
            margin-top: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            font-weight: bold;
        }
        .instructions { margin-bottom: 20px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        .instructions ol { margin: 0; padding-left: 20px; }
        .instructions li { margin-bottom: 4px; text-align: justify; }
        .highlight { color: #AB2F2B; font-weight: bold; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            table-layout: fixed;
        }
        th, td {
            border: 1px solid #e0e0e0;
            padding: 6px 8px;
            text-align: left;
            vertical-align: top;
            word-wrap: break-word;
        }
        th {
            background-color: #f7f7f7;
            font-weight: bold;
            color: #333;
        }
        .col-comp { width: 30%; }
        .col-model { width: 25%; }
        .col-prog { width: 45%; }
        .no-data { text-align: center; font-style: italic; color: #999; }
        ul.prog-list { margin: 0; padding-left: 15px; list-style-type: disc; }
        ul.prog-list li { margin-bottom: 2px; }
        tr { page-break-inside: avoid; }
    </style>
</head>
<body>

    <div class="header">
        <h1>IDP Master Data Reference</h1>
        <div class="publish-date">Generated on {{ now()->format('d F Y, H:i') }}</div>
    </div>

    <h2>Instructions for Import IDP</h2>
    <div class="instructions">
        <ol>
            <li><strong>Fill out the data only in the "IDP" sheet.</strong></li>
            <li><strong>This upload adds new IDP plans for the selected employee.</strong>
                You do not need to fill an Employee ID column &mdash; the system assigns the
                data to the employee whose IDP you are uploading.
            </li>
            <li><strong>Time Frame (Start / End):</strong>
                Use the <strong>DD-MM-YYYY</strong> date format (e.g., 31-12-2025). The
                <em>time_frame_end</em> date cannot be earlier than the <em>time_frame_start</em> date.
            </li>
            <li><strong>Realization Date:</strong>
                If the plan is complete, enter the date in <strong>DD-MM-YYYY</strong> format and provide a
                proof link in the <em>result_evidence</em> column. If not yet realized, fill with a hyphen
                (<code>-</code>) and format the column as <strong>Text</strong>.
            </li>
            <li><strong>Competency Fields (Important):</strong>
                <br>If <strong>Competency Type</strong> is set to <strong>"Soft Competency"</strong>:
                <ul>
                    <li>Use the values for <em>competency_name</em> and <em>development_program</em> exactly as listed below.</li>
                    <li><span class="highlight">Validation Rule:</span> the chosen <em>development_program</em> must be
                        linked to both the <em>competency_name</em> and the <em>Development Model</em> in the Master Data
                        table below. Invalid combinations will fail to import.</li>
                </ul>
                If <strong>Competency Type</strong> is set to <strong>"Technical Competency"</strong>, you may use free text.
            </li>
            <li><strong>Review Tools:</strong> select one of the tools listed in the "Master Data: Review Tools" section.</li>
            <li><strong>Error Checking:</strong> if any row fails, the import result lists the specific reason per row.</li>
        </ol>
    </div>

    <h2>Master Data: Competency &amp; Related Programs</h2>
    <table>
        <thead>
            <tr>
                <th class="col-comp">Competency Name</th>
                <th class="col-model">Development Model</th>
                <th class="col-prog">Development Programs</th>
            </tr>
        </thead>
        <tbody>
            @forelse($competencyNames as $comp)
                @php $groupedPrograms = $competencyGroupedMap[$comp->id] ?? collect(); @endphp

                @if($groupedPrograms->isEmpty())
                    <tr>
                        <td style="font-weight: bold;">{{ $comp->name_en }}</td>
                        <td class="no-data">-</td>
                        <td class="no-data">No programs assigned</td>
                    </tr>
                @else
                    @php $isFirstRow = true; @endphp
                    @foreach($groupedPrograms as $modelName => $programs)
                        <tr>
                            <td style="font-weight: bold; vertical-align: top;">
                                @if($isFirstRow)
                                    {{ $comp->name_en }}
                                    @php $isFirstRow = false; @endphp
                                @endif
                            </td>
                            <td style="font-weight: bold; color: #555;">{{ $modelName }}</td>
                            <td>
                                <ul class="prog-list">
                                    @foreach($programs as $prog)
                                        <li>{{ $prog->name_en }}</li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    @endforeach
                @endif
            @empty
                <tr><td colspan="3" class="no-data" style="padding: 20px;">No master data available.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Master Data: Review Tools</h2>
    <table style="margin-bottom: 0;">
        <thead>
            <tr>
                <th style="width: 100%;">Review Tool Name</th>
            </tr>
        </thead>
        <tbody>
            @forelse($reviewTools as $tool)
                <tr><td>{{ $tool->name_en }}</td></tr>
            @empty
                <tr><td class="no-data">No review tools available.</td></tr>
            @endforelse
        </tbody>
    </table>

</body>
</html>
