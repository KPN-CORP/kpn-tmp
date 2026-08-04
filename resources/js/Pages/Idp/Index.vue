<script setup lang="ts">
import { reactive, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import DataTable, { type Column, type Sort } from '@/Components/Domain/DataTable.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface EmployeeRow {
    employee_id: string
    fullname: string
    group_company: string | null
    job_level: string | null
    designation_name: string | null
}

interface Paginator {
    data: EmployeeRow[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

const props = defineProps<{
    employees: Paginator
    filters: { search: string }
    sort: Sort
}>()

const state = reactive({
    search: props.filters.search ?? '',
    per_page: props.employees.per_page,
})

const columns: Column[] = [
    { key: 'employee_id', label: t.value.facecard.table.id, tdClass: 'text-slate-400' },
    { key: 'fullname', label: t.value.facecard.table.name, tdClass: 'font-medium text-slate-700' },
    { key: 'group_company', label: t.value.facecard.table.businessUnit },
    { key: 'designation_name', label: t.value.facecard.table.designation },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

function reload() {
    router.get(
        '/idp',
        {
            search: state.search || undefined,
            sort: props.sort.key,
            direction: props.sort.dir,
            per_page: state.per_page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function changeSort(sort: Sort) {
    router.get(
        '/idp',
        { search: state.search || undefined, sort: sort.key, direction: sort.dir, per_page: state.per_page },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    () => state.search,
    () => {
        clearTimeout(debounce)
        debounce = setTimeout(reload, 300)
    },
)

function changePerPage(perPage: number) {
    state.per_page = perPage
    reload()
}

// --- Bulk PDF zip (background job + polling) ---
const bulk = reactive({ running: false, progress: 0, error: '' })
let poll: ReturnType<typeof setInterval> | undefined

async function startBulkDownload() {
    bulk.running = true
    bulk.progress = 0
    bulk.error = ''
    try {
        const xsrf = decodeURIComponent(
            document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
        )
        const res = await fetch('/idp/bulk-download', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf,
            },
        })
        const { job_id } = await res.json()
        pollStatus(job_id)
    } catch {
        bulk.error = 'Failed to start export.'
        bulk.running = false
    }
}

function pollStatus(jobId: string) {
    clearInterval(poll)
    poll = setInterval(async () => {
        try {
            const res = await fetch(`/idp/bulk-download/status/${jobId}`, { headers: { Accept: 'application/json' } })
            const data = await res.json()
            bulk.progress = data.progress ?? 0
            if (data.error) {
                bulk.error = data.error
                stopBulk()
            } else if (data.ready) {
                stopBulk()
                window.location.href = `/idp/bulk-download/file/${jobId}`
            }
        } catch {
            bulk.error = 'Lost connection to the job.'
            stopBulk()
        }
    }, 1500)
}

function stopBulk() {
    clearInterval(poll)
    bulk.running = false
}
</script>

<template>
    <Head :title="t.idp.title" />

    <AppLayout>
        <PageHeader
            :title="t.idp.title"
            :subtitle="t.idp.subtitle"
        >
            <template #actions>
                <button
                    type="button"
                    :disabled="bulk.running"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                    @click="startBulkDownload"
                >
                    <i :class="bulk.running ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-file-zipper'" class="text-xs" />
                    {{ bulk.running ? `${t.idp.preparing} ${bulk.progress}%` : t.idp.bulkDownload }}
                </button>
            </template>
        </PageHeader>

        <p v-if="bulk.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ bulk.error }}
        </p>

        <div class="mb-5 max-w-md">
            <div class="relative">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                />
                <input
                    v-model="state.search"
                    type="text"
                    :placeholder="t.facecard.searchPlaceholder"
                    class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>
        </div>

        <DataTable
            :columns="columns"
            :rows="employees.data"
            row-key="employee_id"
            server-sort
            :sort="sort"
            @update:sort="changeSort"
        >
            <template #cell-action="{ row }">
                <Link
                    :href="`/idp/${row.employee_id}`"
                    class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                >
                    <i class="fa-solid fa-seedling" />
                    {{ t.idp.manage }}
                </Link>
            </template>
            <template #empty>{{ t.facecard.noEmployees }}</template>
        </DataTable>

        <Pagination
            :links="employees.links"
            :per-page="employees.per_page"
            :total="employees.total"
            :from="employees.from"
            :to="employees.to"
            @update:per-page="changePerPage"
        />
    </AppLayout>
</template>
