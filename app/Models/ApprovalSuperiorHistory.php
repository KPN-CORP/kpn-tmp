<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An audit snapshot of an employee's approval chain, written on every save.
 * App-owned (default mysql). Only `created_at` is tracked.
 */
class ApprovalSuperiorHistory extends Model
{
    protected $table = 'approval_superior_histories';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'layers' => 'array',
        'created_at' => 'datetime',
    ];
}
