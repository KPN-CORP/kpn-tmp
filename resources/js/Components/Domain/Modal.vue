<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue'

const props = withDefaults(
    defineProps<{
        show: boolean
        title?: string
        maxWidth?: string
    }>(),
    { maxWidth: 'max-w-lg' },
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
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center"
                @click.self="close"
            >
                <div
                    class="w-full rounded-xl bg-white shadow-xl"
                    :class="maxWidth"
                >
                    <div
                        v-if="title || $slots.header"
                        class="flex items-center justify-between border-b border-border px-5 py-4"
                    >
                        <slot name="header">
                            <h3 class="font-bold text-slate-800">{{ title }}</h3>
                        </slot>
                        <button
                            type="button"
                            class="flex h-8 w-8 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                            @click="close"
                        >
                            <i class="fa-solid fa-xmark" />
                        </button>
                    </div>

                    <div class="px-5 py-4">
                        <slot />
                    </div>

                    <div
                        v-if="$slots.footer"
                        class="flex justify-end gap-2 border-t border-border px-5 py-4"
                    >
                        <slot name="footer" />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
