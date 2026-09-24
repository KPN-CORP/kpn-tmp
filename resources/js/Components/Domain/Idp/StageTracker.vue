<script setup lang="ts">
/**
 * The header of the IDP manage screen: where this employee's plan stands in the
 * four-stage workflow, and the one thing to do about it next.
 *
 *   1. Planning          — write the programs
 *   2. Planning approval — the whole set is signed off once, layer by layer
 *   3. Results           — file each program's realization + evidence
 *   4. Result approval   — each result is signed off on its own
 *
 * Signing the plan off is NOT here — neither submitting it nor an approver's
 * approve/reject: both sit under the plan list (`PlanSignOffCard`), so they are
 * only reached after reading every program.
 *
 * Every state the plan can be in resolves to exactly one primary action and one
 * plain-language line saying why, so nobody has to work out what is blocking
 * them from a grid of badges.
 *
 * It is one card: who the plan belongs to, the cycle, the four stages and the
 * next action — plus a `tools` slot (the plan filters). With `sticky` it pins
 * under the top bar on large screens, so the plans scroll beneath it.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import StatusPill from '@/Components/Domain/Idp/StatusPill.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import type { PackageOption, PlanningState, StageProgress, Tone } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    employee: { employee_id: string; fullname: string; designation_name: string | null }
    /** Pin under the top bar (large screens) while the plans scroll. */
    sticky?: boolean
    planning: PlanningState
    progress: StageProgress
    /** Results that are filled in and could be submitted right now. */
    readyResults: number
    canEdit: boolean
    submittingResults: boolean
    /** Every cycle, so one can be picked; the screen shows one at a time. */
    packages: PackageOption[]
    selectedPackageId: number | null
    /** False for a closed cycle — everything on screen is then read-only. */
    viewingActive: boolean
}>()

const emit = defineEmits<{
    (e: 'submit-results'): void
    (e: 'open-chain'): void
    (e: 'select-package', id: number): void
}>()

const initials = computed(() =>
    props.employee.fullname
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
)

// Whether the card is currently pinned — only to deepen its shadow, so it reads
// as floating above the plans rather than as one more card in the flow. A
// zero-height sentinel above it leaves the viewport exactly when it sticks.
const sentinel = ref<HTMLElement | null>(null)
const stuck = ref(false)
let observer: IntersectionObserver | null = null

onMounted(() => {
    if (!props.sticky || !sentinel.value || typeof IntersectionObserver === 'undefined') return
    const topbar = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-height')) || 0
    observer = new IntersectionObserver(
        ([entry]) => {
            stuck.value = !entry.isIntersecting
        },
        { rootMargin: `-${topbar + 1}px 0px 0px 0px` },
    )
    observer.observe(sentinel.value)
})
onBeforeUnmount(() => observer?.disconnect())

type StepState = 'done' | 'current' | 'todo' | 'attention' | 'rejected'

const s = computed(() => t.value.idp.stage)

/** The layer the set is currently sitting with, for the "who has it" line. */
const currentStep = computed(() => {
    const approval = props.planning.approval
    if (!approval || approval.status !== 'pending') return null
    return approval.steps.find((step) => step.level === approval.current_level) ?? null
})

const planningStatusPill = computed<{ tone: Tone; label: string; icon: string }>(() => {
    switch (props.planning.status) {
        case 'pending':
            return {
                tone: 'amber',
                icon: 'fa-solid fa-hourglass-half',
                label: props.planning.approval?.can_act
                    ? t.value.approvalFlow.needsYourApproval
                    : `${t.value.approvalFlow.waiting} ${t.value.approvalFlow.layerShort}${props.planning.approval?.current_level ?? 1}`,
            }
        case 'approved':
            return { tone: 'emerald', icon: 'fa-solid fa-circle-check', label: s.value.planApproved }
        case 'rejected':
            return { tone: 'red', icon: 'fa-solid fa-circle-xmark', label: s.value.planRejected }
        case 'revision':
            return { tone: 'sky', icon: 'fa-solid fa-rotate', label: s.value.planChanged }
        default:
            return { tone: 'slate', icon: 'fa-regular fa-pen-to-square', label: s.value.planDraft }
    }
})

/**
 * The four steps, each resolved to a state and a one-line read-out. Order is
 * the workflow's own, so the row reads left to right as the plan progresses.
 */
const steps = computed(() => {
    const p = props.progress
    const status = props.planning.status
    const total = p.plans

    // 1 — writing the plan
    const planning: StepState = total === 0 ? 'current' : 'done'

    // 2 — the one-off sign-off
    const planningApproval: StepState =
        status === 'approved' ? 'done'
            : status === 'pending' ? 'current'
                : status === 'rejected' ? 'rejected'
                    : status === 'revision' ? 'attention'
                        : 'todo'

    // 3 — filing results (locked until the plan is approved)
    const filed = p.result_pending + p.result_approved + p.result_rejected
    const results: StepState =
        p.result_locked === total && total > 0 ? 'todo'
            : filed === 0 ? (p.result_open > 0 ? 'current' : 'todo')
                : filed === total ? 'done'
                    : 'current'

    // 4 — signing the results off
    const resultApproval: StepState =
        total > 0 && p.result_approved === total ? 'done'
            : p.result_rejected > 0 ? 'attention'
                : p.result_pending > 0 ? 'current'
                    : 'todo'

    return [
        {
            key: 'planning',
            state: planning,
            icon: 'fa-solid fa-pen-ruler',
            title: s.value.stepPlanning,
            detail: `${total} ${total === 1 ? t.value.idp.planSingular : t.value.idp.planPlural}`,
        },
        {
            key: 'planningApproval',
            state: planningApproval,
            icon: 'fa-solid fa-file-signature',
            title: s.value.stepPlanningApproval,
            detail:
                status === 'pending'
                    ? `${t.value.approvalFlow.layer} ${props.planning.approval?.current_level ?? 1}/${props.planning.approval?.total_levels ?? 1}`
                    : status === 'approved'
                        ? s.value.signedOff
                        : status === 'revision'
                            ? s.value.approvalWithdrawn
                            : status === 'rejected'
                                ? t.value.approvalFlow.statusRejected
                                : s.value.notSubmitted,
        },
        {
            key: 'results',
            state: results,
            icon: 'fa-solid fa-clipboard-check',
            title: s.value.stepResults,
            detail: `${filed}/${total} ${s.value.filed}`,
        },
        {
            key: 'resultApproval',
            state: resultApproval,
            icon: 'fa-solid fa-award',
            title: s.value.stepResultApproval,
            detail: p.result_rejected
                ? `${p.result_rejected} ${t.value.approvalFlow.statusRejected.toLowerCase()}`
                : `${p.result_approved}/${total} ${s.value.approvedCount}`,
        },
    ]
})

const stateStyle: Record<
    StepState,
    { ring: string; icon: string; text: string; bar: string; bg: string; focus: string; badge: string }
> = {
    done: {
        ring: 'bg-emerald-100 ring-emerald-200', icon: 'text-emerald-600', text: 'text-emerald-700', bar: 'bg-emerald-400',
        bg: 'bg-emerald-50', focus: 'bg-emerald-50 ring-emerald-300', badge: 'bg-emerald-500',
    },
    current: {
        ring: 'bg-amber-100 ring-amber-200', icon: 'text-amber-600', text: 'text-amber-700', bar: 'bg-amber-400',
        bg: 'bg-amber-50/60', focus: 'bg-amber-50 ring-amber-300', badge: 'bg-amber-500',
    },
    attention: {
        ring: 'bg-sky-100 ring-sky-200', icon: 'text-sky-600', text: 'text-sky-700', bar: 'bg-sky-400',
        bg: 'bg-sky-50/60', focus: 'bg-sky-50 ring-sky-300', badge: 'bg-sky-500',
    },
    rejected: {
        ring: 'bg-red-100 ring-red-200', icon: 'text-red-600', text: 'text-red-700', bar: 'bg-red-400',
        bg: 'bg-red-50/60', focus: 'bg-red-50 ring-red-300', badge: 'bg-red-500',
    },
    todo: {
        ring: 'bg-slate-100 ring-slate-200', icon: 'text-slate-400', text: 'text-slate-400', bar: 'bg-slate-200',
        bg: 'bg-white', focus: 'bg-slate-50 ring-slate-300', badge: 'bg-slate-500',
    },
}

/**
 * Where the plan is right now: the first stage not yet done. Several stages can
 * read "current" at once (results filed while others await approval), but the
 * workflow moves left to right, so the leftmost unfinished one is the position.
 * Every stage done means there is nowhere left to be.
 */
const focusIndex = computed(() => steps.value.findIndex((step) => step.state !== 'done'))

/**
 * The single sentence under the tracker: what is happening and what it is
 * waiting on. Ordered by what blocks progress first.
 */
const hint = computed(() => {
    const { status, has_approvers, total_plans } = props.planning

    if (!props.planning.package) return s.value.hintNoPackage
    // A closed cycle answers every other question: nothing about it can move.
    if (!props.viewingActive) return s.value.hintClosedCycle
    if (total_plans === 0) return s.value.hintNoPlans
    if (!has_approvers) return s.value.hintNoApprovers

    if (status === 'pending') {
        if (props.planning.approval?.can_act) return s.value.hintYourDecision

        const who = currentStep.value?.approver_name ?? currentStep.value?.approver_id ?? ''
        return s.value.hintPending
            .replace('{name}', who)
            .replace('{level}', String(props.planning.approval?.current_level ?? 1))
            .replace('{total}', String(props.planning.approval?.total_levels ?? 1))
    }

    if (status === 'rejected') return s.value.hintRejected
    if (status === 'revision') return s.value.hintRevision
    if (status === 'approved') {
        return props.readyResults > 0
            ? s.value.hintResultsReady.replace('{n}', String(props.readyResults))
            : s.value.hintApproved
    }

    return s.value.hintDraft
})

const showSubmitResults = computed(
    () => props.canEdit && props.planning.status !== 'pending' && props.readyResults > 0,
)

// One cycle is no choice at all, so the picker only appears once there are two.
const canPickPackage = computed(() => props.packages.length > 1)

const packageOptions = computed<Option[]>(() =>
    props.packages.map((pkg) => {
        // The count is what separates the cycle they are looking for from an
        // empty one they have never filed anything in — when there is one.
        const count = pkg.plans === null
            ? ''
            : ` · ${pkg.plans} ${pkg.plans === 1 ? t.value.idp.planSingular : t.value.idp.planPlural}`

        return {
            value: String(pkg.id),
            label: pkg.name + count + (pkg.is_active ? ` · ${s.value.activeCycle}` : ''),
        }
    }),
)
</script>

<template>
    <div ref="sentinel" aria-hidden="true" />
    <div
        :class="sticky
            ? 'mb-4 lg:sticky lg:top-[var(--topbar-height)] lg:z-20 lg:-mx-2 lg:-mt-3 lg:bg-page lg:px-2 lg:pb-3 lg:pt-3'
            : 'mb-6'"
    >
        <section
            class="overflow-hidden rounded-xl border border-border bg-white transition-shadow"
            :class="stuck ? 'shadow-lg shadow-slate-900/5' : 'shadow-sm'"
        >
            <!-- Who · which cycle · overall status and tools -->
            <header class="flex flex-wrap items-center gap-x-5 gap-y-3 border-b border-border px-5 py-3">
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary"
                    >
                        {{ initials }}
                    </div>
                    <div class="min-w-0">
                        <h2 class="truncate text-sm font-bold text-slate-800">{{ employee.fullname }}</h2>
                        <p class="truncate text-xs text-slate-500">
                            {{ employee.employee_id }} · {{ employee.designation_name ?? '—' }}
                        </p>
                    </div>
                </div>

                <span class="hidden h-9 w-px shrink-0 bg-border md:block" />

                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <!-- With several cycles the name becomes the picker; with
                             one there is nothing to choose. -->
                        <SearchableSelect
                            v-if="canPickPackage"
                            class="min-w-[15rem]"
                            :model-value="selectedPackageId === null ? '' : String(selectedPackageId)"
                            :options="packageOptions"
                            :placeholder="s.selectCycle"
                            @update:model-value="(id: string) => emit('select-package', Number(id))"
                        />
                        <h3 v-else class="flex items-center gap-1.5 text-sm font-semibold text-slate-700">
                            <i class="fa-solid fa-diagram-project text-xs text-primary" />
                            {{ planning.package?.name ?? s.noPackage }}
                        </h3>

                        <StatusPill
                            v-if="planning.package"
                            :tone="viewingActive ? 'emerald' : 'slate'"
                            :icon="viewingActive ? 'fa-solid fa-circle-play' : 'fa-solid fa-box-archive'"
                            :label="viewingActive ? s.activeCycle : s.closedCycle"
                            :dot="false"
                            size="sm"
                        />
                    </div>
                </div>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <StatusPill v-bind="planningStatusPill" :dot="false" />
                    <button
                        v-if="planning.approval || planning.history.length"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-50 hover:text-primary"
                        @click="emit('open-chain')"
                    >
                        <i class="fa-solid fa-list-ol text-[10px]" />
                        {{ t.approvalFlow.viewChain }}
                        <span
                            v-if="planning.history.length"
                            class="rounded bg-slate-100 px-1 text-[10px] font-semibold text-slate-500"
                        >
                            +{{ planning.history.length }}
                        </span>
                    </button>
                    <slot name="tools" />
                </div>
            </header>

            <!-- The four stages, left to right as the plan progresses -->
            <ol class="grid grid-cols-2 gap-px bg-border lg:grid-cols-4">
                <li
                    v-for="(step, i) in steps"
                    :key="step.key"
                    class="relative flex items-center gap-3 px-4 py-3 transition-colors"
                    :class="i === focusIndex
                        ? ['ring-2 ring-inset', stateStyle[step.state].focus]
                        : stateStyle[step.state].bg"
                    :aria-current="i === focusIndex ? 'step' : undefined"
                >
                    <span
                        class="absolute inset-x-0 top-0"
                        :class="[stateStyle[step.state].bar, i === focusIndex ? 'h-1' : 'h-0.5']"
                    />

                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-1 ring-inset"
                        :class="stateStyle[step.state].ring"
                    >
                        <i
                            :class="[
                                step.state === 'done' ? 'fa-solid fa-check' : step.icon,
                                stateStyle[step.state].icon,
                            ]"
                            class="text-[11px]"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                {{ s.stepLabel }} {{ i + 1 }}
                            </p>
                            <span
                                v-if="i === focusIndex"
                                class="inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[9px] font-bold uppercase tracking-wide text-white"
                                :class="stateStyle[step.state].badge"
                            >
                                <i class="fa-solid fa-location-dot text-[8px]" />
                                {{ s.youAreHere }}
                            </span>
                        </div>
                        <p
                            class="truncate text-sm font-semibold"
                            :class="step.state === 'todo' && i !== focusIndex ? 'text-slate-500' : 'text-slate-800'"
                        >
                            {{ step.title }}
                        </p>
                    </div>

                    <p class="shrink-0 text-right text-xs font-semibold" :class="stateStyle[step.state].text">
                        {{ step.detail }}
                    </p>
                </li>
            </ol>

            <!-- What to do about it -->
            <footer
                class="flex flex-col gap-2 bg-slate-50/60 px-5 py-2.5 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="flex items-start gap-2 text-sm text-slate-600">
                    <i class="fa-solid fa-circle-info mt-1 shrink-0 text-xs text-primary/60" />
                    <span>{{ hint }}</span>
                </p>

                <div class="flex shrink-0 flex-wrap items-center gap-2">


                    <button
                        v-if="showSubmitResults"
                        type="button"
                        :disabled="submittingResults"
                        class="inline-flex items-center gap-2 rounded-lg border border-primary/40 bg-white px-3.5 py-1.5 text-sm font-semibold text-primary transition hover:bg-primary hover:text-white disabled:opacity-60"
                        @click="emit('submit-results')"
                    >
                        <i v-if="submittingResults" class="fa-solid fa-spinner fa-spin text-xs" />
                        <i v-else class="fa-solid fa-layer-group text-xs" />
                        {{ s.submitAllResults.replace('{n}', String(readyResults)) }}
                    </button>
                </div>
            </footer>
        </section>
    </div>
</template>
