<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'

/**
 * A searchable single-select (combobox). Binds a string value; options are
 * `{ value, label }`. Include an empty-value option for an "All / none" choice.
 */
export interface Option {
    value: string
    label: string
}

const props = defineProps<{
    modelValue: string
    options: Option[]
    placeholder?: string
    invalid?: boolean
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const open = ref(false)
const search = ref('')
const root = ref<HTMLElement | null>(null)
const searchRef = ref<HTMLInputElement | null>(null)

const selectedLabel = computed(
    () => props.options.find((o) => o.value === props.modelValue)?.label ?? '',
)

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    return q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options
})

function toggle() {
    open.value = !open.value
    if (open.value) {
        search.value = ''
        nextTick(() => searchRef.value?.focus())
    }
}

function select(option: Option) {
    emit('update:modelValue', option.value)
    open.value = false
}

function onDocMouseDown(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false
}

onMounted(() => document.addEventListener('mousedown', onDocMouseDown))
onUnmounted(() => document.removeEventListener('mousedown', onDocMouseDown))
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="flex w-full items-center justify-between gap-2 rounded-md border bg-white px-3 py-2.5 text-left text-sm transition focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            :class="invalid ? 'border-red-500' : 'border-border'"
            @click="toggle"
        >
            <span class="truncate" :class="selectedLabel ? 'text-slate-700' : 'text-slate-400'">
                {{ selectedLabel || placeholder || 'Select…' }}
            </span>
            <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-400" />
        </button>

        <div
            v-if="open"
            class="absolute z-30 mt-1 w-full overflow-hidden rounded-md border border-border bg-white shadow-lg"
        >
            <div class="border-b border-border p-2">
                <input
                    ref="searchRef"
                    v-model="search"
                    type="text"
                    placeholder="Search…"
                    class="w-full rounded border border-border bg-white px-2 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                >
            </div>

            <ul class="max-h-56 overflow-y-auto py-1">
                <li v-for="option in filtered" :key="option.value">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition hover:bg-slate-50"
                        :class="option.value === modelValue ? 'font-medium text-primary' : 'text-slate-600'"
                        @click="select(option)"
                    >
                        <span class="truncate">{{ option.label || '—' }}</span>
                        <i v-if="option.value === modelValue" class="fa-solid fa-check shrink-0 text-xs" />
                    </button>
                </li>
                <li v-if="filtered.length === 0" class="px-3 py-4 text-center text-xs text-slate-400">
                    No matches
                </li>
            </ul>
        </div>
    </div>
</template>
