<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import EmployeeSelect from '@/Components/Domain/EmployeeSelect.vue'
import HoverBadge from '@/Components/UI/HoverBadge.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface LayerRef {
    id: string
    name: string | null
}

interface EmployeeRow {
    employee_id: string
    name: string | null
    pt: string | null
    area: string | null
    bu: string | null
    layers: (LayerRef | null)[]
    has_override: boolean
}

interface Paginator {
    data: EmployeeRow[]
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
    employees: Paginator
    filters: { search: string; bu: string; area: string; pt: string }
    sort: Sort
    filterOptions: {
        businessUnits: string[]
        areas: string[]
        pts: string[]
    }
}>()

// Format a layer reference as "employee_id - name", gracefully showing just
// the id when the name can't be resolved (approver not in the employee table).
function fmtRef(ref: LayerRef | null | undefined): string {
    if (!ref) return '—'
    return ref.name ? `${ref.id} - ${ref.name}` : ref.id
}

/**
 * --------------------------------------------------------------------------
 * List: search + pagination (server-side, Inertia partial reload)
 * --------------------------------------------------------------------------
 */

const state = reactive({
    search: props.filters.search ?? '',
    bu: props.filters.bu ?? '',
    area: props.filters.area ?? '',
    pt: props.filters.pt ?? '',
    per_page: props.employees.per_page,
})

function reload(sort: Sort = props.sort) {
    router.get(
        '/approval-setting',
        {
            search: state.search || undefined,
            bu: state.bu || undefined,
            area: state.area || undefined,
            pt: state.pt || undefined,
            sort: sort.key,
            direction: sort.dir,
            per_page: state.per_page,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

// Debounce the free-text search; the selects apply immediately.
let debounce: ReturnType<typeof setTimeout> | undefined
watch(
    () => state.search,
    () => {
        clearTimeout(debounce)
        debounce = setTimeout(() => reload(), 300)
    },
)

const hasFilters = computed(
    () => !!(state.search || state.bu || state.area || state.pt),
)

function resetFilters() {
    state.search = ''
    state.bu = ''
    state.area = ''
    state.pt = ''
    reload()
}

// Selects apply immediately (search is debounced above).
function onFilter(key: 'pt' | 'area' | 'bu', value: string) {
    state[key] = value
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

// Build SearchableSelect options with a leading "All" entry.
function options(values: string[], allLabel: string): Option[] {
    return [
        { value: '', label: allLabel },
        ...values.map((v) => ({ value: v, label: v })),
    ]
}

// Sortable columns — keys must match the server-side whitelist.
const sortableColumns = computed(() => [
    { key: 'employee_id', label: t.value.approval.nik },
    { key: 'fullname', label: t.value.approval.name },
    { key: 'company_name', label: t.value.approval.pt },
    { key: 'office_area', label: t.value.approval.area },
    { key: 'group_company', label: t.value.approval.bu },
])

/**
 * --------------------------------------------------------------------------
 * Update Superior modal
 * --------------------------------------------------------------------------
 */

const editModal = ref(false)
const editingEmployee = ref<EmployeeRow | null>(null)

// Human labels shown in each picker, kept parallel to editForm.layers.
const rowLabels = ref<(string | null)[]>([])
const editForm = useForm<{ layers: (string | null)[] }>({ layers: [] })

function openEdit(row: EmployeeRow) {
    editingEmployee.value = row
    editForm.clearErrors()

    editForm.layers = row.layers.map((l) => l?.id ?? null)
    rowLabels.value = row.layers.map((l) => (l ? fmtRef(l) : null))

    // Always show at least one picker to start from.
    if (editForm.layers.length === 0) {
        editForm.layers = [null]
        rowLabels.value = [null]
    }

    editModal.value = true
}

function addLayer() {
    editForm.layers.push(null)
    rowLabels.value.push(null)
}

function removeLayer(index: number) {
    editForm.layers.splice(index, 1)
    rowLabels.value.splice(index, 1)
}

function submitEdit() {
    if (!editingEmployee.value) return
    editForm.put(`/approval-setting/${editingEmployee.value.employee_id}`, {
        preserveScroll: true,
        onSuccess: () => (editModal.value = false),
    })
}

/**
 * --------------------------------------------------------------------------
 * History drawer
 * --------------------------------------------------------------------------
 */

interface HistoryEntry {
    id: number
    changed_by_name: string | null
    created_at: string | null
    layers: (LayerRef | null)[]
}

const historyDrawer = ref(false)
const historyEmployee = ref<EmployeeRow | null>(null)
const historyEntries = ref<HistoryEntry[]>([])
const historyLoading = ref(false)

async function openHistory(row: EmployeeRow) {
    historyEmployee.value = row
    historyDrawer.value = true
    historyLoading.value = true
    historyEntries.value = []
    try {
        const res = await fetch(`/approval-setting/${row.employee_id}/history`, {
            headers: { Accept: 'application/json' },
        })
        historyEntries.value = res.ok ? await res.json() : []
    } catch {
        historyEntries.value = []
    } finally {
        historyLoading.value = false
    }
}

/**
 * --------------------------------------------------------------------------
 * Import modal
 * --------------------------------------------------------------------------
 */

const importModal = ref(false)
const importForm = useForm<{ file: File | null }>({ file: null })

function onFile(e: Event) {
    importForm.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

function submitImport() {
    importForm.post('/approval-setting/import', {
        preserveScroll: true,
        onSuccess: () => {
            importModal.value = false
            importForm.reset()
        },
    })
}
</script>

<template>
    <Head :title="t.approval.title" />

    <AppLayout>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <PageHeader :title="t.approval.title" :subtitle="t.approval.subtitle" />

            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                @click="importModal = true"
            >
                <i class="fa-solid fa-file-import text-xs" />
                {{ t.approval.import }}
            </button>
        </div>

        <!-- Filters -->
        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="relative sm:col-span-2 lg:col-span-1">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                />
                <input
                    v-model="state.search"
                    type="text"
                    :placeholder="t.approval.searchPlaceholder"
                    class="w-full rounded-md border border-border bg-white py-2.5 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>

            <SearchableSelect
                :model-value="state.pt"
                :options="options(filterOptions.pts, t.approval.allPt)"
                :placeholder="t.approval.allPt"
                @update:model-value="onFilter('pt', $event)"
            />

            <SearchableSelect
                :model-value="state.area"
                :options="options(filterOptions.areas, t.approval.allArea)"
                :placeholder="t.approval.allArea"
                @update:model-value="onFilter('area', $event)"
            />

            <div class="flex gap-2">
                <SearchableSelect
                    class="min-w-0 flex-1"
                    :model-value="state.bu"
                    :options="options(filterOptions.businessUnits, t.approval.allBu)"
                    :placeholder="t.approval.allBu"
                    @update:model-value="onFilter('bu', $event)"
                />

                <button
                    v-if="hasFilters"
                    type="button"
                    class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                    :title="t.approval.resetFilters"
                    @click="resetFilters"
                >
                    <i class="fa-solid fa-xmark" />
                </button>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm">
            <table class="w-full min-w-[880px] border-collapse text-sm">
                <thead>
                    <tr
                        class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                    >
                        <th class="w-12 px-5 py-3 text-center">#</th>
                        <th
                            v-for="col in sortableColumns"
                            :key="col.key"
                            class="cursor-pointer select-none px-5 py-3 transition hover:text-slate-700"
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
                        <th class="px-5 py-3">{{ t.approval.superior }}</th>
                        <th class="w-24 px-5 py-3 text-center">{{ t.approval.actions }}</th>
                    </tr>
                </thead>

                <tbody>
                    <tr
                        v-for="(row, i) in employees.data"
                        :key="row.employee_id"
                        class="border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/60"
                    >
                        <td class="px-5 py-4 text-center text-slate-400">
                            {{ (employees.from ?? 0) + i }}
                        </td>
                        <td class="px-5 py-4 font-medium text-slate-700">
                            {{ row.employee_id }}
                        </td>
                        <td class="px-5 py-4 text-slate-600">{{ row.name ?? '—' }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ row.pt ?? '—' }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ row.area ?? '—' }}</td>
                        <td class="px-5 py-4 text-slate-500">{{ row.bu ?? '—' }}</td>
                        <td class="px-5 py-4">
                            <div v-if="row.layers.length" class="flex flex-wrap gap-1">
                                <HoverBadge
                                    v-for="(layer, li) in row.layers"
                                    :key="li"
                                    :label="`L${li + 1}`"
                                    :tip="fmtRef(layer)"
                                />
                            </div>
                            <span v-else class="text-slate-300">—</span>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-center gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen-to-square"
                                    variant="edit"
                                    :title="t.approval.editSuperior"
                                    @click="openEdit(row)"
                                />
                                <IconButton
                                    icon="fa-solid fa-clock-rotate-left"
                                    variant="default"
                                    :title="t.approval.history"
                                    @click="openHistory(row)"
                                />
                            </div>
                        </td>
                    </tr>

                    <tr v-if="employees.data.length === 0">
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-slate-400">
                            {{ t.approval.noEmployees }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination
                :links="employees.links"
                :per-page="employees.per_page"
                :total="employees.total"
                :from="employees.from"
                :to="employees.to"
                @update:per-page="changePerPage"
            />
        </div>

        <!-- ============================================================
             UPDATE SUPERIOR MODAL
        ============================================================= -->
        <Drawer :show="editModal" :title="t.approval.editSuperior" @close="editModal = false">
            <form id="superior-form" class="space-y-5" @submit.prevent="submitEdit">
                <!-- Subject employee (read-only) -->
                <div>
                    <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ t.approval.employee }}
                    </label>
                    <div
                        class="flex items-center gap-2.5 rounded-lg border border-border bg-slate-50 px-3.5 py-2.5 text-sm text-slate-700"
                    >
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary"
                        >
                            <i class="fa-solid fa-user" />
                        </span>
                        <span class="min-w-0 truncate font-medium">
                            {{ editingEmployee?.employee_id }} - {{ editingEmployee?.name }}
                        </span>
                    </div>
                </div>

                <!-- Dynamic approval layers -->
                <div>
                    <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-400">
                        {{ t.approval.layers }}
                    </label>

                    <div class="space-y-2.5">
                        <div
                            v-for="(id, i) in editForm.layers"
                            :key="i"
                            class="group flex items-center gap-2"
                        >
                            <span
                                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-500"
                            >
                                {{ i + 1 }}
                            </span>
                            <EmployeeSelect
                                class="flex-1"
                                :model-value="editForm.layers[i]"
                                :label="rowLabels[i]"
                                :placeholder="t.approval.selectLayer"
                                @update:model-value="(v) => (editForm.layers[i] = v)"
                                @update:label="(v) => (rowLabels[i] = v)"
                            />
                            <button
                                type="button"
                                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition hover:bg-red-50 hover:text-red-500"
                                :title="t.approval.removeLayer"
                                @click="removeLayer(i)"
                            >
                                <i class="fa-solid fa-trash-can text-xs" />
                            </button>
                        </div>
                    </div>

                    <p v-if="editForm.errors.layers" class="mt-1.5 text-xs text-red-600">
                        {{ editForm.errors.layers }}
                    </p>

                    <!-- Add layer -->
                    <button
                        type="button"
                        class="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-border py-2.5 text-sm font-semibold text-primary transition hover:border-primary hover:bg-primary/5"
                        @click="addLayer"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.approval.addLayer }}
                    </button>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="editModal = false"
                >
                    {{ t.approval.close }}
                </button>
                <button
                    type="submit"
                    form="superior-form"
                    :disabled="editForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.approval.saveChanges }}
                </button>
            </template>
        </Drawer>

        <!-- ============================================================
             HISTORY DRAWER
        ============================================================= -->
        <Drawer :show="historyDrawer" :title="t.approval.history" @close="historyDrawer = false">
            <div class="mb-3 text-sm text-slate-500">
                {{ historyEmployee?.name }} - {{ historyEmployee?.employee_id }}
            </div>

            <div v-if="historyLoading" class="py-10 text-center text-sm text-slate-400">
                <i class="fa-solid fa-spinner fa-spin mr-2" />
                {{ t.approval.loading }}
            </div>

            <ol v-else-if="historyEntries.length" class="space-y-3">
                <li
                    v-for="entry in historyEntries"
                    :key="entry.id"
                    class="rounded-lg border border-border bg-white p-4 shadow-sm"
                >
                    <div class="mb-2 flex items-center justify-between text-xs text-slate-400">
                        <span>
                            <i class="fa-solid fa-user mr-1" />
                            {{ entry.changed_by_name ?? '—' }}
                        </span>
                        <span>{{ entry.created_at }}</span>
                    </div>
                    <ul v-if="entry.layers.length" class="space-y-1 text-sm">
                        <li
                            v-for="(layer, li) in entry.layers"
                            :key="li"
                            class="flex items-center gap-2"
                        >
                            <span class="w-8 shrink-0 font-semibold text-slate-400">
                                L{{ li + 1 }}
                            </span>
                            <span class="text-slate-600">{{ fmtRef(layer) }}</span>
                        </li>
                    </ul>
                    <p v-else class="text-sm italic text-slate-400">{{ t.approval.cleared }}</p>
                </li>
            </ol>

            <div v-else class="py-10 text-center text-sm text-slate-400">
                {{ t.approval.noHistory }}
            </div>
        </Drawer>

        <!-- ============================================================
             IMPORT MODAL
        ============================================================= -->
        <Drawer :show="importModal" :title="t.approval.import" @close="importModal = false">
            <form id="import-form" class="space-y-4" @submit.prevent="submitImport">
                <p class="text-sm text-slate-500">
                    {{ t.approval.importHint }}
                </p>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        {{ t.approval.file }}
                    </label>
                    <input
                        type="file"
                        accept=".xlsx,.xls,.csv"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary hover:file:bg-primary/20"
                        @change="onFile"
                    >
                    <p v-if="importForm.errors.file" class="mt-1 text-xs text-red-600">
                        {{ importForm.errors.file }}
                    </p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="importModal = false"
                >
                    {{ t.approval.close }}
                </button>
                <button
                    type="submit"
                    form="import-form"
                    :disabled="importForm.processing || !importForm.file"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.approval.import }}
                </button>
            </template>
        </Drawer>
    </AppLayout>
</template>
