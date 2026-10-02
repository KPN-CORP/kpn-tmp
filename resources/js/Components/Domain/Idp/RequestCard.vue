<script setup lang="ts">
/**
 * One request on the approver's desk — the whole card, on both of its surfaces
 * (the pending list and the decision log).
 *
 * The card is a pointer, not a copy. The employee's IDP page is where a
 * request is read in full and decided, and every card links there, so the
 * face carries only what is needed to pick the next one up:
 *
 *   who  →  what  →  whose turn it is (or what you decided)  →  the link
 *
 * It expands only for what that page cannot show: the comparison with the
 * previous round, and — in the log — the plan exactly as it was decided, since
 * the IDP page shows it as it reads today. A pending first-round request has
 * nothing of that kind, so it does not expand at all.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

import StatusPill from './StatusPill.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmt, formatDateTime as fmtDateTime } from '@/Composables/useDate'
import { attainment, formatAmount, formatTarget } from './uom'
import { route } from '@/Config/route'
import type { InboxPlan, InboxPlanField, InboxRequest, Tone } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    item: InboxRequest
    /** A decided request in the log, rather than one still waiting. */
    history?: boolean
    open: boolean
    /** value => unit label, already in the active language. */
    uomLabels: Record<string, string>
}>()

/** "3 Hectare (ha)" for one plan, or '' when it carries no target. */
function target(plan: InboxPlan): string {
    return formatTarget(props.uomLabels, plan.target, plan.uom)
}

/**
 * What was reached. Bare when there is a target to print it against, since
 * "10 Hectare (ha) / 12 Hectare (ha)" names the unit twice; with the unit when
 * it stands alone.
 */
function achieved(plan: InboxPlan): string {
    return plan.target === null
        ? formatTarget(props.uomLabels, plan.achievement, plan.uom)
        : formatAmount(plan.achievement)
}

/** How far that got against the target, or null when it cannot be worked out. */
function achievedPercent(plan: InboxPlan): number | null {
    return attainment(plan.target, plan.achievement)
}

/*
 * The round before this one. Each request carries the plans exactly as they
 * were submitted, so two rounds of the same plan can be told apart — and what
 * changed between them is what a resubmission is usually judged on.
 */
const previous = computed(() => props.item.previous)
const diff = computed(() => previous.value?.diff ?? null)
const addedIds = computed(() => new Set(diff.value?.added ?? []))

const changeCount = computed(() =>
    diff.value
        ? diff.value.added.length + diff.value.removed.length + Object.keys(diff.value.changed).length
        : 0,
)

const previousTone = computed<Tone>(() =>
    previous.value?.status === 'rejected' ? 'red' : previous.value?.status === 'approved' ? 'emerald' : 'amber',
)

const previousStatusLabel = computed(() => {
    const status = previous.value?.status

    if (status === 'rejected') return t.value.approvalFlow.outcomeRejected
    if (status === 'approved') return t.value.approvalFlow.outcomeApproved

    return t.value.approvalFlow.outcomeMoving
})

const rejectedBy = computed(() => {
    const rejected = previous.value?.rejected

    return rejected
        ? t.value.approvalFlow.previousRejectedBy
              .replace('{level}', String(rejected.level))
              .replace('{name}', rejected.name ?? '—')
        : ''
})

function fieldLabel(field: InboxPlanField): string {
    const form = t.value.idp.form

    return {
        development_model: form.developmentModel,
        competency_type: form.type,
        competency_name: form.competencyName,
        development_program: form.program,
        review_tools: form.reviewTools,
        expected_outcome: form.expectedOutcome,
        target: form.target,
        uom: form.uom,
        time_frame_start: form.start,
        time_frame_end: form.end,
        realization_date: form.realization,
        achievement: form.achievement,
        result_evidence: form.resultEvidence,
    }[field]
}

function fieldValue(field: InboxPlanField, value: unknown): string {
    if (value === null || value === undefined || String(value).trim() === '') {
        return '—'
    }

    if (field === 'time_frame_start' || field === 'time_frame_end' || field === 'realization_date') {
        return fmt(String(value))
    }

    if (field === 'target' || field === 'achievement') {
        return formatAmount(Number(value))
    }

    if (field === 'uom') {
        return props.uomLabels[String(value)] ?? String(value)
    }

    return String(value)
}

/** What changed on one program since the previous round, ready to print. */
function changesOf(plan: InboxPlan): Array<{ field: string; label: string; before: string; after: string }> {
    const fields = diff.value?.changed[plan.id]

    if (!fields) {
        return []
    }

    return (Object.entries(fields) as Array<[InboxPlanField, [unknown, unknown]]>).map(([field, [before, after]]) => ({
        field,
        label: fieldLabel(field),
        before: fieldValue(field, before),
        after: fieldValue(field, after),
    }))
}

function planMark(plan: InboxPlan): { label: string; cls: string } | null {
    if (addedIds.value.has(plan.id)) {
        return { label: t.value.approvalFlow.newBadge, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200' }
    }

    if (diff.value?.changed[plan.id]) {
        return { label: t.value.approvalFlow.changedBadge, cls: 'bg-amber-50 text-amber-700 ring-amber-200' }
    }

    return null
}

/** The programs a resubmitted plan set added or changed, in plan order. */
const touchedPlans = computed(() => props.item.plans.filter((plan) => planMark(plan) !== null))

const emit = defineEmits<{
    toggle: []
}>()

const isPlanning = computed(() => props.item.stage === 'planning')
const canAct = computed(() => !props.history && !!props.item.can_act)

/**
 * Whether there is anything behind the header. Mirrored by the Inbox's
 * "expand all", which only counts cards that open.
 */
const expandable = computed(() => !!props.history || !!props.item.previous)

function toggle() {
    if (expandable.value) {
        emit('toggle')
    }
}

const idpHref = computed(() =>
    route('idp.show', { employeeId: props.item.owner_id, package: props.item.package?.id ?? null }),
)

/** A result request covers exactly one program; a plan covers the whole set. */
const single = computed<InboxPlan | null>(() =>
    isPlanning.value ? null : (props.item.plans[0] ?? null),
)

/** What the request is called, under the employee's name. */
const headline = computed(() => {
    if (!isPlanning.value) {
        return props.item.title ?? props.item.plans[0]?.development_program ?? '—'
    }

    return `${t.value.approvalFlow.planningSubject} · ${props.item.plans.length} ${t.value.approvalFlow.programs}`
})

/** Programs grouped by development model — how the plan itself is laid out. */
const groups = computed<Array<{ model: string; plans: InboxPlan[] }>>(() => {
    const map = new Map<string, InboxPlan[]>()

    for (const plan of props.item.plans) {
        const key = plan.development_model ?? '—'
        map.set(key, [...(map.get(key) ?? []), plan])
    }

    return [...map.entries()].map(([model, plans]) => ({ model, plans }))
})

function isUrl(value: string | null): boolean {
    return !!value && /^https?:\/\//i.test(value.trim())
}

/**
 * The chip at the top right: what this card wants from the reader. On the
 * pending desk that is either "decide it" or "someone else has it"; in the log
 * it is what they decided.
 */
const mark = computed<{ label: string; tone: Tone; icon: string }>(() => {
    if (props.history) {
        if (props.item.auto) {
            return { label: t.value.approvalFlow.autoApproved, tone: 'slate', icon: 'fa-solid fa-circle-check' }
        }

        return props.item.decision === 'rejected'
            ? { label: t.value.approvalFlow.youRejected, tone: 'red', icon: 'fa-solid fa-circle-xmark' }
            : { label: t.value.approvalFlow.youApproved, tone: 'emerald', icon: 'fa-solid fa-circle-check' }
    }

    if (canAct.value) {
        return { label: t.value.approvalFlow.needsYourApproval, tone: 'amber', icon: 'fa-solid fa-hourglass-half' }
    }

    const layer = `${t.value.approvalFlow.waitingForLayer} ${props.item.awaiting_level ?? '?'}`

    return {
        label: props.item.awaiting_name ? `${layer} · ${props.item.awaiting_name}` : layer,
        tone: 'slate',
        icon: 'fa-regular fa-clock',
    }
})

/** Where the request went after this layer signed it off (log only). */
const outcome = computed<{ label: string; tone: Tone }>(() => {
    if (props.item.outcome === 'approved') {
        return { label: t.value.approvalFlow.outcomeApproved, tone: 'emerald' }
    }

    if (props.item.outcome === 'rejected') {
        return { label: t.value.approvalFlow.outcomeRejected, tone: 'red' }
    }

    const level = props.item.chain?.current_level

    return {
        label: level
            ? `${t.value.approvalFlow.outcomePending} ${level}`
            : t.value.approvalFlow.outcomeMoving,
        tone: 'amber',
    }
})
</script>

<template>
    <article
        class="overflow-hidden rounded-xl border bg-white shadow-sm transition"
        :class="canAct ? 'border-amber-300 ring-1 ring-amber-200/70' : 'border-border'"
    >
        <!--
            The header is the whole card face, and the disclosure control when
            there is something behind it. `role="button"` rather than a <button>
            element, so the link inside it stays valid and keeps working.
        -->
        <header
            :role="expandable ? 'button' : undefined"
            :tabindex="expandable ? 0 : undefined"
            :aria-expanded="expandable ? open : undefined"
            class="flex flex-col gap-3 px-5 py-3.5 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40 lg:flex-row lg:items-center lg:justify-between"
            :class="expandable ? 'cursor-pointer hover:bg-slate-50/70' : ''"
            @click="toggle"
            @keydown.enter.prevent="toggle"
            @keydown.space.prevent="toggle"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg"
                    :class="isPlanning ? 'bg-primary/10 text-primary' : 'bg-emerald-50 text-emerald-600'"
                    :title="isPlanning ? t.approvalFlow.stagePlanning : t.approvalFlow.stageResult"
                >
                    <i :class="isPlanning ? 'fa-solid fa-file-signature' : 'fa-solid fa-clipboard-check'" />
                </span>

                <div class="min-w-0">
                    <p class="flex flex-wrap items-baseline gap-x-2">
                        <span class="truncate font-bold text-slate-800">{{ item.owner_name }}</span>
                        <span class="text-xs text-slate-400">{{ item.owner_id }}</span>
                    </p>

                    <p class="truncate text-sm text-slate-600">
                        {{ headline }}
                        <span v-if="isPlanning && item.package" class="text-slate-400">· {{ item.package.name }}</span>
                    </p>

                    <p class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                        <span>{{ t.approvalFlow.layer }} {{ item.level }}/{{ item.total_levels }}</span>
                        <span v-if="history && item.decided_at">
                            <i class="fa-regular fa-clock mr-1" />{{ fmtDateTime(item.decided_at) }}
                        </span>
                        <span v-else-if="item.submitted_at">
                            <i class="fa-solid fa-paper-plane mr-1" />{{ fmtDateTime(item.submitted_at) }}
                        </span>
                        <span v-if="history" :class="outcome.tone === 'red' ? 'text-red-600' : outcome.tone === 'emerald' ? 'text-emerald-600' : 'text-amber-600'">
                            <i class="fa-solid fa-arrow-right-long mr-1 text-[10px]" />{{ outcome.label }}
                        </span>
                        <span v-if="previous" class="font-medium text-amber-600">
                            <i class="fa-solid fa-rotate mr-1" />{{ t.approvalFlow.resubmitted }}<template v-if="changeCount">
                                · {{ t.approvalFlow.changesCount.replace('{n}', String(changeCount)) }}</template>
                        </span>
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2 self-start lg:self-center">
                <StatusPill :tone="mark.tone" :label="mark.label" :icon="mark.icon" size="sm" />

                <!-- Decided on the employee's plan, where the whole of it is read first -->
                <Link
                    :href="idpHref"
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="canAct
                        ? 'bg-primary text-white shadow-sm hover:bg-primary-hover'
                        : 'border border-border text-primary hover:bg-primary/5'"
                    @click.stop
                >
                    {{ canAct ? t.approvalFlow.reviewAndDecide : t.approvalFlow.openEmployeeIdp }}
                    <i class="fa-solid fa-arrow-right text-[10px]" />
                </Link>

                <span
                    v-if="expandable"
                    class="flex h-7 w-7 items-center justify-center rounded-lg border border-border text-slate-400 transition"
                    :class="open ? 'bg-slate-50' : ''"
                    :title="open ? t.approvalFlow.hideDetails : t.approvalFlow.showDetails"
                >
                    <i :class="open ? 'fa-solid fa-chevron-up' : 'fa-solid fa-chevron-down'" class="text-[10px]" />
                </span>
            </div>
        </header>

        <!-- The note this person left on the decision -->
        <p
            v-if="history && item.note && !item.auto"
            class="border-t border-border bg-slate-50/70 px-5 py-2.5 text-xs leading-relaxed text-slate-600"
        >
            <i class="fa-solid fa-quote-left mr-1.5 text-[10px] text-slate-300" />
            {{ item.note }}
        </p>

        <!-- Only what the employee's IDP page cannot show -->
        <div v-if="expandable" v-show="open" class="divide-y divide-border border-t border-border">
            <!-- A log entry that predates snapshots can only show today's plan -->
            <p v-if="history && !item.frozen" class="flex items-start gap-2 bg-slate-50 px-5 py-2.5 text-xs text-slate-500">
                <i class="fa-solid fa-circle-info mt-0.5 text-slate-400" />
                {{ t.approvalFlow.liveCopy }}
            </p>

            <!--
                What this round is measured against: the one before it — how it
                ended, why, and what the owner changed since.
            -->
            <section v-if="previous" class="bg-amber-50/40 px-5 py-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-700">
                        <i class="fa-solid fa-code-compare mr-1" />
                        {{ t.approvalFlow.comparePrevious }}
                    </p>
                    <p class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                        <span v-if="previous.submitted_at">
                            {{ t.approvalFlow.previousSubmitted }}: {{ fmtDateTime(previous.submitted_at) }}
                        </span>
                        <StatusPill :tone="previousTone" :label="previousStatusLabel" size="sm" />
                    </p>
                </div>

                <div
                    v-if="previous.rejected"
                    class="mt-3 rounded-lg bg-white px-3 py-2 text-xs leading-relaxed text-slate-600 ring-1 ring-inset ring-red-100"
                >
                    <p class="font-medium text-red-700">
                        <i class="fa-solid fa-circle-xmark mr-1" />
                        {{ rejectedBy }}
                        <span v-if="previous.rejected.at" class="font-normal text-slate-400">
                            · {{ fmtDateTime(previous.rejected.at) }}
                        </span>
                    </p>
                    <p v-if="previous.rejected.note" class="mt-1 whitespace-pre-line">
                        <i class="fa-solid fa-quote-left mr-1 text-[10px] text-slate-300" />
                        {{ previous.rejected.note }}
                    </p>
                </div>

                <p v-if="!diff" class="mt-3 text-xs text-slate-500">{{ t.approvalFlow.compareUnavailable }}</p>
                <p v-else-if="!changeCount" class="mt-3 text-xs text-slate-500">
                    <i class="fa-solid fa-equals mr-1 text-slate-400" />
                    {{ t.approvalFlow.noChanges }}
                </p>

                <template v-else>
                    <div class="mt-3 flex flex-wrap gap-2 text-[11px] font-semibold">
                        <span v-if="diff.added.length" class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700 ring-1 ring-inset ring-emerald-200">
                            + {{ diff.added.length }} {{ t.approvalFlow.added }}
                        </span>
                        <span v-if="diff.removed.length" class="rounded-full bg-red-50 px-2 py-0.5 text-red-700 ring-1 ring-inset ring-red-200">
                            − {{ diff.removed.length }} {{ t.approvalFlow.removed }}
                        </span>
                        <span
                            v-if="Object.keys(diff.changed).length"
                            class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700 ring-1 ring-inset ring-amber-200"
                        >
                            ~ {{ Object.keys(diff.changed).length }} {{ t.approvalFlow.changed }}
                        </span>
                    </div>

                    <!-- A result is one program, so its changes are read here -->
                    <ul v-if="single && changesOf(single).length" class="mt-3 space-y-1.5 text-xs">
                        <li v-for="change in changesOf(single)" :key="change.field" class="flex flex-wrap items-baseline gap-x-1.5 break-words">
                            <span class="font-medium text-slate-500">{{ change.label }}:</span>
                            <span class="text-red-600/80 line-through">{{ change.before }}</span>
                            <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                            <span class="font-medium text-emerald-700">{{ change.after }}</span>
                        </li>
                    </ul>

                    <!-- A plan set: each program added or changed, with its before → after -->
                    <ul v-if="!single && touchedPlans.length" class="mt-3 space-y-2.5 text-xs">
                        <li v-for="plan in touchedPlans" :key="plan.id">
                            <p class="text-slate-700">
                                <span
                                    class="mr-1 rounded px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase ring-1 ring-inset"
                                    :class="planMark(plan)!.cls"
                                >{{ planMark(plan)!.label }}</span>
                                <span class="font-medium">{{ plan.competency_name }}</span>
                                · {{ plan.development_program }}
                            </p>
                            <ul v-if="changesOf(plan).length" class="mt-1 space-y-0.5 pl-3">
                                <li v-for="change in changesOf(plan)" :key="change.field" class="flex flex-wrap items-baseline gap-x-1.5 break-words">
                                    <span class="font-medium text-slate-500">{{ change.label }}:</span>
                                    <span class="text-red-600/80 line-through">{{ change.before }}</span>
                                    <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                                    <span class="font-medium text-emerald-700">{{ change.after }}</span>
                                </li>
                            </ul>
                        </li>
                    </ul>

                    <!-- Programs the earlier round had and this one does not -->
                    <div v-if="diff.removed.length" class="mt-3">
                        <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                            {{ t.approvalFlow.removedPrograms }}
                        </p>
                        <ul class="space-y-1 text-xs text-slate-500">
                            <li v-for="plan in diff.removed" :key="plan.id" class="line-through decoration-red-300">
                                <span class="font-medium text-slate-600">{{ plan.competency_name }}</span>
                                · {{ plan.development_program }}
                                <span v-if="plan.development_model" class="text-slate-400">({{ plan.development_model }})</span>
                            </li>
                        </ul>
                    </div>
                </template>
            </section>
            <!--
                A RESULT is read as a pair: what was planned against what was
                delivered. Anything the strip above already shows is a headline
                here, not a repeat — the evidence in particular, which the strip
                can only truncate.
            -->
            <div v-if="history && single" class="grid grid-cols-1 gap-4 px-5 py-4 lg:grid-cols-2">
                <section>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        {{ t.idp.status.planned }}
                    </p>

                    <p class="text-sm font-medium text-slate-800">{{ single.competency_name }}</p>

                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700">
                            {{ single.competency_type }}
                        </span>
                        <span
                            v-if="single.review_tools"
                            class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                        >
                            <i class="fa-solid fa-clipboard-check text-[10px]" />
                            {{ single.review_tools }}
                        </span>
                    </div>

                    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ single.development_program }}</p>

                    <p v-if="single.expected_outcome" class="mt-1.5 whitespace-pre-line text-xs leading-relaxed text-slate-500">
                        <span class="font-medium text-slate-600">{{ t.idp.outcomeLabel }}:</span>
                        {{ single.expected_outcome }}
                    </p>

                    <!-- What the result below is measured against -->
                    <p
                        v-if="target(single)"
                        class="mt-2 inline-flex items-center gap-1.5 rounded bg-primary/5 px-2 py-1 text-xs font-medium text-primary"
                    >
                        <i class="fa-solid fa-bullseye text-[10px]" />
                        {{ t.idp.targetLabel }}:
                        <span class="tabular-nums">{{ target(single) }}</span>
                    </p>
                </section>

                <section class="rounded-lg bg-emerald-50/40 p-3 ring-1 ring-inset ring-emerald-100">
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-emerald-700">
                        {{ t.idp.table.result }}
                    </p>

                    <div
                        v-if="achieved(single)"
                        class="mb-2 flex flex-wrap items-baseline gap-x-2 gap-y-1"
                    >
                        <span class="text-lg font-bold leading-none tabular-nums text-slate-800">
                            {{ achieved(single) }}
                        </span>
                        <span v-if="target(single)" class="text-xs text-slate-500">
                            / {{ target(single) }}
                        </span>
                        <span
                            v-if="achievedPercent(single) !== null"
                            class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold tabular-nums text-emerald-700 ring-1 ring-inset ring-emerald-200"
                        >{{ achievedPercent(single) }}%</span>
                    </div>

                    <p class="text-sm font-medium text-slate-800">
                        <i class="fa-regular fa-calendar-check mr-1.5 text-emerald-600" />
                        {{ single.realization_date ? fmt(single.realization_date) : '—' }}
                    </p>

                    <div class="mt-2 text-xs leading-relaxed text-slate-600">
                        <span class="font-medium text-slate-500">{{ t.idp.evidenceLabel }}:</span>
                        <a
                            v-if="isUrl(single.result_evidence)"
                            :href="single.result_evidence!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ml-1 inline-flex items-center gap-1 break-all font-medium text-primary hover:underline"
                        >
                            <i class="fa-solid fa-link text-[10px]" />
                            {{ single.result_evidence }}
                        </a>
                        <span v-else-if="single.result_evidence" class="ml-1 whitespace-pre-line">{{ single.result_evidence }}</span>
                        <span v-else class="ml-1 text-slate-400">—</span>
                    </div>
                </section>
            </div>

            <!-- A PLAN is read as the set it is: every program, grouped by model. -->
            <template v-else-if="history">
                <div v-for="group in groups" :key="group.model" class="border-b border-border last:border-b-0">
                    <p class="bg-slate-50/70 px-5 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        {{ group.model }}
                        <span class="font-normal text-slate-400">· {{ group.plans.length }}</span>
                    </p>

                    <div
                        v-for="plan in group.plans"
                        :key="plan.id"
                        class="grid grid-cols-1 gap-3 px-5 py-3.5 lg:grid-cols-[1.1fr_1.6fr_auto]"
                    >
                        <!-- What it develops -->
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-800">
                                {{ plan.competency_name }}
                                <span
                                    v-if="planMark(plan)"
                                    class="ml-1 rounded px-1.5 py-0.5 align-middle text-[10px] font-semibold uppercase ring-1 ring-inset"
                                    :class="planMark(plan)!.cls"
                                >{{ planMark(plan)!.label }}</span>
                            </p>
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
                            <span
                                v-if="target(plan)"
                                class="mt-1.5 inline-flex items-center gap-1 rounded bg-primary/5 px-1.5 py-0.5 text-[11px] font-medium text-primary"
                            >
                                <i class="fa-solid fa-bullseye text-[10px]" />
                                {{ t.idp.targetLabel }}:
                                <span class="tabular-nums">{{ target(plan) }}</span>
                            </span>

                        </div>

                        <!-- When it runs -->
                        <div class="shrink-0 text-xs text-slate-500 lg:text-right">
                            <p class="whitespace-nowrap">
                                <i class="fa-regular fa-calendar mr-1 text-slate-300" />
                                {{ fmt(plan.time_frame_start) }}
                                <i class="fa-solid fa-arrow-right-long mx-1 text-[10px] text-slate-300" />
                                {{ plan.time_frame_end ? fmt(plan.time_frame_end) : '—' }}
                            </p>
                        </div>
                    </div>
                </div>
            </template>

            <!-- The programs it covered are gone; the decision is not -->
            <p v-if="history && !item.plans.length" class="px-5 py-4 text-xs text-slate-400">
                {{ t.approvalFlow.planGone }}
            </p>

        </div>
    </article>
</template>
