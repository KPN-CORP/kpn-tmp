<script setup lang="ts">
/**
 * Stage 3: file one program's RESULT — when it was realized and the evidence
 * for it — and send it up the chain.
 *
 * This is deliberately its own drawer rather than two more fields on the plan
 * form: the plan says what WILL be done and is approved as a set, the result
 * says what WAS done and is approved one program at a time. Keeping them apart
 * is what lets the plan be frozen while its results keep moving.
 *
 * Saving without submitting is offered too, so a half-written result (evidence
 * still being gathered) is not lost.
 */
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import ApprovalChain from '@/Components/Domain/Idp/ApprovalChain.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { formatDate } from '@/Composables/useDate'
import { route } from '@/Config/route'
import type { Plan } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    show: boolean
    plan: Plan | null
    /** Localized labels for the program this result belongs to. */
    competencyLabel: string
    programLabel: string
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const r = computed(() => t.value.idp.result)

function blank() {
    return { realization_date: '', result_evidence: '', submit_for_approval: true }
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

const complete = computed(() => !!form.realization_date && !!form.result_evidence.trim())

function send(forApproval: boolean) {
    if (!props.plan) return
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

        <form id="idp-result-form" class="space-y-5" @submit.prevent="send(true)">
            <!-- What this result is for: the program as it was planned -->
            <div class="rounded-xl border border-border bg-slate-50/60 px-4 py-3">
                <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ t.idp.form.program }}
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
                    <input
                        v-model="form.result_evidence"
                        type="text"
                        maxlength="1000"
                        :placeholder="t.idp.form.resultEvidencePlaceholder"
                        class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.result_evidence ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.result_evidence" class="mt-1 text-xs text-red-600">
                        {{ form.errors.result_evidence }}
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
</template>
