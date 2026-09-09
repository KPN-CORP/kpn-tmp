<?php

namespace App\Imports;

use App\Enums\MasterDataType;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\Support\SkipsForeignSheets;
use App\Models\ReviewTool;
use App\Services\IdpMasterService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-import review tools. One row each, keyed by header: `name_en`,
 * `name_id`, `is_active`.
 *
 * The simplest of the master imports, because a review tool is the simplest
 * master: a bilingual name and an active flag, with no description, no code and
 * nothing it points at. So there is no scope to police and no rule class to
 * defer to — only the name rules the shared endpoints apply.
 *
 * A row is matched to a stored tool by its English name, the only identifier
 * one has. Renaming is therefore not something this import can do: a new name
 * simply creates a new tool.
 */
class ReviewToolImport implements ReportsImportOutcome, ToCollection, WithHeadingRow
{
    use SkipsForeignSheets;

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, int> */
    private array $seenNames = [];

    public function __construct(private readonly IdpMasterService $masters) {}

    public function collection(Collection $rows): void
    {
        // A review tool's three columns are a strict subset of what the other
        // master templates carry, so "has name_en and is_active" would also
        // match a Training or Competency sheet — and quietly turn its rows into
        // review tools if someone picked the wrong data type. Hence the
        // negative half: anything naming a competency, a model or a code is
        // some other master's sheet.
        if (! $this->isOurSheet(
            $rows,
            ['name_en', 'is_active'],
            ['code', 'competency', 'competency_type', 'development_model', 'business_units'],
        )) {
            return;
        }

        foreach ($rows as $index => $row) {
            $this->writeOne($index + 2, collect($row));
        }
    }

    public function summary(): string
    {
        $total = $this->created + $this->updated;

        if ($total === 0) {
            return 'No review tool was imported.';
        }

        return "Imported {$total} review tool(s): {$this->created} created, {$this->updated} updated.";
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->withSheetError($this->errors);
    }

    private function writeOne(int $line, Collection $row): void
    {
        $nameEn = $this->cell($row, 'name_en');

        if ($nameEn === null) {
            // Blank throughout is a trailing row, not a mistake.
            if ($row->every(fn ($value) => trim((string) $value) === '')) {
                return;
            }

            $this->errors[] = "Row {$line}: name_en is required.";

            return;
        }

        if (mb_strlen($nameEn) > 255) {
            $this->errors[] = "Row {$line}: name_en may not exceed 255 characters.";

            return;
        }

        // Compared case-insensitively, matching the unique rule behind it.
        $nameKey = mb_strtolower($nameEn);

        if (isset($this->seenNames[$nameKey])) {
            $this->errors[] = "Row {$line}: name '{$nameEn}' is already used by row {$this->seenNames[$nameKey]}.";

            return;
        }

        $existing = ReviewTool::where('name_en', $nameEn)->first();

        $data = [
            'value_en' => $nameEn,
            'value_id' => $this->cell($row, 'name_id'),
            'is_active' => $this->flag($row),
        ];

        try {
            if ($existing !== null) {
                $this->masters->update(MasterDataType::ReviewTools, $existing, $data);
                $this->updated++;
            } else {
                $this->masters->create(MasterDataType::ReviewTools, $data);
                $this->created++;
            }
        } catch (\Throwable $e) {
            // One unwritable row must not cost the rest of the file.
            $this->errors[] = "Row {$line}: could not be saved — {$e->getMessage()}";

            return;
        }

        $this->seenNames[$nameKey] = $line;
    }

    /**
     * A yes/no cell. Anything unrecognised — a blank one included — means
     * active, matching the form, where a new row arrives switched on.
     */
    private function flag(Collection $row): bool
    {
        $value = $this->cell($row, 'is_active');

        if ($value === null) {
            return true;
        }

        return ! in_array(mb_strtolower($value), ['0', 'no', 'false', 'n', 'inactive', 'tidak'], true);
    }

    private function cell(Collection $row, string $key): ?string
    {
        $value = trim((string) $row->get($key));

        return $value === '' ? null : $value;
    }
}
