<script setup lang="ts">
/**
 * The approver's desk, in two views over the same kind of card:
 *
 *  - PENDING — every IDP request this person sits on the chain of, at either
 *    stage. That includes the ones still with an earlier layer: they are read
 *    now and decided when they arrive, so only a card with `can_act` offers
 *    the Approve / Reject buttons.
 *  - HISTORY — the decisions they have already recorded, newest first, each
 *    with the note they left and where the request went afterwards.
 *
 * This page owns the desk: the tabs, the filters, the sorting, which cards are
 * open, and the decision drawer. What one request looks like is RequestCard's
 * job — it is the same card on both views, so a state reads the same wherever
 * it appears.
 *
 * A waiting request opens EXPANDED. Signing something off means reading it, so
 * the detail is the default rather than something to go and find; the log opens
 * collapsed, because a log is for scanning.
 */
import { computed, reactive, ref, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import DecisionDrawer from '@/Components/Domain/Idp/DecisionDrawer.vue'
import RequestCard from '@/Components/Domain/Idp/RequestCard.vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'
import type { InboxRequest } from '@/types/idp'

const { t } = useLocale()

interface Paginator {
    data: InboxRequest[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

interface Sort {
    key: string
    dir: 'asc' | 'desc'
}

const props = defineProps<{
    items: Paginator
    /** Which desk is being read. */
    view: 'pending' | 'history'
    filters: { search: string; stage: string; type: string }
    sort: Sort
    filterOptions: { types: string[] }
    /** Everything on the desk, at every layer. */
    pendingTotal: number
    /** The subset this person may decide right now — what the menu badge counts. */
    actionableTotal: number
    stageTotals: { planning: number; result: number }
    historyTotal: number
}>()

const isHistory = computed(() => props.view === 'history')

// --- List: search + stage + sort + pagination (server-side) -----------------

const state = reactive({
    search: props.filters.search ?? '',
    stage: props.filters.stage ?? '',
    type: props.filters.type ?? '',
    per_page: props.items.per_page,
})

function reload(sort: Sort = props.sort, view: string = props.view) {
    router.get(
        route('approvals.inbox'),
        {
            view: view === 'history' ? 'history' : undefined,
            search: state.search || undefined,
            stage: state.stage || undefined,
            type: state.type || undefined,
            sort: sort.key,
            direction: sort.dir,
            per_page: state.per_page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

// Debounce the free-text search; the selects apply immediately.
let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    () => state.search,
    () => {
        clearTimeout(debounce)
        debounce = setTimeout(() => reload(), 300)
    },
)

const hasFilters = computed(() => !!(state.search || state.stage || state.type))

function resetFilters(view: string = props.view) {
    state.search = ''
    state.stage = ''
    state.type = ''
    reload(props.sort, view)
}

function onStage(value: string) {
    state.stage = value
    reload()
}

function onType(value: string) {
    state.type = value
    reload()
}

function changeSort(key: string) {
    const dir: 'asc' | 'desc' = props.sort.key === key && props.sort.dir === 'asc' ? 'desc' : 'asc'
    reload({ key, dir })
}

function changePerPage(perPage: number) {
    state.per_page = perPage
    reload()
}

// --- The tab strip ----------------------------------------------------------

/**
 * Four tabs over two different things: the first three narrow the pending desk
 * by stage, the fourth switches desks entirely. Switching clears the filters,
 * so what a tab's count promises is what its list shows.
 */
const tabs = computed(() => [
    { key: '', label: t.value.approvalFlow.allStages, count: props.pendingTotal, icon: 'fa-solid fa-inbox' },
    { key: 'planning', label: t.value.approvalFlow.planningRequests, count: props.stageTotals.planning, icon: 'fa-solid fa-file-signature' },
    { key: 'result', label: t.value.approvalFlow.resultRequests, count: props.stageTotals.result, icon: 'fa-solid fa-clipboard-check' },
    { key: 'history', label: t.value.approvalFlow.historyTab, count: props.historyTotal, icon: 'fa-solid fa-clock-rotate-left' },
])

const activeTab = computed(() => (isHistory.value ? 'history' : state.stage))

function selectTab(key: string) {
    if (key === activeTab.value) {
        return
    }

    state.search = ''
    state.type = ''
    state.stage = key === 'history' ? '' : key

    reload(
        // Each desk has its own default ordering: what is waiting longest, and
        // what was decided most recently.
        key === 'history'
            ? { key: 'decided_at', dir: 'desc' }
            : { key: 'submitted_at', dir: 'asc' },
        key === 'history' ? 'history' : 'pending',
    )
}

// The stage filter lives in the tab strip on the pending desk; the history desk
// gets it as a select, since its tab is the one that put it there.
const stageOptions = computed<Option[]>(() => [
    { value: '', label: t.value.approvalFlow.allStages },
    { value: 'planning', label: t.value.approvalFlow.planningRequests },
    { value: 'result', label: t.value.approvalFlow.resultRequests },
])

const typeOptions = computed<Option[]>(() => [
    { value: '', label: t.value.approvalFlow.allTypes },
    ...props.filterOptions.types.map((v) => ({ value: v, label: v })),
])

const sortOptions = computed<Option[]>(() =>
    isHistory.value
        ? [
            { value: 'decided_at', label: t.value.approvalFlow.decidedAt },
            { value: 'submitted_at', label: t.value.approvalFlow.submittedAt },
            { value: 'owner_name', label: t.value.approvalFlow.owner },
            { value: 'stage', label: t.value.approvalFlow.type },
            { value: 'decision', label: t.value.approvalFlow.decision },
        ]
        : [
            { value: 'submitted_at', label: t.value.approvalFlow.submittedAt },
            { value: 'owner_name', label: t.value.approvalFlow.owner },
            { value: 'stage', label: t.value.approvalFlow.type },
            { value: 'level', label: t.value.approvalFlow.yourLayer },
        ],
)

/** Whether this desk holds anything at all, before any filter is applied. */
const hasAnything = computed(() => (isHistory.value ? props.historyTotal > 0 : props.pendingTotal > 0))

// --- Which cards are open ---------------------------------------------------

/**
 * A waiting request opens EXPANDED — both stages. Deciding means reading, so
 * the substance is on screen from the start rather than behind a control the
 * reader has to notice. The log opens collapsed: it is read by scanning, and
 * the decision is already recorded on the card face.
 */
function initialExpanded(): Set<number> {
    return isHistory.value ? new Set() : new Set(props.items.data.map((i) => i.step_id))
}

const expanded = ref<Set<number>>(initialExpanded())

// The component survives a partial reload, so a new page of rows — or the other
// desk — has to re-seed it, or rows arrive closed on a desk that opens them.
watch(() => [props.view, props.items.data], () => (expanded.value = initialExpanded()), { deep: false })

function toggle(id: number) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

/** Open when anything is closed; otherwise close everything. */
const allOpen = computed(
    () => props.items.data.length > 0 && props.items.data.every((i) => expanded.value.has(i.step_id)),
)

function toggleAll() {
    expanded.value = allOpen.value ? new Set() : new Set(props.items.data.map((i) => i.step_id))
}

// --- Deciding --------------------------------------------------------------

const decision = ref<{
    open: boolean
    kind: 'approve' | 'reject'
    approvalId: number | null
    subject: string
    detail: string | null
    level: number | null
    totalLevels: number | null
}>({ open: false, kind: 'approve', approvalId: null, subject: '', detail: null, level: null, totalLevels: null })

function decide(item: InboxRequest, kind: 'approve' | 'reject') {
    decision.value = {
        open: true,
        kind,
        approvalId: item.approval_id,
        subject: `${item.owner_name} · ${item.owner_id}`,
        detail:
            item.stage === 'planning'
                ? `${item.plans.length} ${t.value.approvalFlow.programs}`
                : item.title,
        level: item.level,
        totalLevels: item.total_levels,
    }
}
</script>

<template>
    <Head :title="t.approvalFlow.inboxTitle" />

    <AppLayout>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <PageHeader :title="t.approvalFlow.inboxTitle" :subtitle="t.approvalFlow.inboxSubtitle" />

            <!--
                The pill counts what this person can decide, not the size of the
                desk — the desk also holds requests still with an earlier layer.
                It is the same number the menu badge shows.
            -->
            <span
                v-if="actionableTotal > 0"
                class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800"
            >
                <i class="fa-solid fa-hourglass-half text-[10px]" />
                {{ actionableTotal }} {{ t.approvalFlow.pendingCount }}
            </span>
        </div>

        <!--
            Which kind of request, and which desk. Shown even when nothing is
            waiting — the counts are the answer to "is there anything for me?",
            and an inbox that changes shape when it empties reads as a different
            screen.
        -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <template v-for="tab in tabs" :key="tab.key">
                <!-- History is a different desk, not another slice of this one -->
                <span v-if="tab.key === 'history'" aria-hidden="true" class="mx-1 h-6 w-px bg-border" />

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border px-3.5 py-2 text-sm font-medium transition"
                    :class="
                        activeTab === tab.key
                            ? 'border-primary bg-primary/5 text-primary'
                            : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                    "
                    @click="selectTab(tab.key)"
                >
                    <i :class="tab.icon" class="text-xs" />
                    {{ tab.label }}
                    <span
                        class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
                        :class="activeTab === tab.key ? 'bg-primary/15' : 'bg-slate-100 text-slate-500'"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </template>
        </div>

        <template v-if="hasAnything">
            <p class="mb-4 flex items-start gap-2 text-xs text-slate-500">
                <i class="fa-solid fa-circle-info mt-0.5 text-slate-400" />
                {{ isHistory ? t.approvalFlow.historyHint : t.approvalFlow.inboxHint }}
            </p>

            <!-- Filters -->
            <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="relative" :class="isHistory ? '' : 'sm:col-span-2'">
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400" />
                    <input
                        v-model="state.search"
                        type="text"
                        :placeholder="isHistory ? t.approvalFlow.searchHistoryPlaceholder : t.approvalFlow.searchPlaceholder"
                        class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                </div>

                <!-- On the pending desk the tab strip already narrows by stage. -->
                <SearchableSelect
                    v-if="isHistory"
                    :model-value="state.stage"
                    :options="stageOptions"
                    :placeholder="t.approvalFlow.allStages"
                    @update:model-value="onStage"
                />

                <SearchableSelect
                    :model-value="state.type"
                    :options="typeOptions"
                    :placeholder="t.approvalFlow.allTypes"
                    @update:model-value="onType"
                />

                <div class="flex items-center gap-2">
                    <SearchableSelect
                        class="flex-1"
                        :model-value="sort.key"
                        :options="sortOptions"
                        @update:model-value="(key: string) => changeSort(key)"
                    />
                    <button
                        type="button"
                        class="shrink-0 rounded-md border border-border bg-white px-3 py-2.5 text-sm text-slate-500 transition hover:bg-slate-50"
                        :title="sort.dir === 'asc' ? 'A → Z' : 'Z → A'"
                        @click="changeSort(sort.key)"
                    >
                        <i :class="sort.dir === 'asc' ? 'fa-solid fa-arrow-up-short-wide' : 'fa-solid fa-arrow-down-wide-short'" />
                    </button>
                </div>
            </div>

            <div v-if="hasFilters" class="mb-4">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500 transition hover:text-primary"
                    @click="resetFilters()"
                >
                    <i class="fa-solid fa-rotate-left text-[10px]" />
                    {{ t.approvalFlow.resetFilters }}
                </button>
            </div>

            <!--
                The list itself. One card per request, with a single control for
                opening or closing them all — useful once a desk runs long,
                where the default (everything open) is a lot of scrolling.
            -->
            <div class="mb-2 flex items-center justify-end">
                <button
                    v-if="items.data.length"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                    @click="toggleAll"
                >
                    <i :class="allOpen ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" class="text-[10px]" />
                    {{ allOpen ? t.approvalFlow.collapseAll : t.approvalFlow.expandAll }}
                </button>
            </div>

            <div class="space-y-4">
                <RequestCard
                    v-for="item in items.data"
                    :key="item.step_id"
                    :item="item"
                    :history="isHistory"
                    :open="expanded.has(item.step_id)"
                    @toggle="toggle(item.step_id)"
                    @decide="(kind: 'approve' | 'reject') => decide(item, kind)"
                />


                <p
                    v-if="!items.data.length"
                    class="rounded-xl border border-dashed border-border bg-white px-5 py-12 text-center text-sm text-slate-400"
                >
                    {{ t.approvalFlow.noResults }}
                </p>
            </div>

            <Pagination
                class="mt-5"
                :links="items.links"
                :from="items.from"
                :to="items.to"
                :total="items.total"
                :per-page="items.per_page"
                @update:per-page="changePerPage"
            />
        </template>

        <!-- Nothing waiting / nothing decided yet -->
        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-border bg-white px-5 py-16 text-center"
        >
            <div
                class="flex h-14 w-14 items-center justify-center rounded-full"
                :class="isHistory ? 'bg-slate-100 text-slate-400' : 'bg-emerald-50 text-emerald-500'"
            >
                <i :class="isHistory ? 'fa-solid fa-clock-rotate-left' : 'fa-solid fa-check'" class="text-xl" />
            </div>
            <p class="text-sm text-slate-500">
                {{ isHistory ? t.approvalFlow.historyEmpty : t.approvalFlow.inboxEmpty }}
            </p>
        </div>

        <DecisionDrawer
            :show="decision.open"
            :decision="decision.kind"
            :approval-id="decision.approvalId"
            :subject="decision.subject"
            :detail="decision.detail"
            :level="decision.level"
            :total-levels="decision.totalLevels"
            @close="decision.open = false"
        />
    </AppLayout>
</template>
