<script setup lang="ts">
defineProps<{
    value?: string
}>()
</script>

<template>
    <label class="mb-1.5 block text-sm font-medium text-slate-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
