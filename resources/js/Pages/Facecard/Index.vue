<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import DataTable, { type Column, type Sort } from '@/Components/Domain/DataTable.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
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
    sort: Sort
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
            sort: props.sort.key,
            direction: props.sort.dir,
            per_page: state.per_page,
            ...(resetPage ? {} : { page: currentPage() }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

function changeSort(sort: Sort) {
    router.get(
        '/facecard',
        {
            search: state.search || undefined,
            business_unit: state.business_unit || undefined,
            job_level: state.job_level || undefined,
            designation: state.designation || undefined,
            sort: sort.key,
            direction: sort.dir,
            per_page: state.per_page,
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

const toOptions = (allLabel: string, values: string[]): Option[] => [
    { value: '', label: allLabel },
    ...values.map((v) => ({ value: v, label: v })),
]

const businessUnitOptions = computed(() => toOptions(t.value.facecard.allBusinessUnits, props.filterOptions.businessUnits))
const jobLevelOptions = computed(() => toOptions(t.value.facecard.allJobLevels, props.filterOptions.jobLevels))
const designationOptions = computed(() => toOptions(t.value.facecard.allDesignations, props.filterOptions.designations))

function onFilter(key: 'business_unit' | 'job_level' | 'designation', value: string) {
    state[key] = value
    reload()
}

function exportUrl(): string {
    const params = new URLSearchParams()
    if (state.search) params.set('search', state.search)
    if (state.business_unit) params.set('business_unit', state.business_unit)
    if (state.job_level) params.set('job_level', state.job_level)
    if (state.designation) params.set('designation', state.designation)
    const qs = params.toString()
    return `/facecard/export${qs ? `?${qs}` : ''}`
}
</script>

<template>
    <Head :title="t.facecard.title" />

    <AppLayout>
        <PageHeader
            :title="t.facecard.title"
            :subtitle="t.facecard.subtitle"
        >
            <template #actions>
                <a
                    :href="exportUrl()"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-file-excel text-xs" />
                    {{ t.facecard.exportExcel }}
                </a>
            </template>
        </PageHeader>

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
                    class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>

            <SearchableSelect
                :model-value="state.business_unit"
                :options="businessUnitOptions"
                :placeholder="t.facecard.allBusinessUnits"
                @update:model-value="onFilter('business_unit', $event)"
            />

            <SearchableSelect
                :model-value="state.job_level"
                :options="jobLevelOptions"
                :placeholder="t.facecard.allJobLevels"
                @update:model-value="onFilter('job_level', $event)"
            />

            <div class="flex gap-2">
                <SearchableSelect
                    class="min-w-0 flex-1"
                    :model-value="state.designation"
                    :options="designationOptions"
                    :placeholder="t.facecard.allDesignations"
                    @update:model-value="onFilter('designation', $event)"
                />

                <button
                    v-if="hasActiveFilters()"
                    type="button"
                    class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
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
            server-sort
            :sort="sort"
            @update:sort="changeSort"
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
