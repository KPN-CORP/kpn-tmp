<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue'

const props = withDefaults(
    defineProps<{
        show: boolean
        title?: string
        maxWidth?: string
    }>(),
    { maxWidth: 'max-w-xl' },
)

const emit = defineEmits<{ (e: 'close'): void }>()

function close() {
    emit('close')
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.show) close()
}

// Lock body scroll while open.
watch(
    () => props.show,
    (open) => {
        document.body.style.overflow = open ? 'hidden' : ''
    },
)

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.body.style.overflow = ''
})
</script>

<template>
    <Teleport to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm"
                @click.self="close"
            />
        </Transition>

        <!-- Panel (slides in from the right, full height) -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="show"
                class="fixed inset-y-0 right-0 z-50 flex w-full flex-col rounded-l-2xl bg-white shadow-2xl ring-1 ring-black/5"
                :class="maxWidth"
                role="dialog"
                aria-modal="true"
            >
                <div
                    v-if="title || $slots.header"
                    class="flex items-start justify-between gap-4 border-b border-border bg-slate-50/70 px-6 py-4"
                >
                    <slot name="header">
                        <h3 class="font-bold text-slate-800">{{ title }}</h3>
                    </slot>
                    <button
                        type="button"
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-200/70 hover:text-slate-600"
                        @click="close"
                    >
                        <i class="fa-solid fa-xmark" />
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5">
                    <slot />
                </div>

                <div
                    v-if="$slots.footer"
                    class="flex justify-end gap-2 border-t border-border bg-slate-50/70 px-6 py-4"
                >
                    <slot name="footer" />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
