<script setup lang="ts">
import { useLocale } from '@/Composables/useLocale'

/**
 * Table cell for a master that can be switched off: an Active/Inactive badge
 * that doubles as the toggle, plus a button opening the audit trail of who
 * flipped it.
 *
 * The toggle is the only place the flag changes outside the edit drawer, and
 * both routes funnel through the same service call, so every transition is
 * recorded either way.
 */

defineProps<{
    active: boolean
    /** Disables the toggle while its request is in flight. */
    busy?: boolean
}>()

const emit = defineEmits<{
    (e: 'toggle'): void
    (e: 'history'): void
}>()

const { t } = useLocale()
</script>

<template>
    <div class="inline-flex items-center gap-1.5">
        <button
            type="button"
            :disabled="busy"
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide transition disabled:opacity-50"
            :class="
                active
                    ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200'
                    : 'bg-slate-200 text-slate-600 hover:bg-slate-300'
            "
            :title="active ? t.idp.settings.deactivate : t.idp.settings.activate"
            @click="emit('toggle')"
        >
            <i
                class="fa-solid text-[8px]"
                :class="active ? 'fa-circle-check' : 'fa-circle-minus'"
            />
            {{ active ? t.idp.settings.activeBadge : t.idp.settings.inactiveBadge }}
        </button>

        <button
            type="button"
            class="rounded p-1 text-slate-300 transition hover:bg-slate-100 hover:text-slate-500"
            :title="t.idp.settings.statusHistory"
            @click="emit('history')"
        >
            <i class="fa-solid fa-clock-rotate-left text-[11px]" />
        </button>
    </div>
</template>
