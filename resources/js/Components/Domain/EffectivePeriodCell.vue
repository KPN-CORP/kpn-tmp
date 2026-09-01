<script setup lang="ts">
import { computed } from 'vue'

import { formatDate } from '@/Composables/useDate'
import {
    effectiveStatus,
    type EffectivePeriod,
} from '@/Composables/useEffectivePeriod'
import { useLocale } from '@/Composables/useLocale'

/**
 * Table cell for a dated IDP master: the effective window plus a badge saying
 * whether the row is in effect today, still upcoming, or already expired.
 *
 * A master with neither date set is always effective, and reads as a plain
 * dash rather than a badge — that is the ordinary case, not a state worth
 * calling out.
 */

const props = defineProps<{ item: EffectivePeriod }>()

const { t } = useLocale()

const hasPeriod = computed(
    () =>
        !!props.item.effective_start_date || !!props.item.effective_end_date,
)

const status = computed(() => effectiveStatus(props.item))

// "01-01-2026 – 31-12-2026", with the open end spelled out on either side.
const label = computed(() => {
    const start = props.item.effective_start_date
        ? formatDate(props.item.effective_start_date)
        : t.value.idp.settings.always
    const end = props.item.effective_end_date
        ? formatDate(props.item.effective_end_date)
        : t.value.idp.settings.ongoing
    return `${start} – ${end}`
})

const badge = computed(() => {
    switch (status.value) {
        case 'scheduled':
            return {
                text: t.value.idp.settings.scheduledBadge,
                class: 'bg-amber-100 text-amber-700',
            }
        case 'expired':
            return {
                text: t.value.idp.settings.expiredBadge,
                class: 'bg-slate-200 text-slate-600',
            }
        default:
            return {
                text: t.value.idp.settings.activeBadge,
                class: 'bg-emerald-100 text-emerald-700',
            }
    }
})
</script>

<template>
    <span v-if="!hasPeriod" class="text-sm text-slate-300">—</span>

    <span v-else class="inline-flex flex-wrap items-center gap-1.5">
        <span class="whitespace-nowrap text-sm text-slate-600">{{ label }}</span>
        <span
            class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide"
            :class="badge.class"
        >
            {{ badge.text }}
        </span>
    </span>
</template>
