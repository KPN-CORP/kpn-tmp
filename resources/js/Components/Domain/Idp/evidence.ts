/**
 * Result evidence is something an approver can open: a web link, or a path on
 * the office network (a shared folder). Mirrors SubmitIdpResultRequest, which
 * is the authority — this copy only keeps the form from offering Submit on a
 * value the server would refuse, and decides how a stored value is shown.
 */

export type EvidenceKind = 'web' | 'network'

/**
 * A path on the office network:
 *  - UNC: `\\fileserver\HR\cert.pdf` (or with forward slashes)
 *  - a mapped drive: `S:\HR\cert.pdf`
 *  - a `file://` link
 * Folder and file names may contain spaces, so only the start is checked.
 */
const NETWORK_PATH = [
    /^(\\\\|\/\/)[^\\/\s]+[\\/][^\\/]+/,
    /^[a-z]:[\\/]\S/i,
    /^file:\/\/\S/i,
]

export function isNetworkPath(value: string): boolean {
    const trimmed = value.trim()

    return NETWORK_PATH.some((pattern) => pattern.test(trimmed))
}

function isWebUrl(value: string): boolean {
    try {
        const url = new URL(value)

        return url.protocol === 'http:' || url.protocol === 'https:'
    } catch {
        return false
    }
}

/**
 * What a typed value is, after the same completion the server applies (a bare
 * host such as `drive.google.com/x` becomes https://…). Null when it is
 * neither — a sentence, an ftp link, a javascript: URL.
 */
export function classifyEvidence(value: string): EvidenceKind | null {
    const trimmed = value.trim()

    if (trimmed === '') return null
    if (isNetworkPath(trimmed)) return 'network'

    const withScheme = /^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed)
        ? trimmed
        : /^[\w-]+(\.[\w-]+)+([/?#].*)?$/.test(trimmed)
            ? `https://${trimmed}`
            : trimmed

    return isWebUrl(withScheme) ? 'web' : null
}

/**
 * How a STORED value is shown. Older results hold free text (they predate the
 * link rule), so anything that is neither a web link nor a network path is
 * still displayed — as plain text.
 */
export function storedEvidenceKind(value: string | null): EvidenceKind | 'text' | null {
    if (!value || value.trim() === '') return null
    if (isNetworkPath(value)) return 'network'

    return /^https?:\/\//i.test(value.trim()) ? 'web' : 'text'
}
