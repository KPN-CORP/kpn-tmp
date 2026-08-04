<script setup lang="ts" generic="T extends Record<string, any>">
import { computed, ref } from 'vue'

/**
 * Lightweight data table with per-column sorting.
 *
 * - Client mode (default): clicking a sortable header sorts the given `rows`
 *   in place.
 * - Server mode (`server-sort`): the header emits `update:sort` and the parent
 *   reloads; pass the current `sort` back for the arrow indicator. Use this for
 *   server-paginated tables so sorting spans all pages, not just the visible one.
 *
 * A `cell-<key>` scoped slot customises a cell; `empty` fills an empty table.
 * Columns are sortable unless `sortable: false` (the "action" column never is).
 */
export interface Column {
    key: string
    label: string
    thClass?: string
    tdClass?: string
    sortable?: boolean
}

export interface Sort {
    key: string
    dir: 'asc' | 'desc'
}

const props = defineProps<{
    columns: Column[]
    rows: T[]
    rowKey: string
    minWidth?: string
    serverSort?: boolean
    sort?: Sort | null
}>()

const emit = defineEmits<{ (e: 'update:sort', value: Sort): void }>()

const internalSort = ref<Sort | null>(null)

const currentSort = computed<Sort | null>(() =>
    props.serverSort ? props.sort ?? null : internalSort.value,
)

function isSortable(col: Column): boolean {
    return col.sortable ?? col.key !== 'action'
}

function toggle(col: Column) {
    if (!isSortable(col)) return
    const cur = currentSort.value
    const dir: 'asc' | 'desc' = cur && cur.key === col.key && cur.dir === 'asc' ? 'desc' : 'asc'
    const next: Sort = { key: col.key, dir }
    if (props.serverSort) emit('update:sort', next)
    else internalSort.value = next
}

function compare(a: any, b: any): number {
    if (a == null && b == null) return 0
    if (a == null) return 1
    if (b == null) return -1
    if (typeof a === 'number' && typeof b === 'number') return a - b
    return String(a).localeCompare(String(b), undefined, { numeric: true })
}

const displayRows = computed<T[]>(() => {
    if (props.serverSort || !internalSort.value) return props.rows
    const { key, dir } = internalSort.value
    const factor = dir === 'asc' ? 1 : -1
    return [...props.rows].sort((a, b) => compare(a[key], b[key]) * factor)
})

function arrow(col: Column): string {
    const cur = currentSort.value
    if (!cur || cur.key !== col.key) return 'fa-sort text-slate-300'
    return cur.dir === 'asc' ? 'fa-sort-up text-primary' : 'fa-sort-down text-primary'
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm">
        <table
            class="w-full text-left text-sm"
            :style="{ minWidth: minWidth ?? '720px' }"
        >
            <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wider text-slate-400">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="px-5 py-3 font-semibold"
                        :class="col.thClass"
                    >
                        <button
                            v-if="isSortable(col) && col.label"
                            type="button"
                            class="inline-flex items-center gap-1.5 uppercase tracking-wider transition hover:text-slate-600"
                            @click="toggle(col)"
                        >
                            {{ col.label }}
                            <i class="fa-solid text-[10px]" :class="arrow(col)" />
                        </button>
                        <template v-else>{{ col.label }}</template>
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="row in displayRows"
                    :key="row[rowKey]"
                    class="border-b border-border/60 transition-colors last:border-0 hover:bg-slate-50"
                >
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        class="px-5 py-3 text-slate-600"
                        :class="col.tdClass"
                    >
                        <slot
                            :name="`cell-${col.key}`"
                            :row="row"
                            :value="row[col.key]"
                        >
                            {{ row[col.key] ?? '—' }}
                        </slot>
                    </td>
                </tr>

                <tr v-if="displayRows.length === 0">
                    <td
                        :colspan="columns.length"
                        class="px-5 py-12 text-center text-sm text-slate-400"
                    >
                        <slot name="empty">No records found.</slot>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
