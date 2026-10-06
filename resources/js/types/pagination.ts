/** One entry of Laravel's paginator `links` (prev, each page, next). */
export interface PaginatorLink {
    url: string | null
    label: string
    active: boolean
}

/**
 * A Laravel `LengthAwarePaginator` as Inertia serializes it — the shape every
 * server-paginated list page receives. Only the fields the pages read.
 */
export interface Paginator<T> {
    data: T[]
    links: PaginatorLink[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}
