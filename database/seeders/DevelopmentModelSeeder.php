<?php

namespace Database\Seeders;

use App\Models\DevelopmentModel;
use Illuminate\Database\Seeder;

/**
 * The 70-20-10 development model channels.
 */
class DevelopmentModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            ['name' => 'On The Job Training/Assignment', 'percentage' => 70],
            ['name' => 'Coaching and/or Mentoring', 'percentage' => 20],
            ['name' => 'Formal Learning (Including Training)', 'percentage' => 10],
        ];

        foreach ($models as $model) {
            DevelopmentModel::updateOrCreate(['name' => $model['name']], $model);
        }
    }
}
