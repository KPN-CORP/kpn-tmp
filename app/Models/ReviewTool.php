<?php

namespace App\Models;

use App\Models\Concerns\HasActiveState;
use Illuminate\Database\Eloquent\Model;

/**
 * A tool used to review an IDP item: a flat bilingual name list, each row
 * active or inactive.
 */
class ReviewTool extends Model
{
    use HasActiveState;

    protected $fillable = [
        'name_en',
        'name_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * A new master is usable straight away. Declared here as well as on the
     * column so a model created without the field still carries the value —
     * otherwise `is_active` would be unset in memory until the row is re-read.
     */
    protected $attributes = [
        'is_active' => true,
    ];
}
