<?php

namespace App\Http\Controllers;

use App\Exports\Templates\CompetencyTemplateExport;
use App\Exports\Templates\CompetencyTypeTemplateExport;
use App\Exports\Templates\DevelopmentProgramTemplateExport;
use App\Exports\Templates\ReviewToolTemplateExport;
use App\Exports\Templates\TrainingTemplateExport;
use App\Http\Controllers\Concerns\ReadsSort;
use App\Imports\CompetencyAssessmentImport;
use App\Imports\CompetencyImport;
use App\Imports\CompetencyTypeImport;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\DevelopmentProgramImport;
use App\Imports\ReviewToolImport;
use App\Imports\TrainingImport;
use App\Models\ImportLog;
use App\Models\User;
use App\Services\CorporateScopeService;
use App\Services\Idp\Rules\CompetencyMasterRules;
use App\Services\Idp\Rules\ProgramMasterRules;
use App\Services\Idp\Rules\TrainingMasterRules;
use App\Services\IdpMasterService;
use App\Services\MatrixGradeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        'competency_type' => 'Master Competency Type',
        'competency' => 'Master Competency',
        'training' => 'Master Training',
        'development_program' => 'Master Development',
        'review_tools' => 'Review Tools',
    ];

    /**
     * Data sets that write IDP master data. The Import Center's own permission
     * only says a user may upload talent data, so these are offered — and
     * accepted — only to someone who may manage the masters anyway.
     */
    private const MASTER_DATA_TYPES = [
        'competency_type', 'competency', 'training', 'development_program', 'review_tools',
    ];

    /**
     * Downloadable starter workbooks, per data type. A data type with no entry
     * here simply shows no template link.
     *
     * @var array<string, class-string>
     */
    private const TEMPLATES = [
        'competency_type' => CompetencyTypeTemplateExport::class,
        'competency' => CompetencyTemplateExport::class,
        'training' => TrainingTemplateExport::class,
        'development_program' => DevelopmentProgramTemplateExport::class,
        'review_tools' => ReviewToolTemplateExport::class,
    ];

    public function index(Request $request): Response
    {
        $sort = $this->readSort($request, ['data_type', 'import_date', 'status'], 'import_date');
        // Newest-first feels natural for the date column's default.
        $dir = $sort['key'] === 'import_date' && ! $request->filled('sort') ? 'desc' : $sort['dir'];

        return Inertia::render('Import/Index', [
            'dataTypes' => collect($this->dataTypesFor($request->user()))
                ->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                    'template' => isset(self::TEMPLATES[$value]),
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
     * The data sets with an importer are parsed now; the rest store the file
     * and log as Pending until their per-type importers are added.
     */
    public function processImport(
        Request $request,
        MatrixGradeService $matrix,
        IdpMasterService $masters,
        CompetencyMasterRules $competencyRules,
        TrainingMasterRules $trainingRules,
        CorporateScopeService $corporate,
        ProgramMasterRules $programRules,
    ): RedirectResponse {
        $validated = $request->validate([
            'data_type' => [
                'required', 'string',
                'in:'.implode(',', array_keys($this->dataTypesFor($request->user()))),
            ],
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        $path = $request->file('file')->store('imports', 'local');

        $log = [
            'user_id' => $request->user()->id,
            'data_type' => $validated['data_type'],
            'import_date' => now(),
            'original_file_path' => $path,
        ];

        $importer = match ($validated['data_type']) {
            'competency_assessment' => new CompetencyAssessmentImport($matrix),
            'competency_type' => new CompetencyTypeImport($masters),
            'competency' => new CompetencyImport($masters, $competencyRules),
            'training' => new TrainingImport($masters, $trainingRules, $corporate),
            'development_program' => new DevelopmentProgramImport($masters, $programRules),
            'review_tools' => new ReviewToolImport($masters),
            default => null,
        };

        if ($importer instanceof ReportsImportOutcome) {
            try {
                Excel::import($importer, Storage::disk('local')->path($path));

                $errors = $importer->errors();
                $log['status'] = $errors === [] ? 'Success' : 'Failed';
                $log['result'] = $importer->summary()
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

    /**
     * The starter workbook for a data type — the columns its importer reads,
     * filled with sample rows.
     */
    public function template(Request $request, string $type): BinaryFileResponse
    {
        abort_unless(
            isset(self::TEMPLATES[$type]) && isset($this->dataTypesFor($request->user())[$type]),
            404,
        );

        // Resolved rather than newed: a template may take dependencies (the
        // Master Training one reads the corporate scope).
        $export = app(self::TEMPLATES[$type]);

        return Excel::download($export, $type.'_import_template.xlsx');
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

    /**
     * The data sets this user may import, keyed as {@see DATA_TYPES}.
     *
     * @return array<string, string>
     */
    private function dataTypesFor(?User $user): array
    {
        if ($user?->can('view_idp_master')) {
            return self::DATA_TYPES;
        }

        return array_diff_key(self::DATA_TYPES, array_flip(self::MASTER_DATA_TYPES));
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
