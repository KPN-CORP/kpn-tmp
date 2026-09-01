<script setup lang="ts">
import DateInput from '@/Components/UI/DateInput.vue'
import { useLocale } from '@/Composables/useLocale'

/**
 * The effective-period pair (start / end) shared by the dated IDP master
 * forms: competency, proficiency level, review tool.
 *
 * Both ends are optional and open-ended when blank — a blank start applies the
 * master immediately, a blank end leaves it without an expiry — which is what
 * the hint under the fields spells out.
 */

defineProps<{
    start: string
    end: string
    startError?: string
    endError?: string
}>()

const emit = defineEmits<{
    (e: 'update:start', value: string): void
    (e: 'update:end', value: string): void
}>()

const { t } = useLocale()
</script>

<template>
    <div class="rounded-lg border border-border bg-slate-50/60 p-4">
        <div class="mb-3 flex items-center gap-2">
            <i class="fa-regular fa-calendar text-xs text-slate-400" />
            <span class="text-sm font-semibold text-slate-700">
                {{ t.idp.settings.effectivePeriod }}
            </span>
            <span class="text-xs font-normal text-slate-400">
                ({{ t.idp.settings.optional }})
            </span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">
                    {{ t.idp.settings.startDate }}
                </label>
                <DateInput
                    :model-value="start"
                    :invalid="!!startError"
                    @update:model-value="emit('update:start', $event)"
                />
                <p v-if="startError" class="mt-1 text-xs text-red-600">
                    {{ startError }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-slate-500">
                    {{ t.idp.settings.endDate }}
                </label>
                <DateInput
                    :model-value="end"
                    :invalid="!!endError"
                    @update:model-value="emit('update:end', $event)"
                />
                <p v-if="endError" class="mt-1 text-xs text-red-600">
                    {{ endError }}
                </p>
            </div>
        </div>

        <p class="mt-2 text-xs text-slate-400">
            {{ t.idp.settings.effectivePeriodHint }}
        </p>
    </div>
</template>
