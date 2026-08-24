<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Corporate business-unit master (read-only), on the kpncorp connection. Its
 * `nama_bisnis` values are the business units a development-model package can be
 * scoped to, matched against an employee's `group_company`.
 */
class MasterBisnisunit extends Model
{
    protected $connection = 'kpncorp';

    protected $table = 'master_bisnisunits';

    public $timestamps = false;

    protected $guarded = ['id'];
}
