<script setup lang="ts">
/**
 * One proficiency level as every table shows it: a badge naming its position
 * on the ladder, its code, its name, then what it means.
 *
 * Four screens render a rung — Master Competency, Master Implementation, Master
 * Training and the development-program list — and before this they each did it
 * differently (one had a `PL2` badge, two had the bare name). They all render
 * this now, so the reading is the same wherever a rung turns up.
 *
 * Everything but the name is optional, because not every caller has it:
 *  - `sequence` is absent on a free-typed proficiency (a development program
 *    under the "Others" type has no rung to point at), and the badge is then
 *    left off rather than numbered 0.
 *  - `code` is null on the rungs that predate the column.
 *  - a rung switched off is struck through, matching how the masters' own lists
 *    badge an inactive row.
 */
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

withDefaults(
    defineProps<{
        name: string
        sequence?: number | null
        code?: string | null
        description?: string | null
        active?: boolean
    }>(),
    {
        sequence: null,
        code: null,
        description: null,
        active: true,
    },
)
</script>

<template>
    <div>
        <div class="flex flex-wrap items-center gap-1.5">
            <span
                v-if="sequence != null"
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                :class="
                    active
                        ? 'bg-emerald-50 text-emerald-600'
                        : 'bg-slate-100 text-slate-400 line-through'
                "
                :title="active ? undefined : t.idp.settings.inactiveBadge"
            >
                {{ t.idp.settings.proficiencyLevel }} - {{ sequence }}
            </span>

            <span
                v-if="code"
                class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-indigo-700"
            >
                {{ code }}
            </span>
        </div>

        <p
            class="mt-1 text-sm font-medium"
            :class="active ? 'text-slate-700' : 'text-slate-400 line-through'"
        >
            {{ name }}
        </p>

        <p
            v-if="description"
            class="mt-1 whitespace-pre-line text-[11px] leading-snug text-slate-400"
        >
            {{ description }}
        </p>
    </div>
</template>
