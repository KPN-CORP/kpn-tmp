<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The corporate business-unit master, read from kpncorp's `master_bisnisunits`.
 *
 * This is the single source of truth for "what business units exist". Before,
 * every business-unit dropdown in the app derived its options from whatever
 * distinct values happened to sit in `employees.group_company` (and, on the
 * Master Training screen, `locations.company_name` as well) — so a unit with no
 * employees yet was simply invisible, and the two sources did not agree
 * ("Plantations" vs "KPN Plantations").
 *
 * The unit's NAME (`nama_bisnis`) is what the app stores and compares: it is
 * the value `employees.group_company` holds, which is what role scopes, the
 * list filters and the master child tables (training_business_units,
 * implementation_business_units, competency_type_business_units) are all
 * matched against. `kode_bisnis` is the corporate code (BU01…) and is used only
 * for ordering.
 *
 * Read-only: kpncorp is the corporate master and this app never writes to it.
 */
class BusinessUnit extends Model
{
    protected $connection = 'kpncorp';

    protected $table = 'master_bisnisunits';

    protected $primaryKey = 'kode_bisnis';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /**
     * Read-only: nothing here is mass-assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /**
     * Resolve a raw corporate grouping string onto the master unit it names, or
     * null when none matches.
     *
     * The corporate tables do not spell the units consistently:
     * `locations.company_name` and `departments`/`designations.parent_company_id`
     * say "KPN Plantations" where the master — and `employees.group_company` —
     * say "Plantations". So an exact (case-insensitive) match is tried first,
     * then containment either way. A value naming no master unit (e.g.
     * "KPN Sugar", which the master does not carry) resolves to null.
     *
     * Takes the name list rather than reading it, so a caller resolving a whole
     * table hits the corporate DB once.
     *
     * @param  list<string>  $names  from {@see names()}
     */
    public static function resolveName(string $raw, array $names): ?string
    {
        $needle = strtolower(trim($raw));

        if ($needle === '') {
            return null;
        }

        foreach ($names as $name) {
            if (strtolower($name) === $needle) {
                return $name;
            }
        }

        foreach ($names as $name) {
            $candidate = strtolower($name);

            if (str_contains($needle, $candidate) || str_contains($candidate, $needle)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Every business-unit name, in the master's own order (by `kode_bisnis`,
     * which keeps the catch-all "Others" last rather than sorting it into the
     * middle alphabetically).
     *
     * kpncorp being unreachable yields an empty list rather than an exception,
     * so a screen that only needs the options keeps working — the same way
     * every other corporate read in this app is guarded.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        try {
            return static::query()
                ->whereNotNull('nama_bisnis')
                ->orderBy('kode_bisnis')
                ->pluck('nama_bisnis')
                ->map(fn ($name) => trim((string) $name))
                ->filter(fn (string $name) => $name !== '' && $name !== '-')
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
