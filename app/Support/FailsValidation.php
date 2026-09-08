<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Reporting a cross-field rule as a field error.
 *
 * The master-data rules are relational — "this level is not a rung of that
 * competency" — so they run after `validate()` rather than as rules on a field.
 * They still have to surface where the form can show them, which is what this
 * does: one field, one message, thrown like any validation failure.
 */
trait FailsValidation
{
    protected function fail(string $field, string $message): never
    {
        $validator = validator([], []);
        $validator->errors()->add($field, $message);

        throw new ValidationException($validator);
    }
}
