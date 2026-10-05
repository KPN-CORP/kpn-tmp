<?php

namespace App\Http\Controllers;

use App\Exports\Templates\CompetencyTemplateExport;
use App\Exports\Templates\CompetencyTypeTemplateExport;
use App\Exports\Templates\DevelopmentProgramTemplateExport;
use App\Exports\Templates\ReviewToolTemplateExport;
use App\Exports\Templates\TrainingTemplateExport;
use App\Http\Controllers\Concerns\ReadsPerPage;
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
use Illuminate\Database\Eloquent\Builder;
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
    use ReadsPerPage;
    use ReadsSort;

    /**
     * Importable data sets — label + the permission that unlocks each. The menu
     * itself needs `view_import_center`; a data type is then offered (and
     * accepted) only to a user who also holds its own `import_*` permission.
     *
     * Only data types with a working importer (see processImport) are listed.
     * Data Master, IDP, Talent Box, Proposed Grade and Succession join here —
     * their permissions already exist — once their importers are written.
     *
     * @var array<string, array{label: string, permission: string}>
     */
    public const DATA_TYPES = [
        'competency_assessment' => ['label' => 'Competency Assessment', 'permission' => 'import_competency_assessment'],
        'competency_type' => ['label' => 'Master Competency Type', 'permission' => 'import_competency_type'],
        'competency' => ['label' => 'Master Competency', 'permission' => 'import_competency'],
        'training' => ['label' => 'Master Training', 'permission' => 'import_training'],
        'development_program' => ['label' => 'Master Development', 'permission' => 'import_development_program'],
        'review_tools' => ['label' => 'Review Tools', 'permission' => 'import_review_tools'],
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
            'logs' => $this->visibleLogs($request->user())
                ->with('user:id,name')
                ->orderBy($sort['key'], $dir)
                ->paginate($this->perPage($request))
                ->withQueryString(),
        ]);
    }

    /**
     * Accept an upload, store it, parse it, and record the outcome.
     *
     * Every offered data type has an importer; the Pending branch below is only
     * a fallback for one added to DATA_TYPES before its importer is wired here.
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

    public function download(Request $request, ImportLog $log): StreamedResponse
    {
        abort_unless($this->canSeeLog($request->user(), $log), 404);
        abort_unless($log->original_file_path && Storage::disk('local')->exists($log->original_file_path), 404);

        $name = Str::of($log->data_type)->slug('_').'_'.$log->id.'.xlsx';

        return Storage::disk('local')->download($log->original_file_path, $name);
    }

    public function destroy(Request $request, ImportLog $log): RedirectResponse
    {
        abort_unless($this->canSeeLog($request->user(), $log), 404);

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
        return collect(self::DATA_TYPES)
            ->filter(fn (array $type) => (bool) $user?->can($type['permission']))
            ->map(fn (array $type) => $type['label'])
            ->all();
    }

    /**
     * Data types that still have logs but are not offered for upload here, with
     * the permission that covers them: the single-employee IDP upload (written by
     * IdpController::import), and the types whose Pending-only importers were
     * withdrawn but whose old logs remain.
     *
     * @var array<string, string>
     */
    private const LOG_ONLY_TYPES = [
        'idp' => 'import_idp',
        'data_master' => 'import_data_master',
        'talent_box' => 'import_talent_box',
        'proposed_grade' => 'import_proposed_grade',
        'succession' => 'import_succession',
    ];

    /**
     * Data types whose logs this user may see even when someone else uploaded
     * them: the ones whose import permission they hold.
     *
     * @return list<string>
     */
    private function logTypesFor(User $user): array
    {
        $types = array_keys($this->dataTypesFor($user));

        foreach (self::LOG_ONLY_TYPES as $type => $permission) {
            if ($user->can($permission)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * The logs this user may list, download and delete: their own uploads, plus
     * every upload of a data type they may import. A log holds the uploaded
     * spreadsheet, so holding `view_import_center` alone must not expose other
     * people's files.
     */
    private function visibleLogs(User $user): Builder
    {
        $types = $this->logTypesFor($user);

        return ImportLog::query()->where(function (Builder $q) use ($user, $types) {
            $q->where('user_id', $user->id);

            if ($types !== []) {
                $q->orWhereIn('data_type', $types);
            }
        });
    }

    private function canSeeLog(User $user, ImportLog $log): bool
    {
        return (int) $log->user_id === (int) $user->id
            || in_array($log->data_type, $this->logTypesFor($user), true);
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
