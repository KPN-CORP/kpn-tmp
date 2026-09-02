<?php

namespace App\Services;

use App\Enums\MasterDataType;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The audit trail for activating / deactivating IDP master data and master
 * implementations.
 *
 * It is stored **outside the database on purpose**: toggling a master is a
 * frequent, low-value write, and routing it through a table would grow the
 * database and add a write to every save. Entries are appended as JSON lines to
 * a month-per-file log under `storage/app/audit/`, which is cheap to write,
 * trivially greppable, and rotates by itself.
 *
 * Nothing else reads these files, so the format is free to be exactly what the
 * history drawer needs. An entry is self-contained — it carries the master's
 * name and the actor's name as they were at the time — so the history stays
 * readable after a rename, and after the master or the user is deleted.
 */
class MasterStatusAudit
{
    /**
     * The subject key for a master implementation. Every other subject is a
     * {@see MasterDataType} wire value, so this is the one kind
     * that needs naming.
     */
    public const IMPLEMENTATION = 'implementation';

    /**
     * How many entries a history read returns at most.
     */
    private const LIMIT = 200;

    /**
     * How many month files back a history read is willing to scan.
     */
    private const MONTHS = 24;

    /**
     * Append one activate/deactivate entry. Called only when the flag actually
     * changed, so the log holds transitions rather than every save.
     *
     * `$subject` is the kind being toggled — a MasterDataType wire value, or
     * {@see self::IMPLEMENTATION}. `$name` is the label to show in the history,
     * captured now so it survives a later rename.
     */
    public function record(string $subject, int $id, string $name, bool $active, ?User $actor): void
    {
        $entry = [
            'at' => now()->toIso8601String(),
            'type' => $subject,
            'id' => $id,
            // Kept verbatim so a later rename does not rewrite history.
            'name' => $name,
            'active' => $active,
            'by' => [
                'id' => $actor?->id,
                'employee_id' => $actor?->employee_id,
                'name' => $actor?->name ?? 'System',
            ],
        ];

        $this->append($entry);
    }

    /**
     * One subject's transitions, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function for(string $subject, int $id, int $limit = self::LIMIT): array
    {
        $entries = [];
        $month = now()->startOfMonth();

        // Walk months backwards, newest first, so a short history stops after
        // reading one small file instead of the whole trail.
        for ($i = 0; $i < self::MONTHS && count($entries) < $limit; $i++) {
            $path = $this->pathFor($month);
            $month = $month->copy()->subMonth();

            if (! is_file($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

            // Within a file the newest entry is last, so read it backwards.
            foreach (array_reverse($lines) as $line) {
                $entry = json_decode($line, true);

                if (! is_array($entry) || ($entry['type'] ?? null) !== $subject) {
                    continue;
                }

                if ((int) ($entry['id'] ?? 0) !== $id) {
                    continue;
                }

                $entries[] = $entry;

                if (count($entries) >= $limit) {
                    break;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function append(array $entry): void
    {
        $path = $this->pathFor(now());
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;

        // The audit is a side record, never the reason a save fails: if the
        // file cannot be written the change still goes through, and the failure
        // is reported to the normal log.
        if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            Log::warning('Could not write the master status audit entry.', $entry);
        }
    }

    private function pathFor(\DateTimeInterface $month): string
    {
        return storage_path('app/audit/master-status-'.$month->format('Y-m').'.jsonl');
    }
}
