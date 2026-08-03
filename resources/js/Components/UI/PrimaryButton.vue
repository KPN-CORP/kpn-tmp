<script setup lang="ts">
defineProps<{
    type?: 'submit' | 'button'
    disabled?: boolean
    loading?: boolean
}>()
</script>

<template>
    <button
        :type="type ?? 'submit'"
        :disabled="disabled || loading"
        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-60"
    >
        <i
            v-if="loading"
            class="fa-solid fa-circle-notch fa-spin"
        />
        <slot />
    </button>
</template>
