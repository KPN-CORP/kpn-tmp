<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import StatCard from '@/Components/UI/StatCard.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Record {
    id: number
    name: string
    email: string
    role: string
    status: 'active' | 'pending' | 'inactive'
    created_at: string
}

interface Paginator {
    data: Record[]
    links: { url: string | null; label: string; active: boolean }[]
    total: number
    from: number | null
    to: number | null
    per_page: number
}

const props = defineProps<{
    stats: {
        users: number
        active: number
        reports: number
        revenue: string
    }
    records: Paginator
}>()

// Reload the current page with a new page size, keeping scroll position.
function changePerPage(perPage: number) {
    router.get(
        window.location.pathname,
        { perPage },
        { preserveState: true, preserveScroll: true, replace: true },
    )
}

// Tone classes for the status pill.
const statusTone: Record<string, string> = {
    active: 'bg-green-50 text-green-700 ring-green-600/20',
    pending: 'bg-amber-50 text-amber-700 ring-amber-600/20',
    inactive: 'bg-slate-100 text-slate-500 ring-slate-500/20',
}
</script>

<template>
    <AppLayout>
        <PageHeader
            :title="t.dashboard.title"
            :subtitle="t.dashboard.subtitle"
        >
            <template #actions>
                <button
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    <span>{{ t.dashboard.table.title }}</span>
                </button>
            </template>
        </PageHeader>

        <!-- Stat cards -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                :label="t.dashboard.stats.users"
                :value="props.stats.users.toLocaleString()"
                icon="fa-solid fa-users"
                tone="text-primary bg-red-50"
            />
            <StatCard
                :label="t.dashboard.stats.active"
                :value="props.stats.active"
                icon="fa-solid fa-signal"
                tone="text-teal-600 bg-teal-50"
            />
            <StatCard
                :label="t.dashboard.stats.reports"
                :value="props.stats.reports"
                icon="fa-solid fa-file-lines"
                tone="text-indigo-600 bg-indigo-50"
            />
            <StatCard
                :label="t.dashboard.stats.revenue"
                :value="props.stats.revenue"
                icon="fa-solid fa-wallet"
                tone="text-amber-600 bg-amber-50"
            />
        </div>

        <!-- Records table -->
        <div class="mt-8 rounded-xl border border-border bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-border px-5 py-4">
                <h2 class="font-bold text-slate-800">
                    {{ t.dashboard.table.title }}
                </h2>
            </div>

            <!-- Horizontal scroll on narrow screens; the page body never
                 scrolls sideways. -->
            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-border text-xs uppercase tracking-wider text-slate-400">
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.id }}</th>
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.name }}</th>
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.email }}</th>
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.role }}</th>
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.status }}</th>
                            <th class="px-5 py-3 font-semibold">{{ t.dashboard.table.createdAt }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="row in props.records.data"
                            :key="row.id"
                            class="border-b border-border/60 transition-colors last:border-0 hover:bg-slate-50"
                        >
                            <td class="px-5 py-3 text-slate-400">#{{ row.id }}</td>
                            <td class="px-5 py-3 font-medium text-slate-700">{{ row.name }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ row.email }}</td>
                            <td class="px-5 py-3 text-slate-500">{{ row.role }}</td>
                            <td class="px-5 py-3">
                                <span
                                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                                    :class="statusTone[row.status]"
                                >
                                    {{ t.dashboard.status[row.status] }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-slate-500">{{ row.created_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="px-5 pb-5">
                <Pagination
                    :links="props.records.links"
                    :per-page="props.records.per_page"
                    :total="props.records.total"
                    :from="props.records.from"
                    :to="props.records.to"
                    @update:per-page="changePerPage"
                />
            </div>
        </div>
    </AppLayout>
</template>
