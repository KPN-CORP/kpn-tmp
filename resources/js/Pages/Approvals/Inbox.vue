<script setup lang="ts">
/**
 * The approver's desk, in two views over the same kind of card:
 *
 *  - PENDING — every IDP request waiting on this person's decision, at either
 *    stage.
 *  - HISTORY — the decisions they have already recorded, newest first, each
 *    with the note they left and where the request went afterwards.
 *
 * Requests are cards rather than table rows because the two stages are not the
 * same size: a PLAN request covers every program at once and has to be readable
 * in full before it is signed off, while a RESULT request covers one program and
 * is mostly about the evidence. Each card opens in place to show exactly what is
 * being approved, so a decision never needs another screen.
 */
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import ApprovalChain from '@/Components/Domain/Idp/ApprovalChain.vue'
import DecisionDrawer from '@/Components/Domain/Idp/DecisionDrawer.vue'
import StatusPill from '@/Components/Domain/Idp/StatusPill.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmt, formatDateTime as fmtDateTime } from '@/Composables/useDate'
import { route } from '@/Config/route'
import type { ApprovalInfo, Tone } from '@/types/idp'

const { t } = useLocale()

interface InboxPlan {
    id: number
    development_model: string | null
    competency_type: string
    competency_name: string
    development_program: string
    review_tools: string | null
    expected_outcome: string | null
    time_frame_start: string | null
    time_frame_end: string | null
    realization_date: string | null
    result_evidence: string | null
}

interface InboxItem {
    /** Identifies the ROW: one request appears twice when the same person sits on two of its layers. */
    step_id: number
    approval_id: number
    stage: 'planning' | 'result'
    level: number
    total_levels: number
    owner_id: string
    owner_name: string
    submitted_at: string | null
    package: { id: number; name: string } | null
    title: string | null
    plans: InboxPlan[]
    // History rows only — what this person decided, and what became of it.
    decision?: 'approved' | 'rejected'
    decided_at?: string | null
    note?: string | null
    auto?: boolean
    outcome?: 'pending' | 'approved' | 'rejected'
    chain?: ApprovalInfo
}

interface Paginator {
    data: InboxItem[]
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
    pendingTotal: number
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

// --- Reading a request -----------------------------------------------------

// Plan requests open by default: signing off a plan means reading it, so
// hiding it behind a click would be the wrong way round. Result requests are
// one program and their headline already says which. A decided request opens
// closed either way — the history is for scanning.
function initialExpanded(): Set<number> {
    if (isHistory.value) {
        return new Set()
    }

    return new Set(props.items.data.filter((i) => i.stage === 'planning').map((i) => i.step_id))
}

const expanded = ref<Set<number>>(initialExpanded())

// The component survives a partial reload, so switching desks has to re-seed it.
watch(() => props.view, () => (expanded.value = initialExpanded()))

function toggle(id: number) {
    const next = new Set(expanded.value)
    next.has(id) ? next.delete(id) : next.add(id)
    expanded.value = next
}

// Programs grouped by development model — how the plan itself is laid out, so
// the approver reads it the same way it was written.
function grouped(item: InboxItem): Array<{ model: string; plans: InboxPlan[] }> {
    const groups = new Map<string, InboxPlan[]>()
    for (const plan of item.plans) {
        const key = plan.development_model ?? '—'
        groups.set(key, [...(groups.get(key) ?? []), plan])
    }
    return [...groups.entries()].map(([model, plans]) => ({ model, plans }))
}

function isUrl(value: string | null): boolean {
    return !!value && /^https?:\/\//i.test(value.trim())
}

// --- A decided request ------------------------------------------------------

function decisionLabel(item: InboxItem): string {
    if (item.auto) return t.value.approvalFlow.autoApproved
    return item.decision === 'rejected' ? t.value.approvalFlow.youRejected : t.value.approvalFlow.youApproved
}

function decisionTone(item: InboxItem): Tone {
    if (item.auto) return 'slate'
    return item.decision === 'rejected' ? 'red' : 'emerald'
}

/** Where the request ended up after this layer signed it off. */
function outcome(item: InboxItem): { label: string; tone: Tone } {
    if (item.outcome === 'approved') {
        return { label: t.value.approvalFlow.outcomeApproved, tone: 'emerald' }
    }

    if (item.outcome === 'rejected') {
        return { label: t.value.approvalFlow.outcomeRejected, tone: 'red' }
    }

    // "Still with layer" already names the layer; only the number is missing.
    const level = item.chain?.current_level
    return {
        label: level
            ? `${t.value.approvalFlow.outcomePending} ${level}`
            : t.value.approvalFlow.outcomeMoving,
        tone: 'amber',
    }
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

function decide(item: InboxItem, kind: 'approve' | 'reject') {
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

            <span
                v-if="pendingTotal > 0"
                class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800"
            >
                <i class="fa-solid fa-hourglass-half text-[10px]" />
                {{ pendingTotal }} {{ t.approvalFlow.pendingCount }}
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

            <!-- The requests -->
            <div class="space-y-4">
                <article
                    v-for="item in items.data"
                    :key="item.step_id"
                    class="overflow-hidden rounded-xl border border-border bg-white shadow-sm"
                >
                    <!-- Who, what, and the decision -->
                    <header class="flex flex-col gap-3 border-b border-border px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="flex min-w-0 items-start gap-3">
                            <span
                                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
                                :class="item.stage === 'planning' ? 'bg-primary/10 text-primary' : 'bg-emerald-50 text-emerald-600'"
                            >
                                <i :class="item.stage === 'planning' ? 'fa-solid fa-file-signature' : 'fa-solid fa-clipboard-check'" />
                            </span>

                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <StatusPill
                                        :tone="item.stage === 'planning' ? 'primary' : 'emerald'"
                                        :label="item.stage === 'planning' ? t.approvalFlow.stagePlanning : t.approvalFlow.stageResult"
                                        :dot="false"
                                        size="sm"
                                    />
                                    <h3 class="truncate font-bold text-slate-800">{{ item.owner_name }}</h3>
                                    <span class="text-xs text-slate-400">{{ item.owner_id }}</span>
                                </div>

                                <!-- What was actually being approved -->
                                <p class="mt-1 text-sm text-slate-600">
                                    <template v-if="item.stage === 'planning'">
                                        {{ t.approvalFlow.planningSubject }}
                                        <span class="text-slate-400">·</span>
                                        <span class="font-medium">{{ item.plans.length }} {{ t.approvalFlow.programs }}</span>
                                        <span v-if="item.package" class="text-slate-400"> · {{ item.package.name }}</span>
                                    </template>
                                    <template v-else>{{ item.title }}</template>
                                </p>

                                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                                    <span>
                                        <i class="fa-solid fa-layer-group mr-1" />
                                        {{ t.approvalFlow.layer }} {{ item.level }} / {{ item.total_levels }}
                                    </span>
                                    <span v-if="item.submitted_at">
                                        <i class="fa-solid fa-paper-plane mr-1" />
                                        {{ fmtDateTime(item.submitted_at) }}
                                    </span>
                                    <Link
                                        :href="route('idp.show', item.owner_id)"
                                        class="font-medium text-primary hover:underline"
                                    >
                                        <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-[10px]" />
                                        {{ t.approvalFlow.openEmployeeIdp }}
                                    </Link>
                                </p>
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <StatusPill
                                v-if="isHistory"
                                :tone="decisionTone(item)"
                                :label="decisionLabel(item)"
                                :icon="item.decision === 'rejected' ? 'fa-solid fa-circle-xmark' : 'fa-solid fa-circle-check'"
                            />

                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-xs font-medium text-slate-500 transition hover:bg-slate-50"
                                @click="toggle(item.step_id)"
                            >
                                <i :class="expanded.has(item.step_id) ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" class="text-[10px]" />
                                {{
                                    expanded.has(item.step_id)
                                        ? (item.stage === 'planning' ? t.approvalFlow.hidePlan : t.approvalFlow.hideProgram)
                                        : (item.stage === 'planning' ? t.approvalFlow.showPlan : t.approvalFlow.showProgram)
                                }}
                            </button>

                            <template v-if="!isHistory">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-500 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-600"
                                    @click="decide(item, 'approve')"
                                >
                                    <i class="fa-solid fa-check text-xs" />
                                    {{ t.approvalFlow.approve }}
                                </button>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3.5 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50"
                                    @click="decide(item, 'reject')"
                                >
                                    <i class="fa-solid fa-xmark text-xs" />
                                    {{ t.approvalFlow.reject }}
                                </button>
                            </template>
                        </div>
                    </header>

                    <!-- What this person decided, and what became of the request -->
                    <div
                        v-if="isHistory"
                        class="border-b border-border bg-slate-50/70 px-5 py-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="flex items-center gap-2 text-xs text-slate-500">
                                <i
                                    :class="item.decision === 'rejected'
                                        ? 'fa-solid fa-circle-xmark text-red-500'
                                        : 'fa-solid fa-circle-check text-emerald-500'"
                                />
                                <span class="font-semibold text-slate-700">{{ decisionLabel(item) }}</span>
                                <span v-if="item.decided_at">· {{ fmtDateTime(item.decided_at) }}</span>
                                <span>· {{ t.approvalFlow.layerShort }}{{ item.level }}</span>
                            </p>

                            <StatusPill :tone="outcome(item).tone" :label="outcome(item).label" size="sm" />
                        </div>

                        <p
                            v-if="item.note && !item.auto"
                            class="mt-2 rounded-md bg-white px-2.5 py-1.5 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ring-border"
                        >
                            <i class="fa-solid fa-quote-left mr-1 text-[10px] text-slate-300" />
                            {{ item.note }}
                        </p>
                    </div>

                    <!-- What was being approved, in full -->
                    <div v-if="expanded.has(item.step_id)" class="divide-y divide-border">
                        <div v-for="group in grouped(item)" :key="group.model">
                            <p
                                v-if="item.stage === 'planning'"
                                class="bg-slate-50/70 px-5 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                            >
                                {{ group.model }}
                                <span class="font-normal text-slate-400">· {{ group.plans.length }}</span>
                            </p>

                            <div
                                v-for="plan in group.plans"
                                :key="plan.id"
                                class="grid grid-cols-1 gap-3 px-5 py-3.5 lg:grid-cols-[1.2fr_1.5fr_auto]"
                            >
                                <!-- What it develops -->
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-slate-800">{{ plan.competency_name }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700">
                                            {{ plan.competency_type }}
                                        </span>
                                        <span
                                            v-if="plan.review_tools"
                                            class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                                        >
                                            <i class="fa-solid fa-clipboard-check text-[10px]" />
                                            {{ plan.review_tools }}
                                        </span>
                                    </div>
                                </div>

                                <!-- How, and what was expected of it -->
                                <div class="min-w-0 text-sm text-slate-600">
                                    <p class="leading-relaxed">{{ plan.development_program }}</p>
                                    <p v-if="plan.expected_outcome" class="mt-1 whitespace-pre-line text-xs text-slate-400">
                                        <span class="font-medium">{{ t.idp.outcomeLabel }}:</span>
                                        {{ plan.expected_outcome }}
                                    </p>
                                </div>

                                <!-- When, and — for a result — what came of it -->
                                <div class="shrink-0 text-xs text-slate-500 lg:text-right">
                                    <p class="whitespace-nowrap">
                                        <i class="fa-regular fa-calendar mr-1 text-slate-300" />
                                        {{ fmt(plan.time_frame_start) }}
                                        <i class="fa-solid fa-arrow-right-long mx-1 text-[10px] text-slate-300" />
                                        {{ plan.time_frame_end ? fmt(plan.time_frame_end) : '—' }}
                                    </p>

                                    <template v-if="item.stage === 'result'">
                                        <p v-if="plan.realization_date" class="mt-1 whitespace-nowrap font-medium text-emerald-700">
                                            <i class="fa-regular fa-calendar-check mr-1" />
                                            {{ fmt(plan.realization_date) }}
                                        </p>
                                        <a
                                            v-if="isUrl(plan.result_evidence)"
                                            :href="plan.result_evidence!"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="mt-1 inline-flex items-center gap-1 font-medium text-primary hover:underline"
                                        >
                                            <i class="fa-solid fa-link text-[10px]" />
                                            {{ t.idp.evidenceLabel }}
                                        </a>
                                        <p v-else-if="plan.result_evidence" class="mt-1 max-w-xs text-slate-500 lg:ml-auto">
                                            {{ plan.result_evidence }}
                                        </p>
                                    </template>
                                </div>
                            </div>
                        </div>

                        <!-- The programs it covered are gone; the decision is not -->
                        <p v-if="!item.plans.length" class="px-5 py-4 text-xs text-slate-400">
                            {{ t.approvalFlow.planGone }}
                        </p>

                        <!-- Who else was on it, and what they made of it -->
                        <div v-if="isHistory && item.chain" class="px-5 py-4">
                            <p class="mb-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                {{ t.approvalFlow.fullChain }}
                            </p>
                            <ApprovalChain :approval="item.chain" />
                        </div>
                    </div>
                </article>

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
