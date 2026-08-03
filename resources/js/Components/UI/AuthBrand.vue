<script setup lang="ts">
import { useLocale } from '@/Composables/useLocale'

defineProps<{
    title: string
    subtitle?: string
}>()

const { t } = useLocale()
</script>

<template>
    <div class="mb-7 text-center">
        <!-- Brand mark, sized to match kpn-coi's logo block. -->
        <div
            class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-2xl font-bold text-white shadow-sm"
        >
            K
        </div>

        <h1 class="text-2xl font-bold text-primary">
            {{ title }}
        </h1>

        <p
            v-if="subtitle"
            class="mx-auto mt-2 max-w-xs text-sm leading-relaxed text-slate-500"
        >
            {{ subtitle }}
        </p>

        <span class="sr-only">{{ t.app.name }}</span>
    </div>
</template>
