<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import ConfirmActiveStateDialog from '@/Components/Domain/ConfirmActiveStateDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import { useActiveStateToggle } from '@/Composables/useActiveStateToggle'
import { route } from '@/Config/route'

const { t, locale } = useLocale()

interface Competency {
    id: number
    value: string
    // The competency's short identifier. Null on rows that predate the column.
    code: string | null
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    // Inactive competencies stay listed here but are not offered on new IDP
    // items, and cannot be mapped by a master implementation.
    is_active: boolean
    // The competency's own proficiency ladder, already ordered by sequence,
    // each rung carrying the behaviors observed at it. Typed in on the form,
    // not picked from a master.
    proficiency_levels: ProficiencyLevel[]
    sub_competencies: SubCompetency[]
}

interface CompetencyType {
    id: number
    value: string
    // The type's short identifier. Null on types that predate the column.
    code: string | null
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competencies_count: number
}

interface KeyBehavior {
    id: number
    name_en: string
    name_id: string | null
    sequence: number
}

interface ProficiencyLevel {
    id: number
    // The rung's short identifier, unique within this competency. Null on the
    // rows that predate the column — the form requires one from now on.
    code: string | null
    name_en: string
    name_id: string | null
    description_en: string | null
    description_id: string | null
    sequence: number
    // A rung can be switched off without losing it; the list badges it.
    is_active: boolean
    key_behaviors: KeyBehavior[]
}

interface SubCompetency {
    id: number
    name_en: string
    name_id: string | null
    description_en: string | null
    description_id: string | null
}

const props = defineProps<{
    competencies: Competency[]
    competencyTypes: CompetencyType[]
}>()

/**
 * After a create/update/delete the server redirects back here. Restricting the
 * reload to this page's own data (+ flash) turns every save into an Inertia
 * partial reload, so the expensive shared props (corporate employee lookup,
 * approval counts, notification feed) are not re-evaluated on each mutation.
 */
const reloadOnly = ['competencies', 'flash']

// Localized name for a competency / competency type, falling back to the
// canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized competency description (falls back to the other language).
function competencyDescription(c: Competency): string {
    const preferred = locale.value === 'id' ? c.description_id : c.description_en
    const fallback = locale.value === 'id' ? c.description_en : c.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

/**
 * --------------------------------------------------------------------------
 * Writes done from the list
 * --------------------------------------------------------------------------
 * Add/edit happens on its own page (/master-data/master-competency/create and
 * /{id}/edit), so the list only links to it. What stays here is what belongs
 * to a row rather than to a form: deleting one and switching one on/off. Both
 * post to the shared master endpoints, which redirect back to this list.
 */

const MASTER_TYPE = 'competency_name'

const editUrl = (id: number) => route('master_data.competency.edit', id)

function deleteMaster(id: number, name?: string) {
    pendingDelete.value = {
        url: route('idp.setting.masters.destroy', [MASTER_TYPE, id]),
        name,
    }
}

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

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

/**
 * --------------------------------------------------------------------------
 * Competency types
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const ct of props.competencyTypes) m.set(ct.id, ct)
    return m
})

// Localized name for the competency type linked to a competency (or '').
function competencyTypeName(id: number | null): string {
    if (id == null) return ''
    const ct = competencyTypeById.value.get(id)
    return ct ? masterName(ct) : ''
}

// The linked type's short code (or '' when it has none / is untyped).
function competencyTypeCode(id: number | null): string {
    if (id == null) return ''
    return competencyTypeById.value.get(id)?.code ?? ''
}

/**
 * Localized name for one of the competency's own nested rows. They store
 * `name_en` / `name_id` (the DB's own field names) rather than the masters'
 * `value` / `value_en`, so they need their own reader.
 */
// The row's description in the reading language, falling back to the other —
// same rule as rowName() below.
function rowDescription(row: {
    description_en?: string | null
    description_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? row.description_id : row.description_en
    const fallback = locale.value === 'id' ? row.description_en : row.description_id

    return (preferred || fallback || '').trim()
}

function rowName(row: { name_en: string; name_id?: string | null }): string {
    const preferred = locale.value === 'id' ? row.name_id : row.name_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : row.name_en
}

/**
 * The competency type the list is showing. The list is split by type into a
 * tab strip — null is the "all types" tab, 0 the bucket of competencies that
 * have no type at all.
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

// Competencies with no type assigned. Read off the whole set rather than the
// searched one, so the "Untyped" tab does not appear and vanish while typing.
const untypedCompetencyCount = computed(
    () => props.competencies.filter((c) => c.competency_type_id == null).length,
)

/**
 * --------------------------------------------------------------------------
 * Competency table — search + type tab (external) → sort + pages (below)
 * --------------------------------------------------------------------------
 */

const competencySearch = ref('')

// Everything the search matches, before the type tab narrows it. The tab
// counts are taken from here, so each tab's number is exactly how many rows
// opening it would show.
const searchedCompetencies = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()
    if (!q) return props.competencies

    return props.competencies.filter(
        (c) =>
            masterName(c).toLowerCase().includes(q) ||
            c.value.toLowerCase().includes(q) ||
            (c.code ?? '').toLowerCase().includes(q),
    )
})

/**
 * One option per competency type, plus "All" and — when there are any — the
 * untyped bucket.
 *
 * SearchableSelect binds a string, so the three kinds of choice are encoded:
 * '' is every type, '0' the untyped bucket, and any other value a type id.
 */
const typeOptions = computed<Option[]>(() => {
    const options: Option[] = [
        { value: '', label: t.value.idp.settings.allTypes },
        ...props.competencyTypes.map((ct) => ({
            value: String(ct.id),
            label: masterName(ct),
        })),
    ]

    if (untypedCompetencyCount.value) {
        options.push({ value: '0', label: t.value.idp.settings.untyped })
    }

    return options
})

const typeFilterValue = computed<string>({
    get: () => (selectedTypeFilter.value === null ? '' : String(selectedTypeFilter.value)),
    set: (value) => selectType(value === '' ? null : Number(value)),
})

function selectType(key: number | null) {
    selectedTypeFilter.value = key
    // Two types may hold the same number of competencies, so the length watcher
    // below cannot be relied on to send the reader back to page one.
    competencyPage.value = 1
}

/**
 * The type column only earns its place on the "All" tab: on any other the tab
 * itself already names the type every row carries.
 */
const showTypeColumn = computed(() => selectedTypeFilter.value === null)

// Rows carry the derived localized name + type name the sorter reads; the
// original fields remain (spread) so the cell slots' helpers keep working.
const competencyRows = computed(() => {
    const typeFilter = selectedTypeFilter.value

    return searchedCompetencies.value
        .filter((c) => {
            if (typeFilter === null) return true
            // 0 = the untyped bucket.
            return typeFilter === 0
                ? c.competency_type_id == null
                : c.competency_type_id === typeFilter
        })
        .map((c) => ({
            ...c,
            name: masterName(c),
            type_name: competencyTypeName(c.competency_type_id),
            type_code: competencyTypeCode(c.competency_type_id),
        }))
})

/**
 * The competency table is a grouped (rowspan) grid rather than a ClientTable:
 * one competency owns a block of rows — one row per key behavior, those rows
 * grouped by the proficiency level the behavior sits under — so the ladder
 * reads as a nested table. Competency / description / type / actions are
 * single cells spanning the whole block.
 *
 * Sorting and paging therefore work on competencies, not on rendered rows,
 * which is why they live here instead of coming from ClientTable.
 */

// "3 proficiency levels" / "6 key behaviors" — the same {n} convention
// Master Implementation's row summary uses.
const levelCountLabel = (n: number) =>
    t.value.idp.settings.levelCount.replace('{n}', String(n))

const behaviorCountLabel = (n: number) =>
    t.value.idp.settings.behaviorCount.replace('{n}', String(n))

/**
 * How many rungs a competency's ladder has, and how many key behaviors sit
 * under them in total. Printed under the competency's name, so a row says
 * what the detail drawer holds before it is opened.
 */
function ladderSize(row: { proficiency_levels: ProficiencyLevel[] }) {
    const levels = row.proficiency_levels ?? []

    return {
        levelCount: levels.length,
        behaviorCount: levels.reduce(
            (n, level) => n + (level.key_behaviors ?? []).length,
            0,
        ),
    }
}

// --- sorting (competency / type) ---

const competencySort = ref<{ key: 'name' | 'type_name'; dir: 'asc' | 'desc' }>({
    key: 'name',
    dir: 'asc',
})

function toggleCompetencySort(key: 'name' | 'type_name') {
    const s = competencySort.value
    competencySort.value =
        s.key === key
            ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
            : { key, dir: 'asc' }
    competencyPage.value = 1
}

/**
 * On the "All" tab the type column merges the competencies that share a type
 * into one cell, so the list reads as a type at a time. That only works if
 * they are adjacent, so the type is the PRIMARY sort there and whatever
 * column the reader clicked orders the competencies inside it. Untyped rows
 * sort last, whichever way the type runs.
 */
const sortedCompetencies = computed(() => {
    const { key, dir } = competencySort.value
    const sign = dir === 'asc' ? 1 : -1
    const groupByType = showTypeColumn.value

    const text = (v: unknown) => String(v ?? '')

    return [...competencyRows.value].sort((a, b) => {
        if (groupByType) {
            // '￿' sorts after any real name, which parks the untyped
            // bucket at the end of an ascending list.
            const ta = a.type_name || '￿'
            const tb = b.type_name || '￿'
            // Clicking the type header is the only thing that reverses the
            // groups; sorting by code or name reorders within them.
            const byType = ta.localeCompare(tb) * (key === 'type_name' ? sign : 1)

            if (byType !== 0) return byType
        }

        // The type is already settled by the time we get here, so it cannot
        // also be the tiebreak — fall back to the name.
        const within = key === 'type_name' && groupByType ? 'name' : key

        return text(a[within]).localeCompare(text(b[within])) * sign
    })
})

// --- paging (one row per competency) ---

const competencyPage = ref(1)
const competencyPerPage = ref(10)

const competencyTotalPages = computed(() =>
    Math.max(1, Math.ceil(sortedCompetencies.value.length / competencyPerPage.value)),
)

// Any change to the filtered set sends the user back to the first page.
watch(() => competencyRows.value.length, () => (competencyPage.value = 1))

const competencyFrom = computed(() =>
    sortedCompetencies.value.length
        ? (competencyPage.value - 1) * competencyPerPage.value + 1
        : 0,
)

const competencyTo = computed(() =>
    Math.min(competencyPage.value * competencyPerPage.value, sortedCompetencies.value.length),
)

// The competencies on the current page, one rendered row each.
const competencyBlocks = computed(() => {
    if (competencyPage.value > competencyTotalPages.value) {
        competencyPage.value = competencyTotalPages.value
    }

    const start = (competencyPage.value - 1) * competencyPerPage.value

    const blocks = sortedCompetencies.value
        .slice(start, start + competencyPerPage.value)
        .map((row) => ({
            ...row,
            ...ladderSize(row),
            // Filled in below: how many rows this block's type cell spans. 0
            // on every block but the first of its group, which is what merges
            // the column.
            typeRowspan: 0,
        }))

    /**
     * Merge the type column over each run of competencies sharing a type.
     * The run is taken from the PAGE, not the whole list, so a group split
     * across pages simply merges as far as each page goes.
     */
    for (let i = 0; i < blocks.length; ) {
        const type = blocks[i].competency_type_id
        let span = 0
        let j = i

        while (j < blocks.length && blocks[j].competency_type_id === type) {
            span++
            j++
        }

        blocks[i].typeRowspan = span
        i = j
    }

    return blocks
})

/**
 * --------------------------------------------------------------------------
 * The detail drawer
 * --------------------------------------------------------------------------
 * The ladder used to be two merged columns in the table, which made a row as
 * tall as its longest rung. It is read on demand now: the row says how big
 * the ladder is, and this drawer lays it out — each rung as the shared cell
 * renders it everywhere else, with its key behaviors underneath.
 *
 * Read-only: editing is the form page's job, which the drawer links to.
 */
const detailCompetency = ref<Competency | null>(null)

// The rungs of the open competency, each with its behaviors numbered. Both
// sequences are server-assigned 1..n, so a gap would be a bug — but fall back
// to the row's position anyway, as the table did.
const detailLevels = computed(() =>
    (detailCompetency.value?.proficiency_levels ?? []).map((level, position) => ({
        id: level.id,
        name: rowName(level),
        code: level.code,
        sequence: level.sequence || position + 1,
        description: rowDescription(level) || null,
        active: level.is_active,
        behaviors: (level.key_behaviors ?? []).map((kb, n) => ({
            id: kb.id,
            name: rowName(kb),
            sequence: kb.sequence || n + 1,
        })),
    })),
)

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate a competency + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the competency and everything referencing it; it only
 * stops it being offered on new IDP items and mapped by a master
 * implementation. Who flipped it is recorded in the audit log on disk, which
 * the history drawer reads back.
 */

const { pendingToggle, togglingId, requestToggle, confirmToggle, cancelToggle } =
    useActiveStateToggle(reloadOnly)

function toggleActive(competency: Competency) {
    requestToggle({
        id: competency.id,
        activating: !competency.is_active,
        url: route('idp.setting.masters.active', [MASTER_TYPE, competency.id]),
        name: masterName(competency),
    })
}

const historyCompetency = ref<Competency | null>(null)

function openHistory(competency: Competency) {
    historyCompetency.value = competency
}

function changeCompetencyPerPage(size: number) {
    competencyPerPage.value = size
    competencyPage.value = 1
}
</script>

<template>
    <Head :title="t.idp.settings.competencyTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.competencyTitle"
            :subtitle="t.idp.settings.competencySubtitle"
        />

        <!-- ----------------------------------------------------------------
             Competencies card: header + toolbar + table
        ----------------------------------------------------------------- -->
        <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                <div>
                    <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                        {{ t.idp.settings.competencies }}
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                            {{ competencies.length }}
                        </span>
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.competenciesHint }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="competencySearch"
                            type="search"
                            :placeholder="t.idp.settings.searchCompetency"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <!-- Competency type filter. -->
                    <div class="w-56">
                        <SearchableSelect
                            v-model="typeFilterValue"
                            :options="typeOptions"
                            :placeholder="t.idp.settings.allTypes"
                        />
                    </div>

                    <Link
                        :href="route('master_data.competency.create')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competency }}
                    </Link>
                </div>
            </div>

            <!-- Competency table — one row per competency. The ladder is
                 read in the detail drawer, not down the row. -->
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
                                @click="toggleCompetencySort('type_name')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    {{ t.idp.settings.competencyType }}
                                    <i
                                        class="fa-solid text-[10px]"
                                        :class="competencySort.key === 'type_name'
                                            ? (competencySort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                            : 'fa-sort text-slate-300'"
                                    />
                                </span>
                            </th>
                            <!-- The competency's code rides in this cell, in
                                 front of its name, rather than in a column of
                                 its own. -->
                            <th
                                class="w-72 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                @click="toggleCompetencySort('name')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    {{ t.idp.settings.competency }}
                                    <i
                                        class="fa-solid text-[10px]"
                                        :class="competencySort.key === 'name'
                                            ? (competencySort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                            : 'fa-sort text-slate-300'"
                                    />
                                </span>
                            </th>
                            <th class="px-4 py-2.5 font-semibold">
                                {{ t.idp.settings.description }}
                            </th>
                            <!-- Status sits in this column too: the badge is
                                 itself the on/off control, so it belongs with
                                 edit and delete rather than in a column of
                                 its own. -->
                            <th class="w-44 px-4 py-2.5 text-right font-semibold">
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            v-for="block in competencyBlocks"
                            :key="block.id"
                        >
                            <tr class="border-b border-border/60 transition hover:bg-slate-50/70">
                                <!-- Painted once per RUN of competencies
                                     sharing a type, so the column merges. -->
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

                                <!-- The competency's own code sits above
                                     its name; the ladder's size sits under
                                     it, as a link into the detail drawer. -->
                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <span
                                        v-if="block.code"
                                        class="mb-1 inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                    >
                                        {{ block.code }}
                                    </span>
                                    <div class="font-semibold text-slate-800">
                                        {{ block.name }}
                                    </div>

                                    <button
                                        v-if="block.levelCount > 0"
                                        type="button"
                                        class="mt-1 inline-flex items-center gap-1.5 text-xs text-slate-400 transition hover:text-primary"
                                        @click="detailCompetency = block as unknown as Competency"
                                    >
                                        <i class="fa-solid fa-layer-group text-[10px]" />
                                        {{ levelCountLabel(block.levelCount) }}
                                        <span class="text-slate-300">·</span>
                                        {{ behaviorCountLabel(block.behaviorCount) }}
                                    </button>
                                    <p v-else class="mt-1 text-xs italic text-slate-300">
                                        {{ t.idp.settings.noProficiencyLevel }}
                                    </p>
                                </td>

                                <td class="border-r border-border/40 px-4 py-3 align-top">
                                    <span
                                        v-if="competencyDescription(block as unknown as Competency)"
                                        class="whitespace-pre-wrap break-words text-slate-500"
                                    >
                                        {{ competencyDescription(block as unknown as Competency) }}
                                    </span>
                                    <span v-else class="text-xs italic text-slate-300">
                                        {{ t.idp.settings.noDescription }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 align-top">
                                    <div class="flex flex-col items-end gap-2">
                                        <ActiveStateCell
                                            :active="block.is_active"
                                            :busy="togglingId === block.id"
                                            @toggle="toggleActive(block as unknown as Competency)"
                                            @history="openHistory(block as unknown as Competency)"
                                        />

                                        <div class="flex items-center gap-1">
                                            <IconButton
                                                icon="fa-solid fa-layer-group"
                                                :title="t.idp.settings.viewDetail"
                                                @click="detailCompetency = block as unknown as Competency"
                                            />
                                            <Link :href="editUrl(block.id)">
                                                <IconButton
                                                    icon="fa-solid fa-pen"
                                                    variant="edit"
                                                    :title="t.idp.settings.editCompetency"
                                                />
                                            </Link>
                                            <IconButton
                                                icon="fa-solid fa-trash"
                                                variant="delete"
                                                :title="t.idp.settings.deleteCompetency"
                                                @click="deleteMaster(block.id, block.name)"
                                            />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="competencyBlocks.length === 0">
                            <td :colspan="showTypeColumn ? 4 : 3" class="px-4 py-8 text-center text-slate-400">
                                {{
                                    competencySearch || selectedTypeFilter !== null
                                        ? t.idp.settings.noMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pager: one row per competency. -->
            <div
                v-if="competencyTotalPages > 1 || sortedCompetencies.length > 10"
                class="border-t border-border px-4 py-2.5 [&>div]:!mt-0"
            >
                <Pagination
                    :page="competencyPage"
                    :per-page="competencyPerPage"
                    :total="sortedCompetencies.length"
                    :from="competencyFrom"
                    :to="competencyTo"
                    @update:page="competencyPage = $event"
                    @update:per-page="changeCompetencyPerPage"
                />
            </div>
        </section>

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
            :show="historyCompetency !== null"
            :url="
                historyCompetency
                    ? route('idp.setting.masters.statusHistory', [MASTER_TYPE, historyCompetency.id])
                    : null
            "
            :name="historyCompetency ? masterName(historyCompetency) : ''"
            @close="historyCompetency = null"
        />

        <!-- ================================================================
             DETAIL (read-only)
        ================================================================= -->
        <!-- The competency's whole ladder, which a row can only ever say the
             size of. Same shape as Master Implementation's detail drawer, and
             read-only like it — so, like the activation history, it closes
             without asking. -->
        <Drawer
            :show="detailCompetency !== null"
            :title="t.idp.settings.competencyDetail"
            max-width="max-w-2xl"
            @close="detailCompetency = null"
        >
            <div v-if="detailCompetency" class="space-y-6">
                <!-- What it is -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.competency }}
                    </p>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <span
                            v-if="detailCompetency.code"
                            class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                        >
                            {{ detailCompetency.code }}
                        </span>
                        <span class="text-base font-semibold text-slate-800">
                            {{ masterName(detailCompetency) }}
                        </span>
                    </div>

                    <p
                        v-if="competencyDescription(detailCompetency)"
                        class="mt-2 whitespace-pre-wrap break-words text-sm text-slate-500"
                    >
                        {{ competencyDescription(detailCompetency) }}
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-border/60 pt-3">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            {{ t.idp.settings.competencyType }}
                        </span>
                        <span
                            v-if="competencyTypeCode(detailCompetency.competency_type_id)"
                            class="inline-flex items-center rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-indigo-700"
                        >
                            {{ competencyTypeCode(detailCompetency.competency_type_id) }}
                        </span>
                        <span
                            v-if="competencyTypeName(detailCompetency.competency_type_id)"
                            class="text-sm font-medium text-slate-700"
                        >
                            {{ competencyTypeName(detailCompetency.competency_type_id) }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">
                            {{ t.idp.settings.untyped }}
                        </span>

                        <span
                            class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                detailCompetency.is_active
                                    ? 'bg-emerald-50 text-emerald-600'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{
                                detailCompetency.is_active
                                    ? t.idp.settings.activeBadge
                                    : t.idp.settings.inactiveBadge
                            }}
                        </span>
                    </div>
                </div>

                <!-- What it breaks down into -->
                <div v-if="detailCompetency.sub_competencies?.length">
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.subCompetencies }}
                    </p>
                    <div class="space-y-2">
                        <div
                            v-for="sub in detailCompetency.sub_competencies"
                            :key="sub.id"
                            class="rounded-md border border-border/60 px-3 py-2"
                        >
                            <p class="text-sm font-medium text-slate-700">
                                {{ rowName(sub) }}
                            </p>
                            <p
                                v-if="rowDescription(sub)"
                                class="mt-0.5 whitespace-pre-line text-[11px] leading-snug text-slate-400"
                            >
                                {{ rowDescription(sub) }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- The ladder, in full — the part a row cannot carry -->
                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.proficiencyLevel }}
                    </p>

                    <div v-if="detailLevels.length" class="space-y-3">
                        <div
                            v-for="level in detailLevels"
                            :key="level.id"
                            class="rounded-md border border-border/60 px-3 py-2.5"
                        >
                            <ProficiencyLevelCell
                                :name="level.name"
                                :sequence="level.sequence"
                                :code="level.code"
                                :description="level.description"
                                :active="level.active"
                            />

                            <!-- The behaviors observed at this rung -->
                            <div class="mt-3 border-t border-border/60 pt-2.5">
                                <p
                                    v-if="level.behaviors.length === 0"
                                    class="text-xs italic text-slate-300"
                                >
                                    {{ t.idp.settings.noKeyBehaviorsYet }}
                                </p>

                                <ul v-else class="space-y-2">
                                    <li
                                        v-for="behavior in level.behaviors"
                                        :key="behavior.id"
                                        class="flex flex-wrap items-baseline gap-x-2 gap-y-1"
                                    >
                                        <span
                                            class="inline-flex items-center rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-semibold text-amber-700"
                                        >
                                            {{ t.idp.settings.keyBehavior }} -
                                            {{ behavior.sequence }}
                                        </span>
                                        <span class="text-sm text-slate-600">
                                            {{ behavior.name }}
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <p
                        v-else
                        class="rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                    >
                        {{ t.idp.settings.noProficiencyLevelsYet }}
                    </p>
                </div>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="detailCompetency = null"
                >
                    {{ t.idp.form.close }}
                </button>

                <Link
                    v-if="detailCompetency"
                    :href="editUrl(detailCompetency.id)"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover"
                >
                    {{ t.idp.settings.editCompetency }}
                </Link>
            </template>
        </Drawer>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->

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
