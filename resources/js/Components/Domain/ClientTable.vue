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
        // Options for the "rows per page" selector in the pager.
        perPageOptions?: number[]
    }>(),
    {
        perPage: 5,
        initialSort: null,
        emptyText: 'No data.',
        numbered: false,
        selectable: false,
        selectedKey: null,
        perPageOptions: () => [10, 20, 50, 100],
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
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th v-if="numbered" class="w-14 px-4 py-2.5 text-center font-semibold">
                            #
                        </th>
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
                        class="group border-b border-border/60 transition last:border-0"
                        :class="[
                            selectable ? 'cursor-pointer' : '',
                            selectable && rowKeyVal(row, i) === selectedKey
                                ? 'bg-primary/5 hover:bg-primary/10'
                                : 'hover:bg-slate-50/70',
                        ]"
                        @click="selectable && emit('row-click', row)"
                    >
                        <td v-if="numbered" class="px-4 py-3 text-center text-slate-400">
                            {{ (page - 1) * perPage + i + 1 }}
                        </td>
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
                        <td :colspan="columns.length + (numbered ? 1 : 0)" class="px-4 py-8 text-center text-slate-400">
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
