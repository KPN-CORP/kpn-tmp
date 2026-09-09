<?php

namespace App\Imports;

use App\Enums\MasterDataType;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\Support\SkipsForeignSheets;
use App\Models\BusinessUnit;
use App\Models\CompetencyType;
use App\Services\IdpMasterService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-import competency types. Each row is keyed by header: `code`, `name_en`,
 * `name_id`, `description_en`, `description_id` and `business_units`.
 *
 * Writes go through {@see IdpMasterService} in the wire shape the Master
 * Competency Type screen posts, so an imported type is built exactly the way a
 * typed one is — the code is trimmed, the business units are replaced
 * wholesale, and a rename cascades to the IDP rows naming the type.
 *
 * A row is matched to an existing type by its **code** first, then by its
 * English name; so importing over a type that predates the code column is what
 * gives it one, rather than creating a duplicate.
 */
class CompetencyTypeImport implements ReportsImportOutcome, ToCollection, WithHeadingRow
{
    use SkipsForeignSheets;

    /**
     * What separates the units inside one `business_units` cell. The template
     * writes `;`, which is what the error messages name; the pipe and a line
     * break are accepted too, since a hand-built file may use either.
     * Deliberately not the comma: a corporate unit name may well carry one.
     */
    private const UNIT_SEPARATORS = '/[|;\r\n]+/';

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * Codes and names already claimed in this run, so a file cannot fight
     * itself — the second of two rows sharing one would otherwise silently
     * overwrite the first.
     *
     * @var array<string, int>
     */
    private array $seenCodes = [];

    /** @var array<string, int> */
    private array $seenNames = [];

    public function __construct(private readonly IdpMasterService $masters) {}

    public function collection(Collection $rows): void
    {
        if (! $this->isOurSheet($rows, ['code', 'name_en', 'business_units'])) {
            return;
        }

        // The corporate master, read once: every row's units resolve against it.
        $unitNames = BusinessUnit::names();

        foreach ($rows as $index => $row) {
            $line = $index + 2; // account for the heading row

            $code = trim((string) $row->get('code'));
            $nameEn = trim((string) $row->get('name_en'));

            if ($code === '' && $nameEn === '' && trim((string) $row->get('business_units')) === '') {
                // A trailing blank row is not a mistake worth reporting.
                continue;
            }

            if ($code === '' || $nameEn === '') {
                $this->errors[] = "Row {$line}: missing code or name_en.";

                continue;
            }

            if (mb_strlen($code) > 50 || mb_strlen($nameEn) > 255) {
                $this->errors[] = "Row {$line}: code may not exceed 50 characters, name_en 255.";

                continue;
            }

            // Codes and names are compared case-insensitively, matching the
            // unique indexes behind them (the connection collates that way).
            $codeKey = mb_strtolower($code);
            $nameKey = mb_strtolower($nameEn);

            if (isset($this->seenCodes[$codeKey])) {
                $this->errors[] = "Row {$line}: code '{$code}' is already used by row {$this->seenCodes[$codeKey]}.";

                continue;
            }

            if (isset($this->seenNames[$nameKey])) {
                $this->errors[] = "Row {$line}: name '{$nameEn}' is already used by row {$this->seenNames[$nameKey]}.";

                continue;
            }

            $units = $this->resolveUnits($row->get('business_units'), $unitNames, $line);

            if ($units === null) {
                continue;
            }

            // Code first, so importing over a type that predates the column is
            // what gives it one instead of creating a second type by that name.
            $existing = CompetencyType::where('code', $code)->first()
                ?? CompetencyType::where('name_en', $nameEn)->first();

            if ($clash = $this->clashingType($existing, 'code', $code)) {
                $this->errors[] = "Row {$line}: code '{$code}' already belongs to '{$clash}'.";

                continue;
            }

            if ($clash = $this->clashingType($existing, 'name_en', $nameEn)) {
                $this->errors[] = "Row {$line}: name '{$nameEn}' already belongs to the type coded '{$clash}'.";

                continue;
            }

            $data = [
                'code' => $code,
                'value_en' => $nameEn,
                'value_id' => $this->cell($row->get('name_id')),
                'description_en' => $this->cell($row->get('description_en')),
                'description_id' => $this->cell($row->get('description_id')),
                'business_units' => $units,
            ];

            try {
                if ($existing !== null) {
                    $this->masters->update(MasterDataType::CompetencyType, $existing, $data);
                    $this->updated++;
                } else {
                    $this->masters->create(MasterDataType::CompetencyType, $data);
                    $this->created++;
                }
            } catch (\Throwable $e) {
                // One unwritable row must not cost the rest of the file.
                $this->errors[] = "Row {$line}: could not be saved — {$e->getMessage()}";

                continue;
            }

            $this->seenCodes[$codeKey] = $line;
            $this->seenNames[$nameKey] = $line;
        }
    }

    public function summary(): string
    {
        $total = $this->created + $this->updated;

        if ($total === 0) {
            return 'No competency type was imported.';
        }

        return "Imported {$total} competency type(s): {$this->created} created, {$this->updated} updated.";
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->withSheetError($this->errors);
    }

    /**
     * The business units named in one cell, resolved onto the corporate master,
     * or null when the cell is unusable (the row's error has been recorded).
     *
     * A type says where it applies, so at least one unit is required — the same
     * rule the Master Competency Type form enforces.
     *
     * @param  list<string>  $unitNames
     * @return list<string>|null
     */
    private function resolveUnits(mixed $cell, array $unitNames, int $line): ?array
    {
        $raw = array_filter(array_map(
            'trim',
            preg_split(self::UNIT_SEPARATORS, (string) $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ));

        if ($raw === []) {
            $this->errors[] = "Row {$line}: business_units is required — name at least one, separated by ';'.";

            return null;
        }

        $units = [];
        $unknown = [];

        foreach ($raw as $value) {
            // With the corporate master unreachable there is nothing to check
            // against, so the value is taken as typed rather than rejected —
            // the same way every other corporate read here degrades.
            $resolved = $unitNames === [] ? $value : BusinessUnit::resolveName($value, $unitNames);

            if ($resolved === null) {
                $unknown[] = $value;

                continue;
            }

            $units[] = $resolved;
        }

        if ($unknown !== []) {
            $this->errors[] = "Row {$line}: unknown business unit(s): ".implode(', ', $unknown).'.';

            return null;
        }

        return array_values(array_unique($units));
    }

    /**
     * The other type already holding this value, named for the error message,
     * or null when the value is free.
     */
    private function clashingType(?CompetencyType $existing, string $column, string $value): ?string
    {
        $other = CompetencyType::where($column, $value)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->first();

        if ($other === null) {
            return null;
        }

        return $column === 'code' ? $other->name_en : (string) ($other->code ?? '—');
    }

    private function cell(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
