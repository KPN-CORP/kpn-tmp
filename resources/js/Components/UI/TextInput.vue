<script setup lang="ts">
import { onMounted, ref } from 'vue'

const model = defineModel<string>({ required: true })

withDefaults(
    defineProps<{
        type?: string
        autofocus?: boolean
    }>(),
    {
        type: 'text',
        autofocus: false,
    },
)

const input = ref<HTMLInputElement | null>(null)

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value.focus()
    }
})

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
    <input
        ref="input"
        v-model="model"
        :type="type"
        :autofocus="autofocus"
        class="block w-full rounded-lg border border-border bg-white px-3.5 py-2.5 text-sm text-slate-800 shadow-sm transition-colors placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
    >
</template>
