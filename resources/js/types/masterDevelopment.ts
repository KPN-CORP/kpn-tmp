/**
 * The Master Development screen's wire contract (`Pages/Idp/Settings.vue`),
 * shared by the page, its program form drawer and the program-list filters.
 */

export interface DevModel {
    id: number
    development_model_package_id: number
    name: string
    name_en: string | null
    name_id: string | null
    percentage: number
    // The model decides where its programs' name + description come from: this
    // flag means the Master Training catalogue, otherwise they are typed.
    uses_master_training: boolean
    description_en: string | null
    description_id: string | null
    development_programs_count: number
    individual_development_plans_count: number
}

export interface DevPackage {
    id: number
    name: string
    start_date: string
    end_date: string | null
    is_current: boolean
    is_active: boolean
    models_count: number
    total_percentage: number
}

export interface DevCompetency {
    id: number
    // The competency's short identifier; null on rows predating the column.
    code: string | null
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    // The rungs of this competency's own ladder.
    proficiency_level_ids: number[]
    related_program: number[]
    linked_programs: string[]
    is_active: boolean
}

export interface DevProgram {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    development_model_id: number | null
    model_name: string | null
    competency_type_id: number | null
    // The training the name + description were taken from, or null when typed.
    training_id: number | null
    proficiency_level_id: number | null
    custom_proficiency_level: string | null
    grades: string[]
}

export interface DevCompetencyType {
    id: number
    // The type's short identifier; null on rows predating the column.
    code: string | null
    value: string
    value_en: string | null
    value_id: string | null
}

/**
 * One rung of a competency's own proficiency ladder — there is no shared
 * proficiency-level master any more, so every level belongs to exactly one
 * competency. Which of them a program may target is narrowed further by the
 * implementation map below.
 */
export interface DevProficiencyLevel {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    competency_id: number
    sequence: number
    // The rung's short identifier, unique within its competency. Null on the
    // rows that predate the column.
    code: string | null
    description_en: string | null
    description_id: string | null
    // A rung switched off is struck through in the list; the program keeps it,
    // since what a row already stores is never rejected.
    is_active: boolean
}

/** A training in the Master Training catalogue, as a name option. */
export interface DevTraining {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    // Carried so the form can read back what the program will store; the
    // server copies both again on save.
    description_en: string | null
    description_id: string | null
    // An inactive training is no longer offered as a program's name source.
    is_active: boolean
}

/**
 * One master-implementation mapping, flattened: the proficiency levels a
 * competency is implemented at and the grades that mapping covers. An empty
 * `grades` list means it covers every grade.
 */
export interface DevImplementation {
    competency_id: number | null
    proficiency_level_ids: number[]
    grades: string[]
}

/** The master kinds the program form drawer can post. */
export type MasterType = 'development_program' | 'review_tools'

/**
 * One line of the development-program table: one per (program × linked
 * competency). See `baseRows` in `Pages/Idp/Settings.vue`.
 */
export interface ProgramRow {
    key: string
    id: number
    program: DevProgram
    name: string
    // Stable identities for the merge: two masters may read alike, ids do not.
    modelKey: string
    competencyTypeKey: string
    competencyKey: string
    competencyTypeCode: string
    competencyTypeName: string
    competencyCode: string
    competencyName: string
    proficiency: string
    proficiencySequence: number | null
    proficiencyCode: string | null
    proficiencyDescription: string
    proficiencyActive: boolean
    grades: string[]
}
