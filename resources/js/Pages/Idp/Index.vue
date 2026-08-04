<script setup lang="ts">
import { reactive, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import DataTable, { type Column } from '@/Components/Domain/DataTable.vue'
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
        { search: state.search || undefined, per_page: state.per_page },
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
</script>

<template>
    <Head :title="t.idp.title" />

    <AppLayout>
        <PageHeader
            :title="t.idp.title"
            :subtitle="t.idp.subtitle"
        />

        <div class="mb-5 max-w-md">
            <div class="relative">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                />
                <input
                    v-model="state.search"
                    type="text"
                    :placeholder="t.facecard.searchPlaceholder"
                    class="w-full rounded-md border border-border py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>
        </div>

        <DataTable
            :columns="columns"
            :rows="employees.data"
            row-key="employee_id"
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
