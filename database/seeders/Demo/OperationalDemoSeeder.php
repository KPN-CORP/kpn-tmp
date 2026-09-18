<?php

namespace Database\Seeders\Demo;

use App\Models\ImportLog;
use App\Models\User;
use App\Models\UserGuide;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The operational screens: the Import Center log and the User Guide library.
 *
 * Each guide is written as a real file on the `local` disk, so the download
 * button actually returns something instead of 404-ing on a path that was only
 * ever a database string.
 */
class OperationalDemoSeeder extends Seeder
{
    /** [data_type, status, result, days ago] */
    private const IMPORT_LOGS = [
        ['competency_type', 'Success', 'Imported 4 competency types (1 created, 3 updated). 0 errors.', 21],
        ['competency', 'Success', 'Imported 11 competencies with 33 proficiency levels and 29 sub competencies. 0 errors.', 18],
        ['training', 'Success', 'Imported 10 trainings (10 created, 0 updated). 0 errors.', 14],
        ['development_program', 'Success', 'Imported 24 development programs (24 created, 0 updated). 0 errors.', 12],
        ['review_tools', 'Success', 'Imported 8 review tools (0 created, 8 updated). 0 errors.', 11],
        ['competency_assessment', 'Failed', "3 rows imported, 2 rows failed.\nRow 14: employee 01124990099 was not found.\nRow 27: synergized team score must be between 0 and 4.", 6],
        ['competency_assessment', 'Success', 'Imported 286 competency assessments for period 2026. 0 errors.', 4],
        ['talent_box', 'Pending', 'Awaiting processing.', 1],
    ];

    /** [title, description, target_role, filename] */
    private const GUIDES = [
        ['Employee User Guide', 'How to view your facecard, fill in your Individual Development Plan and submit an item for approval.', 'all', 'employee-user-guide.txt'],
        ['Superior Approval Guide', 'How to review the items waiting in your approval inbox, and what a rejection sends back to the employee.', 'superior', 'superior-approval-guide.txt'],
        ['Admin Master Data Guide', 'Managing competency types, competencies, trainings and development programmes, and importing them in bulk.', 'admin', 'admin-master-data-guide.txt'],
    ];

    public function run(): void
    {
        $user = User::whereNotNull('employee_id')->orderBy('id')->first();

        $this->seedImportLogs($user);
        $this->seedGuides($user);

        $this->command?->info('  import logs: '.ImportLog::count().' | user guides: '.UserGuide::count());
    }

    private function seedImportLogs(?User $user): void
    {
        foreach (self::IMPORT_LOGS as [$type, $status, $result, $daysAgo]) {
            $when = now()->subDays($daysAgo)->setTime(9, 15);

            ImportLog::updateOrCreate(
                ['data_type' => $type, 'import_date' => $when],
                [
                    'user_id' => $user?->id,
                    'status' => $status,
                    'result' => $result,
                ],
            );
        }
    }

    private function seedGuides(?User $user): void
    {
        foreach (self::GUIDES as [$title, $description, $role, $filename]) {
            $path = 'user-guides/'.$filename;

            if (! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, $this->guideBody($title, $description));
            }

            UserGuide::updateOrCreate(
                ['title' => $title],
                [
                    'description' => $description,
                    'file_path' => $path,
                    'file_name' => $filename,
                    'file_size' => (string) Storage::disk('local')->size($path),
                    'target_role' => $role,
                    'uploaded_by' => $user?->id,
                ],
            );
        }
    }

    private function guideBody(string $title, string $description): string
    {
        return $title."\n".str_repeat('=', strlen($title))."\n\n"
            .$description."\n\n"
            ."This is placeholder demo content so the download works end to end.\n"
            ."Replace it by uploading the real guide from the User Guide screen.\n";
    }
}
