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
            [
                'name' => 'On The Job Training/Assignment',
                'percentage' => 70,
                'description_en' => "Learning by Doing (On-the-Job Learning)\n\nExamples of activities:\n- Handling new responsibilities or special projects\n- Job rotation or enrichment\n- Leading process improvement initiatives",
                'description_id' => "Belajar dengan Melakukan (Pembelajaran di Tempat Kerja)\n\nContoh aktivitas:\n- Menangani tanggung jawab baru atau proyek khusus\n- Rotasi atau pengayaan pekerjaan\n- Memimpin inisiatif perbaikan proses",
            ],
            [
                'name' => 'Coaching and/or Mentoring',
                'percentage' => 20,
                'description_en' => "Learning from Others (Social Learning)\n\nExamples of activities:\n- Coaching or mentoring with a supervisor or senior colleague\n- Peer sharing, group discussions, or learning circles\n- Shadowing experienced coworker",
                'description_id' => "Belajar dari Orang Lain (Pembelajaran Sosial)\n\nContoh aktivitas:\n- Coaching atau mentoring dengan atasan atau rekan senior\n- Berbagi antar rekan, diskusi kelompok, atau learning circle\n- Mendampingi rekan kerja yang berpengalaman",
            ],
            [
                'name' => 'Formal Learning (Including Training)',
                'percentage' => 10,
                'description_en' => "Formal Learning\n\nExamples of activities:\n- Attending training, workshops, or seminars\n- Online courses or certification programs\n- Reading books, journals, or e-learning modules",
                'description_id' => "Pembelajaran Formal\n\nContoh aktivitas:\n- Mengikuti pelatihan, workshop, atau seminar\n- Kursus online atau program sertifikasi\n- Membaca buku, jurnal, atau modul e-learning",
            ],
        ];

        foreach ($models as $model) {
            DevelopmentModel::updateOrCreate(['name' => $model['name']], $model);
        }
    }
}
