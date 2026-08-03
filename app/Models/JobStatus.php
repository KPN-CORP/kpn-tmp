<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobStatus extends Model
{
    /** @use HasFactory<\Database\Factories\JobStatusFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'file_name',
        'status',
        'error_message',
        'progress',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
