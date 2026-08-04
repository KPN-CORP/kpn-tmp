<script setup lang="ts" generic="T extends Record<string, any>">
/**
 * Lightweight, presentational data table. The parent owns the data (and any
 * server-side pagination/filtering); this just renders columns and rows, with a
 * `cell-<key>` scoped slot per column for custom cell content, plus an `empty`
 * slot. It never scrolls the page sideways — wide tables scroll inside the
 * container.
 */
export interface Column {
    key: string
    label: string
    thClass?: string
    tdClass?: string
}

defineProps<{
    columns: Column[]
    rows: T[]
    rowKey: string
    minWidth?: string
}>()
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
                        {{ col.label }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr
                    v-for="row in rows"
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

                <tr v-if="rows.length === 0">
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
