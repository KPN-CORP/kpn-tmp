<script setup lang="ts">
import { onMounted, onUnmounted, watch } from 'vue'

/**
 * Reusable confirmation dialog.
 *
 * Replaces the native `confirm()` for destructive / important actions.
 * Drive it with a `show` ref and handle the `confirm` event:
 *
 *   <ConfirmDialog
 *       :show="showDelete"
 *       :title="t.something.confirmTitle"
 *       :message="t.something.confirmDelete"
 *       variant="danger"
 *       :processing="form.processing"
 *       @confirm="doDelete"
 *       @close="showDelete = false"
 *   />
 */
const props = withDefaults(
    defineProps<{
        show: boolean
        title?: string
        message?: string
        confirmLabel?: string
        cancelLabel?: string
        variant?: 'danger' | 'primary'
        processing?: boolean
    }>(),
    {
        variant: 'danger',
        processing: false,
    },
)

const emit = defineEmits<{
    (e: 'confirm'): void
    (e: 'close'): void
}>()

function close() {
    if (props.processing) return
    emit('close')
}

function confirm() {
    if (props.processing) return
    emit('confirm')
}

function onKeydown(e: KeyboardEvent) {
    if (!props.show) return
    if (e.key === 'Escape') close()
    if (e.key === 'Enter') confirm()
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
                class="fixed inset-0 z-[60] flex items-center justify-center overflow-y-auto bg-black/50 p-4"
                @click.self="close"
            >
                <div
                    class="w-full max-w-md rounded-xl bg-white p-6 text-center shadow-xl"
                    role="alertdialog"
                    aria-modal="true"
                >
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full"
                        :class="
                            variant === 'danger'
                                ? 'bg-red-50 text-red-600'
                                : 'bg-primary/10 text-primary'
                        "
                    >
                        <i
                            :class="
                                variant === 'danger'
                                    ? 'fa-solid fa-trash-can text-xl'
                                    : 'fa-solid fa-circle-question text-xl'
                            "
                        />
                    </div>

                    <h3 v-if="title" class="mt-4 text-lg font-bold text-slate-800">
                        {{ title }}
                    </h3>

                    <p v-if="message" class="mt-1.5 text-sm text-slate-500">
                        {{ message }}
                    </p>

                    <slot />

                    <div class="mt-6 flex justify-center gap-3">
                        <button
                            type="button"
                            class="min-w-24 rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"
                            :disabled="processing"
                            @click="close"
                        >
                            {{ cancelLabel }}
                        </button>

                        <button
                            type="button"
                            class="inline-flex min-w-24 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60"
                            :class="
                                variant === 'danger'
                                    ? 'bg-red-600 hover:bg-red-700'
                                    : 'bg-primary hover:bg-primary-hover'
                            "
                            :disabled="processing"
                            @click="confirm"
                        >
                            <i
                                v-if="processing"
                                class="fa-solid fa-spinner fa-spin text-xs"
                            />
                            {{ confirmLabel }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
