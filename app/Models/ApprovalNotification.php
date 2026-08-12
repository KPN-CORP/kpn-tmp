<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An in-app approval notification for one recipient user — a "need approval"
 * alert or an approved / rejected outcome. App-owned (mysql).
 */
class ApprovalNotification extends Model
{
    protected $connection = 'mysql';

    protected $table = 'approval_notifications';

    protected $fillable = [
        'user_id',
        'employee_id',
        'type',
        'idp_approval_id',
        'individual_development_plan_id',
        'subject_employee_id',
        'subject_name',
        'title',
        'message',
        'link',
        'level',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
