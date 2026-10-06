<script setup lang="ts">
/**
 * One of the owner's own requests that an approver rejected — the whole plan
 * set, or one program's result — on their Task Box.
 *
 *  - On the pending desk it is a task: who sent it back and why, and a link to
 *    the place it is corrected (the plan's sign-off card, or the program's row
 *    on My Development Plan). Resubmitting opens a new round, which is what
 *    moves it off the desk.
 *  - In history it records that the rejection was answered: when it was
 *    resubmitted and where that round stands now.
 */
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import StatusPill from '@/Components/Domain/Idp/StatusPill.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate, formatDateTime } from '@/Composables/useDate'
import { route } from '@/Config/route'
import type { InboxRevision, Tone } from '@/types/idp'

const props = defineProps<{
    item: InboxRevision
    /** Shown in the history: answered, not waiting. */
    history?: boolean
}>()

const { t } = useLocale()

const isPlanning = computed(() => props.item.stage === 'planning')
const plan = computed(() => props.item.plans[0] ?? null)

/** Model › competency type › competency — the same context line a result request shows. */
const context = computed<string[]>(() =>
    [plan.value?.development_model, plan.value?.competency_type, plan.value?.competency_name]
        .map((part) => (part ?? '').trim())
        .filter((part) => part !== ''),
)

const headline = computed(() =>
    isPlanning.value ? t.value.approvalFlow.yourPlanRejected : (props.item.title ?? '—'),
)

const rejectedBy = computed(() => {
    const rejected = props.item.rejected
    if (!rejected) return ''

    return t.value.approvalFlow.rejectedByLayer
        .replace('{layer}', `${t.value.approvalFlow.layerShort}${rejected.level}`)
        .replace('{name}', rejected.name ?? '—')
})

/** Where the resubmitted round stands now (history only). */
const outcome = computed<{ tone: Tone; label: string } | null>(() => {
    switch (props.item.outcome) {
        case 'approved':
            return { tone: 'emerald', label: t.value.approvalFlow.statusApproved }
        case 'rejected':
            return { tone: 'red', label: t.value.approvalFlow.statusRejected }
        case 'pending':
            return { tone: 'amber', label: t.value.approvalFlow.waiting }
        default:
            return null
    }
})

// Lands on what is corrected: the program's own row, or the plan's sign-off card.
const href = computed(() =>
    route('idp.mine', { focus: isPlanning.value ? 'plan' : props.item.plan_id }),
)
</script>

<template>
    <article
        class="overflow-hidden rounded-xl border border-l-4 border-border bg-white shadow-sm"
        :class="history ? 'border-l-slate-300' : 'border-l-red-500'"
    >
        <div class="flex flex-col gap-3 px-5 py-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1">
                <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                    <!-- Same stage tag as the approver's cards -->
                    <span
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-semibold"
                        :class="isPlanning ? 'bg-primary/10 text-primary' : 'bg-emerald-50 text-emerald-700'"
                    >
                        <i :class="isPlanning ? 'fa-solid fa-file-signature' : 'fa-solid fa-clipboard-check'" class="text-[10px]" />
                        {{ isPlanning ? t.approvalFlow.planApprovalBadge : t.approvalFlow.resultApprovalBadge }}
                    </span>
                    <span
                        v-if="history"
                        class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-600"
                    >
                        <i class="fa-solid fa-check text-[10px]" />
                        {{ t.approvalFlow.revisedBadge }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1 rounded-md bg-red-50 px-1.5 py-0.5 text-[11px] font-semibold text-red-700"
                    >
                        <i class="fa-solid fa-rotate-left text-[10px]" />
                        {{ t.approvalFlow.reviseBadge }}
                    </span>
                </div>

                <p
                    v-if="context.length"
                    class="flex min-w-0 items-center gap-1 truncate text-xs text-slate-500"
                    :title="context.join(' › ')"
                >
                    <template v-for="(part, i) in context" :key="i">
                        <i v-if="i > 0" class="fa-solid fa-chevron-right text-[8px] text-slate-300" />
                        <span class="truncate" :class="i === context.length - 1 ? 'font-medium text-slate-600' : ''">{{ part }}</span>
                    </template>
                </p>

                <p class="mt-0.5 text-sm font-medium text-slate-700">
                    {{ headline }}
                    <span v-if="isPlanning && item.package" class="font-normal text-slate-400">· {{ item.package.name }}</span>
                </p>

                <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-400">
                    <span v-if="rejectedBy" class="inline-flex items-center gap-1 text-red-600">
                        <i class="fa-solid fa-circle-xmark text-[10px]" />
                        {{ rejectedBy }}
                    </span>
                    <span v-if="item.rejected_at" class="inline-flex items-center gap-1">
                        <i class="fa-regular fa-clock text-[10px]" />
                        {{ formatDateTime(item.rejected_at) }}
                    </span>
                    <span v-if="plan?.time_frame_end" class="inline-flex items-center gap-1">
                        <i class="fa-regular fa-calendar text-[10px]" />
                        {{ formatDate(plan.time_frame_start) }} → {{ formatDate(plan.time_frame_end) }}
                    </span>
                    <span v-if="history && item.resubmitted_at" class="inline-flex items-center gap-1 text-slate-500">
                        <i class="fa-solid fa-paper-plane text-[10px]" />
                        {{ t.approvalFlow.resubmittedOn.replace('{date}', formatDateTime(item.resubmitted_at)) }}
                    </span>
                </p>

                <!-- The reason is what the revision has to answer, so it is never hidden. -->
                <div
                    class="mt-3 rounded-lg border px-3 py-2 text-xs"
                    :class="history ? 'border-border bg-slate-50' : 'border-red-100 bg-red-50/60'"
                >
                    <span class="font-semibold" :class="history ? 'text-slate-600' : 'text-red-700'">{{ t.approvalFlow.rejectionNote }}:</span>
                    <span v-if="item.note" class="ml-1 whitespace-pre-line text-slate-700">{{ item.note }}</span>
                    <span v-else class="ml-1 italic text-slate-400">{{ t.approvalFlow.noRejectionNote }}</span>
                </div>
            </div>

            <StatusPill v-if="history && outcome" :tone="outcome.tone" :label="outcome.label" class="self-start" />

            <Link
                v-else-if="!history"
                :href="href"
                class="inline-flex shrink-0 items-center justify-center gap-2 self-start rounded-md bg-primary px-3.5 py-2 text-sm font-medium text-white transition hover:bg-primary/90"
            >
                <i class="fa-solid fa-pen-to-square text-xs" />
                {{ isPlanning ? t.approvalFlow.revisePlan : t.approvalFlow.reviseResult }}
            </Link>
        </div>
    </article>
</template>
