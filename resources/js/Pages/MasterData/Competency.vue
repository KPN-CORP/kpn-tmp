<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import { useLocale } from '@/Composables/useLocale'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
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

// Selected competency-type filter for the competencies table (null = all;
// 0 = the "untyped" bucket).
const selectedTypeFilter = ref<number | null>(null)

// Keep the filter valid as types are added/removed.
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

// Competencies with no type assigned (drives the "Untyped" filter option).
const untypedCompetencyCount = computed(
    () => props.competencies.filter((c) => c.competency_type_id == null).length,
)

// Bridge the numeric/null type filter to the string-valued <select> in the
// competency toolbar ('' = all, '0' = untyped bucket).
const typeFilterValue = computed<string>({
    get: () => (selectedTypeFilter.value === null ? '' : String(selectedTypeFilter.value)),
    set: (v) => (selectedTypeFilter.value = v === '' ? null : Number(v)),
})

/**
 * --------------------------------------------------------------------------
 * Competency table — search + type filter (external) → ClientTable (sort + pages)
 * --------------------------------------------------------------------------
 */

const competencySearch = ref('')

// Rows carry the derived localized name + type name ClientTable sorts on; the
// original fields remain (spread) so the cell slots' helpers keep working.
const competencyRows = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()
    const typeFilter = selectedTypeFilter.value

    return props.competencies
        .filter((c) => {
            // Competency-type filter (0 = untyped bucket).
            if (typeFilter !== null) {
                if (typeFilter === 0) {
                    if (c.competency_type_id != null) return false
                } else if (c.competency_type_id !== typeFilter) {
                    return false
                }
            }

            if (!q) return true
            return (
                masterName(c).toLowerCase().includes(q) ||
                c.value.toLowerCase().includes(q) ||
                (c.code ?? '').toLowerCase().includes(q)
            )
        })
        .map((c) => ({
            ...c,
            name: masterName(c),
            type_name: competencyTypeName(c.competency_type_id),
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

// One rendered <tr> of a competency block.
interface CompetencyLine {
    // Level cell: rendered only on the first line of its level group.
    levelName: string | null
    levelSequence: number | null
    levelActive: boolean
    levelRowspan: number
    // 2 when the competency has no levels at all — the empty level cell then
    // covers the key-behavior column too.
    levelColspan: number
    levelDescription: string | null
    behaviorName: string | null
}

// Build one competency's lines: its rungs in sequence, each expanded to one
// line per key behavior (a rung with no behaviors still gets a single line).
function linesFor(row: { proficiency_levels: ProficiencyLevel[] }): CompetencyLine[] {
    const levels = row.proficiency_levels ?? []

    if (levels.length === 0) {
        return [{
            levelName: null,
            levelSequence: null,
            levelActive: true,
            levelRowspan: 1,
            levelColspan: 2,
            levelDescription: null,
            behaviorName: null,
        }]
    }

    const lines: CompetencyLine[] = []

    for (const level of levels) {
        const behaviors = (level.key_behaviors ?? []).map((kb) => rowName(kb))
        const span = Math.max(1, behaviors.length)

        for (let i = 0; i < span; i++) {
            lines.push({
                // Only the group's first line paints the level cell.
                levelName: i === 0 ? rowName(level) : null,
                levelSequence: i === 0 ? level.sequence : null,
                levelActive: level.is_active,
                levelRowspan: i === 0 ? span : 0,
                levelColspan: 1,
                levelDescription: i === 0 ? rowDescription(level) || null : null,
                behaviorName: behaviors[i] ?? null,
            })
        }
    }

    return lines
}

// --- sorting (competency / type) ---

const competencySort = ref<{ key: 'code' | 'name' | 'type_name'; dir: 'asc' | 'desc' }>({
    key: 'name',
    dir: 'asc',
})

function toggleCompetencySort(key: 'code' | 'name' | 'type_name') {
    const s = competencySort.value
    competencySort.value =
        s.key === key
            ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' }
            : { key, dir: 'asc' }
    competencyPage.value = 1
}

const sortedCompetencies = computed(() => {
    const { key, dir } = competencySort.value
    const sign = dir === 'asc' ? 1 : -1

    return [...competencyRows.value].sort(
        (a, b) => String(a[key] ?? '').localeCompare(String(b[key] ?? '')) * sign,
    )
})

// --- paging (by competency, so a block is never split across pages) ---

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

// The competencies on the current page, each expanded into its rendered lines.
const competencyBlocks = computed(() => {
    if (competencyPage.value > competencyTotalPages.value) {
        competencyPage.value = competencyTotalPages.value
    }

    const start = (competencyPage.value - 1) * competencyPerPage.value

    return sortedCompetencies.value
        .slice(start, start + competencyPerPage.value)
        .map((row, i) => {
            const lines = linesFor(row)

            return {
                ...row,
                lines,
                rowspan: lines.length,
                // Running position across pages, matching ClientTable's "#".
                index: start + i + 1,
            }
        })
})

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate a competency + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the competency and everything referencing it; it only
 * stops it being offered on new IDP items and mapped by a master
 * implementation. Who flipped it is recorded in the audit log on disk, which
 * the history drawer reads back.
 */

const togglingId = ref<number | null>(null)

function toggleActive(competency: Competency) {
    router.put(
        route('idp.setting.masters.active', [MASTER_TYPE, competency.id]),
        { is_active: !competency.is_active },
        {
            preserveScroll: true,
            preserveState: true,
            only: reloadOnly,
            onStart: () => (togglingId.value = competency.id),
            onFinish: () => (togglingId.value = null),
        },
    )
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
                    <!-- Competency-type filter (replaces the old filter chips) -->
                <select
                    v-model="typeFilterValue"
                    class="rounded-md border border-border bg-white px-3 py-2 text-sm text-slate-600 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
                    <option value="">{{ t.idp.settings.allTypes }}</option>
                    <option
                        v-for="ct in competencyTypes"
                        :key="ct.id"
                        :value="String(ct.id)"
                    >
                        {{ masterName(ct) }}
                    </option>
                    <option v-if="untypedCompetencyCount" value="0">
                        {{ t.idp.settings.untyped }}
                    </option>
                </select>

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

                <Link
                    :href="route('master_data.competency.create')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.competency }}
                </Link>
            </div>
        </div>

            <!-- Competency table — grouped rows: one competency spans a
                 block, split by proficiency level and then key behavior. -->
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400"
                        >
                            <th class="w-14 px-4 py-2.5 text-center font-semibold">
                                #
                            </th>
                            <th
                                class="w-28 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
                                @click="toggleCompetencySort('code')"
                            >
                                <span class="inline-flex items-center gap-1">
                                    {{ t.idp.settings.code }}
                                    <i
                                        class="fa-solid text-[10px]"
                                        :class="competencySort.key === 'code'
                                            ? (competencySort.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                            : 'fa-sort text-slate-300'"
                                    />
                                </span>
                            </th>
                            <th
                                class="w-64 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
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
                            <th
                                class="w-48 cursor-pointer select-none px-4 py-2.5 font-semibold hover:text-slate-600"
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
                            <th class="w-52 px-4 py-2.5 font-semibold">
                                {{ t.idp.settings.status }}
                            </th>
                            <!-- The level column carries its key behaviors,
                                 so its header spans both sub-columns. -->
                            <th class="w-64 px-4 py-2.5 font-semibold" colspan="2">
                                {{ t.idp.settings.proficiencyLevel }}
                            </th>
                            <th class="w-28 px-4 py-2.5 text-right font-semibold">
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            v-for="block in competencyBlocks"
                            :key="block.id"
                        >
                            <tr
                                v-for="(line, i) in block.lines"
                                :key="i"
                                class="transition hover:bg-slate-50/70"
                                :class="i === block.lines.length - 1 ? 'border-b border-border/60' : ''"
                            >
                                <!-- Competency-wide cells: painted once, spanning the block. -->
                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 text-center align-top text-slate-400"
                                >
                                    {{ block.index }}
                                </td>

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                >
                                    <span
                                        v-if="block.code"
                                        class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                                    >
                                        {{ block.code }}
                                    </span>
                                    <span v-else class="text-xs italic text-slate-300">—</span>
                                </td>

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                >
                                    <span class="font-semibold text-slate-800">
                                        {{ block.name }}
                                    </span>
                                </td>

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                >
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

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                >
                                    <span
                                        v-if="block.type_name"
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                                    >
                                        <i class="fa-solid fa-tag text-[9px]" />
                                        {{ block.type_name }}
                                    </span>
                                    <span v-else class="text-xs italic text-slate-300">
                                        {{ t.idp.settings.untyped }}
                                    </span>
                                </td>

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                >
                                    <ActiveStateCell
                                        :active="block.is_active"
                                        :busy="togglingId === block.id"
                                        @toggle="toggleActive(block as unknown as Competency)"
                                        @history="openHistory(block as unknown as Competency)"
                                    />
                                </td>

                                <!-- Level cell: painted once per level group. -->
                                <td
                                    v-if="line.levelRowspan > 0"
                                    :rowspan="line.levelRowspan"
                                    :colspan="line.levelColspan"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                    :class="i + line.levelRowspan < block.lines.length
                                        ? 'border-b border-border/40'
                                        : ''"
                                >
                                    <span
                                        v-if="line.levelName"
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            line.levelActive
                                                ? 'bg-emerald-50 text-emerald-600'
                                                : 'bg-slate-100 text-slate-400 line-through'
                                        "
                                        :title="
                                            line.levelActive
                                                ? undefined
                                                : t.idp.settings.inactiveBadge
                                        "
                                    >
                                        <span
                                            class="inline-flex h-4 w-4 items-center justify-center rounded-full bg-white/70 text-[9px] font-bold"
                                        >
                                            {{ line.levelSequence }}
                                        </span>
                                        {{ line.levelName }}
                                    </span>
                                    <span v-else class="text-xs italic text-slate-300">
                                        {{ t.idp.settings.noProficiencyLevel }}
                                    </span>

                                    <p
                                        v-if="line.levelDescription"
                                        class="mt-1 whitespace-pre-line text-[11px] leading-snug text-slate-400"
                                    >
                                        {{ line.levelDescription }}
                                    </p>
                                </td>

                                <!-- Key behavior: one per line, unless the
                                     level cell already covers this column. -->
                                <td
                                    v-if="line.levelColspan === 1"
                                    class="border-r border-border/40 px-4 py-3 align-top"
                                    :class="i < block.lines.length - 1
                                        ? 'border-b border-border/40'
                                        : ''"
                                >
                                    <span
                                        v-if="line.behaviorName"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600"
                                        :title="t.idp.settings.keyBehavior"
                                    >
                                        <i class="fa-solid fa-list-check text-[9px]" />
                                        {{ line.behaviorName }}
                                    </span>
                                    <span v-else class="text-xs italic text-slate-300">
                                        —
                                    </span>
                                </td>

                                <td
                                    v-if="i === 0"
                                    :rowspan="block.rowspan"
                                    class="px-4 py-3 align-top"
                                >
                                    <div class="flex items-center justify-end gap-1">
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
                                </td>
                            </tr>
                        </template>

                        <tr v-if="competencyBlocks.length === 0">
                            <td colspan="9" class="px-4 py-8 text-center text-slate-400">
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

            <!-- Pager: pages competencies, so a block is never split. -->
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
