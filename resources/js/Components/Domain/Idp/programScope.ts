/**
 * Pure helpers behind the development-program screen (`Pages/Idp/Settings.vue`)
 * and its form drawer. No reactivity here — callers pass what they read.
 */

/**
 * A development model's name in the active UI language, falling back to the
 * canonical `name` when the preferred localized name is empty.
 */
export function localizedModelName(
    model: {
        name: string
        name_en?: string | null
        name_id?: string | null
    },
    locale: string,
): string {
    const preferred = locale === 'id' ? model.name_id : model.name_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : model.name
}

/** The part of a master-implementation mapping the grade helpers read. */
export interface GradeScope {
    proficiency_level_ids: number[]
    grades: string[]
}

/**
 * The grades the given implementations cover for one proficiency level, in
 * corporate grade order (`allGrades`). A mapping that lists no grades of its
 * own covers every grade. Empty when no implementation maps the level at all.
 */
export function gradesForLevel(
    scopes: GradeScope[],
    levelId: number,
    allGrades: string[],
): string[] {
    const matching = scopes.filter((i) => i.proficiency_level_ids.includes(levelId))

    if (matching.length === 0) return []
    if (matching.some((i) => i.grades.length === 0)) return [...allGrades]

    const covered = new Set(matching.flatMap((i) => i.grades))

    return [
        ...allGrades.filter((g) => covered.has(g)),
        // Grades the corporate list doesn't know about (an unreachable
        // kpncorp, or a value that has since gone) still belong to the mapping.
        ...[...covered].filter((g) => !allGrades.includes(g)).sort(),
    ]
}

/**
 * Grades as compact ranges — `2-3` rather than `2, 3` — by collapsing runs that
 * sit next to each other in the corporate grade order (`allGrades`). Anything
 * outside that order is listed as-is.
 */
export function gradeRangeLabel(grades: string[], allGrades: string[]): string {
    const order = new Map(allGrades.map((g, i) => [g, i]))
    const parts: string[] = []
    let run: string[] = []

    const flush = () => {
        if (run.length === 0) return
        parts.push(run.length > 1 ? `${run[0]}-${run[run.length - 1]}` : run[0])
        run = []
    }

    for (const grade of grades.filter((g) => order.has(g))) {
        const prev = run[run.length - 1]
        if (prev !== undefined && order.get(grade)! !== order.get(prev)! + 1) {
            flush()
        }
        run.push(grade)
    }
    flush()

    return [...parts, ...grades.filter((g) => !order.has(g))].join(', ')
}
