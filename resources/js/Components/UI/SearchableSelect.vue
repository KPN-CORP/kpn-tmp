<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'

/**
 * A searchable single-select (combobox). Binds a string value; options are
 * `{ value, label }`. Include an empty-value option for an "All / none" choice.
 *
 * The dropdown is teleported to <body> and positioned `fixed` against the
 * trigger, so it never gets clipped by a scrollable/overflow-hidden ancestor
 * (e.g. a drawer body) and always paints above surrounding chrome. It flips
 * above the trigger when there isn't enough room below.
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
    disabled?: boolean
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const open = ref(false)
const search = ref('')
const root = ref<HTMLElement | null>(null)
const menu = ref<HTMLElement | null>(null)
const searchRef = ref<HTMLInputElement | null>(null)

const selectedLabel = computed(
    () => props.options.find((o) => o.value === props.modelValue)?.label ?? '',
)

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    return q ? props.options.filter((o) => o.label.toLowerCase().includes(q)) : props.options
})

// Fixed-position box for the teleported menu, recomputed from the trigger's
// rect whenever the menu opens or the page scrolls/resizes.
const menuPos = ref({ left: 0, top: 0, width: 0, openUp: false, maxHeight: 320 })

function updatePosition() {
    const el = root.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    const gap = 4
    const spaceBelow = window.innerHeight - rect.bottom - gap
    const spaceAbove = rect.top - gap
    // Flip up only when below is cramped and above has more room.
    const openUp = spaceBelow < 240 && spaceAbove > spaceBelow

    menuPos.value = {
        left: rect.left,
        top: openUp ? rect.top - gap : rect.bottom + gap,
        width: rect.width,
        openUp,
        maxHeight: Math.max(160, Math.min(320, openUp ? spaceAbove : spaceBelow)),
    }
}

const menuStyle = computed(() => ({
    position: 'fixed' as const,
    left: `${menuPos.value.left}px`,
    top: `${menuPos.value.top}px`,
    width: `${menuPos.value.width}px`,
    maxHeight: `${menuPos.value.maxHeight}px`,
    transform: menuPos.value.openUp ? 'translateY(-100%)' : undefined,
    zIndex: 60,
}))

function toggle() {
    if (props.disabled) return
    open.value = !open.value
    if (open.value) {
        search.value = ''
        updatePosition()
        nextTick(() => searchRef.value?.focus())
    }
}

function select(option: Option) {
    emit('update:modelValue', option.value)
    open.value = false
}

function onDocMouseDown(e: MouseEvent) {
    const target = e.target as Node
    if (root.value?.contains(target)) return
    if (menu.value?.contains(target)) return
    open.value = false
}

// Keep the teleported menu glued to the trigger while open, and tear the
// reposition listeners down when it closes.
watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('scroll', updatePosition, true)
        window.addEventListener('resize', updatePosition)
    } else {
        window.removeEventListener('scroll', updatePosition, true)
        window.removeEventListener('resize', updatePosition)
    }
})

onMounted(() => document.addEventListener('mousedown', onDocMouseDown))
onUnmounted(() => {
    document.removeEventListener('mousedown', onDocMouseDown)
    window.removeEventListener('scroll', updatePosition, true)
    window.removeEventListener('resize', updatePosition)
})
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :disabled="disabled"
            class="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2.5 text-left text-sm transition focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-60"
            :class="[
                invalid ? 'border-red-500' : 'border-border',
                disabled ? 'bg-slate-50' : 'bg-white',
            ]"
            @click="toggle"
        >
            <span class="truncate" :class="selectedLabel ? 'text-slate-700' : 'text-slate-400'">
                {{ selectedLabel || placeholder || 'Select…' }}
            </span>
            <i
                class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-400 transition-transform"
                :class="open ? 'rotate-180' : ''"
            />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                class="flex flex-col overflow-hidden rounded-md border border-border bg-white shadow-lg"
                :style="menuStyle"
            >
                <div class="shrink-0 border-b border-border p-2">
                    <input
                        ref="searchRef"
                        v-model="search"
                        type="text"
                        placeholder="Search…"
                        class="w-full rounded border border-border bg-white px-2 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                </div>

                <ul class="flex-1 overflow-y-auto py-1">
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
        </Teleport>
    </div>
</template>
