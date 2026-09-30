/**
 * Rendering a plan's TARGET — the number and the unit it counts.
 *
 * It lives here rather than in any one component because four surfaces show
 * the same value (the plan row, the approver's card, the result drawer and the
 * plan form's own read-back), and a target that reads "3 Hectare (ha)" in one
 * place and "3.00 hectare" in another is the kind of drift this module exists
 * to stop.
 *
 * The unit labels come from the server, not from `Config/locales`: the
 * catalogue is 55 units in two languages, so a second copy in the locale files
 * would be a drift hazard rather than a convenience. This is the same way
 * master data already reaches the UI here.
 */
import type { Locale } from '@/Composables/useLocale'
import type { UomOption } from '@/types/idp'

/**
 * value => label, already resolved to the active language — the same shape
 * (and the same purpose) as the competency / program label maps the plan table
 * is handed.
 */
export function uomLabelMap(units: UomOption[], locale: Locale): Record<string, string> {
    return Object.fromEntries(
        units.map((u) => [u.value, locale === 'id' ? u.label_id : u.label_en]),
    )
}

/**
 * A stored unit as the viewer's language names it. A value the catalogue does
 * not know is shown as stored rather than blanked, so an unmapped unit is
 * visible instead of silent.
 */
export function uomLabel(labels: Record<string, string>, uom: string | null | undefined): string {
    if (!uom) return ''

    return labels[uom] ?? uom
}

/**
 * "3 Hectare (ha)". The amount drops a trailing ".00" — the column is a decimal
 * so a whole target arrives as 3, but a number coming back from a form or a
 * float cast can still carry noise — and thousands are grouped, since a Rupiah
 * target runs long.
 */
export function formatTarget(
    labels: Record<string, string>,
    target: number | string | null | undefined,
    uom: string | null | undefined,
): string {
    if (target === null || target === undefined || target === '') return ''

    const amount = Number(target)

    if (Number.isNaN(amount)) return ''

    const printed = Number.isInteger(amount)
        ? amount.toLocaleString('en-US')
        : amount.toLocaleString('en-US', { maximumFractionDigits: 2 })

    return `${printed} ${uomLabel(labels, uom)}`.trim()
}

/**
 * The picker's options: the unit as the label, what it measures as the muted
 * second line. `SearchableSelect` matches the search box against BOTH, so
 * typing "area" finds every area unit and "kg" finds the kilogram.
 *
 * A stored value the catalogue no longer carries stays selectable, the same
 * exemption a since-retired master gets — editing an unrelated field must
 * never silently drop what a plan holds.
 */
export function uomSelectOptions(
    units: UomOption[],
    locale: Locale,
    current: string,
): { value: string; label: string; description: string }[] {
    const options = units.map((u) => ({
        value: u.value,
        label: locale === 'id' ? u.label_id : u.label_en,
        description: locale === 'id' ? u.group_id : u.group_en,
    }))

    if (current && !units.some((u) => u.value === current)) {
        options.unshift({ value: current, label: current, description: '' })
    }

    return options
}

/**
 * How far an achievement got against its target, as a whole percentage.
 *
 * Null when either number is missing, or when the target is zero — "0% of 0"
 * is not a fact about the work, and dividing by it is not an answer.
 *
 * Deliberately NOT judged: for most units more is better, but for some in the
 * catalogue (Liter per 100 Kilometer, say) less is, so nothing here decides
 * whether a percentage is good news. The UI shows the number and leaves the
 * reading of it to the approver.
 */
export function attainment(
    target: number | string | null | undefined,
    achievement: number | string | null | undefined,
): number | null {
    if (target === null || target === undefined || target === '') return null
    if (achievement === null || achievement === undefined || achievement === '') return null

    const t = Number(target)
    const a = Number(achievement)

    if (Number.isNaN(t) || Number.isNaN(a) || t === 0) return null

    return Math.round((a / t) * 100)
}

/**
 * Just the number, formatted the same way `formatTarget` formats it but with
 * no unit — for the places that print an achievement and its target together
 * ("10 / 12 Hectare (ha)"), where naming the unit twice reads as a stutter.
 */
export function formatAmount(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') return ''

    const amount = Number(value)

    if (Number.isNaN(amount)) return ''

    return Number.isInteger(amount)
        ? amount.toLocaleString('en-US')
        : amount.toLocaleString('en-US', { maximumFractionDigits: 2 })
}
