<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    use HasFactory;

    protected $table = 'import_logs';

    protected $fillable = [
        'user_id',
        'data_type',
        'import_date',
        'result',
        'status',
        'original_file_path',
        'error_file_path',
    ];

    protected $casts = [
        'import_date' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
