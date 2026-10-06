import { computed, ref, watch, type Ref } from 'vue'

import type { Option } from '@/Components/UI/MultiSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import type { ProgramRow } from '@/types/masterDevelopment'

/**
 * --------------------------------------------------------------------------
 * Column filters for the development-program table (Pages/Idp/Settings.vue)
 * --------------------------------------------------------------------------
 * One per grouping column, in the same order the table nests them. Each one's
 * options are drawn from the rows themselves, narrowed by the filters ABOVE it
 * in the cascade, so a filter can never offer a value that would empty the
 * table — and a choice that stops being offered is cleared rather than left
 * silently applied.
 *
 * They deliberately ignore the package/model tabs: the tab counts already point
 * at where the matches are, so keeping the option lists stable across tabs is
 * more useful than hiding a competency that lives in the next cycle.
 *
 * `baseRows` is every row, sorted but unfiltered; `search` is the free-text box,
 * applied last (see `programRows`).
 */
export function useProgramFilters(
    baseRows: Readonly<Ref<ProgramRow[]>>,
    search: Readonly<Ref<string>>,
) {
    const { t } = useLocale()

    const filterType = ref('')
    const filterCompetency = ref('')
    const filterLevel = ref('')
    const filterGrade = ref('')

    const hasFilters = computed(
        () =>
            filterType.value !== '' ||
            filterCompetency.value !== '' ||
            filterLevel.value !== '' ||
            filterGrade.value !== '',
    )

    function clearFilters() {
        filterType.value = ''
        filterCompetency.value = ''
        filterLevel.value = ''
        filterGrade.value = ''
    }

    // "All …" first, then one option per distinct value, by label — the same shape
    // Master Training's filters use, so the two toolbars behave identically.
    function withAllOption(
        label: string,
        options: Option[],
        sorted = true,
    ): Option[] {
        return [
            { value: '', label },
            ...(sorted
                ? options.sort((a, b) => a.label.localeCompare(b.label))
                : options),
        ]
    }

    // Distinct (value, label) pairs drawn from the rows. A blank value is skipped:
    // "no competency at all" is legacy data, not something worth offering.
    function distinctOptions(
        rows: ProgramRow[],
        value: (row: ProgramRow) => string,
        label: (row: ProgramRow) => string,
    ): Option[] {
        const seen = new Map<string, string>()
        for (const row of rows) {
            const v = value(row)
            const l = label(row)
            if (v === '' || l === '' || seen.has(v)) continue
            seen.set(v, l)
        }
        return [...seen].map(([v, l]) => ({ value: v, label: l }))
    }

    // The cascade: each stage is the rows left after the filters above it.
    const rowsForType = computed(() => baseRows.value)

    const rowsForCompetency = computed(() =>
        filterType.value === ''
            ? rowsForType.value
            : rowsForType.value.filter(
                  (row) => row.competencyTypeKey === filterType.value,
              ),
    )

    const rowsForLevel = computed(() =>
        filterCompetency.value === ''
            ? rowsForCompetency.value
            : rowsForCompetency.value.filter(
                  (row) => row.competencyKey === filterCompetency.value,
              ),
    )

    const rowsForGrade = computed(() =>
        filterLevel.value === ''
            ? rowsForLevel.value
            : rowsForLevel.value.filter(
                  (row) => String(row.proficiencySequence ?? '') === filterLevel.value,
              ),
    )

    const typeFilterOptions = computed<Option[]>(() =>
        withAllOption(
            t.value.idp.settings.allCompetencyTypes,
            distinctOptions(
                rowsForType.value,
                (row) => row.competencyTypeKey,
                (row) => row.competencyTypeName,
            ),
        ),
    )

    const competencyFilterOptions = computed<Option[]>(() =>
        withAllOption(
            t.value.idp.settings.allCompetencies,
            distinctOptions(
                rowsForCompetency.value,
                (row) => row.competencyKey,
                (row) => row.competencyName,
            ),
        ),
    )

    /**
     * Levels are keyed on the rung's SEQUENCE, not its id and not its name.
     *
     * A rung belongs to exactly one competency (Phase 5.19), so the id would answer
     * "this competency's first rung" when the question is "everything at rung 1".
     * The sequence is the rung's position on its competency's ladder, which is the
     * thing every competency has in common — and an integer compare rather than a
     * string one.
     *
     * The label is still the name, because that is what the table prints. Ladders
     * almost always name rung N the same way, so one name normally covers the whole
     * option; when they disagree, every name at that rung is listed rather than one
     * being picked to stand for the rest.
     */
    const levelFilterOptions = computed<Option[]>(() => {
        const names = new Map<number, Set<string>>()

        for (const row of rowsForLevel.value) {
            const seq = row.proficiencySequence
            // A free-typed level (an "Others" program) has no rung, so it has no
            // sequence to file under and is not offered here.
            if (seq == null) continue
            if (!names.has(seq)) names.set(seq, new Set())
            if (row.proficiency !== '') names.get(seq)!.add(row.proficiency)
        }

        const options = [...names]
            .sort(([a], [b]) => a - b)
            .map(([seq, labels]) => ({
                value: String(seq),
                label: labels.size ? [...labels].sort().join(' / ') : String(seq),
            }))

        return withAllOption(
            t.value.idp.settings.allProficiencyLevels,
            options,
            false,
        )
    })

    const gradeFilterOptions = computed<Option[]>(() => {
        const seen = new Set<string>()
        for (const row of rowsForGrade.value) {
            for (const g of row.grades) seen.add(g)
        }
        return withAllOption(
            t.value.idp.settings.allGrades,
            [...seen].map((g) => ({ value: g, label: g })),
        )
    })

    // A choice the cascade no longer offers is dropped, so the table never filters
    // on something the toolbar cannot show.
    watch(competencyFilterOptions, (opts) => {
        if (
            filterCompetency.value !== '' &&
            !opts.some((o) => o.value === filterCompetency.value)
        ) {
            filterCompetency.value = ''
        }
    })

    watch(levelFilterOptions, (opts) => {
        if (
            filterLevel.value !== '' &&
            !opts.some((o) => o.value === filterLevel.value)
        ) {
            filterLevel.value = ''
        }
    })

    watch(gradeFilterOptions, (opts) => {
        if (
            filterGrade.value !== '' &&
            !opts.some((o) => o.value === filterGrade.value)
        ) {
            filterGrade.value = ''
        }
    })

    // Search is applied last and deliberately does NOT narrow the option lists:
    // free text that reshuffles four dropdowns as it is typed is unreadable.
    const programRows = computed<ProgramRow[]>(() => {
        const rows =
            filterGrade.value === ''
                ? rowsForGrade.value
                : rowsForGrade.value.filter((row) =>
                      row.grades.includes(filterGrade.value),
                  )

        const q = search.value.trim().toLowerCase()
        if (!q) return rows

        return rows.filter((row) =>
            [
                row.name,
                row.program.value,
                row.competencyName,
                row.competencyCode,
                row.competencyTypeName,
                row.competencyTypeCode,
                row.proficiency,
            ].some((field) => field.toLowerCase().includes(q)),
        )
    })

    return {
        filterType,
        filterCompetency,
        filterLevel,
        filterGrade,
        hasFilters,
        clearFilters,
        typeFilterOptions,
        competencyFilterOptions,
        levelFilterOptions,
        gradeFilterOptions,
        programRows,
    }
}
