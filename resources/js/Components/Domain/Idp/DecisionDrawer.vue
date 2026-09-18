<script setup lang="ts">
/**
 * Approve or reject whatever is currently on the approver's desk, with the
 * required note.
 *
 * One drawer serves both stages and both screens (the manage page and the
 * inbox): the caller says what is being decided and at which layer, this only
 * records the decision.
 */
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'

const { t } = useLocale()

const props = defineProps<{
    show: boolean
    decision: 'approve' | 'reject'
    approvalId: number | null
    /** What is being decided, e.g. the plan set or one program's result. */
    subject: string
    /** The one-line description under the title. */
    detail?: string | null
    level: number | null
    totalLevels: number | null
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const form = useForm({ note: '' })

watch(
    () => props.show,
    (open) => {
        if (open) seedForm(form, { note: '' })
    },
)

function close() {
    emit('close')
    seedForm(form, { note: '' })
}

const { confirming, requestClose, discard } = useUnsavedGuard(form, close)

const approving = computed(() => props.decision === 'approve')

const isFinalLayer = computed(
    () => props.level !== null && props.totalLevels !== null && props.level >= props.totalLevels,
)

function submit() {
    if (!props.approvalId) return
    form.post(route(`idp.approval.${props.decision}`, props.approvalId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => close(),
    })
}
</script>

<template>
    <Drawer :show="show" max-width="max-w-lg" @close="requestClose">
        <template #header>
            <div class="min-w-0">
                <h3 class="font-bold text-slate-800">
                    {{ approving ? t.approvalFlow.approveTitle : t.approvalFlow.rejectTitle }}
                </h3>
                <p class="mt-0.5 truncate text-sm text-slate-500">{{ subject }}</p>
            </div>
        </template>

        <form id="idp-decision-form" class="space-y-4" @submit.prevent="submit">
            <!-- What this decision does next, so it is not a leap of faith -->
            <div
                class="rounded-lg border px-3.5 py-3 text-sm"
                :class="approving
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                    : 'border-red-200 bg-red-50 text-red-800'"
            >
                <p class="flex items-center gap-2 font-semibold">
                    <i :class="approving ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'" />
                    <span v-if="level && totalLevels">
                        {{ t.approvalFlow.layer }} {{ level }} / {{ totalLevels }}
                    </span>
                </p>
                <p class="mt-1 text-xs leading-relaxed">
                    {{
                        approving
                            ? (isFinalLayer ? t.approvalFlow.approveFinalHint : t.approvalFlow.approveNextHint)
                            : t.approvalFlow.rejectHint
                    }}
                </p>
                <p v-if="detail" class="mt-2 truncate text-xs font-medium opacity-80">{{ detail }}</p>
            </div>

            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-700">
                    {{ t.approvalFlow.note }} <span class="text-red-500">*</span>
                </label>
                <textarea
                    v-model="form.note"
                    rows="4"
                    :placeholder="approving ? t.approvalFlow.notePlaceholder : t.approvalFlow.rejectNotePlaceholder"
                    class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    :class="form.errors.note ? 'border-red-500' : 'border-border'"
                />
                <p v-if="form.errors.note" class="mt-1 text-xs text-red-600">{{ form.errors.note }}</p>
            </div>
        </form>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                @click="requestClose"
            >
                {{ t.approvalFlow.cancel }}
            </button>
            <button
                type="submit"
                form="idp-decision-form"
                :disabled="form.processing || !form.note.trim()"
                class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60"
                :class="approving ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-red-500 hover:bg-red-600'"
            >
                <i v-if="form.processing" class="fa-solid fa-spinner fa-spin text-xs" />
                {{ approving ? t.approvalFlow.confirmApprove : t.approvalFlow.confirmReject }}
            </button>
        </template>
    </Drawer>

    <UnsavedChangesDialog :show="confirming" @confirm="discard" @close="confirming = false" />
</template>
