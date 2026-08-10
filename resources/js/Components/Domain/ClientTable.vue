<script setup lang="ts">
import { computed, ref, watch } from 'vue'

export interface Column {
    key: string
    label: string
    sortable?: boolean
    // Sort by a different field than `key` (e.g. a raw value behind a slot).
    sortKey?: string
    align?: 'left' | 'right' | 'center'
    thClass?: string
    tdClass?: string
}

const props = withDefaults(
    defineProps<{
        columns: Column[]
        rows: Record<string, any>[]
        rowKey?: string
        perPage?: number
        initialSort?: { key: string; dir: 'asc' | 'desc' } | null
        emptyText?: string
    }>(),
    { perPage: 5, initialSort: null, emptyText: 'No data.' },
)

const sortKey = ref(props.initialSort?.key ?? '')
const sortDir = ref<'asc' | 'desc'>(props.initialSort?.dir ?? 'asc')
const page = ref(1)

function toggleSort(col: Column) {
    if (!col.sortable) return
    const key = col.sortKey ?? col.key
    if (sortKey.value === key) {
        sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDir.value = 'asc'
    }
    page.value = 1
}

// Type-aware comparison: numbers, then dates, then locale strings; nulls last.
function cmp(a: any, b: any): number {
    if (a == null || a === '') return b == null || b === '' ? 0 : 1
    if (b == null || b === '') return -1
    const an = Number(a)
    const bn = Number(b)
    if (!Number.isNaN(an) && !Number.isNaN(bn)) return an - bn
    const ad = Date.parse(a)
    const bd = Date.parse(b)
    if (!Number.isNaN(ad) && !Number.isNaN(bd)) return ad - bd
    return String(a).localeCompare(String(b))
}

const sorted = computed(() => {
    if (!sortKey.value) return props.rows
    const k = sortKey.value
    const dir = sortDir.value === 'asc' ? 1 : -1
    return [...props.rows].sort((a, b) => cmp(a?.[k], b?.[k]) * dir)
})

const totalPages = computed(() => Math.max(1, Math.ceil(sorted.value.length / props.perPage)))

watch(
    () => props.rows.length,
    () => {
        page.value = 1
    },
)

const pageRows = computed(() => {
    if (page.value > totalPages.value) page.value = totalPages.value
    const start = (page.value - 1) * props.perPage
    return sorted.value.slice(start, start + props.perPage)
})

const from = computed(() => (sorted.value.length ? (page.value - 1) * props.perPage + 1 : 0))
const to = computed(() => Math.min(page.value * props.perPage, sorted.value.length))

function rowKeyVal(row: Record<string, any>, i: number) {
    return props.rowKey ? row[props.rowKey] : i
}

function alignClass(align?: string) {
    return align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
}

function isActive(col: Column) {
    return (col.sortKey ?? col.key) === sortKey.value
}
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-2.5 font-semibold"
                            :class="[alignClass(col.align), col.thClass, col.sortable ? 'cursor-pointer select-none hover:text-slate-600' : '']"
                            @click="toggleSort(col)"
                        >
                            <span
                                class="inline-flex items-center gap-1"
                                :class="col.align === 'right' ? 'flex-row-reverse' : ''"
                            >
                                {{ col.label }}
                                <i
                                    v-if="col.sortable"
                                    class="fa-solid text-[10px]"
                                    :class="isActive(col)
                                        ? (sortDir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary')
                                        : 'fa-sort text-slate-300'"
                                />
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, i) in pageRows"
                        :key="rowKeyVal(row, i)"
                        class="group border-b border-border/60 transition last:border-0 hover:bg-slate-50/70"
                    >
                        <td
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-3 text-slate-700"
                            :class="[alignClass(col.align), col.tdClass]"
                        >
                            <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                {{ row[col.key] ?? '—' }}
                            </slot>
                        </td>
                    </tr>
                    <tr v-if="pageRows.length === 0">
                        <td :colspan="columns.length" class="px-4 py-8 text-center text-slate-400">
                            <slot name="empty">{{ emptyText }}</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pager -->
        <div
            v-if="totalPages > 1"
            class="flex flex-col gap-2 border-t border-border px-4 py-2.5 sm:flex-row sm:items-center sm:justify-between"
        >
            <span class="text-xs text-slate-400">{{ from }}–{{ to }} / {{ sorted.length }}</span>
            <div class="flex gap-1">
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-white text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"
                    :disabled="page === 1"
                    @click="page--"
                >
                    <i class="fa-solid fa-chevron-left text-xs" />
                </button>
                <button
                    v-for="p in totalPages"
                    :key="p"
                    type="button"
                    class="h-8 min-w-8 rounded-md border px-2 text-sm transition"
                    :class="p === page ? 'border-primary bg-primary text-white' : 'border-border bg-white text-slate-600 hover:bg-slate-50'"
                    @click="page = p"
                >
                    {{ p }}
                </button>
                <button
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-white text-slate-600 transition hover:bg-slate-50 disabled:opacity-40"
                    :disabled="page === totalPages"
                    @click="page++"
                >
                    <i class="fa-solid fa-chevron-right text-xs" />
                </button>
            </div>
        </div>
    </div>
</template>
