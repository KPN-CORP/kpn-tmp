<script setup lang="ts">
import { ref, watch } from 'vue'

/**
 * Date field that shows dd-mm-yyyy to the user but binds an ISO `yyyy-mm-dd`
 * value (what gets submitted and stored). Type it, or pick from the native
 * calendar via the button. An invalid/incomplete entry reverts on blur.
 */
const props = defineProps<{
    modelValue: string | null
    invalid?: boolean
    id?: string
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>()

const text = ref(isoToDisplay(props.modelValue))
const nativeRef = ref<HTMLInputElement | null>(null)

watch(
    () => props.modelValue,
    (v) => {
        text.value = isoToDisplay(v)
    },
)

function isoToDisplay(iso: string | null | undefined): string {
    if (!iso) return ''
    const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/)
    return m ? `${m[3]}-${m[2]}-${m[1]}` : ''
}

function displayToIso(s: string): string | null {
    const m = s.trim().match(/^(\d{2})-(\d{2})-(\d{4})$/)
    if (!m) return null
    const [, dd, mm, yyyy] = m
    const iso = `${yyyy}-${mm}-${dd}`
    // Reject impossible dates like 31-02-2026.
    const d = new Date(iso)
    return d.getFullYear() === +yyyy && d.getMonth() + 1 === +mm && d.getDate() === +dd ? iso : null
}

// Commit on blur/Enter: emit ISO if valid, clear if empty, otherwise revert.
function commit() {
    const raw = text.value.trim()
    if (raw === '') {
        emit('update:modelValue', '')
        return
    }
    const iso = displayToIso(raw)
    if (iso) emit('update:modelValue', iso)
    else text.value = isoToDisplay(props.modelValue)
}

function openPicker() {
    const el = nativeRef.value
    if (!el) return
    try {
        // showPicker() where supported; fall back to focus/click.
        if (typeof el.showPicker === 'function') el.showPicker()
        else el.focus()
    } catch {
        el.focus()
    }
}

function onNative(event: Event) {
    emit('update:modelValue', (event.target as HTMLInputElement).value)
}
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            v-model="text"
            type="text"
            inputmode="numeric"
            placeholder="dd-mm-yyyy"
            class="w-full rounded-md border px-3 py-2 pr-10 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            :class="invalid ? 'border-red-500' : 'border-border'"
            @blur="commit"
            @keydown.enter.prevent="commit"
        >

        <button
            type="button"
            class="absolute right-0 top-0 flex h-full w-10 items-center justify-center text-slate-400 transition hover:text-primary"
            tabindex="-1"
            aria-label="Open calendar"
            @click="openPicker"
        >
            <i class="fa-regular fa-calendar text-sm" />
        </button>

        <!-- Native picker: visually hidden, drives the ISO value directly. -->
        <input
            ref="nativeRef"
            type="date"
            class="pointer-events-none absolute bottom-0 right-2 h-0 w-0 opacity-0"
            :value="modelValue ?? ''"
            tabindex="-1"
            aria-hidden="true"
            @input="onNative"
        >
    </div>
</template>
