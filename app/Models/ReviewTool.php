<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tool used to review an IDP item: a flat bilingual name list.
 */
class ReviewTool extends Model
{
    protected $fillable = ['name_en', 'name_id'];
}
