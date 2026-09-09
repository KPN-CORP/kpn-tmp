<?php

namespace App\Imports\Contracts;

/**
 * What the Import Center needs back from an importer to write its log row.
 *
 * Every importer collects per-row problems rather than throwing on the first
 * one — a spreadsheet is usually mostly right — so the outcome is two parts:
 * what landed, and what did not.
 */
interface ReportsImportOutcome
{
    /**
     * A one-line account of what the import did, for the log's result column.
     */
    public function summary(): string;

    /**
     * The rows that could not be imported, one message each. Empty when the
     * whole file landed.
     *
     * @return array<int, string>
     */
    public function errors(): array;
}
