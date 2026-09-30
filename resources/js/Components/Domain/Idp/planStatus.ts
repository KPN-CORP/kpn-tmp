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

/**
 * Where the program sits against its own dates (not its approval state).
 *
 * `overdue` and `completedLate` both mean the deadline was missed; they differ
 * on whether anything has been filed. Keeping them apart matters because they
 * call for different things — one is still outstanding, the other is only a
 * record of how it went.
 */
export type TimelineKey =
    | 'completed'
    | 'completedLate'
    | 'inProgress'
    | 'upcoming'
    | 'overdue'
    | 'planned'

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
    const now = today()
    const start = calendarDay(plan.time_frame_start)
    const end = calendarDay(plan.time_frame_end)
    const realized = calendarDay(plan.realization_date)

    // Filed, so it is no longer outstanding — but a result filed after the
    // deadline is not the same as one filed on time, and reading both as plain
    // "Completed" is what hid every missed deadline in this table.
    if (realized) return end && realized > end ? 'completedLate' : 'completed'

    // Nothing filed and the window has closed. A plan with no end date has no
    // deadline to miss, so it can never land here.
    if (end && now > end) return 'overdue'

    if (start && now < start) return 'upcoming'
    if (start) return 'inProgress'

    return 'planned'
}

/**
 * How many days past its end date a program is — counted to today while it is
 * still outstanding, and to the realization date once something was filed.
 *
 * Null when the deadline was met, or when there is no deadline to miss. Days,
 * not milliseconds: both sides are calendar dates, so the subtraction is done
 * at UTC midnight where every day is exactly 24h and DST cannot shift it.
 */
export function daysLate(plan: Plan): number | null {
    const end = calendarDay(plan.time_frame_end)

    if (!end) return null

    const against = calendarDay(plan.realization_date) ?? today()

    if (against <= end) return null

    const ms = Date.parse(`${against}T00:00:00Z`) - Date.parse(`${end}T00:00:00Z`)

    return Math.round(ms / 86_400_000)
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
