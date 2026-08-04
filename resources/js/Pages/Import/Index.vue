<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import DataTable, { type Column, type Sort } from '@/Components/Domain/DataTable.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDateTime as fmt } from '@/Composables/useDate'

const { t } = useLocale()

interface Log {
    id: number
    data_type: string
    import_date: string
    status: string
    result: string
    original_file_path: string | null
    user?: { name: string } | null
}
interface Paginator {
    data: Log[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

const props = defineProps<{
    dataTypes: { value: string; label: string }[]
    logs: Paginator
    sort: Sort
}>()

const columns: Column[] = [
    { key: 'data_type', label: t.value.import.dataType, tdClass: 'font-medium text-slate-700' },
    { key: 'import_date', label: t.value.import.date },
    { key: 'status', label: t.value.import.status },
    { key: 'result', label: t.value.import.result, sortable: false },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

function changeSort(sort: Sort) {
    router.get('/import-center', { sort: sort.key, direction: sort.dir, per_page: props.logs.per_page }, { preserveState: true, preserveScroll: true, replace: true })
}

const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ data_type: string; file: File | null }>({
    data_type: props.dataTypes[0]?.value ?? '',
    file: null,
})

function onFile(e: Event) {
    form.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

function submit() {
    form.post('/import-center/process', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            form.reset('file')
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function remove(log: Log) {
    if (confirm(t.value.import.confirmDelete)) {
        router.delete(`/import/${log.id}`, { preserveScroll: true })
    }
}

function clearAll() {
    if (confirm(t.value.import.confirmClear)) {
        router.delete('/import', { preserveScroll: true })
    }
}

function changePerPage(perPage: number) {
    router.get('/import-center', { per_page: perPage }, { preserveState: true, preserveScroll: true, replace: true })
}

const statusTone: Record<string, string> = {
    Success: 'bg-green-50 text-green-700 ring-green-600/20',
    Pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    Failed: 'bg-red-50 text-red-700 ring-red-600/20',
}
</script>

<template>
    <Head :title="t.import.title" />

    <AppLayout>
        <PageHeader :title="t.import.title" :subtitle="t.import.subtitle" />

        <!-- Upload -->
        <form
            class="mb-8 grid grid-cols-1 gap-4 rounded-xl border border-border bg-white p-5 shadow-sm sm:grid-cols-[1fr_1fr_auto] sm:items-end"
            @submit.prevent="submit"
        >
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.import.dataType }}</label>
                <SearchableSelect v-model="form.data_type" :options="dataTypes" :invalid="!!form.errors.data_type" />
                <p v-if="form.errors.data_type" class="mt-1 text-xs text-red-600">{{ form.errors.data_type }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.import.file }}</label>
                <input
                    ref="fileInput"
                    type="file"
                    accept=".xlsx,.xls,.csv"
                    class="w-full rounded-md border border-border px-3 py-1.5 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm"
                    @change="onFile"
                >
                <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</p>
            </div>
            <button
                type="submit"
                :disabled="form.processing || !form.file"
                class="inline-flex items-center justify-center gap-2 rounded-md bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
            >
                <i :class="form.processing ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-upload'" />
                {{ t.import.upload }}
            </button>
        </form>

        <!-- Logs -->
        <div class="mb-3 flex items-center justify-between">
            <h2 class="font-bold text-slate-800">{{ t.import.logs }}</h2>
            <button
                v-if="logs.data.length"
                type="button"
                class="text-sm font-medium text-red-600 hover:underline"
                @click="clearAll"
            >
                {{ t.import.clearAll }}
            </button>
        </div>

        <DataTable
            :columns="columns"
            :rows="logs.data"
            row-key="id"
            min-width="820px"
            server-sort
            :sort="sort"
            @update:sort="changeSort"
        >
            <template #cell-import_date="{ value }">
                <span class="text-slate-500">{{ fmt(value) }}</span>
            </template>
            <template #cell-status="{ value }">
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset" :class="statusTone[value] ?? 'bg-slate-100 text-slate-500 ring-slate-500/20'">
                    {{ value }}
                </span>
            </template>
            <template #cell-result="{ value }">
                <span class="block max-w-sm truncate text-slate-500" :title="value">{{ value }}</span>
            </template>
            <template #cell-action="{ row }">
                <a
                    v-if="row.original_file_path"
                    :href="`/import-download/${row.id}`"
                    class="mr-1 inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-primary"
                >
                    <i class="fa-solid fa-download" />
                </a>
                <button class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600" @click="remove(row)">
                    <i class="fa-solid fa-trash" />
                </button>
            </template>
            <template #empty>{{ t.import.noLogs }}</template>
        </DataTable>

        <Pagination
            :links="logs.links"
            :per-page="logs.per_page"
            :total="logs.total"
            :from="logs.from"
            :to="logs.to"
            @update:per-page="changePerPage"
        />
    </AppLayout>
</template>
