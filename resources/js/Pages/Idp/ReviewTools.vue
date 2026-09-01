<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import EffectivePeriodFields from '@/Components/Domain/EffectivePeriodFields.vue'
import EffectivePeriodCell from '@/Components/Domain/EffectivePeriodCell.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface ReviewTool {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    // Optional window during which this tool is offered on new IDP items.
    effective_start_date: string | null
    effective_end_date: string | null
}

const props = defineProps<{
    reviewTools: ReviewTool[]
}>()

/**
 * Restrict server reloads after a mutation to this page's own data (+ flash),
 * so each save is a lightweight Inertia partial reload.
 */
const reloadOnly = ['reviewTools', 'flash']

// Localized name, falling back to the canonical value when the preferred
// language is empty.
function toolName(item: ReviewTool): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

/**
 * --------------------------------------------------------------------------
 * Search + table (client-side; ClientTable handles sort + pagination)
 * --------------------------------------------------------------------------
 */

const search = ref('')

const filteredTools = computed(() => {
    const q = search.value.trim().toLowerCase()
    const rows = props.reviewTools.map((r) => ({ ...r, name: toolName(r) }))
    return q
        ? rows.filter(
              (r) =>
                  r.name.toLowerCase().includes(q) ||
                  r.value.toLowerCase().includes(q),
          )
        : rows
})

const columns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.reviewTool, sortable: true },
    {
        key: 'period',
        label: t.value.idp.settings.effectivePeriod,
        sortable: true,
        sortKey: 'effective_start_date',
        thClass: 'w-72',
    },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

/**
 * --------------------------------------------------------------------------
 * Create / edit (reuses the shared master-data routes with type review_tools)
 * --------------------------------------------------------------------------
 */

const modal = ref(false)
const editingId = ref<number | null>(null)

const form = useForm({
    type: 'review_tools',
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    // Both ends optional: blank start = effective now, blank end = no expiry.
    effective_start_date: '',
    effective_end_date: '',
})

function openModal(tool?: ReviewTool) {
    editingId.value = tool?.id ?? null
    form.clearErrors()
    form.value_en = tool?.value_en ?? tool?.value ?? ''
    form.value_id = tool?.value_id ?? ''
    form.effective_start_date = tool?.effective_start_date ?? ''
    form.effective_end_date = tool?.effective_end_date ?? ''
    modal.value = true
}

function submit() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (modal.value = false),
    }

    if (editingId.value) {
        form.put(`/idp-setting/masters/review_tools/${editingId.value}`, opts)
    } else {
        form.post('/idp-setting/masters', opts)
    }
}

const modalTitle = computed(() =>
    editingId.value
        ? t.value.idp.settings.editReviewTool
        : t.value.idp.settings.reviewTool,
)

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteTool(tool: ReviewTool) {
    pendingDelete.value = {
        url: `/idp-setting/masters/review_tools/${tool.id}`,
        name: toolName(tool),
    }
}

function confirmDelete() {
    if (!pendingDelete.value) return

    router.delete(pendingDelete.value.url, {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}
</script>

<template>
    <Head :title="t.idp.settings.reviewTools" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.reviewTools"
            :subtitle="t.idp.settings.reviewToolsHint"
        />

        <div class="space-y-6">
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <!-- Header: title · search · add -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.reviewTools }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ reviewTools.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.reviewToolsHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="search"
                                type="search"
                                :placeholder="t.idp.settings.searchReviewTool"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openModal()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.reviewTool }}
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <ClientTable
                    :columns="columns"
                    :rows="filteredTools"
                    row-key="id"
                    :per-page="10"
                >
                        <template #cell-name="{ row }">
                            <span class="font-medium text-slate-700">{{ row.name }}</span>
                        </template>

                        <template #cell-period="{ row }">
                            <EffectivePeriodCell :item="(row as unknown as ReviewTool)" />
                        </template>

                        <template #cell-actions="{ row }">
                            <div class="flex items-center justify-end gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editReviewTool"
                                    @click="openModal(row as unknown as ReviewTool)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteReviewTool"
                                    @click="deleteTool(row as unknown as ReviewTool)"
                                />
                            </div>
                        </template>

                        <template #empty>
                            {{ search ? t.idp.settings.noToolsMatch : t.idp.settings.none }}
                        </template>
                    </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             REVIEW TOOL MODAL
        ================================================================= -->

        <Drawer :show="modal" :title="modalTitle" @close="modal = false">
            <form id="review-tool-form" class="space-y-4" @submit.prevent="submit">
                <!-- English section -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                        >
                            EN
                        </span>
                        <span class="text-sm font-semibold text-slate-700">
                            {{ t.idp.settings.english }}
                        </span>
                    </div>

                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        {{ t.idp.settings.name }}
                    </label>
                    <input
                        v-model="form.value_en"
                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.value_en ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.value_en" class="mt-1 text-xs text-red-600">
                        {{ form.errors.value_en }}
                    </p>
                </div>

                <!-- Bahasa Indonesia section -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                        >
                            ID
                        </span>
                        <span class="text-sm font-semibold text-slate-700">
                            {{ t.idp.settings.bahasa }}
                        </span>
                    </div>

                    <label class="mb-1 block text-xs font-medium text-slate-500">
                        {{ t.idp.settings.name }}
                    </label>
                    <input
                        v-model="form.value_id"
                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.value_id ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.value_id" class="mt-1 text-xs text-red-600">
                        {{ form.errors.value_id }}
                    </p>
                </div>

                <!-- Effective period -->
                <EffectivePeriodFields
                    v-model:start="form.effective_start_date"
                    v-model:end="form.effective_end_date"
                    :start-error="form.errors.effective_start_date"
                    :end-error="form.errors.effective_end_date"
                />
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="modal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="review-tool-form"
                    :disabled="form.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->

        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.idp.settings.deleteTitle"
            :message="t.idp.settings.confirmDelete"
            :confirm-label="t.idp.settings.delete"
            :cancel-label="t.idp.form.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete?.name"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ pendingDelete.name }}
            </p>
        </ConfirmDialog>
    </AppLayout>
</template>
