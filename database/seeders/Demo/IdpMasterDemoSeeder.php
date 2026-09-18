<?php

namespace Database\Seeders\Demo;

use App\Models\BusinessUnit;
use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Models\CompetencyKeyBehavior;
use App\Models\CompetencyProficiencyLevel;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentProgram;
use App\Models\ReviewTool;
use App\Models\SubCompetency;
use App\Models\Training;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo IDP master data: competency types, competencies (with their own
 * proficiency ladder, key behaviors and sub-competencies), the Master
 * Implementation map, Master Trainings and the development programs that hang
 * off the development models.
 *
 * Additive and idempotent. Rows are matched by their natural key (a name, or a
 * code) and updated; child rows that already exist are never replaced, so
 * anything entered by hand through the screens survives a re-run.
 */
class IdpMasterDemoSeeder extends Seeder
{
    /** Used only when kpncorp is unreachable and the BU master cannot be read. */
    private const FALLBACK_BUSINESS_UNITS = [
        'Plantations', 'Property', 'Cement', 'Katingan', 'KPN Corporation', 'Downstream', 'Others',
    ];

    /** name_en => [code, name_id, description_en, description_id] */
    private const TYPES = [
        'Soft Competency' => ['SOFT', 'Keahlian Non Teknis',
            'Behavioural capabilities that apply across every role.',
            'Kemampuan perilaku yang berlaku di semua peran.'],
        'Technical Competency' => ['TECH', 'Keahlian Teknis',
            'Job-specific knowledge and craft.',
            'Pengetahuan dan keahlian khusus sesuai pekerjaan.'],
        'Culture' => ['CULT', 'Kultur',
            'The values every employee is expected to live by.',
            'Nilai-nilai yang dijalankan oleh setiap karyawan.'],
        'Others' => ['OTHR', 'Lainnya',
            'Catch-all for development that fits no other type.',
            'Kategori lain yang tidak termasuk tipe manapun.'],
    ];

    /**
     * The demo competencies. Each carries its own proficiency ladder (rungs in
     * order, each with its key behaviors) and the parts it breaks down into.
     *
     * [type name_en, code, name_en, name_id, description_en, description_id, sub-competencies]
     */
    private const COMPETENCIES = [
        ['Soft Competency', 'SC-SYN', 'Synergy', 'Sinergi',
            'Works across teams and functions to reach a shared outcome.',
            'Bekerja lintas tim dan fungsi untuk mencapai hasil bersama.',
            ['Collaboration|Kolaborasi', 'Conflict Resolution|Penyelesaian Konflik', 'Knowledge Sharing|Berbagi Pengetahuan'],
        ],
        ['Soft Competency', 'SC-LEAD', 'Leadership', 'Kepemimpinan',
            'Sets direction and brings people with them.',
            'Menetapkan arah dan membawa serta orang-orang di dalamnya.',
            ['Setting Direction|Menetapkan Arah', 'Coaching Others|Membina Orang Lain', 'Driving Accountability|Mendorong Akuntabilitas'],
        ],
        ['Soft Competency', 'SC-COMM', 'Communication', 'Komunikasi',
            'Conveys ideas clearly and listens to understand.',
            'Menyampaikan gagasan dengan jelas dan mendengarkan untuk memahami.',
            ['Written Communication|Komunikasi Tertulis', 'Presentation|Presentasi', 'Active Listening|Mendengar Aktif'],
        ],
        ['Soft Competency', 'SC-PROB', 'Problem Solving', 'Pemecahan Masalah',
            'Breaks a problem down and lands a workable answer.',
            'Menguraikan masalah dan menemukan jawaban yang dapat dijalankan.',
            ['Root Cause Analysis|Analisis Akar Masalah', 'Decision Making|Pengambilan Keputusan'],
        ],
        ['Soft Competency', 'SC-ADPT', 'Adaptability', 'Adaptabilitas',
            'Stays effective when priorities and conditions change.',
            'Tetap efektif ketika prioritas dan kondisi berubah.',
            ['Learning Agility|Kecepatan Belajar', 'Resilience|Ketahanan Diri'],
        ],
        ['Technical Competency', 'TC-DATA', 'Data Analysis', 'Analisis Data',
            'Turns data into a conclusion someone can act on.',
            'Mengubah data menjadi kesimpulan yang dapat ditindaklanjuti.',
            ['Data Preparation|Penyiapan Data', 'Statistical Reasoning|Penalaran Statistik', 'Data Visualisation|Visualisasi Data'],
        ],
        ['Technical Competency', 'TC-PROJ', 'Project Management', 'Manajemen Proyek',
            'Plans, runs and lands a project against scope, time and cost.',
            'Merencanakan dan menjalankan proyek sesuai lingkup, waktu dan biaya.',
            ['Planning and Scheduling|Perencanaan dan Penjadwalan', 'Risk Management|Manajemen Risiko', 'Stakeholder Management|Manajemen Pemangku Kepentingan'],
        ],
        ['Technical Competency', 'TC-FIN', 'Financial Acumen', 'Ketajaman Finansial',
            'Reads the numbers behind a business decision.',
            'Memahami angka di balik sebuah keputusan bisnis.',
            ['Budgeting|Penganggaran', 'Cost Control|Pengendalian Biaya'],
        ],
        ['Technical Competency', 'TC-DIGI', 'Digital Literacy', 'Literasi Digital',
            'Uses the company digital tools confidently and safely.',
            'Menggunakan perangkat digital perusahaan dengan percaya diri dan aman.',
            ['Core Applications|Aplikasi Inti', 'Information Security|Keamanan Informasi'],
        ],
        ['Culture', 'CU-INTG', 'Integrity', 'Integritas',
            'Does the right thing when nobody is checking.',
            'Melakukan hal yang benar meski tidak ada yang mengawasi.',
            ['Honesty|Kejujuran', 'Compliance|Kepatuhan'],
        ],
        ['Culture', 'CU-CUST', 'Customer Focus', 'Fokus Pelanggan',
            'Puts the customer outcome at the centre of the work.',
            'Menempatkan hasil bagi pelanggan sebagai pusat pekerjaan.',
            ['Service Mindset|Pola Pikir Melayani', 'Responsiveness|Daya Tanggap'],
        ],
    ];

    /**
     * The three rungs every demo competency's ladder carries.
     * name_en => [name_id, description_en, description_id, [key behaviors]]
     */
    private const LADDER = [
        'PL1' => ['Dasar', 'Applies the competency with guidance on routine work.',
            'Menerapkan kompetensi dengan bimbingan pada pekerjaan rutin.',
            ['Follows the agreed standard|Mengikuti standar yang disepakati',
                'Asks for help at the right time|Meminta bantuan pada saat yang tepat',
                'Completes routine tasks accurately|Menyelesaikan tugas rutin dengan akurat']],
        'PL2' => ['Menengah', 'Applies the competency independently on day-to-day work.',
            'Menerapkan kompetensi secara mandiri pada pekerjaan sehari-hari.',
            ['Works without close supervision|Bekerja tanpa pengawasan ketat',
                'Handles non-routine situations|Menangani situasi tidak rutin',
                'Explains their reasoning to others|Menjelaskan alasannya kepada orang lain']],
        'PL3' => ['Mahir', 'Sets the standard and develops the competency in others.',
            'Menetapkan standar dan mengembangkan kompetensi pada orang lain.',
            ['Sets the standard for the team|Menetapkan standar bagi tim',
                'Coaches others to the same level|Membina orang lain ke tingkat yang sama',
                'Improves how the work is done|Memperbaiki cara kerja dijalankan']],
    ];

    /** The grade band a demo implementation covers. */
    private const IMPLEMENTATION_GRADES = ['3A', '3B', '4A', '4B', '5A', '5B', '6A', '6B', '7A'];

    /** [competency name_en, name_en, name_id, description_en] */
    private const TRAININGS = [
        ['Leadership', 'Leading Teams Fundamentals', 'Dasar Memimpin Tim',
            'A two-day workshop on setting direction and giving feedback.'],
        ['Leadership', 'Advanced Leadership Programme', 'Program Kepemimpinan Lanjutan',
            'A six-month programme for managers of managers.'],
        ['Communication', 'Business Presentation Skills', 'Keterampilan Presentasi Bisnis',
            'Structuring and delivering a persuasive business presentation.'],
        ['Communication', 'Effective Business Writing', 'Penulisan Bisnis Efektif',
            'Writing email, memos and reports that get read and acted on.'],
        ['Data Analysis', 'Excel for Analysts', 'Excel untuk Analis',
            'Pivot tables, lookups and clean data preparation.'],
        ['Data Analysis', 'Dashboard Design with Power BI', 'Desain Dasbor dengan Power BI',
            'Building a dashboard that answers a business question.'],
        ['Project Management', 'Project Management Essentials', 'Dasar Manajemen Proyek',
            'Scope, schedule, budget and risk on a live project.'],
        ['Financial Acumen', 'Finance for Non-Finance Managers', 'Keuangan bagi Manajer Non-Keuangan',
            'Reading a P&L, a balance sheet and a cash-flow statement.'],
        ['Digital Literacy', 'Information Security Awareness', 'Kesadaran Keamanan Informasi',
            'Phishing, passwords and handling company data safely.'],
        ['Integrity', 'Code of Conduct Refresher', 'Penyegaran Kode Etik',
            'The company code of conduct and how to raise a concern.'],
    ];

    /**
     * Typed development programmes, per development model that does NOT draw
     * its names from Master Training.
     * model key => [[competency name_en, name_en, name_id, description_en], ...]
     */
    private const TYPED_PROGRAMS = [
        'on the job' => [
            ['Synergy', 'Cross-functional project assignment', 'Penugasan proyek lintas fungsi',
                'Join a project team outside your own function for one cycle.'],
            ['Leadership', 'Act as deputy during manager leave', 'Menjadi pejabat sementara saat atasan cuti',
                'Cover the manager decisions and meetings while they are away.'],
            ['Communication', 'Lead the weekly team briefing', 'Memimpin pengarahan tim mingguan',
                'Own the agenda and run the weekly briefing for one quarter.'],
            ['Problem Solving', 'Own a continuous-improvement initiative', 'Memiliki inisiatif perbaikan berkelanjutan',
                'Pick a recurring problem and run the improvement end to end.'],
            ['Data Analysis', 'Build the monthly performance report', 'Menyusun laporan kinerja bulanan',
                'Take over the monthly report, from data pull to commentary.'],
            ['Project Management', 'Run a small project as project lead', 'Memimpin proyek kecil sebagai ketua proyek',
                'Lead a project with a defined scope, budget and end date.'],
            ['Adaptability', 'Rotate into another site for one cycle', 'Rotasi ke lokasi lain selama satu siklus',
                'Work from another site and adapt to its way of working.'],
            ['Customer Focus', 'Spend a week on the customer front line', 'Menghabiskan sepekan di garis depan pelanggan',
                'Work alongside the customer-facing team and report what you learn.'],
        ],
        'coaching' => [
            ['Synergy', 'Monthly coaching with the department head', 'Pembinaan bulanan bersama kepala departemen',
                'A standing monthly session on working across teams.'],
            ['Leadership', 'Mentoring by a senior leader', 'Pendampingan oleh pemimpin senior',
                'Six mentoring sessions with a leader outside your line.'],
            ['Communication', 'Presentation feedback with a coach', 'Umpan balik presentasi bersama pembina',
                'Rehearse and review three real presentations with a coach.'],
            ['Financial Acumen', 'Shadow the finance business partner', 'Mendampingi mitra bisnis keuangan',
                'Sit with the finance partner through one monthly close.'],
            ['Integrity', 'Ethics case discussion with the line manager', 'Diskusi kasus etika bersama atasan',
                'Work through real ethics cases with your manager each quarter.'],
            ['Digital Literacy', 'Peer coaching on the core applications', 'Pembinaan sejawat pada aplikasi inti',
                'Pair with a power user on the systems you use daily.'],
        ],
    ];

    public function run(): void
    {
        $units = $this->businessUnits();

        $types = $this->seedTypes($units);
        $competencies = $this->seedCompetencies($types);
        $this->seedImplementations($competencies, $units);
        $trainings = $this->seedTrainings($competencies, $units);
        $this->seedPrograms($competencies, $trainings);
        $this->seedReviewTools();

        $this->command?->info('  competency types: '.CompetencyType::count()
            .' | competencies: '.Competency::count()
            .' | rungs: '.CompetencyProficiencyLevel::count()
            .' | sub-competencies: '.SubCompetency::count()
            .' | implementations: '.CompetencyImplementation::count()
            .' | trainings: '.Training::count()
            .' | programs: '.DevelopmentProgram::count());
    }

    /**
     * The corporate business-unit master, falling back to a fixed list when
     * kpncorp is unreachable so the demo still produces coherent scope rows.
     *
     * @return list<string>
     */
    private function businessUnits(): array
    {
        $names = BusinessUnit::names();

        return $names !== [] ? $names : self::FALLBACK_BUSINESS_UNITS;
    }

    /**
     * Work locations per business unit, read from kpncorp's location master and
     * folded onto the BU master's own names (so "KPN Plantations" lands under
     * "Plantations"). Empty when kpncorp is unreachable.
     *
     * @param  list<string>  $units
     * @return array<string, list<string>>
     */
    private function workLocations(array $units): array
    {
        try {
            $rows = DB::connection('kpncorp')->table('locations')
                ->select('company_name', 'area')->get();
        } catch (\Throwable) {
            return [];
        }

        $byUnit = [];

        foreach ($rows as $row) {
            $unit = BusinessUnit::resolveName((string) $row->company_name, $units);

            if ($unit === null || blank($row->area)) {
                continue;
            }

            $byUnit[$unit][(string) $row->area] = true;
        }

        return array_map(fn ($areas) => array_slice(array_keys($areas), 0, 8), $byUnit);
    }

    /**
     * @param  list<string>  $units
     * @return array<string, CompetencyType>
     */
    private function seedTypes(array $units): array
    {
        $types = [];

        foreach (self::TYPES as $nameEn => [$code, $nameId, $descEn, $descId]) {
            $type = CompetencyType::firstOrNew(['name_en' => $nameEn]);
            $type->fill([
                'code' => $type->code ?: $code,
                'name_id' => $type->name_id ?: $nameId,
                'description_en' => $type->description_en ?: $descEn,
                'description_id' => $type->description_id ?: $descId,
            ])->save();

            // A type must carry at least one business unit to be saveable from
            // the screen; give every demo type the full corporate set.
            $existing = $type->businessUnits()->pluck('business_unit')->all();

            foreach (array_diff($units, $existing) as $unit) {
                $type->businessUnits()->create(['business_unit' => $unit]);
            }

            $types[$nameEn] = $type;
        }

        return $types;
    }

    /**
     * @param  array<string, CompetencyType>  $types
     * @return array<string, Competency>
     */
    private function seedCompetencies(array $types): array
    {
        $competencies = [];

        foreach (self::COMPETENCIES as [$typeName, $code, $nameEn, $nameId, $descEn, $descId, $subs]) {
            $type = $types[$typeName] ?? null;

            if (! $type) {
                continue;
            }

            $competency = Competency::firstOrNew(['name_en' => $nameEn]);
            $competency->fill([
                'competency_type_id' => $competency->competency_type_id ?: $type->id,
                'code' => $competency->code ?: $code,
                'name_id' => $competency->name_id ?: $nameId,
                'description_en' => $competency->description_en ?: $descEn,
                'description_id' => $competency->description_id ?: $descId,
                'is_active' => $competency->exists ? (bool) $competency->is_active : true,
            ])->save();

            $this->seedLadder($competency);

            // Only fill the breakdown when the competency has none, so parts
            // written by hand are never replaced.
            if ($competency->subCompetencies()->count() === 0) {
                foreach ($subs as $sub) {
                    [$subEn, $subId] = explode('|', $sub);
                    SubCompetency::create([
                        'competency_id' => $competency->id,
                        'name_en' => $subEn,
                        'name_id' => $subId,
                    ]);
                }
            }

            $competencies[$nameEn] = $competency->refresh();
        }

        return $competencies;
    }

    /**
     * The competency's own proficiency ladder. A rung already on the ladder
     * keeps its name and description; only what is missing is added, and a rung
     * that already has key behaviors is left alone entirely.
     */
    private function seedLadder(Competency $competency): void
    {
        $sequence = 0;

        foreach (self::LADDER as $rungName => [$nameId, $descEn, $descId, $behaviors]) {
            $sequence++;

            $rung = CompetencyProficiencyLevel::firstOrNew([
                'competency_id' => $competency->id,
                'name_en' => $rungName,
            ]);

            $rung->fill([
                'name_id' => $rung->name_id ?: $nameId,
                'description_en' => $rung->description_en ?: $descEn,
                'description_id' => $rung->description_id ?: $descId,
                'sequence' => $sequence,
                'is_active' => $rung->exists ? (bool) $rung->is_active : true,
            ])->save();

            if ($rung->keyBehaviors()->count() > 0) {
                continue;
            }

            foreach (array_values($behaviors) as $i => $behavior) {
                [$behaviorEn, $behaviorId] = explode('|', $behavior);
                CompetencyKeyBehavior::create([
                    'competency_proficiency_level_id' => $rung->id,
                    'name_en' => $behaviorEn,
                    'name_id' => $behaviorId,
                    'sequence' => $i + 1,
                ]);
            }
        }
    }

    /**
     * The Master Implementation map -- which competency is rolled out, at which
     * rungs and for which grades and business units. Development programmes can
     * only target what is implemented, so every demo competency gets one.
     *
     * @param  array<string, Competency>  $competencies
     * @param  list<string>  $units
     */
    private function seedImplementations(array $competencies, array $units): void
    {
        foreach ($competencies as $competency) {
            $implementation = CompetencyImplementation::firstOrNew([
                'competency_id' => $competency->id,
            ]);

            $isNew = ! $implementation->exists;

            $implementation->fill([
                'competency_type_id' => $competency->competency_type_id,
                'is_active' => $implementation->exists ? (bool) $implementation->is_active : true,
            ])->save();

            if (! $isNew) {
                // An existing mapping was set up through the screen -- leave its
                // scope exactly as configured.
                continue;
            }

            $implementation->proficiencyLevels()->sync(
                $competency->proficiencyLevels()->pluck('id')->all(),
            );

            foreach (self::IMPLEMENTATION_GRADES as $grade) {
                $implementation->grades()->create(['grade' => $grade]);
            }

            foreach ($units as $unit) {
                $implementation->businessUnits()->create(['business_unit' => $unit]);
            }
        }
    }

    /**
     * @param  array<string, Competency>  $competencies
     * @param  list<string>  $units
     * @return array<string, Training>
     */
    private function seedTrainings(array $competencies, array $units): array
    {
        $locations = $this->workLocations($units);
        $trainings = [];

        foreach (self::TRAININGS as [$competencyName, $nameEn, $nameId, $descEn]) {
            $competency = $competencies[$competencyName] ?? null;

            if (! $competency) {
                continue;
            }

            $training = Training::firstOrNew(['name_en' => $nameEn]);
            $isNew = ! $training->exists;

            $training->fill([
                'competency_type_id' => $competency->competency_type_id,
                'competency_id' => $competency->id,
                'name_id' => $training->name_id ?: $nameId,
                'description_en' => $training->description_en ?: $descEn,
                'is_active' => $training->exists ? (bool) $training->is_active : true,
            ])->save();

            $trainings[$nameEn] = $training;

            if (! $isNew) {
                continue;
            }

            $training->proficiencyLevels()->sync(
                $competency->proficiencyLevels()->pluck('id')->all(),
            );

            // A training is offered in a few units, and only at sites that
            // belong to them -- the rule the Master Training screen enforces.
            foreach (array_slice($units, 0, 3) as $unit) {
                $training->businessUnits()->create(['business_unit' => $unit]);

                foreach (array_slice($locations[$unit] ?? [], 0, 3) as $area) {
                    $training->workLocations()->create(['work_location' => $area]);
                }
            }
        }

        return $trainings;
    }

    /**
     * Development programmes, filed under the development models. A model
     * flagged `uses_master_training` takes its programmes' names and
     * descriptions from the Master Training catalogue; every other model
     * carries typed names.
     *
     * @param  array<string, Competency>  $competencies
     * @param  array<string, Training>  $trainings
     */
    private function seedPrograms(array $competencies, array $trainings): void
    {
        foreach (DevelopmentModel::orderBy('id')->get() as $model) {
            if ($model->uses_master_training) {
                $this->seedTrainingPrograms($model, $trainings);

                continue;
            }

            $key = str_contains(strtolower((string) $model->name), 'coaching') ? 'coaching' : 'on the job';

            foreach (self::TYPED_PROGRAMS[$key] as [$competencyName, $nameEn, $nameId, $descEn]) {
                $competency = $competencies[$competencyName] ?? null;

                if (! $competency) {
                    continue;
                }

                $this->program($model, $competency, $nameEn, $nameId, $descEn, null);
            }
        }
    }

    /**
     * @param  array<string, Training>  $trainings
     */
    private function seedTrainingPrograms(DevelopmentModel $model, array $trainings): void
    {
        foreach ($trainings as $training) {
            $competency = $training->competency;

            if (! $competency) {
                continue;
            }

            // The model says the name comes from the catalogue, so it is copied
            // off the training verbatim -- exactly what the form does on save.
            $this->program(
                $model,
                $competency,
                (string) $training->name_en,
                $training->name_id,
                $training->description_en,
                $training->id,
            );
        }
    }

    /**
     * One development programme, scoped by the Master Implementation map: the
     * proficiency level must be implemented for the competency and the grades
     * must be ones that implementation covers.
     */
    private function program(
        DevelopmentModel $model,
        Competency $competency,
        string $nameEn,
        ?string $nameId,
        ?string $descEn,
        ?int $trainingId,
    ): void {
        $implementation = CompetencyImplementation::where('competency_id', $competency->id)->first();

        if (! $implementation) {
            return;
        }

        $levels = $implementation->proficiencyLevels()
            ->orderBy('competency_proficiency_levels.sequence')
            ->pluck('competency_proficiency_levels.id')
            ->all();

        // Spread the programmes across the rungs the implementation covers,
        // rather than pinning every one of them to the first rung.
        $levelId = $levels === [] ? null : $levels[crc32($nameEn) % count($levels)];

        $grades = $implementation->grades()->pluck('grade')->all();

        $program = DevelopmentProgram::firstOrNew([
            'development_model_id' => $model->id,
            'name_en' => $nameEn,
        ]);

        $isNew = ! $program->exists;

        $program->fill([
            'competency_type_id' => $competency->competency_type_id,
            'training_id' => $trainingId,
            'proficiency_level_id' => $program->proficiency_level_id ?: $levelId,
            'name_id' => $program->name_id ?: $nameId,
            'description_en' => $program->description_en ?: $descEn,
        ])->save();

        // The competency link is the pivot the Competency screen also edits.
        $program->competencies()->syncWithoutDetaching([$competency->id]);

        if ($isNew) {
            foreach ($grades as $grade) {
                $program->grades()->create(['grade' => $grade]);
            }
        }
    }

    /**
     * The review tools an IDP item can name. The catalogue is already seeded in
     * this database; this only fills what is missing.
     */
    private function seedReviewTools(): void
    {
        $tools = [
            'Self Assessment' => 'Penilaian Mandiri',
            'Superior Rating' => 'Penilaian Atasan',
            'Peer Review' => 'Ulasan Rekan Kerja',
            '360 Degree Feedback' => 'Umpan Balik 360 Derajat',
            'KPI Review' => 'Tinjauan KPI',
            'Management Review' => 'Tinjauan Manajemen',
            'Project Based Evaluation' => 'Evaluasi Berbasis Proyek',
            'Assessment Center' => 'Pusat Asesmen',
        ];

        foreach ($tools as $nameEn => $nameId) {
            $tool = ReviewTool::firstOrNew(['name_en' => $nameEn]);
            $tool->fill([
                'name_id' => $tool->name_id ?: $nameId,
                'is_active' => $tool->exists ? (bool) $tool->is_active : true,
            ])->save();
        }
    }
}
