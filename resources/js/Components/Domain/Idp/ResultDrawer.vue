<script setup lang="ts">
/**
 * Stage 3: file one program's RESULT — what was achieved against the target,
 * when it was realized, and the evidence for it — and send it up the chain.
 *
 * This is deliberately its own drawer rather than more fields on the plan
 * form: the plan says what WILL be done and is approved as a set, the result
 * says what WAS done and is approved one program at a time. Keeping them apart
 * is what lets the plan be frozen while its results keep moving.
 *
 * Saving without submitting is offered too, so a half-written result (evidence
 * still being gathered) is not lost.
 */
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import ApprovalChain from '@/Components/Domain/Idp/ApprovalChain.vue'
import { useLocale } from '@/Composables/useLocale'
import { classifyEvidence } from '@/Components/Domain/Idp/evidence'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { formatDate } from '@/Composables/useDate'
import { attainment, formatTarget, uomLabel } from '@/Components/Domain/Idp/uom'
import { route } from '@/Config/route'
import type { Plan } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    show: boolean
    plan: Plan | null
    /** Localized labels for the program this result belongs to. */
    competencyLabel: string
    programLabel: string
    /** value => unit label, already in the active language. */
    uomLabels: Record<string, string>
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const r = computed(() => t.value.idp.result)

function blank() {
    return {
        realization_date: '',
        achievement: '',
        result_evidence: '',
        submit_for_approval: true,
    }
}

const form = useForm(blank())

// Seed the values AND the defaults each time the drawer opens, so `isDirty`
// measures this sitting's edits rather than the distance from an empty form.
watch(
    () => props.show,
    (open) => {
        if (!open) return
        seedForm(form, {
            realization_date: props.plan?.realization_date?.slice(0, 10) ?? '',
            achievement:
                props.plan?.achievement === null || props.plan?.achievement === undefined
                    ? ''
                    : String(props.plan.achievement),
            result_evidence: props.plan?.result_evidence ?? '',
            submit_for_approval: true,
        })
    },
)

function close() {
    emit('close')
    seedForm(form, blank())
}

const { confirming, requestClose, discard } = useUnsavedGuard(form, close)

/** The chain this result will walk, or the one it is walking now. */
const chain = computed(() => props.plan?.stage.result.approval ?? props.plan?.stage.result.chain_preview ?? null)

const isResubmission = computed(() => props.plan?.stage.result.status === 'rejected')

const rejectionNote = computed(() => {
    const steps = props.plan?.stage.result.approval?.steps ?? []
    return steps.find((step) => step.status === 'rejected') ?? null
})

// --- What this result is measured against -----------------------------------

/** "12 Hectare (ha)", or '' on a plan filed before targets were recorded. */
const targetLabel = computed(() => formatTarget(props.uomLabels, props.plan?.target, props.plan?.uom))

/** The unit on its own, printed as the achievement input's suffix. */
const unitLabel = computed(() => uomLabel(props.uomLabels, props.plan?.uom))

/**
 * How far this achievement got, as a percentage, updated as they type. Shown
 * without a verdict: more is better for most of the catalogue, less is for
 * some (fuel consumption), so the reading belongs to the approver.
 */
const percent = computed(() => attainment(props.plan?.target, form.achievement))

// --- Field state ------------------------------------------------------------

// A web link or a shared-folder path (mirrors SubmitIdpResultRequest).
const evidenceKind = computed(() => classifyEvidence(form.result_evidence))
const evidenceIsUrl = computed(() => evidenceKind.value !== null)

/** Only complain once they have typed something. */
const evidenceInvalid = computed(
    () => form.result_evidence.trim() !== '' && !evidenceIsUrl.value,
)

const complete = computed(
    () => !!form.realization_date && form.achievement !== '' && evidenceIsUrl.value,
)

/** Named in the footer, so a disabled button says what it is waiting on. */
const missing = computed(() => {
    const out: string[] = []
    if (form.achievement === '') out.push(t.value.idp.form.achievement)
    if (!form.realization_date) out.push(t.value.idp.form.realization)
    if (!evidenceIsUrl.value) out.push(t.value.idp.form.resultEvidence)

    return out
})

/**
 * Saving a draft is reversible and goes straight through; SUBMITTING hands the
 * program to an approver and locks it, so that one asks first.
 */
const confirmingSubmit = ref(false)

/** Who the result lands on first, named where it is known. */
const goesToName = computed(() => {
    const steps = chain.value?.steps ?? []
    const step = steps.find((s) => s.status === 'pending') ?? steps[0]

    return step?.approver_name ?? step?.approver_id ?? ''
})

function send(forApproval: boolean) {
    if (!props.plan) return
    confirmingSubmit.value = false
    form.submit_for_approval = forApproval
    form.post(route('idp.approval.save_result', props.plan.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => close(),
    })
}
</script>

<template>
    <Drawer :show="show" max-width="max-w-2xl" @close="requestClose">
        <template #header>
            <div class="min-w-0">
                <h3 class="font-bold text-slate-800">
                    {{ isResubmission ? r.resubmitTitle : r.title }}
                </h3>
                <p class="mt-0.5 truncate text-sm text-slate-500">{{ competencyLabel }}</p>
            </div>
        </template>

        <form id="idp-result-form" class="space-y-5" @submit.prevent="confirmingSubmit = true">
            <!-- What this result is for: the program as it was planned -->
            <div class="rounded-xl border border-border bg-slate-50/60 px-4 py-3">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ r.whatWasPlanned }}
                </p>
                <p class="text-sm leading-relaxed text-slate-700">{{ programLabel }}</p>
                <p
                    v-if="plan?.time_frame_start"
                    class="mt-2 flex items-center gap-1.5 text-xs text-slate-500"
                >
                    <i class="fa-regular fa-calendar text-slate-300" />
                    {{ formatDate(plan.time_frame_start) }}
                    <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                    {{ plan.time_frame_end ? formatDate(plan.time_frame_end) : '—' }}
                </p>
                <p v-if="plan?.expected_outcome" class="mt-2 whitespace-pre-line text-xs text-slate-500">
                    <span class="font-medium text-slate-600">{{ t.idp.outcomeLabel }}:</span>
                    {{ plan.expected_outcome }}
                </p>
            </div>

            <!-- Why it came back, when it did -->
            <div
                v-if="isResubmission && rejectionNote"
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3"
            >
                <p class="flex items-center gap-2 text-sm font-semibold text-red-800">
                    <i class="fa-solid fa-circle-xmark text-xs" />
                    {{ r.wasRejected.replace('{name}', rejectionNote.approver_name ?? rejectionNote.approver_id) }}
                </p>
                <p v-if="rejectionNote.note" class="mt-1.5 text-xs leading-relaxed text-red-700">
                    {{ rejectionNote.note }}
                </p>
            </div>

            <!-- The heart of the form: what was reached, against what was set.
                 Target and achievement sit side by side so the comparison is
                 read rather than remembered. -->
            <div class="overflow-hidden rounded-xl border border-primary/25">
                <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_auto_minmax(0,1.15fr)]">
                    <!-- Target: what the plan committed to -->
                    <div class="flex flex-col justify-center bg-primary/5 px-4 py-3.5">
                        <p class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-primary/80">
                            <i class="fa-solid fa-bullseye text-[10px]" />
                            {{ t.idp.targetLabel }}
                        </p>
                        <p v-if="targetLabel" class="text-lg font-bold leading-tight tabular-nums text-slate-800">
                            {{ targetLabel }}
                        </p>
                        <p v-else class="text-xs leading-relaxed text-slate-500">
                            {{ r.noTargetSet }}
                        </p>
                    </div>

                    <!-- Only reads as an arrow once the two sit side by side -->
                    <div class="hidden items-center justify-center bg-primary/5 pr-1 sm:flex">
                        <i class="fa-solid fa-arrow-right-long text-primary/30" />
                    </div>

                    <!-- Achievement: what actually happened -->
                    <div class="border-t border-primary/20 bg-white px-4 py-3.5 sm:border-l sm:border-t-0">
                        <label
                            for="idp-achievement"
                            class="mb-1.5 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <i class="fa-solid fa-flag-checkered text-[10px] text-slate-400" />
                            {{ t.idp.form.achievement }} <span class="text-red-500">*</span>
                        </label>

                        <div class="flex items-center gap-2">
                            <input
                                id="idp-achievement"
                                v-model="form.achievement"
                                type="number"
                                min="0"
                                step="any"
                                inputmode="decimal"
                                :placeholder="t.idp.form.achievementPlaceholder"
                                class="min-w-0 flex-1 rounded-lg border px-3 py-2 text-base font-semibold tabular-nums focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.achievement ? 'border-red-500' : 'border-border'"
                            >
                            <span v-if="unitLabel" class="shrink-0 text-sm font-medium text-slate-500">
                                {{ unitLabel }}
                            </span>
                        </div>

                        <p v-if="form.errors.achievement" class="mt-1.5 text-xs text-red-600">
                            {{ form.errors.achievement }}
                        </p>

                        <!-- How far that got, live. No verdict: more is better
                             for most units, less is for some. -->
                        <p
                            v-else-if="percent !== null"
                            class="mt-1.5 inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        >
                            <span class="tabular-nums">{{ percent }}%</span>
                            {{ r.howFar }}
                        </p>
                        <p v-else-if="unitLabel" class="mt-1.5 text-xs text-slate-400">
                            {{ r.achievementHint }}
                        </p>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.form.realization }} <span class="text-red-500">*</span>
                    </label>
                    <DateInput v-model="form.realization_date" :invalid="!!form.errors.realization_date" />
                    <p v-if="form.errors.realization_date" class="mt-1 text-xs text-red-600">
                        {{ form.errors.realization_date }}
                    </p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.form.resultEvidence }} <span class="text-red-500">*</span>
                    </label>
                    <div class="relative">
                        <i
                            :class="evidenceKind === 'network' ? 'fa-solid fa-folder-open text-amber-500' : 'fa-solid fa-link text-slate-300'"
                            class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs"
                        />
                        <!-- text, not url: a shared-folder path is valid evidence and
                             the browser's own url check would refuse it -->
                        <input
                            v-model="form.result_evidence"
                            type="text"
                            spellcheck="false"
                            maxlength="1000"
                            :placeholder="t.idp.form.resultEvidencePlaceholder"
                            class="w-full rounded-lg border py-2 pl-8 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :class="form.errors.result_evidence || evidenceInvalid ? 'border-red-500' : 'border-border'"
                        >
                    </div>
                    <p v-if="form.errors.result_evidence" class="mt-1 text-xs text-red-600">
                        {{ form.errors.result_evidence }}
                    </p>
                    <p v-else-if="evidenceInvalid" class="mt-1 flex items-start gap-1.5 text-xs text-red-600">
                        <i class="fa-solid fa-circle-exclamation mt-0.5 text-[10px]" />
                        <span>{{ r.evidenceMustBeUrl }}</span>
                    </p>
                    <p v-else class="mt-1 text-xs text-slate-400">{{ r.evidenceHint }}</p>
                </div>
            </div>

            <!-- Where it goes once submitted -->
            <div class="rounded-xl border border-border px-4 py-3">
                <p class="mb-2.5 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    <i class="fa-solid fa-route text-[10px]" />
                    {{ r.goesTo }}
                </p>
                <ApprovalChain :approval="chain" :preview="!plan?.stage.result.approval" compact />
            </div>
        </form>

        <template #footer>
            <!-- Say what a disabled button is waiting on, rather than leaving it
                 greyed out with no reason. -->
            <p v-if="missing.length" class="mr-auto hidden items-center gap-2 text-xs text-slate-500 sm:flex">
                <i class="fa-solid fa-circle-info text-[10px] text-slate-400" />
                <span>
                    {{ t.idp.form.stillNeeded }}
                    <span class="font-medium text-slate-600">{{ missing.join(', ') }}</span>
                </span>
            </p>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                @click="requestClose"
            >
                {{ t.idp.form.cancel }}
            </button>
            <button
                type="button"
                :disabled="form.processing || !complete"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                @click="send(false)"
            >
                {{ r.saveOnly }}
            </button>
            <button
                type="submit"
                form="idp-result-form"
                :disabled="form.processing || !complete"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
            >
                <i v-if="form.processing" class="fa-solid fa-spinner fa-spin text-xs" />
                <i v-else class="fa-solid fa-paper-plane text-xs" />
                {{ r.saveAndSubmit }}
            </button>
        </template>
    </Drawer>

    <UnsavedChangesDialog :show="confirming" @confirm="discard" @close="confirming = false" />

    <ConfirmDialog
        :show="confirmingSubmit"
        :title="r.confirmTitle"
        :message="goesToName ? r.confirmMessage.replace('{name}', goesToName) : r.confirmMessageNoName"
        :confirm-label="r.confirmAction"
        :cancel-label="t.idp.form.cancel"
        variant="primary"
        icon="fa-solid fa-paper-plane"
        :processing="form.processing"
        @confirm="send(true)"
        @close="confirmingSubmit = false"
    />
</template>
