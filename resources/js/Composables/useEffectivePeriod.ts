/**
 * The optional effective period carried by the dated IDP masters
 * (competencies, proficiency levels, review tools).
 *
 * Both ends are open-ended when null: a null start is effective from always, a
 * null end never expires. A row with neither date set is simply always
 * effective — that is the state every pre-existing master is in.
 */

import { formatDate } from '@/Composables/useDate'

export interface EffectivePeriod {
    effective_start_date?: string | null
    effective_end_date?: string | null
}

/** Where a master sits relative to today. */
export type EffectiveStatus = 'active' | 'scheduled' | 'expired'

/** Today as `yyyy-mm-dd`, in the viewer's own timezone. */
function todayIso(): string {
    const d = new Date()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${d.getFullYear()}-${mm}-${dd}`
}

/**
 * ISO date strings sort lexicographically, so the window check is plain string
 * comparison — no Date parsing, and no timezone day-shifts.
 */
export function effectiveStatus(item: EffectivePeriod): EffectiveStatus {
    const today = todayIso()
    const start = item.effective_start_date ?? null
    const end = item.effective_end_date ?? null

    if (start && start > today) return 'scheduled'
    if (end && end < today) return 'expired'
    return 'active'
}

export function isEffective(item: EffectivePeriod): boolean {
    return effectiveStatus(item) === 'active'
}

/** An effective window clipped to what is still ahead of us. */
export interface Window {
    /** Inclusive start — never earlier than today. */
    from: string
    /** Inclusive end, or null for open-ended. */
    to: string | null
    /** The window has already passed, so nothing constrains a new pick. */
    past: boolean
}

/**
 * The part of a master's own effective period that still lies ahead. Used to
 * decide which other dated masters may be attached to it: only the remaining
 * window can constrain a new selection.
 *
 * A blank start means the master is in effect now, so today is the earliest
 * date anything attached to it has to cover.
 */
export function remainingWindow(item: EffectivePeriod): Window {
    const today = todayIso()
    const start = item.effective_start_date || today
    const from = start > today ? start : today
    const to = item.effective_end_date || null

    return { from, to, past: to !== null && to < from }
}

/**
 * Whether a master is usable at some point inside `window` — i.e. it has not
 * expired before the window opens, and does not start only after it closes.
 *
 * Mirrors the `effectiveBetween` query scope on the PHP side, which is what
 * actually enforces this on save.
 */
export function usableInWindow(item: EffectivePeriod, window: Window): boolean {
    if (window.past) return true

    const start = item.effective_start_date ?? null
    const end = item.effective_end_date ?? null

    if (end && end < window.from) return false
    if (start && window.to && start > window.to) return false

    return true
}

/**
 * The window as a parenthesised suffix for an option label — `(01-01-2026 –
 * 31-12-2026)` — so the reason a master is or isn't on offer is visible right
 * where the choice is made. Empty for a master with no period at all.
 */
export function periodSuffix(
    item: EffectivePeriod,
    alwaysLabel: string,
    ongoingLabel: string,
): string {
    if (!item.effective_start_date && !item.effective_end_date) return ''

    const start = item.effective_start_date
        ? formatDate(item.effective_start_date)
        : alwaysLabel
    const end = item.effective_end_date
        ? formatDate(item.effective_end_date)
        : ongoingLabel

    return ` (${start} – ${end})`
}
