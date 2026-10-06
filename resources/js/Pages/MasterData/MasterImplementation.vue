<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import ConfirmActiveStateDialog from '@/Components/Domain/ConfirmActiveStateDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import ImplementationFormDrawer from '@/Components/Domain/MasterData/ImplementationFormDrawer.vue'
import ImplementationDetailDrawer from '@/Components/Domain/MasterData/ImplementationDetailDrawer.vue'
import { formatDateTime } from '@/Composables/useDate'
import { useLocale } from '@/Composables/useLocale'
import { useActiveStateToggle } from '@/Composables/useActiveStateToggle'
import { route } from '@/Config/route'
import { useDeleteConfirm } from '@/Composables/useDeleteConfirm'
import { useMasterLabels } from '@/Composables/useMasterLabels'
import type {
    ImplCompetency as Competency,
    ImplCompetencyType as CompetencyType,
    ImplProficiencyLevel as ProficiencyLevel,
    Implementation,
} from '@/types/masterImplementation'

const { t } = useLocale()
const { masterName, rowDescription } = useMasterLabels()

const props = defineProps<{
    implementations: Implementation[]
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    grades: string[]
    businessUnits: string[]
}>()

/**
 * After a mutation the server redirects back here; restrict the reload to this
 * page's own data (+ flash) so every save is a lightweight partial reload.
 */
const reloadOnly = ['implementations', 'flash']

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
 * Implementation form (create / edit)
 * --------------------------------------------------------------------------
 * The form, its cascade watchers and the unsaved-changes guard live in
 * ImplementationFormDrawer; the page only opens it and says what a save
 * reloads.
 */

const implFormDrawer = ref<InstanceType<typeof ImplementationFormDrawer> | null>(null)

function openImpl(item?: Implementation) {
    implFormDrawer.value?.open(item)
}

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
 * --------------------------------------------------------------------------
 * Filters
 * --------------------------------------------------------------------------
 * Four dropdowns over the searched set: competency type, competency, business
 * unit and grade. They narrow the same client-side list the search box does,
 * so every one of them takes effect immediately.
 *
 * Each dropdown's options are derived from the mappings that are actually
 * listed — not from the corporate masters — because these filter a list rather
 * than fill a form: an option that matches no row would be a dead end, and the
 * count beside each one says how many rows picking it shows.
 *
 * The competency type is `null` for "all types" and 0 for the bucket of
 * mappings that carry none.
 */

const selectedTypeFilter = ref<number | null>(null)
const selectedCompetencyFilter = ref<number | null>(null)
const selectedBusinessUnitFilter = ref('')
const selectedGradeFilter = ref('')

// Keep the chosen type valid as types are added/removed.
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

// Picking a type drops a competency that does not belong to it, so the two
// dropdowns can never describe an empty intersection.
watch(selectedTypeFilter, (typeId) => {
    if (typeId === null || selectedCompetencyFilter.value === null) return

    const row = labelledImpls.value.find(
        (r) => r.competency_id === selectedCompetencyFilter.value,
    )
    const rowType = row?.competency_type_id ?? 0

    if (rowType !== typeId) selectedCompetencyFilter.value = null
})

const hasFilters = computed(
    () =>
        selectedTypeFilter.value !== null ||
        selectedCompetencyFilter.value !== null ||
        selectedBusinessUnitFilter.value !== '' ||
        selectedGradeFilter.value !== '',
)

function clearFilters() {
    selectedTypeFilter.value = null
    selectedCompetencyFilter.value = null
    selectedBusinessUnitFilter.value = ''
    selectedGradeFilter.value = ''
    implPage.value = 1
}

// Every filter sends the reader back to page one: two selections may leave the
// same number of rows, so the length watcher below cannot be relied on.
watch(
    [
        selectedTypeFilter,
        selectedCompetencyFilter,
        selectedBusinessUnitFilter,
        selectedGradeFilter,
    ],
    () => (implPage.value = 1),
)

type LabelledImpl = (typeof labelledImpls.value)[number]

// The searched set with every filter but one applied — the basis for that
// one's option counts, and for its own filtering.
function narrowed(except: 'type' | 'competency' | 'businessUnit' | 'grade' | null) {
    return searchedImpls.value.filter((row) => {
        if (except !== 'type' && selectedTypeFilter.value !== null) {
            const rowType = row.competency_type_id ?? 0
            if (rowType !== selectedTypeFilter.value) return false
        }

        if (
            except !== 'competency' &&
            selectedCompetencyFilter.value !== null &&
            row.competency_id !== selectedCompetencyFilter.value
        ) {
            return false
        }

        if (
            except !== 'businessUnit' &&
            selectedBusinessUnitFilter.value !== '' &&
            !(row.business_units ?? []).includes(selectedBusinessUnitFilter.value)
        ) {
            return false
        }

        if (
            except !== 'grade' &&
            selectedGradeFilter.value !== '' &&
            !(row.grades ?? []).includes(selectedGradeFilter.value)
        ) {
            return false
        }

        return true
    })
}

// --- the four option lists ---

const typeFilterOptions = computed<Option[]>(() => {
    const rows = narrowed('type')
    const counts = new Map<number, number>()

    for (const row of rows) {
        const key = row.competency_type_id ?? 0
        counts.set(key, (counts.get(key) ?? 0) + 1)
    }

    const options: Option[] = [
        { value: '', label: t.value.idp.settings.allTypes },
    ]

    // A type no remaining mapping carries is left off — it would return an
    // empty list. The one currently picked always stays, so choosing it does
    // not make it vanish from its own dropdown.
    for (const ct of props.competencyTypes) {
        if (!counts.get(ct.id) && selectedTypeFilter.value !== ct.id) continue

        options.push({ value: String(ct.id), label: masterName(ct) })
    }

    // The untyped bucket only exists while some mapping is in it.
    if (counts.get(0) || selectedTypeFilter.value === 0) {
        options.push({ value: '0', label: t.value.idp.settings.untyped })
    }

    return options
})

const competencyFilterOptions = computed<Option[]>(() => {
    const labels = new Map<number, string>()

    for (const row of narrowed('competency')) {
        if (row.competency_id == null || labels.has(row.competency_id)) continue

        labels.set(
            row.competency_id,
            row.competency_code
                ? `${row.competency_code} — ${row.competency_name}`
                : row.competency_name || String(row.competency_id),
        )
    }

    return [
        { value: '', label: t.value.idp.settings.allCompetencies },
        ...[...labels.entries()]
            .sort((a, b) => a[1].localeCompare(b[1]))
            .map(([id, label]) => ({ value: String(id), label })),
    ]
})

// Business units and grades are lists on a mapping, so one row feeds several
// options. Every distinct value the remaining mappings cover, sorted.
function listFilterOptions(
    rows: LabelledImpl[],
    pick: (row: LabelledImpl) => string[],
    allLabel: string,
): Option[] {
    const values = new Set<string>()

    for (const row of rows) {
        for (const value of pick(row) ?? []) values.add(value)
    }

    return [
        { value: '', label: allLabel },
        ...[...values].sort((a, b) => a.localeCompare(b)).map((value) => ({
            value,
            label: value,
        })),
    ]
}

const businessUnitFilterOptions = computed<Option[]>(() =>
    listFilterOptions(
        narrowed('businessUnit'),
        (row) => row.business_units ?? [],
        t.value.idp.settings.allBusinessUnits,
    ),
)

const gradeFilterOptions = computed<Option[]>(() =>
    listFilterOptions(
        narrowed('grade'),
        (row) => row.grades ?? [],
        t.value.idp.settings.allGrades,
    ),
)

/**
 * The type column only earns its place while every type is shown: once one is
 * picked, the dropdown above already names the type every row carries.
 */
const showTypeColumn = computed(() => selectedTypeFilter.value === null)

const implRows = computed(() => narrowed(null))

// --- sorting (competency / type / when it was made or last touched) ---

type ImplSortKey = 'competency_name' | 'type_name' | 'created_at' | 'updated_at'

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

        // The timestamps are ISO strings, which sort chronologically as text.
        // A row with none sorts last whichever way the column runs, the same
        // convention the untyped bucket follows.
        if (within === 'created_at' || within === 'updated_at') {
            const av = a[within] ?? ''
            const bv = b[within] ?? ''

            if (av === '' || bv === '') return av === bv ? 0 : (av === '' ? 1 : -1)

            return av.localeCompare(bv) * sign
        }

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
 * --------------------------------------------------------------------------
 * A compact row, with the whole mapping one click away
 * --------------------------------------------------------------------------
 * A mapping used to render one line per proficiency level, each carrying that
 * rung's description, beside a cell of grade chips and a cell of business-unit
 * chips — a single row could run half a screen.
 *
 * So the row is capped rather than collapsed. Every list cell shows the first
 * few values and counts the rest, which keeps every row the same height and
 * the table scannable; the full mapping — every rung with what it means, every
 * grade, every unit — opens in a read-only drawer from the row's "View detail"
 * button. That is the same drawer pattern the rest of this app uses to show a
 * record, so there is nothing new to learn and nothing hidden behind an
 * affordance a reader has to discover.
 */

// How many chips a list cell shows before it counts the remainder. Three fits
// one line at these column widths.
const CHIP_LIMIT = 3

const shownChips = (list: string[] | undefined) => (list ?? []).slice(0, CHIP_LIMIT)

const hiddenChips = (list: string[] | undefined) =>
    Math.max(0, (list ?? []).length - CHIP_LIMIT)

// "+2 more", for the tail a capped cell does not show.
function moreLabel(count: number): string {
    return t.value.idp.settings.moreItems.replace('{n}', String(count))
}

/**
 * The proficiency levels of one mapping, in the shape ProficiencyLevelCell
 * reads — so a rung is laid out in the detail drawer exactly as it is on the
 * Master Competency list: badge, code, name, then what it means.
 */
function levelLines(levels: ProficiencyLevel[]) {
    return levels.map((level) => ({
        id: level.id,
        name: masterName(level),
        sequence: level.sequence,
        code: level.code,
        description: rowDescription(level) || null,
        active: level.is_active,
    }))
}

// The mappings on the current page.
const implBlocks = computed(() => {
    if (implPage.value > implTotalPages.value) {
        implPage.value = implTotalPages.value
    }

    const start = (implPage.value - 1) * implPerPage.value

    const blocks = sortedImpls.value
        .slice(start, start + implPerPage.value)
        .map((row) => ({
            ...row,
            lines: levelLines(row.levels),
            // Filled in below: how many rows this block's type cell spans. 0 on
            // every block but the first of its group, which is what merges the
            // column.
            typeRowspan: 0,
        }))

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
            span += 1
            j++
        }

        blocks[i].typeRowspan = span
        i = j
    }

    return blocks
})

/**
 * --------------------------------------------------------------------------
 * Detail drawer (read-only)
 * --------------------------------------------------------------------------
 * Holds a row's own value, not an id, so it keeps rendering while the drawer
 * closes. It is read-only, so — like the activation history and the approval
 * chain — it raises no unsaved-changes prompt.
 */

type ImplBlock = (typeof implBlocks.value)[number]

const detailImpl = ref<ImplBlock | null>(null)

function openDetail(row: ImplBlock) {
    detailImpl.value = row
}

// Straight from reading a mapping to changing it, without going back to the
// row to find the pencil.
function editFromDetail() {
    const row = detailImpl.value
    detailImpl.value = null

    if (row) openImpl(row)
}

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate a mapping + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the mapping and its scope; it only stops the mapping
 * applying from now on. Who flipped it is recorded in the audit log on disk,
 * which the history drawer reads back.
 */

const { pendingToggle, togglingId, requestToggle, confirmToggle, cancelToggle } =
    useActiveStateToggle(reloadOnly)

function toggleActive(row: Implementation) {
    requestToggle({
        id: row.id,
        activating: !row.is_active,
        url: route('idp.setting.implementations.active', row.id),
        // A mapping has no name of its own — the competency it maps labels it.
        name: implLabel(row),
    })
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

const { pendingDelete, deleting, confirmDelete, cancelDelete } = useDeleteConfirm(reloadOnly)

function deleteImpl(row: { id: number; competency_name: string }) {
    pendingDelete.value = {
        url: route('idp.setting.implementations.destroy', row.id),
        name: row.competency_name,
    }
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

                <!-- Filter bar. Four dropdowns, each counting what picking
                     it would leave, and each narrowing the other three. -->
                <div class="border-b border-border/60 bg-slate-50/40 px-5 py-3">
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {{ t.idp.settings.competencyType }}
                            </label>
                            <SearchableSelect
                                :model-value="
                                    selectedTypeFilter === null ? '' : String(selectedTypeFilter)
                                "
                                :options="typeFilterOptions"
                                :placeholder="t.idp.settings.allTypes"
                                @update:model-value="
                                    selectedTypeFilter = $event === '' ? null : Number($event)
                                "
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {{ t.idp.settings.competency }}
                            </label>
                            <SearchableSelect
                                :model-value="
                                    selectedCompetencyFilter === null
                                        ? ''
                                        : String(selectedCompetencyFilter)
                                "
                                :options="competencyFilterOptions"
                                :placeholder="t.idp.settings.allCompetencies"
                                @update:model-value="
                                    selectedCompetencyFilter = $event === '' ? null : Number($event)
                                "
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {{ t.idp.settings.businessUnit }}
                            </label>
                            <SearchableSelect
                                v-model="selectedBusinessUnitFilter"
                                :options="businessUnitFilterOptions"
                                :placeholder="t.idp.settings.allBusinessUnits"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                {{ t.idp.settings.grade }}
                            </label>
                            <SearchableSelect
                                v-model="selectedGradeFilter"
                                :options="gradeFilterOptions"
                                :placeholder="t.idp.settings.allGrades"
                            />
                        </div>
                    </div>

                    <!-- Only shown once something is filtered, so the bar does
                         not carry a permanently dead control. -->
                    <div v-if="hasFilters" class="mt-2.5 flex items-center gap-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                            @click="clearFilters"
                        >
                            <i class="fa-solid fa-xmark text-[10px]" />
                            {{ t.idp.settings.clearFilters }}
                        </button>
                        <span class="text-xs text-slate-400">
                            {{ t.idp.settings.matchCount.replace('{n}', String(implRows.length)) }}
                        </span>
                    </div>
                </div>

                <!-- Implementation table — one row per mapping, every list
                     cell capped so rows stay a uniform height. The whole
                     mapping opens in the detail drawer. -->
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
                                <th class="w-48 px-4 py-2.5 font-semibold">
                                    {{ t.idp.settings.businessUnit }}
                                </th>
                                <th class="w-40 px-4 py-2.5 font-semibold">
                                    {{ t.idp.settings.grade }}
                                </th>
                                <th
                                    class="w-28 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                    @click="toggleImplSort('created_at')"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        {{ t.idp.settings.createdAt }}
                                        <i
                                            class="fa-solid text-[10px]"
                                            :class="implSort.key === 'created_at'
                                                ? (implSort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                                : 'fa-sort text-slate-300'"
                                        />
                                    </span>
                                </th>
                                <th
                                    class="w-28 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                    @click="toggleImplSort('updated_at')"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        {{ t.idp.settings.updatedAt }}
                                        <i
                                            class="fa-solid text-[10px]"
                                            :class="implSort.key === 'updated_at'
                                                ? (implSort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                                : 'fa-sort text-slate-300'"
                                        />
                                    </span>
                                </th>
                                <!-- Status sits in this column too: the badge
                                     is itself the on/off control, so it belongs
                                     with view, edit and delete. -->
                                <th class="w-52 px-4 py-2.5 text-right font-semibold">
                                    {{ t.idp.settings.action }}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr
                                v-for="block in implBlocks"
                                :key="block.id"
                                class="border-b border-border/60 transition hover:bg-slate-50/70"
                            >
                                <!-- Painted once per RUN of mappings sharing a
                                     type, so the column merges. -->
                                <td
                                    v-if="showTypeColumn && block.typeRowspan > 0"
                                    :rowspan="block.typeRowspan"
                                    class="border-r border-border/40 bg-slate-50/40 px-4 py-3 align-top"
                                >
                                    <!-- Code on its own line above the name.
                                         The chip is inline and the name a
                                         block, which is what breaks the line,
                                         so a row with no code leaves no gap. -->
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

                                <!-- The competency's own code sits above its
                                     name, with the rungs it maps counted
                                     underneath — the one thing the row cannot
                                     show in full, so it says how much there is
                                     and the detail drawer shows it. -->
                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <span
                                        v-if="block.competency_code"
                                        class="mb-1 inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                    >
                                        {{ block.competency_code }}
                                    </span>
                                    <div class="font-semibold text-slate-800">
                                        {{ block.competency_name || '—' }}
                                    </div>
                                    <button
                                        type="button"
                                        class="mt-1 text-xs text-primary underline-offset-2 hover:underline"
                                        @click="openDetail(block)"
                                    >
                                        {{
                                            block.lines.length
                                                ? t.idp.settings.levelCount.replace('{n}', String(block.lines.length))
                                                : t.idp.settings.noProficiencyLevel
                                        }}
                                    </button>
                                </td>

                                <!-- Capped list cells: the first few values,
                                     then how many more the detail drawer has. -->
                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <div v-if="block.business_units?.length" class="flex flex-wrap gap-1">
                                        <span
                                            v-for="bu in shownChips(block.business_units)"
                                            :key="bu"
                                            class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                                        >
                                            {{ bu }}
                                        </span>
                                        <button
                                            v-if="hiddenChips(block.business_units)"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/5"
                                            :title="block.business_units.join(', ')"
                                            @click="openDetail(block)"
                                        >
                                            {{ moreLabel(hiddenChips(block.business_units)) }}
                                        </button>
                                    </div>
                                    <span v-else class="text-xs italic text-slate-300">—</span>
                                </td>

                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <div v-if="block.grades?.length" class="flex flex-wrap gap-1">
                                        <span
                                            v-for="grade in shownChips(block.grades)"
                                            :key="grade"
                                            class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                                        >
                                            {{ grade }}
                                        </span>
                                        <button
                                            v-if="hiddenChips(block.grades)"
                                            type="button"
                                            class="rounded px-1.5 py-0.5 text-xs font-medium text-primary hover:bg-primary/5"
                                            :title="block.grades.join(', ')"
                                            @click="openDetail(block)"
                                        >
                                            {{ moreLabel(hiddenChips(block.grades)) }}
                                        </button>
                                    </div>
                                    <span v-else class="text-xs italic text-slate-300">—</span>
                                </td>

                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <span
                                        class="whitespace-nowrap text-slate-500"
                                        :title="formatDateTime(block.created_at)"
                                    >
                                        {{ formatDateTime(block.created_at) }}
                                    </span>
                                </td>

                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <span
                                        class="whitespace-nowrap text-slate-500"
                                        :title="formatDateTime(block.updated_at)"
                                    >
                                        {{ formatDateTime(block.updated_at) }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-col items-end gap-2">
                                        <ActiveStateCell
                                            :active="block.is_active"
                                            :busy="togglingId === block.id"
                                            @toggle="toggleActive(block)"
                                            @history="openHistory(block)"
                                        />

                                        <div class="flex items-center gap-1">
                                            <IconButton
                                                icon="fa-solid fa-eye"
                                                :title="t.idp.settings.viewDetail"
                                                @click="openDetail(block)"
                                            />
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

                            <tr v-if="implBlocks.length === 0">
                                <td
                                    :colspan="showTypeColumn ? 7 : 6"
                                    class="px-4 py-8 text-center text-slate-400"
                                >
                                    {{
                                        implSearch || hasFilters
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
             IMPLEMENTATION MODAL (+ its unsaved-changes confirmation)
        ================================================================= -->

        <ImplementationFormDrawer
            ref="implFormDrawer"
            :competency-types="competencyTypes"
            :competencies="competencies"
            :proficiency-levels="proficiencyLevels"
            :grades="grades"
            :business-units="businessUnits"
            :reload-only="reloadOnly"
        />

        <!-- ================================================================
             DETAIL (read-only)
        ================================================================= -->

        <ImplementationDetailDrawer
            :impl="detailImpl"
            @close="detailImpl = null"
            @edit="editFromDetail"
        />

        <!-- ================================================================
             ACTIVATION HISTORY
        ================================================================= -->

        <!-- Activating / deactivating writes on the spot, so the badge asks. -->
        <ConfirmActiveStateDialog
            :target="pendingToggle"
            :processing="togglingId !== null"
            @confirm="confirmToggle"
            @close="cancelToggle"
        />

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
            @close="cancelDelete"
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
