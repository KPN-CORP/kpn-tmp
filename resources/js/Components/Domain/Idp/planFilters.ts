/**
 * What the plan table can be narrowed by, and the one predicate that answers it.
 *
 * It sits outside the filter bar component because two callers need the same
 * answer: the table, which decides whether to render a row, and the cascade
 * behind the dropdowns, which decides whether to offer a value. Asking one
 * function keeps them from ever disagreeing.
 */
import { calendarDay } from '@/Composables/useDate'
import { planningKey, resultKey, timelineKey } from '@/Components/Domain/Idp/planStatus'
import type { Plan } from '@/types/idp'

export interface PlanFilterState {
    /** A development model id, as a string — what the select binds. */
    developmentModel: string
    competencyType: string
    competency: string
    reviewTool: string
    timeline: string
    planning: string
    result: string
    /** ISO `yyyy-mm-dd`; either end may be left open. */
    from: string
    to: string
}

/**
 * The three fields that form a hierarchy — a model holds types, a type holds
 * competencies — so each offers only what the ones before it leave standing.
 * The review tool and the statuses are orthogonal attributes rather than a
 * hierarchy, so they always offer every value and never narrow each other.
 */
export type CascadeField = 'competencyType' | 'competency'

export function blankPlanFilters(): PlanFilterState {
    return {
        developmentModel: '',
        competencyType: '',
        competency: '',
        reviewTool: '',
        timeline: '',
        planning: '',
        result: '',
        from: '',
        to: '',
    }
}

/**
 * A range whose ends are the wrong way round describes no period at all. It is
 * reported rather than applied: applying it would empty the table, which reads
 * as "nothing matches" when the truth is "that is not a range".
 */
export function rangeInvalid(f: PlanFilterState): boolean {
    return !!(f.from && f.to && f.to < f.from)
}

export function hasPlanFilters(f: PlanFilterState): boolean {
    return countPlanFilters(f) > 0
}

/** How many filters are narrowing the table, for the badge. */
export function countPlanFilters(f: PlanFilterState): number {
    // Derived rather than listed by name, so a filter added later counts itself.
    // The date range is one filter with two ends, not two — and counts for none
    // while it is inverted, since it is then not being applied.
    const { from, to, ...rest } = f
    const range = (from || to) && !rangeInvalid(f) ? 1 : 0

    return range + Object.values(rest).filter(Boolean).length
}

/**
 * Does this plan survive the filters?
 *
 * The date range is an OVERLAP test, not containment: a program running Jan–Dec
 * is part of what happens in March, so asking for March finds it. A plan with
 * no end date is treated as lasting its start day; one with no dates at all is
 * open on both sides and so is never excluded by the range.
 */
export function matchesPlanFilters(plan: Plan, f: PlanFilterState): boolean {
    if (f.developmentModel && String(plan.development_model_id) !== f.developmentModel) return false
    if (f.competencyType && plan.competency_type !== f.competencyType) return false
    if (f.competency && plan.competency_name !== f.competency) return false
    if (f.reviewTool && (plan.review_tools ?? '') !== f.reviewTool) return false
    if (f.timeline && timelineKey(plan) !== f.timeline) return false
    if (f.planning && planningKey(plan) !== f.planning) return false
    if (f.result && resultKey(plan) !== f.result) return false

    if (!rangeInvalid(f)) {
        // Days, not instants: the picker binds `2026-01-01` while the wire ships
        // `2026-01-01T00:00:00.000000Z`, and comparing those as strings would
        // sort the longer one greater — excluding a plan that starts on the
        // `to` date.
        const start = calendarDay(plan.time_frame_start)
        const end = calendarDay(plan.time_frame_end) ?? start

        if (f.from && end && end < f.from) return false
        if (f.to && start && start > f.to) return false
    }

    return true
}

/** The value `field` reads off a plan. */
export const cascadeValue: Record<CascadeField, (plan: Plan) => string | null> = {
    competencyType: (plan) => plan.competency_type,
    competency: (plan) => plan.competency_name,
}

/**
 * The filters standing to the LEFT of `field`, and nothing else — run through
 * `matchesPlanFilters` to find what that field may still offer.
 */
export function upstreamOf(f: PlanFilterState, field: CascadeField): PlanFilterState {
    const upstream = blankPlanFilters()

    upstream.developmentModel = f.developmentModel
    if (field === 'competency') upstream.competencyType = f.competencyType

    return upstream
}
