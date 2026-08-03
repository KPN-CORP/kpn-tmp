<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'

// A stand-in page for sidebar links whose real screens aren't built yet.
defineProps<{
    title: string
}>()
</script>

<template>
    <AppLayout>
        <PageHeader :title="title" />

        <div
            class="flex min-h-[320px] flex-col items-center justify-center rounded-xl border border-dashed border-border bg-white p-10 text-center"
        >
            <div
                class="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-red-50 text-primary"
            >
                <i class="fa-solid fa-screwdriver-wrench text-xl" />
            </div>

            <h2 class="text-lg font-bold text-slate-700">
                {{ title }}
            </h2>
            <p class="mt-1 max-w-sm text-sm text-slate-400">
                This screen is a placeholder — the layout is ready, the feature
                comes next.
            </p>
        </div>
    </AppLayout>
</template>
