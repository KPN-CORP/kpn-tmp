/**
 * Shared date formatting so the whole UI reads dd-mm-yyyy.
 */

/**
 * Format a date (or ISO datetime) string as `dd-mm-yyyy`.
 * Date-only values are parsed by their parts to avoid timezone day-shifts.
 * Returns '—' for empty and the original string if it isn't a valid date.
 */
export function formatDate(value: string | null | undefined): string {
    if (!value) return '—'

    const s = String(value)
    const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})/)
    if (iso) {
        return `${iso[3]}-${iso[2]}-${iso[1]}`
    }

    const d = new Date(s)
    if (Number.isNaN(d.getTime())) return s

    const dd = String(d.getDate()).padStart(2, '0')
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    return `${dd}-${mm}-${d.getFullYear()}`
}

/**
 * Format a datetime string as `dd-mm-yyyy HH:mm` (local time).
 */
export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '—'

    const d = new Date(String(value))
    if (Number.isNaN(d.getTime())) return String(value)

    const dd = String(d.getDate()).padStart(2, '0')
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const hh = String(d.getHours()).padStart(2, '0')
    const min = String(d.getMinutes()).padStart(2, '0')
    return `${dd}-${mm}-${d.getFullYear()} ${hh}:${min}`
}
