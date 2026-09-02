<script setup lang="ts">
import { useLocale } from '@/Composables/useLocale'

/**
 * The active/inactive switch shared by the master forms that carry one:
 * competency, proficiency level, review tool.
 *
 * Deactivating never removes anything — it only takes the master out of the
 * pickers for new work — which is what the hint spells out, since "inactive"
 * would otherwise read as "deleted".
 */

defineProps<{
    modelValue: boolean
    error?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: boolean): void }>()

const { t } = useLocale()
</script>

<template>
    <div class="rounded-lg border border-border bg-slate-50/60 p-4">
        <label class="flex items-start gap-2.5">
            <input
                :checked="modelValue"
                type="checkbox"
                class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary"
                @change="
                    emit(
                        'update:modelValue',
                        ($event.target as HTMLInputElement).checked,
                    )
                "
            >
            <span class="text-sm">
                <span class="font-medium text-slate-700">
                    {{ t.idp.settings.activeLabel }}
                </span>
                <span class="mt-0.5 block text-xs text-slate-400">
                    {{ t.idp.settings.activeHint }}
                </span>
            </span>
        </label>

        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
