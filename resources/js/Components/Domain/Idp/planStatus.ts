/**
 * What state one plan row is in, on each of the three axes the table shows:
 * its own timeline, its planning sign-off, and its result.
 *
 * It lives here rather than in PlanRow because the filter bar asks the same
 * questions the pills answer — so a row can never be filtered into a bucket
 * whose chip says something else.
 */
import { calendarDay, today } from '@/Composables/useDate'
import type { Plan } from '@/types/idp'

/** Where the program sits against its own dates (not its approval state). */
export type TimelineKey = 'completed' | 'inProgress' | 'upcoming' | 'overdue' | 'planned'

/** Whether this row is covered by the set's planning approval. */
export type PlanningKey = 'approved' | 'inReview' | 'notApproved'

/**
 * Where the result stands. `open` is split in two — nothing filed yet vs filled
 * in and submittable — because those are different things to do about it.
 */
export type ResultKey = 'locked' | 'notFiled' | 'ready' | 'pending' | 'approved' | 'rejected'

/**
 * Compared as calendar days, not instants: the wire ships a date-only column as
 * `…T00:00:00.000000Z`, which `new Date` reads as UTC midnight. Against a local
 * midnight `today` that is a day out either way — east of UTC a plan starting
 * today reads "Upcoming", west of it one ending today reads "Overdue".
 */
export function timelineKey(plan: Plan): TimelineKey {
    if (plan.realization_date) return 'completed'

    const now = today()
    const start = calendarDay(plan.time_frame_start)
    const end = calendarDay(plan.time_frame_end)

    if (end && now > end) return 'overdue'
    if (start && now < start) return 'upcoming'
    if (start) return 'inProgress'

    return 'planned'
}

export function planningKey(plan: Plan): PlanningKey {
    if (plan.stage.planning_approved) return 'approved'
    if (plan.stage.frozen_by_planning) return 'inReview'

    return 'notApproved'
}

export function resultKey(plan: Plan): ResultKey {
    const result = plan.stage.result
    if (result.status !== 'open') return result.status

    return result.filled ? 'ready' : 'notFiled'
}

/**
 * Workflow order, for sorting a column of state chips by how far along a row is
 * rather than by the alphabet. Ascending puts the least-progressed first, so
 * descending surfaces `rejected` at the top — which is what someone chasing
 * problems is sorting for.
 */
export const PLANNING_ORDER: PlanningKey[] = ['notApproved', 'inReview', 'approved']

export const RESULT_ORDER: ResultKey[] = [
    'locked',
    'notFiled',
    'ready',
    'pending',
    'approved',
    'rejected',
]
