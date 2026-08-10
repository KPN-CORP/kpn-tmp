<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
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
    { key: 'select', label: '', sortable: false, thClass: 'w-10', tdClass: 'w-10' },
    { key: 'employee_id', label: t.value.facecard.table.id, tdClass: 'text-slate-400' },
    { key: 'fullname', label: t.value.facecard.table.name, tdClass: 'font-medium text-slate-700' },
    { key: 'group_company', label: t.value.facecard.table.businessUnit },
    { key: 'job_level', label: t.value.facecard.table.jobLevel },
    { key: 'designation_name', label: t.value.facecard.table.designation },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

// --- Row selection (persists across pages while the component stays mounted) ---
const selected = ref<string[]>([])

const pageIds = computed(() => props.employees.data.map((r) => r.employee_id))
const allOnPageSelected = computed(
    () => pageIds.value.length > 0 && pageIds.value.every((id) => selected.value.includes(id)),
)
const someOnPageSelected = computed(
    () => !allOnPageSelected.value && pageIds.value.some((id) => selected.value.includes(id)),
)

const isSelected = (id: string) => selected.value.includes(id)

function toggleRow(id: string) {
    selected.value = isSelected(id)
        ? selected.value.filter((x) => x !== id)
        : [...selected.value, id]
}

function toggleAllOnPage() {
    if (allOnPageSelected.value) {
        selected.value = selected.value.filter((id) => !pageIds.value.includes(id))
    } else {
        selected.value = [...new Set([...selected.value, ...pageIds.value])]
    }
}

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
        const res = await fetch('/facecard/bulk-download', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf,
            },
            body: JSON.stringify({ employee_ids: selected.value }),
        })
        const { job_id } = await res.json()
        pollStatus(job_id)
    } catch {
        bulk.error = t.value.facecard.exportError
        bulk.running = false
    }
}

function pollStatus(jobId: string) {
    clearInterval(poll)
    poll = setInterval(async () => {
        try {
            const res = await fetch(`/facecard/bulk-download/status/${jobId}`, { headers: { Accept: 'application/json' } })
            const data = await res.json()
            bulk.progress = data.progress ?? 0
            if (data.error) {
                bulk.error = data.error
                stopBulk()
            } else if (data.ready) {
                stopBulk()
                window.location.href = `/facecard/bulk-download/file/${jobId}`
            }
        } catch {
            bulk.error = t.value.facecard.exportError
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
    <Head :title="t.facecard.title" />

    <AppLayout>
        <PageHeader
            :title="t.facecard.title"
            :subtitle="t.facecard.subtitle"
        >
            <template #actions>
                <button
                    type="button"
                    :disabled="bulk.running"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                    @click="startBulkDownload"
                >
                    <i :class="bulk.running ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-file-zipper'" class="text-xs" />
                    <template v-if="bulk.running">{{ t.facecard.preparing }} {{ bulk.progress }}%</template>
                    <template v-else-if="selected.length">{{ t.facecard.downloadSelected }} ({{ selected.length }})</template>
                    <template v-else>{{ t.facecard.bulkDownload }}</template>
                </button>
                <a
                    :href="exportUrl()"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-file-excel text-xs" />
                    {{ t.facecard.exportExcel }}
                </a>
            </template>
        </PageHeader>

        <p v-if="bulk.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {{ bulk.error }}
        </p>

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
            <template #head-select>
                <input
                    type="checkbox"
                    class="h-4 w-4 cursor-pointer rounded border-slate-300 text-primary focus:ring-primary"
                    :checked="allOnPageSelected"
                    :indeterminate.prop="someOnPageSelected"
                    :title="t.facecard.selectAll"
                    @change="toggleAllOnPage"
                >
            </template>
            <template #cell-select="{ row }">
                <input
                    type="checkbox"
                    class="h-4 w-4 cursor-pointer rounded border-slate-300 text-primary focus:ring-primary"
                    :checked="isSelected(row.employee_id)"
                    @change="toggleRow(row.employee_id)"
                >
            </template>
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
