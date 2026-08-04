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
    filters: {
        search: string
        business_unit: string
        job_level: string
        designation: string
    }
    filterOptions: {
        businessUnits: string[]
        jobLevels: string[]
        designations: string[]
    }
}>()

const state = reactive({
    search: props.filters.search ?? '',
    business_unit: props.filters.business_unit ?? '',
    job_level: props.filters.job_level ?? '',
    designation: props.filters.designation ?? '',
    per_page: props.employees.per_page,
})

const columns: Column[] = [
    { key: 'employee_id', label: t.value.facecard.table.id, tdClass: 'text-slate-400' },
    { key: 'fullname', label: t.value.facecard.table.name, tdClass: 'font-medium text-slate-700' },
    { key: 'group_company', label: t.value.facecard.table.businessUnit },
    { key: 'job_level', label: t.value.facecard.table.jobLevel },
    { key: 'designation_name', label: t.value.facecard.table.designation },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

function reload(resetPage = true) {
    router.get(
        '/facecard',
        {
            search: state.search || undefined,
            business_unit: state.business_unit || undefined,
            job_level: state.job_level || undefined,
            designation: state.designation || undefined,
            per_page: state.per_page,
            ...(resetPage ? {} : { page: currentPage() }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function currentPage(): number {
    const active = props.employees.links.find((l) => l.active)
    return active ? Number(active.label) : 1
}

// Debounce free-text search; selects apply immediately.
let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    () => state.search,
    () => {
        clearTimeout(debounce)
        debounce = setTimeout(() => reload(), 300)
    },
)

function resetFilters() {
    state.search = ''
    state.business_unit = ''
    state.job_level = ''
    state.designation = ''
    reload()
}

function changePerPage(perPage: number) {
    state.per_page = perPage
    reload()
}

const hasActiveFilters = () =>
    !!(state.search || state.business_unit || state.job_level || state.designation)
</script>

<template>
    <Head :title="t.facecard.title" />

    <AppLayout>
        <PageHeader
            :title="t.facecard.title"
            :subtitle="t.facecard.subtitle"
        />

        <!-- Filters -->
        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2 lg:col-span-1">
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

            <select
                v-model="state.business_unit"
                class="rounded-md border border-border px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                @change="reload()"
            >
                <option value="">{{ t.facecard.allBusinessUnits }}</option>
                <option
                    v-for="bu in filterOptions.businessUnits"
                    :key="bu"
                    :value="bu"
                >
                    {{ bu }}
                </option>
            </select>

            <select
                v-model="state.job_level"
                class="rounded-md border border-border px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                @change="reload()"
            >
                <option value="">{{ t.facecard.allJobLevels }}</option>
                <option
                    v-for="jl in filterOptions.jobLevels"
                    :key="jl"
                    :value="jl"
                >
                    {{ jl }}
                </option>
            </select>

            <div class="flex gap-2">
                <select
                    v-model="state.designation"
                    class="min-w-0 flex-1 rounded-md border border-border px-3 py-2.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    @change="reload()"
                >
                    <option value="">{{ t.facecard.allDesignations }}</option>
                    <option
                        v-for="d in filterOptions.designations"
                        :key="d"
                        :value="d"
                    >
                        {{ d }}
                    </option>
                </select>

                <button
                    v-if="hasActiveFilters()"
                    type="button"
                    class="shrink-0 rounded-md border border-border px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                    :title="t.facecard.clearFilters"
                    @click="resetFilters"
                >
                    <i class="fa-solid fa-xmark" />
                </button>
            </div>
        </div>

        <!-- Table -->
        <DataTable
            :columns="columns"
            :rows="employees.data"
            row-key="employee_id"
        >
            <template #cell-action="{ row }">
                <Link
                    :href="`/employee/${row.employee_id}`"
                    class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                >
                    <i class="fa-solid fa-id-card" />
                    {{ t.facecard.viewDetail }}
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
