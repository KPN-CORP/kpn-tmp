<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import { route } from '@/Config/route'

/**
 * Multi-select over user accounts, searched on the server. The user table holds
 * every corporate account (thousands), so options are fetched as the user types
 * rather than shipped with the page. Binds the selected employee ids.
 */
const props = defineProps<{
    modelValue: string[]
    /** Labels for the values already selected, so they are named before any search. */
    selected: Option[]
    placeholder?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const results = ref<Option[]>([])
// Every label seen so far, so a picked user keeps its name after the search
// that found it is replaced by the next one.
const known = ref(new Map<string, Option>())

let debounce: ReturnType<typeof setTimeout> | undefined
let inflight: AbortController | undefined

const options = computed<Option[]>(() => {
    const byValue = new Map<string, Option>()
    for (const option of props.selected) byValue.set(option.value, option)
    for (const value of props.modelValue) {
        const option = known.value.get(value)
        if (option) byValue.set(value, option)
    }
    for (const option of results.value) byValue.set(option.value, option)
    return [...byValue.values()]
})

async function fetchUsers(q: string) {
    inflight?.abort()
    inflight = new AbortController()

    try {
        const res = await fetch(`${route('roles.users')}?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json' },
            signal: inflight.signal,
        })
        if (!res.ok) return
        const found = (await res.json()) as Option[]
        for (const option of found) known.value.set(option.value, option)
        results.value = found
    } catch {
        // Aborted by a newer search, or offline — keep the last results.
    }
}

function onSearch(q: string) {
    clearTimeout(debounce)
    debounce = setTimeout(() => fetchUsers(q.trim()), 300)
}

onMounted(() => fetchUsers(''))
onBeforeUnmount(() => {
    clearTimeout(debounce)
    inflight?.abort()
})
</script>

<template>
    <MultiSelect
        :model-value="modelValue"
        :options="options"
        :placeholder="placeholder"
        @update:model-value="emit('update:modelValue', $event)"
        @search="onSearch"
    />
</template>
