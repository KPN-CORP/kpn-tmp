<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'


const { t, locale } = useLocale()

interface Localized {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface CompetencyType extends Localized {
    // The type's short identifier. Null on types that predate the column; the
    // list renders it as a chip in front of the type name when present.
    code: string | null
    competencies_count: number
}

interface Competency extends Localized {
    // The competency's short identifier. Null on rows that predate the column.
    code: string | null
    competency_type_id: number | null
    // The rungs of this competency's own proficiency ladder.
    proficiency_level_ids: number[]
    // Only an active competency can be mapped.
    is_active: boolean
}

/**
 * One rung of a competency's ladder. There is no shared proficiency-level
 * master any more, so a level belongs to exactly one competency and is offered
 * only once that competency is chosen.
 */
interface ProficiencyLevel extends Localized {
    competency_id: number
    sequence: number
    // The rung's short identifier, unique within its competency. Null on the
    // rows that predate the column.
    code: string | null
    is_active: boolean
    description_en: string | null
    description_id: string | null
}

interface Implementation {
    id: number
    competency_type_id: number | null
    competency_id: number | null
    // One or more proficiency levels pinned to this implementation.
    proficiency_level_ids: number[]
    // The grades this implementation covers; empty means every grade.
    grades: string[]
    // A mapping covers any number of business units; empty means it is not
    // narrowed to one.
    business_units: string[]
    // An inactive mapping keeps its row but no longer applies.
    is_active: boolean
    job_family: string | null
    function_name: string | null
    position: string | null
}

const props = defineProps<{
    implementations: Implementation[]
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    grades: string[]
    // Dynamic org-scope hierarchy: business unit → job family / function → position.
    businessUnits: string[]
    jobFamiliesByBu: Record<string, string[]>
    functionsByBu: Record<string, string[]>
    positionsByBuFunction: Record<string, Record<string, string[]>>
}>()

/**
 * After a mutation the server redirects back here; restrict the reload to this
 * page's own data (+ flash) so every save is a lightweight partial reload.
 */
const reloadOnly = ['implementations', 'flash']

// Localized name for a master row, falling back to the canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
} | null | undefined): string {
    if (!item) return ''
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

/**
 * A row's description in the reading language, falling back to the other — the
 * same rule the Master Competency list applies. '' when there is none.
 */
function rowDescription(row: {
    description_en?: string | null
    description_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? row.description_id : row.description_en
    const fallback = locale.value === 'id' ? row.description_en : row.description_id

    return (preferred || fallback || '').trim()
}

/**
 * --------------------------------------------------------------------------
 * Lookup maps (id → row) for resolving labels in the table
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const c of props.competencyTypes) m.set(c.id, c)
    return m
})

const competencyById = computed(() => {
    const m = new Map<number, Competency>()
    for (const c of props.competencies) m.set(c.id, c)
    return m
})

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const p of props.proficiencyLevels) m.set(p.id, p)
    return m
})

/**
 * --------------------------------------------------------------------------
 * Dropdown options (string lists → { value, label })
 * --------------------------------------------------------------------------
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

/**
 * An implementation maps masters that must be usable now, so only active ones
 * are offered: a deactivated competency or proficiency level would produce a
 * mapping that applies to nobody. The server enforces the same rule on save.
 */

// Competencies filtered by the chosen competency type, minus the inactive ones.
const competencyOptions = computed<Option[]>(() => {
    const typeId = implForm.competency_type_id
    if (typeId == null) return []

    const options = props.competencies
        .filter((c) => c.competency_type_id === typeId && c.is_active)
        .map((c) => ({ value: String(c.id), label: masterName(c) }))

    // A competency saved earlier that has since been switched off keeps its
    // place, so an edit to some other field doesn't blank the select and lose
    // the mapping.
    const current = selectedCompetency.value
    if (current && !current.is_active) {
        options.unshift({ value: String(current.id), label: masterName(current) })
    }

    return options
})

const competencyInactive = computed(
    () => !!selectedCompetency.value && !selectedCompetency.value.is_active,
)

const gradeOptions = computed<Option[]>(() => toStringOptions(props.grades))

// --- Cascading org hierarchy ---

const businessUnitOptions = computed<Option[]>(() => toStringOptions(props.businessUnits))

/**
 * The children of the hierarchy are the **union** across the selected business
 * units — a mapping that covers several units may reach a job family, function
 * or position belonging to any of them. Same convention Master Training uses
 * for the work locations of several units.
 *
 * These three are not rendered today (the org-scope section only offers the
 * business units), but the columns are still stored and submitted, so they stay
 * in step with the selection.
 */
function unionFor(map: Record<string, string[]>): Option[] {
    const values = new Set<string>()
    for (const bu of implForm.business_units) {
        for (const value of map[bu] ?? []) values.add(value)
    }
    return toStringOptions([...values].sort())
}

const jobFamilyOptions = computed<Option[]>(() => unionFor(props.jobFamiliesByBu))

const functionOptions = computed<Option[]>(() => unionFor(props.functionsByBu))

const positionOptions = computed<Option[]>(() => {
    const fn = implForm.function_name
    if (!fn) return []

    const values = new Set<string>()
    for (const bu of implForm.business_units) {
        for (const value of props.positionsByBuFunction[bu]?.[fn] ?? []) values.add(value)
    }
    return toStringOptions([...values].sort())
})

/**
 * --------------------------------------------------------------------------
 * Implementation form (create / edit)
 * --------------------------------------------------------------------------
 */

const implModal = ref(false)
const editingImplId = ref<number | null>(null)
// Suppresses the cascade watchers while a row is being loaded into the form.
const loadingForm = ref(false)

function blankImpl() {
    return {
        competency_type_id: null as number | null,
        competency_id: null as number | null,
        // MultiSelect binds string[]; converted to ints server-side.
        proficiency_level_ids: [] as string[],
        grades: [] as string[],
        business_units: [] as string[],
        // A new mapping applies straight away.
        is_active: true,
        job_family: '',
        function_name: '',
        position: '',
    }
}

const implForm = useForm(blankImpl())

// The competency currently chosen in the form (scopes the proficiency options).
const selectedCompetency = computed<Competency | null>(() =>
    implForm.competency_id == null
        ? null
        : competencyById.value.get(implForm.competency_id) ?? null,
)

// The active levels the chosen competency offers.
const proficiencyOptions = computed<Option[]>(() => {
    const c = selectedCompetency.value
    if (!c) return []

    const pinned = new Set(implForm.proficiency_level_ids)

    return c.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(id))
        .filter((p): p is ProficiencyLevel => !!p)
        // Levels already stored on this implementation stay listed even once
        // switched off; dropping them would silently unpin them on the next
        // save. They are flagged below instead.
        .filter((p) => p.is_active || pinned.has(String(p.id)))
        // The description says what the rung means, which is the only thing
        // telling PL1 from PL2 apart on a form.
        .map((p) => ({
            value: String(p.id),
            label: masterName(p),
            description: rowDescription(p) || undefined,
        }))
})

// Pinned levels that have since been switched off.
const inactivePinnedLevelNames = computed(() =>
    implForm.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(Number(id)))
        .filter((p): p is ProficiencyLevel => !!p && !p.is_active)
        .map((p) => masterName(p)),
)

// Drop any pinned proficiency level the newly chosen competency doesn't offer.
// Suppressed while a row is being loaded — the options list deliberately keeps
// what the row already stores, so the load must not unpin it either.
watch(selectedCompetency, (c) => {
    if (loadingForm.value) return

    const valid = new Set((c?.proficiency_level_ids ?? []).map(String))
    implForm.proficiency_level_ids = implForm.proficiency_level_ids.filter((id) =>
        valid.has(id),
    )
})

// Changing the competency type drops a competency that no longer belongs to it.
// Suppressed while loading, so a stored pair that has since drifted apart is
// neither blanked nor left marking the form dirty.
watch(
    () => implForm.competency_type_id,
    (typeId) => {
        if (loadingForm.value) return

        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            implForm.competency_id = null
        }
    },
)

// Changing the business units invalidates every child in the hierarchy — but
// not while a row is being loaded into the form, which would wipe the very
// values being restored (watchers flush after the form is seeded in openImpl).
watch(
    () => implForm.business_units,
    () => {
        if (loadingForm.value) return

        implForm.job_family = ''
        implForm.function_name = ''
        implForm.position = ''
    },
)

// Changing the function invalidates the position.
watch(
    () => implForm.function_name,
    () => {
        implForm.position = ''
    },
)

function openImpl(item?: Implementation) {
    editingImplId.value = item?.id ?? null

    // Suppress the cascade watchers for the duration of the load, so restoring
    // a row never clears its own children.
    loadingForm.value = true

    // Seeded as both data and defaults, so `isDirty` — which drives the
    // discard prompt — measures this sitting's edits (see `seedForm`).
    seedForm(implForm, {
        ...blankImpl(),
        competency_type_id: item?.competency_type_id ?? null,
        competency_id: item?.competency_id ?? null,
        proficiency_level_ids: (item?.proficiency_level_ids ?? []).map(String),
        grades: [...(item?.grades ?? [])],
        business_units: [...(item?.business_units ?? [])],
        is_active: item?.is_active ?? true,
        job_family: item?.job_family ?? '',
        function_name: item?.function_name ?? '',
        position: item?.position ?? '',
    })

    implModal.value = true

    // Release once the watchers the seeding queued have flushed.
    nextTick(() => (loadingForm.value = false))
}

function closeImpl() {
    implModal.value = false
    seedForm(implForm, blankImpl())
}

// Closing the drawer throws the draft away, so confirm first when there is
// something to lose. Backdrop click, Escape and Cancel all route through here.
const { confirming, requestClose, discard } = useUnsavedGuard(implForm, closeImpl)

function submitImpl() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => closeImpl(),
    }

    if (editingImplId.value) {
        implForm.put(route('idp.setting.implementations.update', editingImplId.value), opts)
    } else {
        implForm.post(route('idp.setting.implementations.store'), opts)
    }
}

const implTitle = computed(() =>
    editingImplId.value
        ? t.value.idp.settings.editImplementation
        : t.value.idp.settings.addImplementation,
)

/**
 * The form is a cascade (competency type -> competency -> level/grades), so the
 * scope section reports whether its two required fields are settled — the step
 * badge turns into a check. Org scope and status are always satisfiable, so
 * they carry a plain step number.
 */
const scopeComplete = computed(
    () => implForm.competency_type_id != null && implForm.competency_id != null,
)

/**
 * --------------------------------------------------------------------------
 * Implementation table — search + type tab (external) → sort + pages (below)
 * --------------------------------------------------------------------------
 * Same shape as the Master Competency list: the competency type splits the
 * list into a tab strip and, on the "All" tab, merges down the left edge, and
 * a mapping's proficiency levels are one row each rather than a wrap of chips.
 * So this is a grouped (rowspan) grid rather than a ClientTable — sorting and
 * paging therefore work on mappings, not on rendered rows, which is why they
 * live here instead of coming from ClientTable.
 */

const implSearch = ref('')

// Every mapping with its labels resolved, before search or the type tab.
const labelledImpls = computed(() =>
    props.implementations.map((row) => {
        const competency = row.competency_id != null
            ? competencyById.value.get(row.competency_id)
            : null
        const type = row.competency_type_id != null
            ? competencyTypeById.value.get(row.competency_type_id)
            : null

        // Ordered by their position on the competency's ladder, so PL1 reads
        // above PL2 whatever order they were pinned in.
        const levels = (row.proficiency_level_ids ?? [])
            .map((id) => proficiencyLevelById.value.get(id))
            .filter((p): p is ProficiencyLevel => !!p)
            .sort((a, b) => a.sequence - b.sequence || a.id - b.id)

        return {
            ...row,
            competency_name: masterName(competency),
            competency_code: competency?.code ?? '',
            type_name: masterName(type),
            type_code: type?.code ?? '',
            levels,
            proficiency_names: levels.flatMap((p) => [masterName(p), p.code ?? '']),
        }
    }),
)

// Everything the search matches, before the type tab narrows it. The tab
// counts are taken from here, so each tab's number is exactly how many rows
// opening it would show.
const searchedImpls = computed(() => {
    const q = implSearch.value.trim().toLowerCase()
    if (!q) return labelledImpls.value

    return labelledImpls.value.filter((row) =>
        [
            row.competency_name,
            row.competency_code,
            row.type_name,
            row.type_code,
            ...row.proficiency_names,
            ...(row.grades ?? []),
            ...(row.business_units ?? []),
            row.job_family ?? '',
            row.function_name ?? '',
            row.position ?? '',
        ].some((v) => v.toLowerCase().includes(q)),
    )
})

/**
 * The competency type the list is showing. The list is split by type into a
 * tab strip — null is the "all types" tab, 0 the bucket of mappings that have
 * no type at all.
 */
const selectedTypeFilter = ref<number | null>(null)

// Keep the open tab valid as types are added/removed.
watch(
    () => props.competencyTypes,
    (list) => {
        if (
            selectedTypeFilter.value !== null &&
            selectedTypeFilter.value !== 0 &&
            !list.some((ct) => ct.id === selectedTypeFilter.value)
        ) {
            selectedTypeFilter.value = null
        }
    },
)

// Mappings with no type assigned. Read off the whole set rather than the
// searched one, so the "Untyped" tab does not appear and vanish while typing.
const untypedImplCount = computed(
    () => props.implementations.filter((i) => i.competency_type_id == null).length,
)

// One tab per competency type, plus "All" and — when there are any — the
// untyped bucket.
const typeTabs = computed(() => {
    const counts = new Map<number, number>()

    for (const row of searchedImpls.value) {
        const key = row.competency_type_id ?? 0
        counts.set(key, (counts.get(key) ?? 0) + 1)
    }

    const tabs: { key: number | null; label: string; count: number }[] = [
        {
            key: null,
            label: t.value.idp.settings.allTypes,
            count: searchedImpls.value.length,
        },
        ...props.competencyTypes.map((ct) => ({
            key: ct.id,
            label: masterName(ct),
            count: counts.get(ct.id) ?? 0,
        })),
    ]

    if (untypedImplCount.value) {
        tabs.push({
            key: 0,
            label: t.value.idp.settings.untyped,
            count: counts.get(0) ?? 0,
        })
    }

    return tabs
})

function selectType(key: number | null) {
    selectedTypeFilter.value = key
    // Two tabs may hold the same number of mappings, so the length watcher
    // below cannot be relied on to send the reader back to page one.
    implPage.value = 1
}

/**
 * The type column only earns its place on the "All" tab: on any other the tab
 * itself already names the type every row carries.
 */
const showTypeColumn = computed(() => selectedTypeFilter.value === null)

const implRows = computed(() => {
    const typeFilter = selectedTypeFilter.value
    if (typeFilter === null) return searchedImpls.value

    return searchedImpls.value.filter((row) =>
        // 0 = the untyped bucket.
        typeFilter === 0
            ? row.competency_type_id == null
            : row.competency_type_id === typeFilter,
    )
})

// --- sorting (competency / type) ---

type ImplSortKey = 'competency_name' | 'type_name'

const implSort = ref<{ key: ImplSortKey; dir: 'asc' | 'desc' }>({
    key: 'competency_name',
    dir: 'asc',
})

function toggleImplSort(key: ImplSortKey) {
    const s = implSort.value
    implSort.value =
        s.key === key
            ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
            : { key, dir: 'asc' }
    implPage.value = 1
}

/**
 * On the "All" tab the type column merges the mappings that share a type into
 * one cell, so the list reads as a type at a time. That only works if they are
 * adjacent, so the type is the PRIMARY sort there and whatever column the
 * reader clicked orders the mappings inside it. Untyped rows sort last,
 * whichever way the type runs.
 */
const sortedImpls = computed(() => {
    const { key, dir } = implSort.value
    const sign = dir === 'asc' ? 1 : -1
    const groupByType = showTypeColumn.value

    return [...implRows.value].sort((a, b) => {
        if (groupByType) {
            // '￿' sorts after any real name, which parks the untyped
            // bucket at the end of an ascending list.
            const ta = a.type_name || '￿'
            const tb = b.type_name || '￿'
            // Clicking the type header is the only thing that reverses the
            // groups; the other columns reorder within them.
            const byType = ta.localeCompare(tb) * (key === 'type_name' ? sign : 1)

            if (byType !== 0) return byType
        }

        // The type is already settled by the time we get here, so it cannot
        // also be the tiebreak — fall back to the competency name.
        const within = key === 'type_name' && groupByType ? 'competency_name' : key

        return String(a[within] ?? '').localeCompare(String(b[within] ?? '')) * sign
    })
})

// --- paging (by mapping, so a block is never split across pages) ---

const implPage = ref(1)
const implPerPage = ref(10)

const implTotalPages = computed(() =>
    Math.max(1, Math.ceil(sortedImpls.value.length / implPerPage.value)),
)

// Any change to the filtered set sends the user back to the first page.
watch(() => implRows.value.length, () => (implPage.value = 1))

const implFrom = computed(() =>
    sortedImpls.value.length ? (implPage.value - 1) * implPerPage.value + 1 : 0,
)

const implTo = computed(() =>
    Math.min(implPage.value * implPerPage.value, sortedImpls.value.length),
)

function changeImplPerPage(size: number) {
    implPerPage.value = size
    implPage.value = 1
}

/**
 * One rendered <tr> of a mapping block: a single proficiency level, or one
 * blank line when the mapping pins none.
 *
 * The fields are what ProficiencyLevelCell reads, so a rung is laid out here
 * exactly as it is on the Master Competency list — badge, code, name, then what
 * it means.
 */
interface ImplLine {
    name: string | null
    sequence: number | null
    code: string | null
    description: string | null
    active: boolean
}

function linesFor(levels: ProficiencyLevel[]): ImplLine[] {
    if (levels.length === 0) {
        return [{ name: null, sequence: null, code: null, description: null, active: true }]
    }

    return levels.map((level) => ({
        name: masterName(level),
        sequence: level.sequence,
        code: level.code,
        description: rowDescription(level) || null,
        active: level.is_active,
    }))
}

// The mappings on the current page, each expanded into its rendered lines.
const implBlocks = computed(() => {
    if (implPage.value > implTotalPages.value) {
        implPage.value = implTotalPages.value
    }

    const start = (implPage.value - 1) * implPerPage.value

    const blocks = sortedImpls.value
        .slice(start, start + implPerPage.value)
        .map((row) => {
            const lines = linesFor(row.levels)

            return {
                ...row,
                lines,
                rowspan: lines.length,
                // Filled in below: how many rendered lines this block's type
                // cell spans. 0 on every block but the first of its group,
                // which is what merges the column.
                typeRowspan: 0,
            }
        })

    /**
     * Merge the type column over each run of mappings sharing a type. The run
     * is taken from the PAGE, not the whole list, so a group split across
     * pages simply merges as far as each page goes.
     */
    for (let i = 0; i < blocks.length; ) {
        const type = blocks[i].competency_type_id
        let span = 0
        let j = i

        while (j < blocks.length && blocks[j].competency_type_id === type) {
            span += blocks[j].rowspan
            j++
        }

        blocks[i].typeRowspan = span
        i = j
    }

    return blocks
})

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate a mapping + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the mapping and its scope; it only stops the mapping
 * applying from now on. Who flipped it is recorded in the audit log on disk,
 * which the history drawer reads back.
 */

const togglingId = ref<number | null>(null)

function toggleActive(row: Implementation) {
    router.put(
        route('idp.setting.implementations.active', row.id),
        { is_active: !row.is_active },
        {
            preserveScroll: true,
            preserveState: true,
            only: reloadOnly,
            onStart: () => (togglingId.value = row.id),
            onFinish: () => (togglingId.value = null),
        },
    )
}

const historyImpl = ref<Implementation | null>(null)

function openHistory(row: Implementation) {
    historyImpl.value = row
}

// A mapping has no name of its own, so the competency it maps labels it.
function implLabel(row: Implementation | null): string {
    if (!row || row.competency_id == null) return ''
    return masterName(competencyById.value.get(row.competency_id))
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteImpl(row: { id: number; competency_name: string }) {
    pendingDelete.value = {
        url: route('idp.setting.implementations.destroy', row.id),
        name: row.competency_name,
    }
}

function confirmDelete() {
    if (!pendingDelete.value) return

    router.delete(pendingDelete.value.url, {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}
</script>

<template>
    <Head :title="t.idp.settings.implementationTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.implementationTitle"
            :subtitle="t.idp.settings.implementationSubtitle"
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Implementations · header + toolbar + table
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.implementations }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ implementations.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.implementationsHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="implSearch"
                                type="search"
                                :placeholder="t.idp.settings.searchImplementation"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openImpl()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addImplementation }}
                        </button>
                    </div>
                </div>

                <!-- One tab per competency type. The type is what a reader
                     comes to this screen with in mind, so it splits the list
                     rather than sitting in a dropdown; the count on each tab
                     is how many rows opening it shows, search included. -->
                <div
                    class="flex gap-2 overflow-x-auto border-b border-border/60 bg-slate-50/40 px-5 py-2.5"
                    role="tablist"
                >
                    <button
                        v-for="tab in typeTabs"
                        :key="tab.key ?? 'all'"
                        type="button"
                        role="tab"
                        :aria-selected="selectedTypeFilter === tab.key"
                        class="inline-flex shrink-0 items-center gap-2 rounded-lg border px-3.5 py-1.5 text-sm font-medium transition"
                        :class="
                            selectedTypeFilter === tab.key
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-transparent bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="selectType(tab.key)"
                    >
                        {{ tab.label }}
                        <span
                            class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
                            :class="
                                selectedTypeFilter === tab.key
                                    ? 'bg-primary/15'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <!-- Implementation table — grouped rows: one mapping spans a
                     block, split into one row per proficiency level. -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr
                                class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400"
                            >
                                <!-- The type leads: it is what the list is
                                     grouped by, so it reads as the section
                                     label down the left edge. -->
                                <th
                                    v-if="showTypeColumn"
                                    class="w-52 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                    @click="toggleImplSort('type_name')"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        {{ t.idp.settings.competencyType }}
                                        <i
                                            class="fa-solid text-[10px]"
                                            :class="implSort.key === 'type_name'
                                                ? (implSort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                                : 'fa-sort text-slate-300'"
                                        />
                                    </span>
                                </th>
                                <th
                                    class="w-56 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                    @click="toggleImplSort('competency_name')"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        {{ t.idp.settings.competency }}
                                        <i
                                            class="fa-solid text-[10px]"
                                            :class="implSort.key === 'competency_name'
                                                ? (implSort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                                : 'fa-sort text-slate-300'"
                                        />
                                    </span>
                                </th>
                                <!-- w-56 like the other two level columns: the
                                     badge names the level in full now, so w-48
                                     wrapped it. -->
                                <th class="w-56 px-4 py-2.5 font-semibold">
                                    {{ t.idp.settings.proficiencyLevel }}
                                </th>
                                <th class="w-40 px-4 py-2.5 font-semibold">
                                    {{ t.idp.settings.grade }}
                                </th>
                                <th class="w-48 px-4 py-2.5 font-semibold">
                                    {{ t.idp.settings.businessUnit }}
                                </th>
                                <!-- Status sits in this column too: the badge
                                     is itself the on/off control, so it belongs
                                     with edit and delete. -->
                                <th class="w-44 px-4 py-2.5 text-right font-semibold">
                                    {{ t.idp.settings.action }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <template v-for="block in implBlocks" :key="block.id">
                                <tr
                                    v-for="(line, i) in block.lines"
                                    :key="i"
                                    class="transition hover:bg-slate-50/70"
                                    :class="i === block.lines.length - 1 ? 'border-b border-border/60' : ''"
                                >
                                    <!-- Painted once per RUN of mappings
                                         sharing a type, so the column merges. -->
                                    <td
                                        v-if="showTypeColumn && i === 0 && block.typeRowspan > 0"
                                        :rowspan="block.typeRowspan"
                                        class="border-r border-border/40 bg-slate-50/40 px-4 py-3 align-top"
                                    >
                                        <!-- Code on its own line above the
                                             name. The chip is inline and the
                                             name a block, which is what breaks
                                             the line, so a row with no code
                                             leaves no gap. -->
                                        <template v-if="block.type_name">
                                            <span
                                                v-if="block.type_code"
                                                class="mb-1 inline-flex items-center rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-indigo-700"
                                            >
                                                {{ block.type_code }}
                                            </span>
                                            <div class="font-semibold text-slate-800">
                                                {{ block.type_name }}
                                            </div>
                                        </template>
                                        <span v-else class="text-xs italic text-slate-300">
                                            {{ t.idp.settings.untyped }}
                                        </span>
                                    </td>

                                    <!-- Mapping-wide cells: painted once,
                                         spanning the block. The competency's
                                         own code sits above its name. -->
                                    <td
                                        v-if="i === 0"
                                        :rowspan="block.rowspan"
                                        class="border-r border-border/40 px-4 py-3 align-top"
                                    >
                                        <span
                                            v-if="block.competency_code"
                                            class="mb-1 inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                        >
                                            {{ block.competency_code }}
                                        </span>
                                        <div class="font-semibold text-slate-800">
                                            {{ block.competency_name || '—' }}
                                        </div>
                                    </td>

                                    <!-- Proficiency level: one per line. -->
                                    <td
                                        class="border-r border-border/40 px-4 py-3 align-top"
                                        :class="i < block.lines.length - 1 ? 'border-b border-border/40' : ''"
                                    >
                                        <ProficiencyLevelCell
                                            v-if="line.name"
                                            :name="line.name"
                                            :sequence="line.sequence"
                                            :code="line.code"
                                            :description="line.description"
                                            :active="line.active"
                                        />
                                        <span v-else class="text-xs italic text-slate-300">
                                            {{ t.idp.settings.noProficiencyLevel }}
                                        </span>
                                    </td>

                                    <td
                                        v-if="i === 0"
                                        :rowspan="block.rowspan"
                                        class="border-r border-border/40 px-4 py-3 align-top"
                                    >
                                        <div v-if="block.grades?.length" class="flex flex-wrap gap-1">
                                            <span
                                                v-for="(grade, g) in block.grades"
                                                :key="g"
                                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                                            >
                                                {{ grade }}
                                            </span>
                                        </div>
                                        <span v-else class="text-xs italic text-slate-300">—</span>
                                    </td>

                                    <td
                                        v-if="i === 0"
                                        :rowspan="block.rowspan"
                                        class="border-r border-border/40 px-4 py-3 align-top"
                                    >
                                        <div v-if="block.business_units?.length" class="flex flex-wrap gap-1">
                                            <span
                                                v-for="bu in block.business_units"
                                                :key="bu"
                                                class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                                            >
                                                {{ bu }}
                                            </span>
                                        </div>
                                        <span v-else class="text-xs italic text-slate-300">—</span>
                                    </td>

                                    <!-- Status rides in the action cell: the
                                         badge is itself the on/off control, so
                                         it belongs with edit and delete rather
                                         than in a column of its own. -->
                                    <td
                                        v-if="i === 0"
                                        :rowspan="block.rowspan"
                                        class="px-4 py-3 align-top"
                                    >
                                        <div class="flex flex-col items-end gap-2">
                                            <ActiveStateCell
                                                :active="block.is_active"
                                                :busy="togglingId === block.id"
                                                @toggle="toggleActive(block)"
                                                @history="openHistory(block)"
                                            />

                                            <div class="flex items-center gap-1">
                                                <IconButton
                                                    icon="fa-solid fa-pen"
                                                    variant="edit"
                                                    :title="t.idp.settings.editImplementation"
                                                    @click="openImpl(block)"
                                                />
                                                <IconButton
                                                    icon="fa-solid fa-trash"
                                                    variant="delete"
                                                    :title="t.idp.settings.deleteImplementation"
                                                    @click="deleteImpl(block)"
                                                />
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>

                            <tr v-if="implBlocks.length === 0">
                                <td
                                    :colspan="showTypeColumn ? 6 : 5"
                                    class="px-4 py-8 text-center text-slate-400"
                                >
                                    {{
                                        implSearch || selectedTypeFilter !== null
                                            ? t.idp.settings.noImplementationsMatch
                                            : t.idp.settings.noImplementations
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pager: pages mappings, so a block is never split. -->
                <div
                    v-if="implTotalPages > 1 || sortedImpls.length > 10"
                    class="border-t border-border px-4 py-2.5 [&>div]:!mt-0"
                >
                    <Pagination
                        :page="implPage"
                        :per-page="implPerPage"
                        :total="sortedImpls.length"
                        :from="implFrom"
                        :to="implTo"
                        @update:page="implPage = $event"
                        @update:per-page="changeImplPerPage"
                    />
                </div>
            </section>
        </div>

        <!-- ================================================================
             IMPLEMENTATION MODAL
        ================================================================= -->

        <Drawer
            :show="implModal"
            :title="implTitle"
            max-width="max-w-3xl"
            @close="requestClose"
        >
            <form id="impl-form" class="space-y-4" @submit.prevent="submitImpl">
                <!-- ========================================================
                     1. Scope — which competency, at which proficiency
                ========================================================= -->
                <FormSection
                    :step="1"
                    :title="t.idp.settings.scope"
                    icon="fa-solid fa-bullseye"
                    :complete="scopeComplete"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <!-- Competency type (scopes everything below it) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.competencyType }}
                                <span class="text-red-500">*</span>
                            </label>

                            <SearchableSelect
                                :model-value="
                                    implForm.competency_type_id == null
                                        ? ''
                                        : String(implForm.competency_type_id)
                                "
                                :options="competencyTypeOptions"
                                :placeholder="t.idp.settings.selectCompetencyType"
                                :invalid="!!implForm.errors.competency_type_id"
                                @update:model-value="
                                    implForm.competency_type_id =
                                        $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-if="implForm.errors.competency_type_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ implForm.errors.competency_type_id }}
                            </p>
                        </div>

                        <!-- Competency (of that type, active only) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.competency }}
                                <span class="text-red-500">*</span>
                            </label>

                            <SearchableSelect
                                v-if="implForm.competency_type_id != null && competencyOptions.length"
                                :model-value="
                                    implForm.competency_id == null
                                        ? ''
                                        : String(implForm.competency_id)
                                "
                                :options="competencyOptions"
                                :placeholder="t.idp.settings.competencyPickHint"
                                :invalid="!!implForm.errors.competency_id"
                                @update:model-value="
                                    implForm.competency_id = $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        implForm.competency_type_id == null
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        implForm.competency_type_id == null
                                            ? t.idp.settings.pickTypeFirst
                                            : t.idp.settings.noCompetenciesForType
                                    }}
                                </span>
                            </p>

                            <p v-if="implForm.errors.competency_id" class="mt-1 text-xs text-red-600">
                                {{ implForm.errors.competency_id }}
                            </p>

                            <!-- A competency saved before it was switched off. Kept so
                                 the mapping isn't lost, but it can't stay as it is. -->
                            <p
                                v-if="competencyInactive"
                                class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                            >
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                                <span>{{ t.idp.settings.competencyInactiveForImplementation }}</span>
                            </p>
                        </div>

                        <!-- Proficiency levels the competency offers -->
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.proficiencyLevel }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>

                            <MultiSelect
                                v-if="selectedCompetency && proficiencyOptions.length"
                                :model-value="implForm.proficiency_level_ids"
                                :options="proficiencyOptions"
                                :placeholder="t.idp.settings.proficiencyLevelPickHint"
                                :invalid="!!implForm.errors.proficiency_level_ids"
                                select-all
                                :select-all-label="t.idp.settings.selectAllLevels"
                                :clear-all-label="t.idp.settings.clearAllLevels"
                                @update:model-value="implForm.proficiency_level_ids = $event"
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        selectedCompetency
                                            ? 'fa-solid fa-circle-info'
                                            : 'fa-solid fa-lock'
                                    "
                                />
                                <span>
                                    {{
                                        !selectedCompetency
                                            ? t.idp.settings.pickCompetencyFirst
                                            : selectedCompetency.proficiency_level_ids.length > 0
                                                ? t.idp.settings.noActiveProficiencyForCompetency
                                                : t.idp.settings.noProficiencyForCompetency
                                    }}
                                </span>
                            </p>

                            <p
                                v-if="implForm.errors.proficiency_level_ids"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ implForm.errors.proficiency_level_ids }}
                            </p>

                            <!-- Levels pinned earlier that have since been switched off. -->
                            <p
                                v-if="inactivePinnedLevelNames.length"
                                class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                            >
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                                <span>
                                    {{ t.idp.settings.inactiveLevelsPinned }}
                                    {{ inactivePinnedLevelNames.join(', ') }}
                                </span>
                            </p>
                        </div>

                        <!-- Grades (empty means every grade) -->
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.grade }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>

                            <MultiSelect
                                :model-value="implForm.grades"
                                :options="gradeOptions"
                                :placeholder="t.idp.settings.gradePickHint"
                                :invalid="!!implForm.errors.grades"
                                select-all
                                :select-all-label="t.idp.settings.selectAllGrades"
                                :clear-all-label="t.idp.settings.clearAllGrades"
                                @update:model-value="implForm.grades = $event"
                            />
                            <p v-if="implForm.errors.grades" class="mt-1 text-xs text-red-600">
                                {{ implForm.errors.grades }}
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     2. Organization scope — who the mapping applies to
                ========================================================= -->
                <FormSection
                    :step="2"
                    :title="t.idp.settings.orgScope"
                    icon="fa-solid fa-building"
                >
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.businessUnit }}
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>

                        <MultiSelect
                            :model-value="implForm.business_units"
                            :options="businessUnitOptions"
                            :placeholder="t.idp.settings.businessUnitsPickHint"
                            :invalid="!!implForm.errors.business_units"
                            select-all
                            :select-all-label="t.idp.settings.selectAllBusinessUnits"
                            :clear-all-label="t.idp.settings.clearAllBusinessUnits"
                            @update:model-value="implForm.business_units = $event"
                        />
                        <p v-if="implForm.errors.business_units" class="mt-1 text-xs text-red-600">
                            {{ implForm.errors.business_units }}
                        </p>
                    </div>
                </FormSection>

                <!-- ========================================================
                     3. Status — applies from now on, or retired
                ========================================================= -->
                <FormSection
                    :step="3"
                    :title="t.idp.settings.status"
                    icon="fa-solid fa-toggle-on"
                >
                    <ActiveStateField
                        v-model="implForm.is_active"
                        :error="implForm.errors.is_active"
                    />
                </FormSection>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="requestClose"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="impl-form"
                    :disabled="implForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             UNSAVED-CHANGES CONFIRMATION
        ================================================================= -->

        <UnsavedChangesDialog
            :show="confirming"
            @confirm="discard"
            @close="confirming = false"
        />

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->
        <!-- ================================================================
             ACTIVATION HISTORY
        ================================================================= -->

        <MasterStatusHistory
            :show="historyImpl !== null"
            :url="
                historyImpl
                    ? route('idp.setting.implementations.statusHistory', historyImpl.id)
                    : null
            "
            :name="implLabel(historyImpl)"
            @close="historyImpl = null"
        />


        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.idp.settings.deleteTitle"
            :message="t.idp.settings.confirmDelete"
            :confirm-label="t.idp.settings.delete"
            :cancel-label="t.idp.form.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete?.name"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ pendingDelete.name }}
            </p>
        </ConfirmDialog>
    </AppLayout>
</template>
