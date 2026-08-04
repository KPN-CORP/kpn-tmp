<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ReadsSort;
use App\Imports\CompetencyAssessmentImport;
use App\Models\ImportLog;
use App\Services\MatrixGradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    use ReadsSort;

    /**
     * Importable data sets. Each maps to a spreadsheet importer (added when
     * maatwebsite/excel lands — see processImport).
     */
    public const DATA_TYPES = [
        'competency_assessment' => 'Competency Assessment',
        'data_master' => 'Data Master (Matrix Grade)',
        'idp' => 'Individual Development Program',
        'talent_box' => 'Talent Box & Potential',
        'proposed_grade' => 'Proposed Grade',
        'succession' => 'Succession',
    ];

    public function index(Request $request): Response
    {
        $sort = $this->readSort($request, ['data_type', 'import_date', 'status'], 'import_date');
        // Newest-first feels natural for the date column's default.
        $dir = $sort['key'] === 'import_date' && ! $request->filled('sort') ? 'desc' : $sort['dir'];

        return Inertia::render('Import/Index', [
            'dataTypes' => collect(self::DATA_TYPES)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'sort' => ['key' => $sort['key'], 'dir' => $dir],
            'logs' => ImportLog::with('user:id,name')
                ->orderBy($sort['key'], $dir)
                ->paginate((int) $request->integer('per_page', 10))
                ->withQueryString(),
        ]);
    }

    /**
     * Accept an upload, store it, parse it, and record the outcome.
     *
     * `competency_assessment` is parsed now; the other data sets store the file
     * and log as Pending until their per-type importers are added.
     */
    public function processImport(Request $request, MatrixGradeService $matrix): RedirectResponse
    {
        $validated = $request->validate([
            'data_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::DATA_TYPES))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $path = $request->file('file')->store('imports', 'local');

        $log = [
            'user_id' => $request->user()->id,
            'data_type' => $validated['data_type'],
            'import_date' => now(),
            'original_file_path' => $path,
        ];

        if ($validated['data_type'] === 'competency_assessment') {
            try {
                $import = new CompetencyAssessmentImport($matrix);
                Excel::import($import, Storage::disk('local')->path($path));

                $errors = $import->errors();
                $log['status'] = $errors === [] ? 'Success' : 'Failed';
                $log['result'] = "Imported {$import->imported()} row(s)."
                    .($errors === [] ? '' : ' Issues: '.implode(' ', array_slice($errors, 0, 20)));
            } catch (\Throwable $e) {
                $log['status'] = 'Failed';
                $log['result'] = 'Import error: '.$e->getMessage();
            }
        } else {
            $log['status'] = 'Pending';
            $log['result'] = 'Uploaded. An importer for this data type is not enabled yet.';
        }

        ImportLog::create($log);

        return back()->with(
            $log['status'] === 'Failed' ? 'error' : 'success',
            $log['result'],
        );
    }

    public function download(ImportLog $log): StreamedResponse
    {
        abort_unless($log->original_file_path && Storage::disk('local')->exists($log->original_file_path), 404);

        $name = Str::of($log->data_type)->slug('_').'_'.$log->id.'.xlsx';

        return Storage::disk('local')->download($log->original_file_path, $name);
    }

    public function destroy(ImportLog $log): RedirectResponse
    {
        $this->deleteFiles($log);
        $log->delete();

        return back()->with('success', 'Import log deleted.');
    }

    public function destroyAll(): RedirectResponse
    {
        ImportLog::query()->each(fn (ImportLog $log) => $this->deleteFiles($log));
        ImportLog::query()->delete();

        return back()->with('success', 'All import logs cleared.');
    }

    private function deleteFiles(ImportLog $log): void
    {
        foreach ([$log->original_file_path, $log->error_file_path] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
