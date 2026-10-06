<script setup lang="ts">
/**
 * One development program in the plan table, with both of its stages side by
 * side: where its PLANNING stands (approved as part of the set, or not yet) and
 * where its RESULT stands (locked, open, submitted, signed off).
 *
 * The result column is also where the work happens — file it, submit it, or,
 * if you are the approver whose turn it is, decide on it — so the row is read
 * and acted on in the same place.
 */
import { computed } from 'vue'
import StatusPill from '@/Components/Domain/Idp/StatusPill.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate } from '@/Composables/useDate'
import { daysLate, planningKey, resultKey } from '@/Components/Domain/Idp/planStatus'
import EvidenceLink from '@/Components/Domain/Idp/EvidenceLink.vue'
import { attainment, formatTarget } from '@/Components/Domain/Idp/uom'
import type { Plan, Tone } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    plan: Plan
    /** Labels already resolved to the active language by the parent. */
    competencyLabel: string
    typeLabel: string
    programLabel: string
    reviewToolLabel: string
    /** value => unit label, already in the active language. */
    uomLabels: Record<string, string>
    timeline: { key: string; label: string; late: string; badge: string; dot: string }
    canEdit: boolean
    submitting: boolean
    /** Pointed at by a Task Box link: tinted, amber while it waits on the viewer. */
    highlighted?: boolean
}>()

const emit = defineEmits<{
    (e: 'edit'): void
    (e: 'delete'): void
    (e: 'file-result'): void
    (e: 'submit-result'): void
    (e: 'act', decision: 'approve' | 'reject'): void
    (e: 'open-chain'): void
}>()

const r = computed(() => t.value.idp.result)

/** Days the realization date falls after the planned end, or null when it is on time. */
const realizedLate = computed(() => (props.plan.realization_date ? daysLate(props.plan) : null))
const s = computed(() => t.value.idp.stage)
const stage = computed(() => props.plan.stage)
const result = computed(() => stage.value.result)

// Both pills read the shared state helpers, so what a chip says and what the
// filter bar puts a row under are decided in one place.

/** The PLANNING state of this one row, within the set's own state. */
const planningPill = computed<{ tone: Tone; label: string; icon: string }>(() => {
    switch (planningKey(props.plan)) {
        case 'approved':
            return { tone: 'emerald', icon: 'fa-solid fa-circle-check', label: s.value.planApproved }
        case 'inReview':
            return { tone: 'amber', icon: 'fa-solid fa-hourglass-half', label: s.value.inReview }
        default:
            return { tone: 'slate', icon: 'fa-regular fa-pen-to-square', label: s.value.notApprovedYet }
    }
})

const resultPill = computed<{ tone: Tone; label: string; icon: string }>(() => {
    switch (resultKey(props.plan)) {
        case 'approved':
            return { tone: 'emerald', icon: 'fa-solid fa-circle-check', label: t.value.approvalFlow.statusApproved }
        case 'rejected':
            return { tone: 'red', icon: 'fa-solid fa-circle-xmark', label: t.value.approvalFlow.statusRejected }
        case 'pending':
            // Whose turn it is is a rendering detail, not a different state.
            return result.value.can_act
                ? { tone: 'amber', icon: 'fa-solid fa-gavel', label: t.value.approvalFlow.needsYourApproval }
                : {
                    tone: 'amber',
                    icon: 'fa-solid fa-hourglass-half',
                    label: `${t.value.approvalFlow.waiting} ${t.value.approvalFlow.layerShort}${result.value.approval?.current_level ?? 1}`,
                }
        case 'ready':
            return { tone: 'sky', icon: 'fa-solid fa-paper-plane', label: r.value.readyToSubmit }
        case 'notFiled':
            return { tone: 'slate', icon: 'fa-regular fa-circle', label: r.value.notFiled }
        default:
            return { tone: 'slate', icon: 'fa-solid fa-lock', label: r.value.locked }
    }
})

/** Why the row cannot be edited, for the disabled control's tooltip. */
const lockReason = computed(() => {
    if (stage.value.frozen_by_planning) return s.value.lockedInReview
    if (result.value.status === 'pending') return r.value.lockedResultPending
    if (result.value.status === 'approved') return r.value.lockedResultApproved
    return ''
})


const hasChain = computed(() => (result.value.approval?.steps.length ?? 0) > 0)

/** "3 Hectare (ha)", or '' when this plan carries no target. */
const targetLabel = computed(() => formatTarget(props.uomLabels, props.plan.target, props.plan.uom))

/** What was reached, in the same unit — the counterpart of the target above. */
const achievementLabel = computed(
    () => formatTarget(props.uomLabels, props.plan.achievement, props.plan.uom),
)

/** How far that got. Null when there is nothing to divide by. */
const achievedPercent = computed(() => attainment(props.plan.target, props.plan.achievement))

/**
 * How a pointed-at row is marked: amber while it waits on this viewer's
 * decision, red while it waits on the owner's revision, otherwise a plain tint.
 */
const mark = computed<'decide' | 'revise' | 'plain'>(() => {
    if (result.value.can_act) return 'decide'
    if (result.value.status === 'rejected' && stage.value.can_edit) return 'revise'
    return 'plain'
})
</script>

<template>
    <tr
        :id="`plan-row-${plan.id}`"
        class="group scroll-mt-24 border-b border-border/60 align-top transition-colors duration-500 last:border-0"
        :class="
            highlighted
                ? mark === 'decide'
                    ? 'bg-amber-50 hover:bg-amber-50'
                    : mark === 'revise'
                        ? 'bg-red-50/70 hover:bg-red-50/70'
                        : 'bg-primary/5 hover:bg-primary/5'
                : 'hover:bg-slate-50/70'
        "
    >
        <!-- What it develops. A highlighted row carries its mark on this edge. -->
        <td
            class="px-5 py-3.5"
            :class="
                highlighted
                    ? mark === 'decide'
                        ? 'shadow-[inset_4px_0_0_0_#f59e0b]'
                        : mark === 'revise'
                            ? 'shadow-[inset_4px_0_0_0_#ef4444]'
                            : 'shadow-[inset_4px_0_0_0_var(--color-primary)]'
                    : ''
            "
        >
            <span
                v-if="highlighted && mark === 'decide'"
                class="mb-1.5 inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold text-amber-800"
            >
                <i class="fa-solid fa-hourglass-half text-[10px]" />
                {{ t.approvalFlow.needsYourApproval }}
            </span>
            <span
                v-else-if="highlighted && mark === 'revise'"
                class="mb-1.5 inline-flex items-center gap-1 rounded-md bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700"
            >
                <i class="fa-solid fa-rotate-left text-[10px]" />
                {{ t.approvalFlow.reviseBadge }}
            </span>
            <div class="font-medium text-slate-800">{{ competencyLabel }}</div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-medium text-indigo-700">
                    {{ typeLabel }}
                </span>
                <span
                    v-if="plan.review_tools"
                    class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                >
                    <i class="fa-solid fa-clipboard-check text-[10px]" />
                    {{ reviewToolLabel }}
                </span>
            </div>
            <div class="mt-1.5 max-w-sm text-xs leading-relaxed text-slate-500">{{ programLabel }}</div>
            <div v-if="plan.expected_outcome" class="mt-1 max-w-sm whitespace-pre-line text-xs text-slate-400">
                <span class="font-medium">{{ t.idp.outcomeLabel }}:</span> {{ plan.expected_outcome }}
            </div>
            <div
                v-if="targetLabel"
                class="mt-1.5 inline-flex items-center gap-1 rounded bg-primary/5 px-1.5 py-0.5 text-[11px] font-medium text-primary"
            >
                <i class="fa-solid fa-bullseye text-[10px]" />
                {{ t.idp.targetLabel }}: <span class="tabular-nums">{{ targetLabel }}</span>
            </div>
        </td>

        <!-- When it runs -->
        <td class="px-5 py-3.5 text-slate-500">
            <div class="flex items-center gap-1.5 whitespace-nowrap text-sm">
                <i class="fa-regular fa-calendar text-xs text-slate-300" />
                <span>{{ formatDate(plan.time_frame_start) }}</span>
                <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                <span>{{ plan.time_frame_end ? formatDate(plan.time_frame_end) : '—' }}</span>
            </div>
            <span
                class="mt-1.5 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset"
                :class="timeline.badge"
            >
                <span class="h-1.5 w-1.5 rounded-full" :class="timeline.dot" />
                {{ timeline.label }}
                <span v-if="timeline.late" class="font-normal opacity-75">{{ timeline.late }}</span>
            </span>
        </td>

        <!-- Stage 2: is the plan itself signed off? -->
        <td class="px-5 py-3.5">
            <StatusPill v-bind="planningPill" :dot="false" size="sm" />
            <p
                v-if="stage.planning_approved && stage.planning_approved_at"
                class="mt-1 text-[11px] text-slate-400"
            >
                {{ formatDate(stage.planning_approved_at) }}
            </p>
        </td>

        <!-- Stages 3 + 4: the result, and everything you can do about it -->
        <td class="px-5 py-3.5">
            <div class="flex flex-col items-start gap-1.5">
                <StatusPill v-bind="resultPill" :dot="false" size="sm" />

                <!-- What was filed -->
                <template v-if="plan.realization_date">
                    <!-- The number first: it is what the row is really about -->
                    <p
                        v-if="achievementLabel"
                        class="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-slate-700"
                    >
                        <i class="fa-solid fa-flag-checkered text-[10px] text-slate-300" />
                        <span class="tabular-nums">{{ achievementLabel }}</span>
                        <span
                            v-if="achievedPercent !== null"
                            class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-slate-500"
                        >{{ achievedPercent }}%</span>
                    </p>
                    <p class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
                        <span>
                            <i class="fa-regular fa-calendar-check mr-1 text-slate-300" />{{ formatDate(plan.realization_date) }}
                        </span>
                        <!-- Realized after the time frame ended -->
                        <span
                            v-if="realizedLate"
                            class="inline-flex items-center gap-1 rounded bg-orange-50 px-1.5 py-0.5 font-semibold text-orange-700 ring-1 ring-orange-200"
                            :title="t.idp.status.completedLate"
                        >
                            <i class="fa-solid fa-clock-rotate-left text-[9px]" />
                            {{ realizedLate === 1 ? r.lateOneDay : r.lateDays.replace('{n}', String(realizedLate)) }}
                        </span>
                    </p>
                    <p v-if="plan.result_evidence" class="max-w-[16rem] text-[11px]">
                        <EvidenceLink :value="plan.result_evidence" compact />
                    </p>
                </template>

                <!-- The approver's decision, inline -->
                <div v-if="result.can_act" class="flex items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-emerald-500 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-emerald-600"
                        @click="emit('act', 'approve')"
                    >
                        <i class="fa-solid fa-check" />
                        {{ t.approvalFlow.approve }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-red-500 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-red-600"
                        @click="emit('act', 'reject')"
                    >
                        <i class="fa-solid fa-xmark" />
                        {{ t.approvalFlow.reject }}
                    </button>
                </div>

                <!-- The owner's next move -->
                <div v-else-if="result.can_submit" class="flex flex-wrap items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-1 text-[11px] font-semibold text-primary transition hover:bg-primary hover:text-white"
                        @click="emit('file-result')"
                    >
                        <i class="fa-solid" :class="result.filled ? 'fa-pen' : 'fa-plus'" />
                        {{
                            result.status === 'rejected'
                                ? r.reviseAndResubmit
                                : result.filled ? r.editResult : r.fileResult
                        }}
                    </button>
                    <button
                        v-if="result.filled && result.status !== 'rejected'"
                        type="button"
                        :disabled="submitting"
                        class="inline-flex items-center gap-1 rounded-md bg-primary px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                        @click="emit('submit-result')"
                    >
                        <i v-if="submitting" class="fa-solid fa-spinner fa-spin" />
                        <i v-else class="fa-solid fa-paper-plane" />
                        {{ r.submit }}
                    </button>
                </div>

                <!-- Nothing to do here yet, and why -->
                <p
                    v-else-if="result.status === 'locked' && canEdit"
                    class="flex items-start gap-1 text-[11px] text-slate-400"
                >
                    <i class="fa-solid fa-circle-info mt-0.5" />
                    {{ r.lockedHint }}
                </p>

                <button
                    v-if="hasChain"
                    type="button"
                    class="text-[11px] font-medium text-slate-400 transition hover:text-primary hover:underline"
                    @click="emit('open-chain')"
                >
                    <i class="fa-solid fa-list-ol mr-0.5" />
                    {{ t.approvalFlow.viewChain }}
                </button>
            </div>
        </td>

        <!-- Editing the plan itself -->
        <td v-if="canEdit" class="px-5 py-3.5 text-right">
            <div class="inline-flex gap-1">
                <template v-if="stage.can_edit || stage.can_delete">
                    <button
                        v-if="stage.can_edit"
                        type="button"
                        class="h-8 w-8 rounded-md text-slate-400 opacity-60 transition hover:bg-slate-100 hover:text-primary group-hover:opacity-100"
                        :title="t.idp.editPlan"
                        @click="emit('edit')"
                    >
                        <i class="fa-solid fa-pen text-xs" />
                    </button>
                    <button
                        v-if="stage.can_delete"
                        type="button"
                        class="h-8 w-8 rounded-md text-slate-400 opacity-60 transition hover:bg-red-50 hover:text-red-600 group-hover:opacity-100"
                        :title="t.idp.form.delete"
                        @click="emit('delete')"
                    >
                        <i class="fa-solid fa-trash text-xs" />
                    </button>
                </template>
                <span
                    v-else
                    class="inline-flex h-8 w-8 items-center justify-center text-slate-300"
                    :title="lockReason"
                >
                    <i class="fa-solid fa-lock text-xs" />
                </span>
            </div>
        </td>
    </tr>
</template>
