<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import DataTable, { type Column, type Sort } from '@/Components/Domain/DataTable.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface ReportRow {
    employee_id: string
    fullname: string
    group_company: string
    job_level: string
    designation_name: string
    unit: string
    potential: string
    talent_box: string
    idp_progress: string
}

interface Paginator {
    data: ReportRow[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

const props = defineProps<{
    rows: Paginator
    filters: {
        search: string
        year: string
        business_unit: string
        job_level: string
        designation: string
        unit: string
    }
    sort: Sort
    filterOptions: {
        businessUnits: string[]
        jobLevels: string[]
        designations: string[]
        units: string[]
    }
    availableYears: number[]
    can: {
        talent: boolean
        idp: boolean
    }
}>()

const state = reactive({
    search: props.filters.search ?? '',
    year: props.filters.year ?? '',
    business_unit: props.filters.business_unit ?? '',
    job_level: props.filters.job_level ?? '',
    designation: props.filters.designation ?? '',
    unit: props.filters.unit ?? '',
    per_page: props.rows.per_page,
})

const columns = computed<Column[]>(() => {
    const cols: Column[] = [
        { key: 'no', label: t.value.report.table.no, sortable: false, thClass: 'w-12 text-center', tdClass: 'text-center text-slate-400' },
        { key: 'employee_id', label: t.value.report.table.id, tdClass: 'text-slate-400' },
        { key: 'fullname', label: t.value.report.table.name, tdClass: 'font-medium text-slate-700' },
        { key: 'group_company', label: t.value.report.table.businessUnit },
        { key: 'job_level', label: t.value.report.table.jobLevel },
        { key: 'designation_name', label: t.value.report.table.designation },
        { key: 'unit', label: t.value.report.table.department },
    ]
    if (props.can.talent) {
        cols.push({ key: 'potential', label: t.value.report.table.potential, sortable: false, tdClass: 'text-center' })
        cols.push({ key: 'talent_box', label: t.value.report.table.talentBox, sortable: false, tdClass: 'text-center' })
    }
    if (props.can.idp) {
        cols.push({ key: 'idp_progress', label: t.value.report.table.idpProgress, sortable: false, tdClass: 'text-center' })
    }
    return cols
})

// Augment rows with a running number based on the current page offset.
const numberedRows = computed(() =>
    props.rows.data.map((row, i) => ({ ...row, no: (props.rows.from ?? 1) + i })),
)

function query(extra: Record<string, unknown> = {}) {
    return {
        search: state.search || undefined,
        year: state.year || undefined,
        business_unit: state.business_unit || undefined,
        job_level: state.job_level || undefined,
        designation: state.designation || undefined,
        unit: state.unit || undefined,
        sort: props.sort.key,
        direction: props.sort.dir,
        per_page: state.per_page,
        ...extra,
    }
}

function reload(extra: Record<string, unknown> = {}) {
    router.get('/report', query(extra), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

function changeSort(sort: Sort) {
    reload({ sort: sort.key, direction: sort.dir })
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
    state.year = ''
    state.business_unit = ''
    state.job_level = ''
    state.designation = ''
    state.unit = ''
    reload()
}

function changePerPage(perPage: number) {
    state.per_page = perPage
    reload()
}

const hasActiveFilters = computed(
    () =>
        !!(
            state.search ||
            state.year ||
            state.business_unit ||
            state.job_level ||
            state.designation ||
            state.unit
        ),
)

const toOptions = (allLabel: string, values: (string | number)[]): Option[] => [
    { value: '', label: allLabel },
    ...values.map((v) => ({ value: String(v), label: String(v) })),
]

const yearOptions = computed(() => toOptions(t.value.report.allYears, props.availableYears))
const businessUnitOptions = computed(() => toOptions(t.value.report.allBusinessUnits, props.filterOptions.businessUnits))
const jobLevelOptions = computed(() => toOptions(t.value.report.allJobLevels, props.filterOptions.jobLevels))
const designationOptions = computed(() => toOptions(t.value.report.allDesignations, props.filterOptions.designations))
const departmentOptions = computed(() => toOptions(t.value.report.allDepartments, props.filterOptions.units))

function onFilter(key: 'year' | 'business_unit' | 'job_level' | 'designation' | 'unit', value: string) {
    state[key] = value
    reload()
}

function exportUrl(reportName: 'talent_report' | 'idp_progress'): string {
    const params = new URLSearchParams({ report_name: reportName })
    if (state.search) params.set('search', state.search)
    if (state.year) params.set('year', state.year)
    if (state.business_unit) params.set('business_unit', state.business_unit)
    if (state.job_level) params.set('job_level', state.job_level)
    if (state.designation) params.set('designation', state.designation)
    if (state.unit) params.set('unit', state.unit)
    return `/report/export?${params.toString()}`
}
</script>

<template>
    <Head :title="t.report.title" />

    <AppLayout>
        <PageHeader :title="t.report.title" :subtitle="t.report.subtitle">
            <template #actions>
                <details v-if="can.talent || can.idp" class="group relative">
                    <summary
                        class="inline-flex cursor-pointer list-none items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary/90"
                    >
                        <i class="fa-solid fa-download text-xs" />
                        {{ t.report.downloadReport }}
                        <i class="fa-solid fa-chevron-down text-[10px] transition group-open:rotate-180" />
                    </summary>
                    <div
                        class="absolute right-0 z-10 mt-2 w-56 overflow-hidden rounded-lg border border-border bg-white py-1 shadow-lg"
                    >
                        <a
                            v-if="can.talent"
                            :href="exportUrl('talent_report')"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-excel text-xs text-emerald-600" />
                            {{ t.report.downloadTalent }}
                        </a>
                        <a
                            v-if="can.idp"
                            :href="exportUrl('idp_progress')"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-excel text-xs text-emerald-600" />
                            {{ t.report.downloadIdp }}
                        </a>
                    </div>
                </details>
            </template>
        </PageHeader>

        <!-- Filters -->
        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div class="relative sm:col-span-2 lg:col-span-3 xl:col-span-1">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                />
                <input
                    v-model="state.search"
                    type="text"
                    :placeholder="t.report.searchPlaceholder"
                    class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>

            <SearchableSelect
                :model-value="state.year"
                :options="yearOptions"
                :placeholder="t.report.allYears"
                @update:model-value="onFilter('year', $event)"
            />

            <SearchableSelect
                :model-value="state.business_unit"
                :options="businessUnitOptions"
                :placeholder="t.report.allBusinessUnits"
                @update:model-value="onFilter('business_unit', $event)"
            />

            <SearchableSelect
                :model-value="state.job_level"
                :options="jobLevelOptions"
                :placeholder="t.report.allJobLevels"
                @update:model-value="onFilter('job_level', $event)"
            />

            <SearchableSelect
                :model-value="state.designation"
                :options="designationOptions"
                :placeholder="t.report.allDesignations"
                @update:model-value="onFilter('designation', $event)"
            />

            <div class="flex gap-2">
                <SearchableSelect
                    class="min-w-0 flex-1"
                    :model-value="state.unit"
                    :options="departmentOptions"
                    :placeholder="t.report.allDepartments"
                    @update:model-value="onFilter('unit', $event)"
                />

                <button
                    v-if="hasActiveFilters"
                    type="button"
                    class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                    :title="t.report.clearFilters"
                    @click="resetFilters"
                >
                    <i class="fa-solid fa-xmark" />
                </button>
            </div>
        </div>

        <!-- Table -->
        <DataTable
            :columns="columns"
            :rows="numberedRows"
            row-key="employee_id"
            min-width="960px"
            server-sort
            :sort="sort"
            @update:sort="changeSort"
        >
            <template #cell-potential="{ value }">
                <span
                    class="inline-flex min-w-[3rem] justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="{
                        'bg-emerald-100 text-emerald-700': value === 'High',
                        'bg-amber-100 text-amber-700': value === 'Medium',
                        'bg-rose-100 text-rose-700': value === 'Low',
                        'bg-slate-100 text-slate-400': value === 'N.A.',
                    }"
                >{{ value }}</span>
            </template>

            <template #empty>{{ t.report.noRecords }}</template>
        </DataTable>

        <Pagination
            :links="rows.links"
            :per-page="rows.per_page"
            :total="rows.total"
            :from="rows.from"
            :to="rows.to"
            @update:per-page="changePerPage"
        />
    </AppLayout>
</template>
