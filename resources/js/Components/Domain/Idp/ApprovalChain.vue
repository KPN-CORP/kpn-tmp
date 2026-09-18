<script setup lang="ts">
/**
 * The L1 → L2 → … chain of one approval request, as a vertical stepper: who is
 * on it, what they decided, when, and the note they left.
 *
 * Used wherever a chain is read — the plan set's approval drawer, a program's
 * result drawer, and the approver's inbox — so "where is this right now" looks
 * the same in all three.
 *
 * With `preview`, the request has not been submitted yet and the chain is only
 * showing where it WOULD go.
 */
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDateTime } from '@/Composables/useDate'
import type { ApprovalInfo, StepStatus } from '@/types/idp'

const { t } = useLocale()

const props = withDefaults(
    defineProps<{
        approval: ApprovalInfo | null
        /** The chain is only a preview of where the request will go. */
        preview?: boolean
        /** Drop the notes and timestamps; just who and what. */
        compact?: boolean
    }>(),
    { preview: false, compact: false },
)

const steps = computed(() => props.approval?.steps ?? [])

function isCurrent(level: number): boolean {
    return (
        !props.preview &&
        props.approval?.status === 'pending' &&
        props.approval?.current_level === level
    )
}

function badge(status: StepStatus, level: number): string {
    if (status === 'approved') return 'bg-emerald-50 text-emerald-600 ring-emerald-200'
    if (status === 'rejected') return 'bg-red-50 text-red-600 ring-red-200'
    if (isCurrent(level)) return 'bg-amber-50 text-amber-600 ring-amber-200'
    return 'bg-slate-50 text-slate-400 ring-slate-200'
}

function icon(status: StepStatus, level: number): { icon: string; color: string } {
    if (status === 'approved') return { icon: 'fa-solid fa-circle-check', color: 'text-emerald-500' }
    if (status === 'rejected') return { icon: 'fa-solid fa-circle-xmark', color: 'text-red-500' }
    if (isCurrent(level)) return { icon: 'fa-solid fa-hourglass-half', color: 'text-amber-500' }
    return { icon: 'fa-regular fa-circle', color: 'text-slate-300' }
}

function stateLabel(status: StepStatus, level: number): string {
    if (status === 'approved') return t.value.approvalFlow.approvedBy
    if (status === 'rejected') return t.value.approvalFlow.rejectedBy
    if (isCurrent(level)) return t.value.approvalFlow.awaitingDecision
    if (props.preview) return t.value.approvalFlow.willReview
    return t.value.approvalFlow.pending
}
</script>

<template>
    <div>
        <p v-if="!steps.length" class="text-sm text-slate-400">
            {{ t.approvalFlow.noApprovers }}
        </p>

        <template v-else>
            <p
                v-if="approval?.submitted_at && !compact"
                class="mb-3 flex items-center gap-1.5 text-xs text-slate-500"
            >
                <i class="fa-solid fa-paper-plane text-slate-300" />
                <span>{{ t.approvalFlow.submittedAt }}: {{ formatDateTime(approval.submitted_at) }}</span>
            </p>

            <ol class="space-y-3">
                <li v-for="step in steps" :key="step.level" class="flex gap-3">
                    <!-- Layer marker + the line that joins it to the next one -->
                    <div class="flex flex-col items-center">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ring-1 ring-inset"
                            :class="badge(step.status, step.level)"
                        >
                            {{ t.approvalFlow.layerShort }}{{ step.level }}
                        </span>
                        <span v-if="step.level < steps.length" class="mt-1 w-px flex-1 bg-border" />
                    </div>

                    <div class="min-w-0 flex-1 pb-1">
                        <div class="flex items-center gap-2">
                            <i :class="[icon(step.status, step.level).icon, icon(step.status, step.level).color]" class="text-xs" />
                            <span class="truncate text-sm font-semibold text-slate-800">
                                {{ step.approver_name ?? step.approver_id }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">
                            {{ stateLabel(step.status, step.level) }}
                            <span v-if="step.acted_at && !compact"> · {{ formatDateTime(step.acted_at) }}</span>
                        </p>
                        <p
                            v-if="step.note && !compact"
                            class="mt-1 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs leading-relaxed text-slate-600"
                        >
                            <i class="fa-solid fa-quote-left mr-1 text-[10px] text-slate-300" />
                            {{ step.note }}
                        </p>
                    </div>
                </li>
            </ol>
        </template>
    </div>
</template>
