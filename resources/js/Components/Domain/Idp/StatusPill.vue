<script setup lang="ts">
/**
 * The one status chip the whole IDP screen uses, so a state reads the same
 * wherever it appears: the stage tracker, a plan row, the approver's inbox.
 */
import { computed } from 'vue'
import type { Tone } from '@/types/idp'

const props = withDefaults(
    defineProps<{
        tone?: Tone
        label: string
        icon?: string
        /** Show the small leading dot (dropped when an icon is given). */
        dot?: boolean
        size?: 'sm' | 'md'
    }>(),
    { tone: 'slate', dot: true, size: 'md' },
)

const tones: Record<Tone, { chip: string; dot: string }> = {
    slate: { chip: 'bg-slate-100 text-slate-600 ring-slate-500/20', dot: 'bg-slate-400' },
    amber: { chip: 'bg-amber-50 text-amber-700 ring-amber-600/20', dot: 'bg-amber-500' },
    emerald: { chip: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' },
    red: { chip: 'bg-red-50 text-red-700 ring-red-600/20', dot: 'bg-red-500' },
    sky: { chip: 'bg-sky-50 text-sky-700 ring-sky-600/20', dot: 'bg-sky-500' },
    primary: { chip: 'bg-primary/10 text-primary ring-primary/20', dot: 'bg-primary' },
}

const style = computed(() => tones[props.tone])
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset"
        :class="[style.chip, size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs']"
    >
        <i v-if="icon" :class="icon" class="text-[10px]" />
        <span v-else-if="dot" class="h-1.5 w-1.5 rounded-full" :class="style.dot" />
        {{ label }}
    </span>
</template>
