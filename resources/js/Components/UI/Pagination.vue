<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

const props = defineProps<{
    // Laravel paginator `links`: [prev, ...pages, next]
    links: { url: string | null; label: string; active: boolean }[]
    perPage: number
    total?: number
    from?: number | null
    to?: number | null
    perPageOptions?: number[]
}>()

const emit = defineEmits<{
    (e: 'update:perPage', value: number): void
}>()

const options = computed(() => props.perPageOptions ?? [10, 20, 50, 100])

const prev = computed(() => props.links[0])
const next = computed(() => props.links[props.links.length - 1])
const pages = computed(() => props.links.slice(1, -1))

const hasPages = computed(() => props.links.length > 3)

// Hide the whole bar when everything fits on one page at the smallest page
// size — there is nothing to paginate or configure.
const shouldShow = computed(() => {
    if (props.total == null) {
        return hasPages.value
    }

    return props.total > Math.min(...options.value)
})

function onPerPageChange(event: Event) {
    emit('update:perPage', Number((event.target as HTMLSelectElement).value))
}
</script>

<template>
    <div
        v-if="shouldShow"
        class="mt-6 flex flex-col items-center justify-between gap-4 sm:flex-row"
    >
        <!-- Left: rows per page -->
        <div class="flex items-center gap-2 text-sm text-slate-600">
            <span>{{ t.pagination.rowsPerPage }}</span>

            <select
                :value="perPage"
                class="w-20 rounded-md border border-border px-2 py-1.5 text-sm"
                @change="onPerPageChange"
            >
                <option
                    v-for="opt in options"
                    :key="opt"
                    :value="opt"
                >
                    {{ opt }}
                </option>
            </select>

            <span
                v-if="total != null"
                class="ml-1 text-slate-400"
            >
                {{ from ?? 0 }}–{{ to ?? 0 }} {{ t.pagination.of }} {{ total }}
            </span>
        </div>

        <!-- Right: page links -->
        <nav
            v-if="hasPages"
            class="flex items-center gap-1"
        >
            <!-- Previous -->
            <Link
                :href="prev.url ?? ''"
                class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border px-3 text-sm text-slate-600 transition hover:bg-slate-50"
                :class="{ 'pointer-events-none opacity-40': !prev.url }"
                :aria-label="t.pagination.previous"
            >
                <i class="fa-solid fa-chevron-left text-xs" />
            </Link>

            <!-- Page numbers -->
            <Link
                v-for="link in pages"
                :key="link.label"
                :href="link.url ?? ''"
                class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition"
                :class="[
                    link.active
                        ? 'border-primary bg-primary text-white'
                        : 'border-border text-slate-600 hover:bg-slate-50',
                    !link.url && 'pointer-events-none opacity-60',
                ]"
                v-html="link.label"
            />

            <!-- Next -->
            <Link
                :href="next.url ?? ''"
                class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border px-3 text-sm text-slate-600 transition hover:bg-slate-50"
                :class="{ 'pointer-events-none opacity-40': !next.url }"
                :aria-label="t.pagination.next"
            >
                <i class="fa-solid fa-chevron-right text-xs" />
            </Link>
        </nav>
    </div>
</template>
