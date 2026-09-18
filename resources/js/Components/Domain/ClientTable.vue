<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Pagination from '@/Components/UI/Pagination.vue'

export interface Column {
    key: string
    label: string
    sortable?: boolean
    // Sort by a different field than `key` (e.g. a raw value behind a slot).
    sortKey?: string
    align?: 'left' | 'right' | 'center'
    thClass?: string
    tdClass?: string
    // Merge this cell vertically across adjacent rows that share the same
    // value. Merging is hierarchical in column order: a merge column only
    // spans rows that also agree on every merge column to its left, so a
    // second-level group never straddles two first-level groups. Runs are
    // computed per page, so a group split by pagination simply merges twice.
    // Not compatible with `expanded` rows, which would offset the spans.
    merge?: boolean
    // Compare this field instead of `key` when deciding what merges — use it
    // when the rendered label is not a stable identity (two models may read
    // alike; their ids do not).
    mergeKey?: string
}

const props = withDefaults(
    defineProps<{
        columns: Column[]
        rows: Record<string, any>[]
        rowKey?: string
        perPage?: number
        initialSort?: { key: string; dir: 'asc' | 'desc' } | null
        emptyText?: string
        // When true, prepend an auto-numbered "#" column showing each row's
        // running position across pages.
        numbered?: boolean
        // When true, rows are clickable (cursor + hover) and emit `row-click`;
        // the row whose `rowKey` matches `selectedKey` is highlighted.
        selectable?: boolean
        selectedKey?: string | number | null
        // Row keys whose `expanded` slot is rendered as a full-width row
        // underneath. The parent owns which rows are open, so it can allow one
        // at a time or several.
        expandedKeys?: (string | number)[]
        // Options for the "rows per page" selector in the pager.
        perPageOptions?: number[]
        // Draw vertical dividers between the columns. Opt-in: a plain list
        // reads better without them, but a table with `merge` columns needs
        // the column edges drawn, or a merged block does not read as a block.
        bordered?: boolean
    }>(),
    {
        perPage: 5,
        initialSort: null,
        emptyText: 'No data.',
        numbered: false,
        selectable: false,
        selectedKey: null,
        expandedKeys: () => [],
        perPageOptions: () => [10, 20, 50, 100],
        bordered: false,
    },
)

const emit = defineEmits<{ (e: 'row-click', row: Record<string, any>): void }>()

const sortKey = ref(props.initialSort?.key ?? '')
const sortDir = ref<'asc' | 'desc'>(props.initialSort?.dir ?? 'asc')
const page = ref(1)

// The page size is user-adjustable via the pager's "rows per page" selector;
// seed it from the `perPage` prop and keep it in sync if the prop changes.
const perPageState = ref(props.perPage)
watch(
    () => props.perPage,
    (v) => (perPageState.value = v),
)

function changePerPage(size: number) {
    perPageState.value = size
    page.value = 1
}

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

const totalPages = computed(() => Math.max(1, Math.ceil(sorted.value.length / perPageState.value)))

watch(
    () => props.rows.length,
    () => {
        page.value = 1
    },
)

const pageRows = computed(() => {
    if (page.value > totalPages.value) page.value = totalPages.value
    const start = (page.value - 1) * perPageState.value
    return sorted.value.slice(start, start + perPageState.value)
})

const from = computed(() => (sorted.value.length ? (page.value - 1) * perPageState.value + 1 : 0))
const to = computed(() => Math.min(page.value * perPageState.value, sorted.value.length))

// Mirror Pagination's own visibility rule so the bordered footer only appears
// when there is actually a pager to show (more than one page, or enough rows to
// let the "rows per page" selector matter).
const showPager = computed(
    () =>
        totalPages.value > 1 ||
        sorted.value.length > Math.min(...props.perPageOptions),
)

function rowKeyVal(row: Record<string, any>, i: number) {
    return props.rowKey ? row[props.rowKey] : i
}

function alignClass(align?: string) {
    return align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
}

function isActive(col: Column) {
    return (col.sortKey ?? col.key) === sortKey.value
}

// Total column count, so an expanded row can span the full table width.
const colSpan = computed(() => props.columns.length + (props.numbered ? 1 : 0))

function isExpanded(row: Record<string, any>, i: number) {
    return props.expandedKeys.includes(rowKeyVal(row, i))
}

/**
 * Rowspans for the merge columns on the current page: `spans[i][col.key]` is
 * how many rows this cell covers, or 0 when the row above already covers it
 * and this row must not render a cell at all.
 */
const mergeColumns = computed(() => props.columns.filter((c) => c.merge))

const mergeSpans = computed<Record<string, number>[]>(() => {
    const rows = pageRows.value
    const spans: Record<string, number>[] = rows.map(() => ({}))
    const cols = mergeColumns.value
    if (!cols.length) return spans

    // A row's group at depth d is everything it agrees on up to and including
    // that column — which is what makes the nesting hierarchical.
    const SEP = String.fromCharCode(0)
    const sig = (row: Record<string, any>, depth: number) =>
        cols
            .slice(0, depth + 1)
            .map((c) => String(row?.[c.mergeKey ?? c.key] ?? ''))
            .join(SEP)

    cols.forEach((col, depth) => {
        let start = 0
        for (let i = 1; i <= rows.length; i++) {
            if (i === rows.length || sig(rows[i], depth) !== sig(rows[start], depth)) {
                spans[start][col.key] = i - start
                for (let j = start + 1; j < i; j++) spans[j][col.key] = 0
                start = i
            }
        }
    })

    return spans
})

function cellSpan(col: Column, i: number): number {
    if (!col.merge) return 1
    return mergeSpans.value[i]?.[col.key] ?? 1
}

/**
 * The column divider, skipped on the last column so the table does not draw a
 * line on top of its container's own right border. It is keyed on the column
 * rather than a `last:` variant because a merged-away cell is not rendered at
 * all, so the last cell IN A ROW is not always the last column.
 */
const lastColumnKey = computed(() => props.columns[props.columns.length - 1]?.key)

function dividerClass(colKey?: string) {
    if (!props.bordered || colKey === lastColumnKey.value) return ''
    return 'border-r border-border/60'
}
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th
                            v-if="numbered"
                            class="w-14 px-4 py-2.5 text-center font-semibold"
                            :class="dividerClass()"
                        >
                            #
                        </th>
                        <th
                            v-for="col in columns"
                            :key="col.key"
                            class="px-4 py-2.5 font-semibold"
                            :class="[alignClass(col.align), col.thClass, dividerClass(col.key), col.sortable ? 'cursor-pointer select-none hover:text-slate-600' : '']"
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
                    <template v-for="(row, i) in pageRows" :key="rowKeyVal(row, i)">
                        <tr
                            class="group border-b border-border/60 transition last:border-0"
                            :class="[
                                selectable ? 'cursor-pointer' : '',
                                selectable && rowKeyVal(row, i) === selectedKey
                                    ? 'bg-primary/5 hover:bg-primary/10'
                                    : 'hover:bg-slate-50/70',
                                isExpanded(row, i) ? 'border-b-0' : '',
                            ]"
                            @click="selectable && emit('row-click', row)"
                        >
                            <td
                                v-if="numbered"
                                class="px-4 py-3 text-center text-slate-400"
                                :class="dividerClass()"
                            >
                                {{ (page - 1) * perPageState + i + 1 }}
                            </td>
                            <!-- A merged cell is rendered once, by the first
                                 row of its group; the rows it covers skip it.
                                 Every cell is middle-aligned, merged or not, so
                                 a group label lines up with the rows it covers
                                 rather than floating above a taller neighbour. -->
                            <template v-for="col in columns" :key="col.key">
                                <td
                                    v-if="cellSpan(col, i) !== 0"
                                    class="px-4 py-3 align-middle text-slate-700"
                                    :class="[alignClass(col.align), col.tdClass, dividerClass(col.key)]"
                                    :rowspan="cellSpan(col, i) > 1 ? cellSpan(col, i) : undefined"
                                >
                                    <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                                        {{ row[col.key] ?? '—' }}
                                    </slot>
                                </td>
                            </template>
                        </tr>

                        <!-- Detail panel for this row, spanning the full width. -->
                        <tr v-if="isExpanded(row, i)" class="border-b border-border/60 last:border-0">
                            <td :colspan="colSpan" class="bg-slate-50/60 p-0">
                                <slot name="expanded" :row="row" />
                            </td>
                        </tr>
                    </template>
                    <tr v-if="pageRows.length === 0">
                        <td :colspan="colSpan" class="px-4 py-8 text-center text-slate-400">
                            <slot name="empty">{{ emptyText }}</slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pager: shared rows-per-page + windowed page strip (client mode). -->
        <div
            v-if="showPager"
            class="border-t border-border px-4 py-2.5 [&>div]:!mt-0"
        >
            <Pagination
                :page="page"
                :per-page="perPageState"
                :total="sorted.length"
                :from="from"
                :to="to"
                :per-page-options="perPageOptions"
                @update:page="page = $event"
                @update:per-page="changePerPage"
            />
        </div>
    </div>
</template>
