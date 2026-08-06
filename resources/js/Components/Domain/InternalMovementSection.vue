<script setup lang="ts">
import { computed, ref } from 'vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate } from '@/Composables/useDate'

const { t } = useLocale()

interface Movement {
    effective_from: string | null
    effective_to: string | null
    type: string | null
    detail: string | null
    from: string | null
    to: string | null
    status: string | null
}

const props = defineProps<{
    movements: Movement[]
    attributes: string[]
}>()

const PER_PAGE = 5

const statusFilter = ref('Active')
const typeFilter = ref('')
const detailFilter = ref('')
const page = ref(1)

const filtered = computed(() =>
    props.movements.filter((m) => {
        const matchesStatus = statusFilter.value === 'All' || m.status === statusFilter.value
        const matchesType = !typeFilter.value || m.type === typeFilter.value
        const matchesDetail = !detailFilter.value || m.detail === detailFilter.value
        return matchesStatus && matchesType && matchesDetail
    }),
)

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / PER_PAGE)))

const pageRows = computed(() => {
    if (page.value > totalPages.value) page.value = 1
    const start = (page.value - 1) * PER_PAGE
    return filtered.value.slice(start, start + PER_PAGE)
})

function resetFilters() {
    statusFilter.value = 'Active'
    typeFilter.value = ''
    detailFilter.value = ''
    page.value = 1
}

// "Active" status + empty type/detail is the default; anything else is a filter.
const hasActiveFilters = computed(
    () => statusFilter.value !== 'Active' || !!typeFilter.value || !!detailFilter.value,
)

function onFilter(target: 'status' | 'type' | 'detail', value: string) {
    if (target === 'status') statusFilter.value = value
    else if (target === 'type') typeFilter.value = value
    else detailFilter.value = value
    page.value = 1
}

const statusOptions = computed<Option[]>(() => [
    { value: 'Active', label: t.value.facecard.movement.active },
    { value: 'Inactive', label: t.value.facecard.movement.inactive },
    { value: 'Archived', label: t.value.facecard.movement.archived },
    { value: 'All', label: t.value.facecard.movement.allStatuses },
])

const typeOptions = computed<Option[]>(() => [
    { value: '', label: t.value.facecard.movement.allTypes },
    { value: 'Promotion', label: t.value.facecard.movement.promotion },
    { value: 'Demotion', label: t.value.facecard.movement.demotion },
    { value: 'Transfer', label: t.value.facecard.movement.transfer },
])

const detailOptions = computed<Option[]>(() => [
    { value: '', label: t.value.facecard.movement.allDetails },
    ...props.attributes.map((attr) => ({ value: attr, label: attr })),
])

function typeClass(type: string | null): string {
    if (type === 'Promotion') return 'font-semibold text-emerald-600'
    if (type === 'Demotion') return 'font-semibold text-red-600'
    return 'text-slate-600'
}

function statusBadge(status: string | null): string {
    if (status === 'Active') return 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
    if (status === 'Inactive') return 'bg-slate-100 text-slate-600 ring-slate-500/20'
    if (status === 'Archived') return 'bg-amber-50 text-amber-700 ring-amber-600/20'
    return 'bg-slate-100 text-slate-500 ring-slate-500/20'
}
</script>

<template>
    <div class="rounded-xl border border-border bg-white shadow-sm">
        <div class="flex items-center gap-2.5 rounded-t-xl border-b border-border px-5 py-3.5">
            <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
            <h3 class="font-semibold text-slate-800">{{ t.facecard.movement.title }}</h3>
        </div>

        <div class="px-5 py-4">
        <!-- Filters -->
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 lg:items-end">
            <div>
                <label class="mb-1 block text-xs text-slate-500">{{ t.facecard.movement.filterStatus }}</label>
                <SearchableSelect
                    :model-value="statusFilter"
                    :options="statusOptions"
                    @update:model-value="onFilter('status', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-xs text-slate-500">{{ t.facecard.movement.filterType }}</label>
                <SearchableSelect
                    :model-value="typeFilter"
                    :options="typeOptions"
                    :placeholder="t.facecard.movement.allTypes"
                    @update:model-value="onFilter('type', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-xs text-slate-500">{{ t.facecard.movement.filterDetail }}</label>
                <div class="flex gap-2">
                    <SearchableSelect
                        class="min-w-0 flex-1"
                        :model-value="detailFilter"
                        :options="detailOptions"
                        :placeholder="t.facecard.movement.allDetails"
                        @update:model-value="onFilter('detail', $event)"
                    />
                    <button
                        v-if="hasActiveFilters"
                        type="button"
                        class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                        :title="t.facecard.clearFilters"
                        @click="resetFilters"
                    >
                        <i class="fa-solid fa-xmark" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-4 py-2.5 font-semibold" colspan="2">{{ t.facecard.movement.effectiveDate }}</th>
                        <th class="px-4 py-2.5 font-semibold" colspan="2">{{ t.facecard.movement.movement }}</th>
                        <th class="px-4 py-2.5 font-semibold" rowspan="2">{{ t.facecard.movement.from }}</th>
                        <th class="px-4 py-2.5 font-semibold" rowspan="2">{{ t.facecard.movement.to }}</th>
                        <th class="px-4 py-2.5 font-semibold" rowspan="2">{{ t.facecard.movement.status }}</th>
                    </tr>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-4 py-2 font-semibold">{{ t.facecard.movement.start }}</th>
                        <th class="px-4 py-2 font-semibold">{{ t.facecard.movement.end }}</th>
                        <th class="px-4 py-2 font-semibold">{{ t.facecard.movement.type }}</th>
                        <th class="px-4 py-2 font-semibold">{{ t.facecard.movement.detail }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(m, i) in pageRows" :key="i" class="border-b border-border/60 transition last:border-0 hover:bg-slate-50/70">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ formatDate(m.effective_from) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-500">{{ formatDate(m.effective_to) }}</td>
                        <td class="px-4 py-3" :class="typeClass(m.type)">{{ m.type ?? t.facecard.profile.na }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ m.detail ?? t.facecard.profile.na }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ m.from ?? t.facecard.profile.na }}</td>
                        <td class="px-4 py-3 text-slate-700">{{ m.to ?? t.facecard.profile.na }}</td>
                        <td class="px-4 py-3">
                            <span
                                v-if="m.status"
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                :class="statusBadge(m.status)"
                            >
                                {{ m.status }}
                            </span>
                            <span v-else class="text-slate-300">{{ t.facecard.profile.na }}</span>
                        </td>
                    </tr>
                    <tr v-if="pageRows.length === 0">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">
                            {{ t.facecard.movement.empty }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div v-if="totalPages > 1" class="mt-3 flex justify-end gap-1">
            <button
                v-for="p in totalPages"
                :key="p"
                type="button"
                class="h-8 w-8 rounded-md border text-sm transition"
                :class="p === page
                    ? 'border-primary bg-primary text-white'
                    : 'border-border bg-white text-slate-600 hover:bg-slate-50'"
                @click="page = p"
            >
                {{ p }}
            </button>
        </div>
        </div>
    </div>
</template>
