<script setup lang="ts">
import { computed } from 'vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import { useLocale } from '@/Composables/useLocale'

/**
 * The "keep editing / discard" prompt raised when a drawer form is closed with
 * unsaved edits. Pairs with `useUnsavedGuard`:
 *
 *   <UnsavedChangesDialog
 *       :show="confirming"
 *       @confirm="discard"
 *       @close="confirming = false"
 *   />
 *
 * `message` overrides the generic wording where a screen can name what is
 * about to be lost.
 */
const props = defineProps<{ show: boolean; message?: string }>()

defineEmits<{ (e: 'confirm'): void; (e: 'close'): void }>()

const { t } = useLocale()

const message = computed(() => props.message ?? t.value.common.discardMessage)
</script>

<template>
    <ConfirmDialog
        :show="show"
        :title="t.common.discardTitle"
        :message="message"
        :confirm-label="t.common.discardConfirm"
        :cancel-label="t.common.keepEditing"
        variant="danger"
        icon="fa-solid fa-triangle-exclamation"
        @confirm="$emit('confirm')"
        @close="$emit('close')"
    />
</template>
