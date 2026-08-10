<?php

namespace App\Imports;

use App\Models\ApprovalSuperior;
use App\Models\ApprovalSuperiorHistory;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-import per-employee approval layers. Each row is keyed by header:
 * `nik` (or `employee_id`) plus `layer_1`..`layer_5` (each an approver NIK).
 * Existing mappings are replaced; every change is audited.
 */
class ApprovalLayerImport implements ToCollection, WithHeadingRow
{
    private int $imported = 0;

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(
        private readonly ?int $userId = null,
        private readonly ?string $userName = null,
    ) {}

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2; // account for the heading row

            $employeeId = trim((string) ($row->get('nik') ?? $row->get('employee_id') ?? ''));

            if ($employeeId === '') {
                $this->errors[] = "Row {$line}: missing NIK / employee_id.";

                continue;
            }

            // Read layer_1..layer_10 columns into an ordered, non-empty list.
            $layers = [];
            for ($i = 1; $i <= 10; $i++) {
                $value = trim((string) ($row->get('layer_'.$i) ?? ''));
                if ($value !== '') {
                    $layers[] = $value;
                }
            }

            ApprovalSuperior::updateOrCreate(
                ['employee_id' => $employeeId],
                ['layers' => $layers, 'updated_by' => $this->userId],
            );

            ApprovalSuperiorHistory::create([
                'employee_id' => $employeeId,
                'layers' => $layers,
                'changed_by' => $this->userId,
                'changed_by_name' => $this->userName,
                'created_at' => now(),
            ]);

            $this->imported++;
        }
    }

    public function imported(): int
    {
        return $this->imported;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
