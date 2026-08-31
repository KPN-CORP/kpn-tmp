<?php

namespace App\Enums;

use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\KeyBehavior;
use App\Models\ProficiencyLevel;
use App\Models\ReviewTool;
use App\Models\Training;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The kinds of IDP master data managed by the shared master endpoints.
 *
 * Each case used to be a `type` string in the single `development_plan_masters`
 * table; every kind now has its own table. The enum is what routes a request to
 * the right model, and it stays the wire value the settings screens post as
 * `type`, so the frontend contract is unchanged.
 */
enum MasterDataType: string
{
    case CompetencyType = 'competency_type';
    case CompetencyName = 'competency_name';
    case ProficiencyLevel = 'proficiency_level';
    case KeyBehavior = 'key_behavior';
    case DevelopmentProgram = 'development_program';
    case ReviewTools = 'review_tools';
    case Training = 'training';

    /**
     * The Eloquent model backing this kind of master data.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::CompetencyType => CompetencyType::class,
            self::CompetencyName => Competency::class,
            self::ProficiencyLevel => ProficiencyLevel::class,
            self::KeyBehavior => KeyBehavior::class,
            self::DevelopmentProgram => DevelopmentProgram::class,
            self::ReviewTools => ReviewTool::class,
            self::Training => Training::class,
        };
    }

    /**
     * A fresh query builder for this kind of master data.
     */
    public function query(): Builder
    {
        return $this->modelClass()::query();
    }

    public function table(): string
    {
        return (new ($this->modelClass()))->getTable();
    }

    /**
     * Whether rows of this kind carry a bilingual description.
     */
    public function hasDescription(): bool
    {
        return match ($this) {
            self::CompetencyType,
            self::CompetencyName,
            self::ProficiencyLevel,
            self::KeyBehavior,
            self::Training => true,
            self::DevelopmentProgram, self::ReviewTools => false,
        };
    }

    /**
     * The `individual_development_plans` column that stores this master's name
     * verbatim, or null when IDP rows never reference this kind. Renaming a
     * master cascades to that column; a master still referenced there can't be
     * deleted.
     */
    public function idpColumn(): ?string
    {
        return match ($this) {
            self::CompetencyType => 'competency_type',
            self::CompetencyName => 'competency_name',
            self::DevelopmentProgram => 'development_program',
            self::ReviewTools => 'review_tools',
            self::ProficiencyLevel, self::KeyBehavior, self::Training => null,
        };
    }

    /**
     * The `in:` rule body listing every accepted wire value.
     */
    public static function validationList(): string
    {
        return implode(',', array_column(self::cases(), 'value'));
    }
}
