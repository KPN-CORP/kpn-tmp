<script setup lang="ts">
defineProps<{
    title: string
    subtitle?: string
}>()
</script>

<template>
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <h1 class="text-xl font-bold text-slate-800">
                {{ title }}
            </h1>

            <p
                v-if="subtitle"
                class="mt-1 text-sm text-slate-400"
            >
                {{ subtitle }}
            </p>
        </div>

        <!-- Optional actions (buttons, filters) -->
        <div
            v-if="$slots.actions"
            class="flex shrink-0 items-center gap-2"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
