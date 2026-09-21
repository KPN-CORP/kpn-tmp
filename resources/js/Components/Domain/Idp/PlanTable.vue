<script setup lang="ts">
/**
 * One development model's card: its header, its plans as a sortable table, and
 * its own pager.
 *
 * It is a component per model rather than a loop in the panel because the sort
 * and the page belong to ONE model's table — sorting the 70% block must not
 * reorder the 20% one, and paging through a long block must not move the others.
 * A component instance per section gives each its own state for free.
 */
import { computed, ref, watch } from 'vue'
import PlanRow from '@/Components/Domain/Idp/PlanRow.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import { useLocale } from '@/Composables/useLocale'
import { calendarDay } from '@/Composables/useDate'
import {
    planningKey,
    resultKey,
    PLANNING_ORDER,
    RESULT_ORDER,
} from '@/Components/Domain/Idp/planStatus'
import type { Plan } from '@/types/idp'

const { t } = useLocale()

/** A plan plus the timeline chip the panel resolved for it. */
export interface PlanTableRow {
    plan: Plan
    timeline: { key: string; label: string; badge: string; dot: string }
}

type SortKey = 'competency' | 'timeframe' | 'planning' | 'result'

const props = defineProps<{
    model: {
        id: number
        name: string
        percentage: number
        can_add: boolean
        accent: { bar: string; chip: string; solid: string }
    }
    /** The rows to show — already filtered by the panel. */
    rows: PlanTableRow[]
    /** How many plans this model holds before filtering. */
    total: number
    /** A filter is narrowing the table, so the header says what of what. */
    filtering: boolean
    /** Canonical value → localized label, resolved once by the panel. */
    competencyLabels: Record<string, string>
    typeLabels: Record<string, string>
    programLabels: Record<string, string>
    reviewToolLabels: Record<string, string>
    /** Show the add button (and, when frozen, why it is gone). */
    canEdit: boolean
    viewingActive: boolean
    plansEditable: boolean
    /** Show the per-row edit / delete column. */
    rowsEditable: boolean
    submittingResultId: number | null
}>()

const emit = defineEmits<{
    (e: 'add'): void
    (e: 'edit', plan: Plan): void
    (e: 'delete', plan: Plan): void
    (e: 'file-result', plan: Plan): void
    (e: 'submit-result', plan: Plan): void
    (e: 'act', plan: Plan, decision: 'approve' | 'reject'): void
    (e: 'open-chain', plan: Plan): void
}>()

function localize(map: Record<string, string>, value: string | null): string {
    return value ? (map[value] ?? value) : ''
}

// --- Sorting ---------------------------------------------------------------

// Unsorted by default, so a section opens in the order the plans were written.
const sortKey = ref<SortKey | ''>('')
const sortDir = ref<'asc' | 'desc'>('asc')

const columns: SortKey[] = ['competency', 'timeframe', 'planning', 'result']

const columnLabels = computed<Record<SortKey, string>>(() => ({
    competency: t.value.idp.table.competency,
    timeframe: t.value.idp.table.timeframe,
    planning: t.value.idp.table.planning,
    result: t.value.idp.table.result,
}))

function toggleSort(key: SortKey) {
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDir.value = 'asc'
    }

    page.value = 1
}

/**
 * What a column sorts on. The two status columns sort by their position in the
 * workflow, not by their label — "Approved" before "In review" is the alphabet
 * talking, not the process.
 */
function sortValue(row: PlanTableRow, key: SortKey): string | number {
    switch (key) {
        case 'competency':
            return localize(props.competencyLabels, row.plan.competency_name)
        case 'timeframe':
            return calendarDay(row.plan.time_frame_start) ?? ''
        case 'planning':
            return PLANNING_ORDER.indexOf(planningKey(row.plan))
        case 'result':
            return RESULT_ORDER.indexOf(resultKey(row.plan))
    }
}

/** Blanks sort last ascending, so an undated plan never heads the list. */
function cmp(a: string | number, b: string | number): number {
    if (typeof a === 'number' && typeof b === 'number') return a - b

    const as = String(a)
    const bs = String(b)
    if (!as) return bs ? 1 : 0
    if (!bs) return -1

    return as.localeCompare(bs)
}

const sorted = computed(() => {
    const key = sortKey.value
    if (!key) return props.rows

    const dir = sortDir.value === 'asc' ? 1 : -1

    return [...props.rows].sort((a, b) => cmp(sortValue(a, key), sortValue(b, key)) * dir)
})

// --- Paging ----------------------------------------------------------------

const PER_PAGE_OPTIONS = [5, 10, 25, 50]

const page = ref(1)
const perPage = ref(10)

const totalPages = computed(() => Math.max(1, Math.ceil(sorted.value.length / perPage.value)))

// A filter change swaps the rows underneath the pager, so page 3 of what is now
// a single page would render empty. Keyed on the rows themselves rather than
// their count, so a same-size change resets too.
watch(
    () => props.rows.map((r) => r.plan.id).join(','),
    () => {
        page.value = 1
    },
)

watch(totalPages, (n) => {
    if (page.value > n) page.value = n
})

const pageRows = computed(() => {
    const start = (page.value - 1) * perPage.value

    return sorted.value.slice(start, start + perPage.value)
})

const from = computed(() => (sorted.value.length ? (page.value - 1) * perPage.value + 1 : 0))
const to = computed(() => Math.min(page.value * perPage.value, sorted.value.length))

function changePerPage(size: number) {
    perPage.value = size
    page.value = 1
}

// Mirrors ClientTable's rule, so a pager only appears once it has something to
// do — more than one page, or enough rows for the page-size selector to matter.
const showPager = computed(
    () => totalPages.value > 1 || sorted.value.length > Math.min(...PER_PAGE_OPTIONS),
)

// --- Header ----------------------------------------------------------------

const countLabel = computed(() => {
    const n = props.rows.length

    if (props.filtering) {
        return t.value.idp.filters.countOfTotal
            .replace('{shown}', String(n))
            .replace('{total}', String(props.total))
    }

    return `${n} ${n === 1 ? t.value.idp.planSingular : t.value.idp.planPlural}`
})
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
        <header class="relative flex flex-wrap items-center justify-between gap-3 border-b border-border bg-slate-50/50 py-3.5 pl-6 pr-5">
            <!-- The model's colour, as the card's own edge rather than a pill -->
            <span class="absolute inset-y-0 left-0 w-1.5" :class="model.accent.bar" />

            <div class="flex min-w-0 items-center gap-3.5">
                <span
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-bold text-white shadow-sm"
                    :class="model.accent.solid"
                >
                    {{ model.percentage }}%
                </span>

                <div class="min-w-0">
                    <h3 class="truncate font-bold leading-tight text-slate-800">{{ model.name }}</h3>
                    <p class="mt-1 flex items-center gap-1.5 text-xs text-slate-500">
                        <i class="fa-regular fa-rectangle-list text-[10px] text-slate-300" />
                        <span :class="filtering ? 'font-medium text-primary' : ''">{{ countLabel }}</span>
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <!-- The set is frozen while its approval is in flight, so say why
                     the add button is gone rather than hiding it silently. (A
                     closed cycle says so once, in the tracker, not on every
                     model.) -->
                <span
                    v-if="canEdit && viewingActive && !plansEditable"
                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-200"
                    :title="t.idp.stage.lockedInReview"
                >
                    <i class="fa-solid fa-lock text-[10px]" />
                    {{ t.idp.stage.locked }}
                </span>

                <button
                    v-if="canEdit && model.can_add"
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-primary-hover"
                    @click="emit('add')"
                >
                    <i class="fa-solid fa-plus" />
                    {{ t.idp.addPlan }}
                </button>
            </div>
        </header>

        <div v-if="rows.length" class="overflow-x-auto">
            <table class="w-full min-w-[960px] text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th
                            v-for="col in columns"
                            :key="col"
                            class="cursor-pointer select-none px-5 py-2.5 font-semibold transition hover:text-slate-600"
                            @click="toggleSort(col)"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                {{ columnLabels[col] }}
                                <i
                                    class="fa-solid text-[10px]"
                                    :class="sortKey === col
                                        ? (sortDir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                        : 'fa-sort text-slate-300'"
                                />
                            </span>
                        </th>
                        <th v-if="rowsEditable" class="px-5 py-2.5 text-right font-semibold" />
                    </tr>
                </thead>
                <tbody>
                    <PlanRow
                        v-for="row in pageRows"
                        :key="row.plan.id"
                        :plan="row.plan"
                        :competency-label="localize(competencyLabels, row.plan.competency_name)"
                        :type-label="localize(typeLabels, row.plan.competency_type)"
                        :program-label="localize(programLabels, row.plan.development_program)"
                        :review-tool-label="localize(reviewToolLabels, row.plan.review_tools)"
                        :timeline="row.timeline"
                        :can-edit="rowsEditable"
                        :submitting="submittingResultId === row.plan.id"
                        @edit="emit('edit', row.plan)"
                        @delete="emit('delete', row.plan)"
                        @file-result="emit('file-result', row.plan)"
                        @submit-result="emit('submit-result', row.plan)"
                        @act="(d) => emit('act', row.plan, d)"
                        @open-chain="emit('open-chain', row.plan)"
                    />
                </tbody>
            </table>
        </div>

        <div v-else class="flex flex-col items-center gap-3 px-5 py-10 text-center">
            <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                <i class="fa-regular fa-folder-open text-xl" />
            </div>
            <p class="text-sm text-slate-400">{{ t.idp.noPlans }}</p>
            <button
                v-if="canEdit && model.can_add"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                @click="emit('add')"
            >
                <i class="fa-solid fa-plus" />
                {{ t.idp.addPlan }}
            </button>
        </div>

        <!-- This model's own pager: paging the 70% block leaves the others put. -->
        <div v-if="showPager" class="border-t border-border px-4 py-2.5 [&>div]:!mt-0">
            <Pagination
                :page="page"
                :per-page="perPage"
                :total="sorted.length"
                :from="from"
                :to="to"
                :per-page-options="PER_PAGE_OPTIONS"
                @update:page="page = $event"
                @update:per-page="changePerPage"
            />
        </div>
    </section>
</template>
