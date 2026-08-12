<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmt, formatDateTime as fmtDateTime } from '@/Composables/useDate'

const { t } = useLocale()

interface InboxPlan {
    id: number
    competency_type: string
    competency_name: string
    development_program: string
    expected_outcome: string | null
    time_frame_start: string | null
    time_frame_end: string | null
}

interface InboxItem {
    approval_id: number
    level: number
    total_levels: number
    owner_id: string
    owner_name: string
    submitted_at: string | null
    plan: InboxPlan
}

defineProps<{ items: InboxItem[] }>()

// Approve / reject dialog.
const actOpen = ref(false)
const actDecision = ref<'approve' | 'reject'>('approve')
const actItem = ref<InboxItem | null>(null)
const actForm = useForm({ note: '' })

function openAct(item: InboxItem, decision: 'approve' | 'reject') {
    actDecision.value = decision
    actItem.value = item
    actForm.reset()
    actForm.clearErrors()
    actOpen.value = true
}

function submitAct() {
    if (!actItem.value) return
    actForm.post(`/idp-approvals/${actItem.value.approval_id}/${actDecision.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            actOpen.value = false
            actForm.reset()
        },
    })
}
</script>

<template>
    <Head :title="t.approvalFlow.inboxTitle" />

    <AppLayout>
        <PageHeader :title="t.approvalFlow.inboxTitle" :subtitle="t.approvalFlow.inboxSubtitle" />

        <!-- Empty state -->
        <div
            v-if="items.length === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-border bg-white px-6 py-16 text-center shadow-sm"
        >
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-400">
                <i class="fa-solid fa-circle-check text-2xl" />
            </div>
            <p class="text-sm text-slate-500">{{ t.approvalFlow.inboxEmpty }}</p>
        </div>

        <!-- Items -->
        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div
                v-for="item in items"
                :key="item.approval_id"
                class="flex flex-col gap-3 rounded-xl border border-border bg-white p-5 shadow-sm"
            >
                <!-- Owner + layer -->
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-bold text-slate-800">{{ item.owner_name }}</p>
                        <p class="truncate text-xs text-slate-400">{{ item.owner_id }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">
                        <i class="fa-solid fa-hourglass-half text-[10px]" />
                        {{ t.approvalFlow.layerShort }}{{ item.level }} / {{ item.total_levels }}
                    </span>
                </div>

                <!-- Plan detail -->
                <div class="space-y-1.5 rounded-lg bg-slate-50/70 p-3">
                    <div class="flex items-center gap-2">
                        <span
                            class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                            :class="item.plan.competency_type === 'Technical Competency' ? 'bg-teal-50 text-teal-700' : 'bg-indigo-50 text-indigo-700'"
                        >
                            {{ item.plan.competency_type }}
                        </span>
                        <span class="truncate text-sm font-semibold text-slate-800">{{ item.plan.competency_name }}</span>
                    </div>
                    <p class="text-sm text-slate-600">
                        <i class="fa-solid fa-book-open mr-1 text-xs text-slate-300" />
                        {{ item.plan.development_program }}
                    </p>
                    <p v-if="item.plan.expected_outcome" class="text-xs text-slate-400">
                        {{ item.plan.expected_outcome }}
                    </p>
                    <p class="flex items-center gap-1.5 text-xs text-slate-400">
                        <i class="fa-regular fa-calendar" />
                        {{ fmt(item.plan.time_frame_start) }}
                        <i class="fa-solid fa-arrow-right-long text-[10px]" />
                        {{ item.plan.time_frame_end ? fmt(item.plan.time_frame_end) : '—' }}
                    </p>
                    <p v-if="item.submitted_at" class="flex items-center gap-1.5 text-xs text-slate-400">
                        <i class="fa-solid fa-paper-plane text-[10px] text-slate-300" />
                        {{ t.approvalFlow.submittedAt }}: {{ fmtDateTime(item.submitted_at) }}
                    </p>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-between gap-2">
                    <Link
                        :href="`/idp/${item.owner_id}`"
                        class="text-xs font-medium text-primary transition hover:underline"
                    >
                        <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-[10px]" />
                        {{ t.approvalFlow.openIdp }}
                    </Link>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-red-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-red-600"
                            @click="openAct(item, 'reject')"
                        >
                            <i class="fa-solid fa-xmark" />
                            {{ t.approvalFlow.reject }}
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-500 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-600"
                            @click="openAct(item, 'approve')"
                        >
                            <i class="fa-solid fa-check" />
                            {{ t.approvalFlow.approve }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Approve / reject drawer -->
        <Drawer :show="actOpen" max-width="max-w-lg" @close="actOpen = false">
            <template #header>
                <div class="min-w-0">
                    <h3 class="font-bold text-slate-800">
                        {{ actDecision === 'approve' ? t.approvalFlow.approveTitle : t.approvalFlow.rejectTitle }}
                    </h3>
                    <p v-if="actItem" class="mt-0.5 truncate text-sm text-slate-500">
                        {{ actItem.owner_name }} · {{ actItem.plan.competency_name }}
                    </p>
                </div>
            </template>

            <form id="inbox-act-form" class="space-y-4" @submit.prevent="submitAct">
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.approvalFlow.note }} <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        v-model="actForm.note"
                        rows="4"
                        :placeholder="t.approvalFlow.notePlaceholder"
                        class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="actForm.errors.note ? 'border-red-500' : 'border-border'"
                    />
                    <p v-if="actForm.errors.note" class="mt-1 text-xs text-red-600">{{ actForm.errors.note }}</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="actOpen = false"
                >
                    {{ t.approvalFlow.cancel }}
                </button>
                <button
                    type="submit"
                    form="inbox-act-form"
                    :disabled="actForm.processing"
                    class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60"
                    :class="actDecision === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-red-500 hover:bg-red-600'"
                >
                    <i v-if="actForm.processing" class="fa-solid fa-spinner fa-spin" />
                    {{ actDecision === 'approve' ? t.approvalFlow.confirmApprove : t.approvalFlow.confirmReject }}
                </button>
            </template>
        </Drawer>
    </AppLayout>
</template>
