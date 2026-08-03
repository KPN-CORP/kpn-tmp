<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGuide extends Model
{
    /** @use HasFactory<\Database\Factories\UserGuideFactory> */
    use HasFactory;

    protected $table = 'user_guides';

    protected $fillable = [
        'title',
        'description',
        'file_path',
        'file_name',
        'file_size',
        'target_role',
        'uploaded_by',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
