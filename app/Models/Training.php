<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A training in the master training catalogue (bilingual name + description).
 */
class Training extends Model
{
    protected $fillable = ['name_en', 'name_id', 'description_en', 'description_id'];
}
