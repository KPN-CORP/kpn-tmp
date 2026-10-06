<?php

namespace App\Services;

use App\Models\BusinessUnit;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Every read of the corporate (`kpncorp`) tables that the IDP master screens
 * need for their org-scope pickers, in one place.
 *
 * Two things are true of all of them: they are *option lists* — distinct
 * groupings, not rows anyone owns — and kpncorp may simply be unreachable. So
 * every read is guarded (an unreachable database leaves the screen working with
 * no options rather than 500ing) and every result is memoized for the life of
 * the request. The memoization is why this is bound as a singleton: the same
 * hierarchy used to be read twice on a training save (once to render, once to
 * validate) and the business-unit master three times on a single render.
 */
class CorporateScopeService
{
    /** @var array<string, mixed> */
    private array $memo = [];

    /**
     * The corporate business-unit master — the only source for what units
     * exist ({@see BusinessUnit}).
     *
     * @return list<string>
     */
    public function businessUnits(): array
    {
        return $this->once('businessUnits', fn () => BusinessUnit::names());
    }

    /**
     * Grades a development program or an implementation can be scoped to: the
     * distinct employee `job_level` values, matching how the rest of the app
     * treats "grade".
     *
     * @return list<string>
     */
    public function grades(): array
    {
        return $this->once('grades', function () {
            try {
                return Employee::whereNotNull('job_level')
                    ->distinct()->orderBy('job_level')
                    ->pluck('job_level')->filter()->values()->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * Work locations from the corporate `locations` table, grouped by the
     * business unit that owns them (`locations.company_name`, the same grouping
     * the employee master calls `group_company`). The location itself is the
     * `area` — the named site, e.g. "Head Office - Jakarta".
     *
     * The business-unit list is the master, so a unit with no location rows (or
     * no employees) still shows up; its location dropdown is then simply empty.
     * Location groups are resolved onto a master unit by name, which is what
     * folds the corporate tables' "KPN Plantations" into the master's
     * "Plantations" — that pair used to appear as two separate business units.
     * A group naming no master unit is dropped.
     *
     * @return array{businessUnits: list<string>, byBusinessUnit: array<string, list<string>>}
     */
    public function workLocations(): array
    {
        return $this->once('workLocations', function () {
            $businessUnits = $this->businessUnits();

            $byBusinessUnit = $this->group(
                fn () => DB::connection('kpncorp')->table('locations')
                    ->select('company_name', 'area')
                    ->whereNotNull('company_name')
                    ->whereNotNull('area')
                    ->distinct()
                    ->orderBy('area')
                    ->get(),
                fn ($row) => [$row->company_name, $row->area],
                $businessUnits,
            );

            return ['businessUnits' => $businessUnits, 'byBusinessUnit' => $byBusinessUnit];
        });
    }

    /**
     * Read a corporate table and fold it into a nested map of ordered, distinct
     * lists.
     *
     * `$path` returns the keys for one row, outermost first: the first is the
     * grouping value, resolved onto a master business unit, and the rest are
     * used verbatim down to the leaf. A row with a blank or placeholder ("-")
     * part, or one whose unit names no master, is dropped.
     *
     * @param  callable(): iterable<mixed>  $read
     * @param  callable(mixed): list<mixed>  $path
     * @param  list<string>  $units
     * @return array<string, mixed>
     */
    private function group(callable $read, callable $path, array $units): array
    {
        $map = [];

        try {
            foreach ($read() as $row) {
                $parts = array_map(fn ($part) => trim((string) $part), $path($row));

                $unit = BusinessUnit::resolveName(array_shift($parts), $units);

                if ($unit === null) {
                    continue;
                }

                if (array_filter($parts, fn (string $part) => $part === '' || $part === '-')) {
                    continue;
                }

                // Walk down to the leaf, creating the levels on the way, and
                // use the leaf itself as a key so duplicates collapse.
                $map[$unit] ??= [];
                $node = &$map[$unit];

                foreach ($parts as $part) {
                    $node[$part] ??= [];
                    $node = &$node[$part];
                }

                unset($node);
            }
        } catch (\Throwable) {
            // kpncorp unreachable, or a table this deployment doesn't carry.
        }

        return $this->toLists($map);
    }

    /**
     * Turn the de-dup maps built by {@see group()} into ordered lists: a level
     * whose children are all leaves becomes a sorted list, anything above it
     * stays a map.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function toLists(array $map): array
    {
        return collect($map)->map(function (array $node) {
            $leaf = collect($node)->every(fn ($child) => $child === []);

            return $leaf
                ? collect(array_keys($node))->sort()->values()->all()
                : $this->toLists($node);
        })->all();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @return T
     */
    private function once(string $key, callable $build): mixed
    {
        return $this->memo[$key] ??= $build();
    }
}
