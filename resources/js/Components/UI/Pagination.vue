<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

/**
 * Shared pagination bar with two modes:
 *
 *  - Server mode  — pass Laravel's paginator `links` (Inertia <Link> visits).
 *  - Client mode  — pass `page` + `total` (in-memory lists). Emits
 *    `update:page` so the parent can slice the data itself.
 *
 * Both modes share the rows-per-page selector, the "from–to of total" summary,
 * and the windowed page strip (1 … 22 23 24 … 45).
 */
const props = defineProps<{
    // Server mode: Laravel `links` = [prev, ...pages, next].
    links?: { url: string | null; label: string; active: boolean }[]
    // Client mode: current 1-based page.
    page?: number
    perPage: number
    total?: number
    from?: number | null
    to?: number | null
    perPageOptions?: number[]
}>()

const emit = defineEmits<{
    (e: 'update:perPage', value: number): void
    (e: 'update:page', value: number): void
}>()

// Server mode as soon as Laravel links are supplied.
const serverMode = computed(
    () => Array.isArray(props.links) && props.links.length > 0,
)

// Always include the current page size so the <select> has a matching option
// (otherwise a default like 15 renders blank against [10,20,50,100]).
const options = computed(() => {
    const base = props.perPageOptions ?? [10, 20, 50, 100]
    return base.includes(props.perPage)
        ? base
        : [...base, props.perPage].sort((a, b) => a - b)
})

/* ----------------------------- server links ----------------------------- */

const prev = computed(() => props.links?.[0])
const next = computed(() => props.links?.[(props.links?.length ?? 1) - 1])
const linkPages = computed(() => props.links?.slice(1, -1) ?? [])

/* ------------------------------ client mode ----------------------------- */

const totalPages = computed(() =>
    Math.max(1, Math.ceil((props.total ?? 0) / Math.max(1, props.perPage))),
)

const currentPage = computed(() =>
    Math.min(Math.max(1, props.page ?? 1), totalPages.value),
)

// Windowed page list so a large page count shows "1 … 22 23 24 … 45" instead
// of every number. '…' marks a gap.
function pageWindow(current: number, total: number): (number | '…')[] {
    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1)
    }

    const pages: (number | '…')[] = [1]
    const start = Math.max(2, current - 1)
    const end = Math.min(total - 1, current + 1)

    if (start > 2) pages.push('…')
    for (let p = start; p <= end; p++) pages.push(p)
    if (end < total - 1) pages.push('…')
    pages.push(total)

    return pages
}

const clientPages = computed(() => pageWindow(currentPage.value, totalPages.value))

/* ------------------------------- summary -------------------------------- */

// Prefer explicit from/to (server passes them); otherwise derive from the page.
const summaryFrom = computed(() => {
    if (props.from != null) return props.from
    if (props.total == null) return 0
    return props.total === 0 ? 0 : (currentPage.value - 1) * props.perPage + 1
})

const summaryTo = computed(() => {
    if (props.to != null) return props.to
    if (props.total == null) return 0
    return Math.min(currentPage.value * props.perPage, props.total)
})

const hasPages = computed(() =>
    serverMode.value ? (props.links?.length ?? 0) > 3 : totalPages.value > 1,
)

// Hide the whole bar when there is nothing to paginate or configure.
const shouldShow = computed(() => {
    if (props.total == null) return hasPages.value
    return hasPages.value || props.total > Math.min(...options.value)
})

function onPerPageChange(event: Event) {
    emit('update:perPage', Number((event.target as HTMLSelectElement).value))
}

function goTo(p: number | '…') {
    if (p === '…') return
    if (p < 1 || p > totalPages.value || p === currentPage.value) return
    emit('update:page', p)
}
</script>

<template>
    <div
        v-if="shouldShow"
        class="mt-6 flex flex-col items-center justify-between gap-4 sm:flex-row"
    >
        <!-- Left: rows per page + summary -->
        <div class="flex items-center gap-2 text-sm text-slate-600">
            <span>{{ t.pagination.rowsPerPage }}</span>

            <select
                :value="perPage"
                class="w-20 rounded-md border border-border bg-white px-2 py-1.5 text-sm"
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
                {{ summaryFrom }}–{{ summaryTo }} {{ t.pagination.of }} {{ total }}
            </span>
        </div>

        <!-- Right: page links -->
        <nav
            v-if="hasPages"
            class="flex items-center gap-1"
        >
            <!-- ===================== Server mode ===================== -->
            <template v-if="serverMode">
                <Link
                    :href="prev?.url ?? ''"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50"
                    :class="{ 'pointer-events-none opacity-40': !prev?.url }"
                    :aria-label="t.pagination.previous"
                >
                    <i class="fa-solid fa-chevron-left text-xs" />
                </Link>

                <Link
                    v-for="link in linkPages"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition"
                    :class="[
                        link.active
                            ? 'border-primary bg-primary text-white'
                            : 'border-border bg-white text-slate-600 hover:bg-slate-50',
                        !link.url && 'pointer-events-none opacity-60',
                    ]"
                    v-html="link.label"
                />

                <Link
                    :href="next?.url ?? ''"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50"
                    :class="{ 'pointer-events-none opacity-40': !next?.url }"
                    :aria-label="t.pagination.next"
                >
                    <i class="fa-solid fa-chevron-right text-xs" />
                </Link>
            </template>

            <!-- ===================== Client mode ===================== -->
            <template v-else>
                <button
                    type="button"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                    :disabled="currentPage === 1"
                    :aria-label="t.pagination.previous"
                    @click="goTo(currentPage - 1)"
                >
                    <i class="fa-solid fa-chevron-left text-xs" />
                </button>

                <template
                    v-for="(p, idx) in clientPages"
                    :key="idx"
                >
                    <span
                        v-if="p === '…'"
                        class="inline-flex h-9 min-w-9 items-center justify-center px-1 text-sm text-slate-400"
                    >
                        …
                    </span>
                    <button
                        v-else
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition"
                        :class="
                            p === currentPage
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="goTo(p)"
                    >
                        {{ p }}
                    </button>
                </template>

                <button
                    type="button"
                    class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                    :disabled="currentPage === totalPages"
                    :aria-label="t.pagination.next"
                    @click="goTo(currentPage + 1)"
                >
                    <i class="fa-solid fa-chevron-right text-xs" />
                </button>
            </template>
        </nav>
    </div>
</template>
