<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An approval workflow for one module. Owns an ordered list of layers.
 * App-owned (default mysql connection).
 */
class ApprovalFlow extends Model
{
    protected $table = 'approval_flows';

    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Modules that can carry an approval flow. */
    public const MODULES = ['idp', 'appraisal'];

    /**
     * Layers in approval order (lowest sequence first).
     */
    public function layers(): HasMany
    {
        return $this->hasMany(ApprovalLayer::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }
}
