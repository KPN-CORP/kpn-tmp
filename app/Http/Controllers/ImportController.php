<?php

namespace App\Http\Controllers;

use App\Models\ImportLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
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
        return Inertia::render('Import/Index', [
            'dataTypes' => collect(self::DATA_TYPES)->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])->values(),
            'logs' => ImportLog::with('user:id,name')
                ->latest('import_date')
                ->paginate((int) $request->integer('per_page', 15))
                ->withQueryString(),
        ]);
    }

    /**
     * Accept an upload, store it, and record a log row.
     *
     * NOTE: actual spreadsheet parsing is DEFERRED until maatwebsite/excel is
     * installed. For now the file is stored and logged as "Pending" so the flow,
     * storage, logging, and download all work end-to-end; wire the per-type
     * Import classes here when the dependency is added.
     */
    public function processImport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'data_type' => ['required', 'string', 'in:'.implode(',', array_keys(self::DATA_TYPES))],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $path = $request->file('file')->store('imports', 'local');

        ImportLog::create([
            'user_id' => $request->user()->id,
            'data_type' => $validated['data_type'],
            'import_date' => now(),
            'status' => 'Pending',
            'result' => 'Uploaded and queued. Spreadsheet processing is not yet enabled '
                .'(pending maatwebsite/excel).',
            'original_file_path' => $path,
        ]);

        return back()->with('success', 'File uploaded. Processing will run once import parsing is enabled.');
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
