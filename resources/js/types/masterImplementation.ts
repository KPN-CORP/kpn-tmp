/**
 * The Master Implementation screen's wire contract
 * (`Pages/MasterData/MasterImplementation.vue`), shared by the page and its
 * form + detail drawers.
 */

export interface ImplLocalized {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

export interface ImplCompetencyType extends ImplLocalized {
    // The type's short identifier. Null on types that predate the column; the
    // list renders it as a chip in front of the type name when present.
    code: string | null
    competencies_count: number
}

export interface ImplCompetency extends ImplLocalized {
    // The competency's short identifier. Null on rows that predate the column.
    code: string | null
    competency_type_id: number | null
    // The rungs of this competency's own proficiency ladder.
    proficiency_level_ids: number[]
    // Only an active competency can be mapped.
    is_active: boolean
}

/**
 * One rung of a competency's ladder. There is no shared proficiency-level
 * master any more, so a level belongs to exactly one competency and is offered
 * only once that competency is chosen.
 */
export interface ImplProficiencyLevel extends ImplLocalized {
    competency_id: number
    sequence: number
    // The rung's short identifier, unique within its competency. Null on the
    // rows that predate the column.
    code: string | null
    is_active: boolean
    description_en: string | null
    description_id: string | null
}

export interface Implementation {
    id: number
    competency_type_id: number | null
    competency_id: number | null
    // One or more proficiency levels pinned to this implementation.
    proficiency_level_ids: number[]
    // The grades this implementation covers; empty means every grade.
    grades: string[]
    // A mapping covers any number of business units; empty means it is not
    // narrowed to one.
    business_units: string[]
    // An inactive mapping keeps its row but no longer applies.
    is_active: boolean
    job_family: string | null
    function_name: string | null
    position: string | null
    // ISO strings; the list sorts on them and formats them for display.
    created_at: string | null
    updated_at: string | null
}

/** One proficiency level of a mapping, in the shape ProficiencyLevelCell reads. */
export interface ImplementationLevelLine {
    id: number
    name: string
    sequence: number
    code: string | null
    description: string | null
    active: boolean
}

/** A mapping with its labels resolved, as the read-only detail drawer shows it. */
export interface ImplementationDetail extends Implementation {
    competency_name: string
    competency_code: string
    type_name: string
    type_code: string
    lines: ImplementationLevelLine[]
}
