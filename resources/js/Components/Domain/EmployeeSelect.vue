<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface EmployeeResult {
    employee_id: string
    fullname: string
    designation_name: string | null
    group_company: string | null
}

const props = withDefaults(
    defineProps<{
        modelValue: string | null
        label?: string | null
        placeholder?: string
        disabled?: boolean
    }>(),
    { label: null, placeholder: '', disabled: false },
)

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void
    (e: 'update:label', value: string | null): void
}>()

// Local copy of the human label for the current selection.
const display = ref<string | null>(props.label)
watch(
    () => props.label,
    (v) => (display.value = v),
)

const open = ref(false)
const query = ref('')
const results = ref<EmployeeResult[]>([])
const searching = ref(false)
let debounce: ReturnType<typeof setTimeout> | undefined
const root = ref<HTMLElement | null>(null)

watch(query, () => {
    clearTimeout(debounce)
    debounce = setTimeout(search, 300)
})

async function search() {
    searching.value = true
    try {
        const res = await fetch(
            `/approval-setting/employees?q=${encodeURIComponent(query.value)}`,
            { headers: { Accept: 'application/json' } },
        )
        results.value = res.ok ? await res.json() : []
    } catch {
        results.value = []
    } finally {
        searching.value = false
    }
}

function toggle() {
    if (props.disabled) return
    open.value = !open.value
    if (open.value) {
        query.value = ''
        if (results.value.length === 0) search()
    }
}

function pick(emp: EmployeeResult) {
    display.value = `${emp.employee_id} - ${emp.fullname}`
    emit('update:modelValue', emp.employee_id)
    emit('update:label', display.value)
    open.value = false
}

function clear(e: Event) {
    e.stopPropagation()
    display.value = null
    emit('update:modelValue', null)
    emit('update:label', null)
}

function onClickOutside(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false
}
watch(open, (v) => {
    if (v) document.addEventListener('mousedown', onClickOutside)
    else document.removeEventListener('mousedown', onClickOutside)
})
onBeforeUnmount(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            class="flex w-full items-center justify-between gap-2 rounded-lg border px-3.5 py-2.5 text-left text-sm shadow-sm transition focus:outline-none focus:ring-2 focus:ring-primary/30"
            :class="[
                disabled
                    ? 'cursor-not-allowed border-border bg-slate-50 text-slate-400'
                    : open
                      ? 'border-primary bg-white'
                      : 'border-border bg-white hover:border-slate-300',
            ]"
            @click="toggle"
        >
            <span v-if="modelValue && display" class="min-w-0 truncate text-slate-700">
                {{ display }}
            </span>
            <span v-else class="truncate text-slate-400">
                {{ placeholder || t.approval.selectLayer }}
            </span>

            <span class="flex shrink-0 items-center gap-1.5">
                <i
                    v-if="modelValue && !disabled"
                    class="fa-solid fa-xmark text-xs text-slate-400 transition hover:text-slate-600"
                    role="button"
                    :aria-label="t.approval.clear"
                    @click="clear"
                />
                <i class="fa-solid fa-chevron-down text-xs text-slate-400" />
            </span>
        </button>

        <!-- Dropdown -->
        <div
            v-if="open"
            class="absolute z-20 mt-1 w-full rounded-md border border-border bg-white shadow-lg"
        >
            <div class="relative border-b border-border">
                <i
                    class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                />
                <input
                    v-model="query"
                    type="search"
                    :placeholder="t.approval.searchEmployee"
                    class="w-full rounded-t-md border-0 py-2 pl-9 pr-9 text-sm focus:outline-none focus:ring-0"
                >
                <i
                    v-if="searching"
                    class="fa-solid fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                />
            </div>
            <ul class="max-h-56 overflow-y-auto py-1">
                <li v-for="emp in results" :key="emp.employee_id">
                    <button
                        type="button"
                        class="flex w-full flex-col items-start px-3 py-2 text-left transition hover:bg-primary/5"
                        @click="pick(emp)"
                    >
                        <span class="text-sm font-medium text-slate-700">
                            {{ emp.employee_id }} - {{ emp.fullname }}
                        </span>
                        <span class="text-xs text-slate-400">
                            {{ emp.designation_name ?? 'N.A' }}
                        </span>
                    </button>
                </li>
                <li
                    v-if="!searching && results.length === 0"
                    class="px-3 py-6 text-center text-sm text-slate-400"
                >
                    {{ t.approval.noEmployeeResults }}
                </li>
            </ul>
        </div>
    </div>
</template>
