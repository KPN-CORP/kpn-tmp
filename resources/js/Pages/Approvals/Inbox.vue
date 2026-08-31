<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
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

interface Paginator {
    data: InboxItem[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

interface Sort {
    key: string
    dir: 'asc' | 'desc'
}

const props = defineProps<{
    items: Paginator
    filters: { search: string; type: string }
    sort: Sort
    filterOptions: { types: string[] }
    pendingTotal: number
}>()

/**
 * --------------------------------------------------------------------------
 * List: search + sort + pagination (server-side, Inertia partial reload)
 * --------------------------------------------------------------------------
 */

const state = reactive({
    search: props.filters.search ?? '',
    type: props.filters.type ?? '',
    per_page: props.items.per_page,
})

function reload(sort: Sort = props.sort) {
    router.get(
        '/approvals',
        {
            search: state.search || undefined,
            type: state.type || undefined,
            sort: sort.key,
            direction: sort.dir,
            per_page: state.per_page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

// Debounce the free-text search; the type select applies immediately.
let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    () => state.search,
    () => {
        clearTimeout(debounce)
        debounce = setTimeout(() => reload(), 300)
    },
)

const hasFilters = computed(() => !!(state.search || state.type))

function resetFilters() {
    state.search = ''
    state.type = ''
    reload()
}

function onType(value: string) {
    state.type = value
    reload()
}

// Toggle a column's sort: click cycles asc → desc on the same key, or starts
// asc on a new key.
function changeSort(key: string) {
    const dir: 'asc' | 'desc' =
        props.sort.key === key && props.sort.dir === 'asc' ? 'desc' : 'asc'
    reload({ key, dir })
}

function changePerPage(perPage: number) {
    state.per_page = perPage
    reload()
}

// Sortable columns — keys must match the server-side whitelist.
const sortableColumns = computed(() => [
    { key: 'owner_name', label: t.value.approvalFlow.owner, class: '' },
    { key: 'competency_name', label: t.value.approvalFlow.competency, class: '' },
    { key: 'development_program', label: t.value.approvalFlow.program, class: '' },
    { key: 'submitted_at', label: t.value.approvalFlow.submittedAt, class: 'w-40' },
    { key: 'level', label: t.value.approvalFlow.yourLayer, class: 'w-28' },
])

const typeOptions = computed<Option[]>(() => [
    { value: '', label: t.value.approvalFlow.allTypes },
    ...props.filterOptions.types.map((v) => ({ value: v, label: v })),
])

/**
 * --------------------------------------------------------------------------
 * Approve / reject drawer
 * --------------------------------------------------------------------------
 */

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
        <div class="flex flex-wrap items-start justify-between gap-3">
            <PageHeader :title="t.approvalFlow.inboxTitle" :subtitle="t.approvalFlow.inboxSubtitle" />

            <span
                v-if="pendingTotal > 0"
                class="inline-flex items-center gap-2 rounded-full bg-amber-100 px-3 py-1.5 text-xs font-semibold text-amber-800"
            >
                <i class="fa-solid fa-hourglass-half text-[10px]" />
                {{ pendingTotal }} {{ t.approvalFlow.pendingCount }}
            </span>
        </div>

        <!-- Filters (nothing to filter when the inbox is empty) -->
        <div v-if="pendingTotal > 0" class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                />
                <input
                    v-model="state.search"
                    type="text"
                    :placeholder="t.approvalFlow.searchPlaceholder"
                    class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>

            <div class="flex gap-2">
                <SearchableSelect
                    class="min-w-0 flex-1"
                    :model-value="state.type"
                    :options="typeOptions"
                    :placeholder="t.approvalFlow.allTypes"
                    @update:model-value="onType"
                />

                <button
                    v-if="hasFilters"
                    type="button"
                    class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                    :title="t.approvalFlow.resetFilters"
                    @click="resetFilters"
                >
                    <i class="fa-solid fa-xmark" />
                </button>
            </div>
        </div>

        <!-- Empty inbox (nothing pending at all, before any filtering) -->
        <div
            v-if="pendingTotal === 0"
            class="flex flex-col items-center gap-3 rounded-xl border border-border bg-white px-6 py-16 text-center shadow-sm"
        >
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-emerald-50 text-emerald-400">
                <i class="fa-solid fa-circle-check text-2xl" />
            </div>
            <p class="text-sm text-slate-500">{{ t.approvalFlow.inboxEmpty }}</p>
        </div>

        <!-- Table -->
        <template v-else>
            <div class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm">
                <table class="w-full min-w-[1040px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-12 px-5 py-3 text-center">#</th>
                            <th
                                v-for="col in sortableColumns"
                                :key="col.key"
                                class="cursor-pointer select-none px-5 py-3 transition hover:text-slate-700"
                                :class="col.class"
                                @click="changeSort(col.key)"
                            >
                                <span class="inline-flex items-center gap-1.5">
                                    {{ col.label }}
                                    <i
                                        class="text-[10px]"
                                        :class="
                                            sort.key === col.key
                                                ? sort.dir === 'asc'
                                                    ? 'fa-solid fa-sort-up text-primary'
                                                    : 'fa-solid fa-sort-down text-primary'
                                                : 'fa-solid fa-sort text-slate-300'
                                        "
                                    />
                                </span>
                            </th>
                            <th class="w-44 px-5 py-3">{{ t.approvalFlow.timeframe }}</th>
                            <th class="w-32 px-5 py-3 text-center">{{ t.approvalFlow.actions }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(item, i) in items.data"
                            :key="item.approval_id"
                            class="border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/60"
                        >
                            <td class="px-5 py-4 text-center text-slate-400">
                                {{ (items.from ?? 0) + i }}
                            </td>

                            <!-- Employee -->
                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-700">{{ item.owner_name }}</p>
                                <p class="mt-0.5 text-xs text-slate-400">{{ item.owner_id }}</p>
                            </td>

                            <!-- Competency (+ type) -->
                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-700">{{ item.plan.competency_name }}</p>
                                <span
                                    class="mt-1 inline-block rounded px-1.5 py-0.5 text-[11px] font-medium"
                                    :class="
                                        item.plan.competency_type === 'Technical Competency'
                                            ? 'bg-teal-50 text-teal-700'
                                            : 'bg-indigo-50 text-indigo-700'
                                    "
                                >
                                    {{ item.plan.competency_type }}
                                </span>
                            </td>

                            <!-- Development program -->
                            <td class="px-5 py-4">
                                <p class="text-slate-600">{{ item.plan.development_program }}</p>
                                <p
                                    v-if="item.plan.expected_outcome"
                                    class="mt-0.5 line-clamp-2 text-xs text-slate-400"
                                >
                                    {{ item.plan.expected_outcome }}
                                </p>
                            </td>

                            <!-- Submitted -->
                            <td class="px-5 py-4 text-slate-500">
                                {{ item.submitted_at ? fmtDateTime(item.submitted_at) : '—' }}
                            </td>

                            <!-- Layer -->
                            <td class="px-5 py-4">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800"
                                >
                                    <i class="fa-solid fa-hourglass-half text-[10px]" />
                                    {{ t.approvalFlow.layerShort }}{{ item.level }} / {{ item.total_levels }}
                                </span>
                            </td>

                            <!-- Timeframe -->
                            <td class="px-5 py-4 text-xs text-slate-500">
                                <span class="inline-flex items-center gap-1.5">
                                    {{ fmt(item.plan.time_frame_start) }}
                                    <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                                    {{ item.plan.time_frame_end ? fmt(item.plan.time_frame_end) : '—' }}
                                </span>
                            </td>

                            <!-- Actions -->
                            <td class="px-5 py-4">
                                <div class="flex items-center justify-center gap-1">
                                    <Link
                                        :href="`/idp/${item.owner_id}`"
                                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                                        :title="t.approvalFlow.openIdp"
                                    >
                                        <i class="fa-solid fa-arrow-up-right-from-square text-xs" />
                                    </Link>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-emerald-50 hover:text-emerald-600"
                                        :title="t.approvalFlow.approve"
                                        @click="openAct(item, 'approve')"
                                    >
                                        <i class="fa-solid fa-check text-xs" />
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                        :title="t.approvalFlow.reject"
                                        @click="openAct(item, 'reject')"
                                    >
                                        <i class="fa-solid fa-xmark text-xs" />
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <tr v-if="items.data.length === 0">
                            <td colspan="8" class="px-5 py-12 text-center text-sm text-slate-400">
                                {{ t.approvalFlow.noResults }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                <Pagination
                    :links="items.links"
                    :per-page="items.per_page"
                    :total="items.total"
                    :from="items.from"
                    :to="items.to"
                    @update:per-page="changePerPage"
                />
            </div>
        </template>

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
                <!-- Item recap -->
                <div v-if="actItem" class="space-y-1.5 rounded-lg bg-slate-50/70 p-3 text-sm">
                    <p class="text-slate-600">
                        <i class="fa-solid fa-book-open mr-1 text-xs text-slate-300" />
                        {{ actItem.plan.development_program }}
                    </p>
                    <p class="flex items-center gap-1.5 text-xs text-slate-400">
                        <i class="fa-regular fa-calendar" />
                        {{ fmt(actItem.plan.time_frame_start) }}
                        <i class="fa-solid fa-arrow-right-long text-[10px]" />
                        {{ actItem.plan.time_frame_end ? fmt(actItem.plan.time_frame_end) : '—' }}
                    </p>
                </div>

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
