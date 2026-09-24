<script setup lang="ts">
/**
 * The planning sign-off, at the foot of the plan list — both halves of it:
 *
 *   submit — the owner sends the whole set for approval;
 *   decide — the approver whose turn it is approves or rejects it.
 *
 * It sits below every plan on purpose: the set goes through as one and is
 * frozen while it is reviewed, so the button is reached only after scrolling
 * past what is being signed. (The Task Box links here rather than deciding in
 * place, for the same reason.) It restates the set — programs per development
 * model — and the approval route, and flags what is easy to miss: a model with
 * nothing under it, or filters hiding part of the plan.
 */
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'
import type { PlanningState } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    mode: 'submit' | 'decide'
    planning: PlanningState
    /** The cycle's development models, each with its plans (unfiltered). */
    models: { id: number; name: string; percentage: number | string | null; plans: unknown[] }[]
    /** Filters are narrowing the table, so part of the plan is off screen. */
    filtering: boolean
    /** Submit mode only: the request is in flight. */
    submitting?: boolean
}>()

const emit = defineEmits<{
    (e: 'submit'): void
    (e: 'act', decision: 'approve' | 'reject'): void
    (e: 'clear-filters'): void
}>()

const s = computed(() => t.value.idp.stage)
const deciding = computed(() => props.mode === 'decide')

const totalPlans = computed(() => props.models.reduce((n, m) => n + m.plans.length, 0))

const title = computed(() => (deciding.value ? s.value.decideCardTitle : s.value.submitCardTitle))

const body = computed(() =>
    (deciding.value ? s.value.decideCardBody : s.value.submitCardBody)
        .replace('{plans}', String(totalPlans.value))
        .replace('{models}', String(props.models.filter((m) => m.plans.length > 0).length))
        .replace('{level}', String(props.planning.approval?.current_level ?? 1))
        .replace('{total}', String(props.planning.approval?.total_levels ?? 1)),
)

const emptyModels = computed(() => props.models.filter((m) => m.plans.length === 0))

/**
 * The approval route, in order. Submitting shows where the set WILL go; deciding
 * shows the live chain, with this approver's layer marked.
 */
const chain = computed(() => {
    const approval = deciding.value ? props.planning.approval : props.planning.chain_preview
    const current = deciding.value ? approval?.current_level ?? null : null

    return (approval?.steps ?? []).map((step) => ({
        level: step.level,
        name: step.approver_name ?? step.approver_id,
        mine: step.level === current,
        done: step.status === 'approved',
    }))
})

/** What approving will do next — pass it on, or complete it. */
const decisionHint = computed(() => {
    const approval = props.planning.approval
    return approval && (approval.current_level ?? 1) >= approval.total_levels
        ? t.value.approvalFlow.approveFinalHint
        : t.value.approvalFlow.approveNextHint
})

// A rejection and a withdrawn approval both send the whole plan round again.
const submitLabel = computed(() =>
    props.planning.status === 'rejected' || props.planning.status === 'revision'
        ? s.value.resubmitPlanning
        : s.value.submitPlanning,
)
</script>

<template>
    <section
        class="overflow-hidden rounded-xl border bg-white shadow-sm"
        :class="deciding ? 'border-amber-300' : 'border-primary/25'"
    >
        <div class="flex flex-col gap-5 p-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex min-w-0 gap-4">
                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                    :class="deciding ? 'bg-amber-100 text-amber-600' : 'bg-primary/10 text-primary'"
                >
                    <i :class="deciding ? 'fa-solid fa-gavel' : 'fa-solid fa-file-signature'" />
                </div>

                <div class="min-w-0 space-y-3">
                    <div>
                        <h3 class="font-bold text-slate-800">{{ title }}</h3>
                        <p class="mt-0.5 text-sm text-slate-500">{{ body }}</p>
                    </div>

                    <!-- The set being signed, per development model -->
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="model in models"
                            :key="model.id"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                            :class="model.plans.length
                                ? 'bg-slate-50 text-slate-600 ring-slate-200'
                                : 'bg-amber-50 text-amber-700 ring-amber-200'"
                        >
                            <i
                                class="text-[10px]"
                                :class="model.plans.length ? 'fa-solid fa-check text-emerald-500' : 'fa-solid fa-triangle-exclamation'"
                            />
                            {{ model.name }}
                            <span v-if="model.percentage !== null" class="text-slate-400">{{ model.percentage }}%</span>
                            <span class="font-semibold">· {{ model.plans.length }}</span>
                        </span>
                    </div>

                    <p v-if="emptyModels.length" class="flex items-start gap-1.5 text-xs text-amber-700">
                        <i class="fa-solid fa-circle-exclamation mt-0.5 text-[10px]" />
                        {{ deciding ? s.decideCardEmptyModels : s.submitCardEmptyModels }}
                    </p>

                    <!-- The approval route -->
                    <p v-if="chain.length" class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500">
                        <span class="font-medium">{{ deciding ? s.decideCardRoute : s.submitCardRoute }}</span>
                        <template v-for="(step, i) in chain" :key="step.level">
                            <i v-if="i" class="fa-solid fa-arrow-right-long text-[9px] text-slate-300" />
                            <span
                                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-medium"
                                :class="step.mine
                                    ? 'bg-amber-100 text-amber-800 ring-1 ring-inset ring-amber-300'
                                    : step.done
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'bg-slate-100 text-slate-600'"
                            >
                                <i v-if="step.done" class="fa-solid fa-check text-[9px]" />
                                {{ t.approvalFlow.layerShort }}{{ step.level }} · {{ step.name }}
                                <span v-if="step.mine" class="font-bold">({{ s.decideCardYou }})</span>
                            </span>
                        </template>
                    </p>
                </div>
            </div>

            <!-- Decide -->
            <div v-if="deciding" class="flex shrink-0 flex-col items-stretch gap-2 lg:items-end">
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-red-200 bg-white px-4 py-2.5 text-sm font-semibold text-red-600 transition hover:bg-red-50 lg:flex-none"
                        @click="emit('act', 'reject')"
                    >
                        <i class="fa-solid fa-xmark text-xs" />
                        {{ t.approvalFlow.reject }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-emerald-500 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-600 lg:flex-none"
                        @click="emit('act', 'approve')"
                    >
                        <i class="fa-solid fa-check text-xs" />
                        {{ s.approvePlan }}
                    </button>
                </div>
                <p class="flex items-center gap-1.5 text-[11px] text-slate-400 lg:justify-end">
                    <i class="fa-solid fa-circle-info text-[9px]" />
                    {{ decisionHint }}
                </p>
            </div>

            <!-- Submit -->
            <div v-else class="flex shrink-0 flex-col items-stretch gap-2 lg:items-end">
                <button
                    type="button"
                    :disabled="submitting"
                    class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-hover disabled:opacity-60"
                    @click="emit('submit')"
                >
                    <i v-if="submitting" class="fa-solid fa-spinner fa-spin text-xs" />
                    <i v-else class="fa-solid fa-paper-plane text-xs" />
                    {{ submitLabel }}
                </button>
                <p class="flex items-center gap-1.5 text-[11px] text-slate-400 lg:justify-end">
                    <i class="fa-solid fa-lock text-[9px]" />
                    {{ s.submitCardLockNote }}
                </p>
            </div>
        </div>

        <!-- Part of the plan is filtered out of view -->
        <div
            v-if="filtering"
            class="flex flex-wrap items-center justify-between gap-2 border-t border-amber-200 bg-amber-50 px-5 py-2.5"
        >
            <p class="flex items-center gap-2 text-xs text-amber-800">
                <i class="fa-solid fa-filter text-[10px]" />
                {{ deciding ? s.decideCardFiltered : s.submitCardFiltered }}
            </p>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-amber-300 bg-white px-2.5 py-1 text-xs font-semibold text-amber-800 transition hover:bg-amber-100"
                @click="emit('clear-filters')"
            >
                <i class="fa-solid fa-xmark text-[10px]" />
                {{ t.idp.filters.clear }}
            </button>
        </div>
    </section>
</template>
