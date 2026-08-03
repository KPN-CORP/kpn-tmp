<script setup lang="ts">
defineProps<{
    label: string
    value: string | number
    icon: string
    /** Tailwind text/bg color class pair for the icon chip, e.g. 'text-primary bg-red-50'. */
    tone?: string
}>()
</script>

<template>
    <div
        class="flex items-center gap-4 rounded-xl border border-border bg-white p-5 shadow-sm"
    >
        <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg"
            :class="tone ?? 'text-primary bg-red-50'"
        >
            <i :class="icon" />
        </div>

        <div class="min-w-0">
            <div class="truncate text-sm text-slate-400">
                {{ label }}
            </div>

            <div class="mt-0.5 text-2xl font-bold text-slate-800">
                {{ value }}
            </div>
        </div>
    </div>
</template>
