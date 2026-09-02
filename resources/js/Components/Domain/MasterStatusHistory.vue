<script setup lang="ts">
import { ref, watch } from 'vue'

import Drawer from '@/Components/Domain/Drawer.vue'
import { formatDateTime } from '@/Composables/useDate'
import { useLocale } from '@/Composables/useLocale'

/**
 * The activate / deactivate trail for one row, shared by every settings screen
 * that can switch something off — the IDP masters and master implementations
 * alike, which is why the endpoint arrives as a URL rather than being built
 * from a master type here.
 *
 * The entries do not come from the database — they are read back from the
 * append-only audit log on disk — so this drawer fetches them on open rather
 * than receiving them as Inertia props with the rest of the page.
 */

interface Entry {
    at: string
    active: boolean
    name: string
    by: { id: number | null; employee_id: string | null; name: string }
}

const props = defineProps<{
    show: boolean
    /** Status-history endpoint for this row; null while nothing is selected. */
    url: string | null
    /** Shown as the drawer's subject line. */
    name: string
}>()

const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useLocale()

const entries = ref<Entry[]>([])
const loading = ref(false)

// Fetch on open, and again if the drawer is pointed at a different row without
// closing in between.
watch(
    () => [props.show, props.url],
    async () => {
        if (!props.show || !props.url) return

        loading.value = true
        entries.value = []

        try {
            const res = await fetch(props.url, {
                headers: { Accept: 'application/json' },
            })
            entries.value = res.ok ? (await res.json()).history : []
        } catch {
            entries.value = []
        } finally {
            loading.value = false
        }
    },
    { immediate: true },
)

// The actor, with their employee id when there is one.
function actor(entry: Entry): string {
    return entry.by.employee_id
        ? `${entry.by.name} (${entry.by.employee_id})`
        : entry.by.name
}
</script>

<template>
    <Drawer
        :show="show"
        :title="t.idp.settings.statusHistory"
        @close="emit('close')"
    >
        <p class="mb-4 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700">
            {{ name }}
        </p>

        <div v-if="loading" class="py-10 text-center text-sm text-slate-400">
            <i class="fa-solid fa-circle-notch fa-spin mr-1" />
            {{ t.idp.settings.loading }}
        </div>

        <ol v-else-if="entries.length" class="space-y-3">
            <li
                v-for="(entry, i) in entries"
                :key="i"
                class="rounded-lg border border-border bg-white p-3"
            >
                <div class="flex items-center justify-between gap-2">
                    <span
                        class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
                        :class="
                            entry.active
                                ? 'bg-emerald-100 text-emerald-700'
                                : 'bg-slate-200 text-slate-600'
                        "
                    >
                        {{
                            entry.active
                                ? t.idp.settings.activatedBadge
                                : t.idp.settings.deactivatedBadge
                        }}
                    </span>
                    <span class="text-xs text-slate-400">
                        {{ formatDateTime(entry.at) }}
                    </span>
                </div>

                <p class="mt-1.5 text-sm text-slate-600">
                    {{ t.idp.settings.changedBy }}
                    <span class="font-medium text-slate-800">{{ actor(entry) }}</span>
                </p>

                <!-- The name as it was at the time, so a later rename doesn't
                     rewrite what the entry says. -->
                <p
                    v-if="entry.name && entry.name !== name"
                    class="mt-0.5 text-xs italic text-slate-400"
                >
                    {{ entry.name }}
                </p>
            </li>
        </ol>

        <p v-else class="py-10 text-center text-sm text-slate-400">
            {{ t.idp.settings.noStatusHistory }}
        </p>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                @click="emit('close')"
            >
                {{ t.idp.form.close }}
            </button>
        </template>
    </Drawer>
</template>
