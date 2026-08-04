<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'

/**
 * Searchable multi-select. Binds a string[] of values; selected items show as
 * removable chips (by label). Options are `{ value, label }`.
 */
export interface Option {
    value: string
    label: string
}

const props = defineProps<{
    modelValue: string[]
    options: Option[]
    placeholder?: string
    invalid?: boolean
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const open = ref(false)
const search = ref('')
const root = ref<HTMLElement | null>(null)
const searchRef = ref<HTMLInputElement | null>(null)

const selectedOptions = computed(() =>
    props.modelValue.map((v) => props.options.find((o) => o.value === v) ?? { value: v, label: v }),
)

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    return q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options
})

function isSelected(value: string): boolean {
    return props.modelValue.includes(value)
}

function toggle(option: Option) {
    const next = isSelected(option.value)
        ? props.modelValue.filter((v) => v !== option.value)
        : [...props.modelValue, option.value]
    emit('update:modelValue', next)
}

function remove(value: string) {
    emit('update:modelValue', props.modelValue.filter((v) => v !== value))
}

function openDropdown() {
    open.value = true
    search.value = ''
    nextTick(() => searchRef.value?.focus())
}

function onDocMouseDown(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false
}

onMounted(() => document.addEventListener('mousedown', onDocMouseDown))
onUnmounted(() => document.removeEventListener('mousedown', onDocMouseDown))
</script>

<template>
    <div ref="root" class="relative">
        <div
            class="flex min-h-[42px] w-full cursor-text flex-wrap items-center gap-1.5 rounded-md border bg-white px-2 py-1.5 text-sm transition"
            :class="invalid ? 'border-red-500' : 'border-border'"
            @click="openDropdown"
        >
            <span
                v-for="option in selectedOptions"
                :key="option.value"
                class="inline-flex items-center gap-1 rounded bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary"
            >
                {{ option.label }}
                <button type="button" class="hover:text-primary-hover" @click.stop="remove(option.value)">
                    <i class="fa-solid fa-xmark text-[10px]" />
                </button>
            </span>

            <span v-if="selectedOptions.length === 0" class="text-slate-400">
                {{ placeholder || 'Select…' }}
            </span>

            <i class="fa-solid fa-chevron-down ml-auto shrink-0 text-xs text-slate-400" />
        </div>

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
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition hover:bg-slate-50"
                        :class="isSelected(option.value) ? 'font-medium text-primary' : 'text-slate-600'"
                        @click="toggle(option)"
                    >
                        <span
                            class="flex h-4 w-4 shrink-0 items-center justify-center rounded border"
                            :class="isSelected(option.value) ? 'border-primary bg-primary text-white' : 'border-slate-300'"
                        >
                            <i v-if="isSelected(option.value)" class="fa-solid fa-check text-[9px]" />
                        </span>
                        <span class="truncate">{{ option.label }}</span>
                    </button>
                </li>
                <li v-if="filtered.length === 0" class="px-3 py-4 text-center text-xs text-slate-400">
                    No matches
                </li>
            </ul>
        </div>
    </div>
</template>
