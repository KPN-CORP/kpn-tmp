<script setup lang="ts">
const model = defineModel<boolean>({ required: true })
</script>

<template>
    <input
        v-model="model"
        type="checkbox"
        class="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-primary/30"
    >
</template>
