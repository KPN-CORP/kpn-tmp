<script setup lang="ts">
import { computed } from 'vue'

import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import { useLocale } from '@/Composables/useLocale'

/**
 * The prompt in front of a master's Active/Inactive badge, shared by every
 * screen that has one so the wording cannot drift apart.
 *
 * Which way the row is going changes every word of it — and its colour:
 * switching a master OFF takes it out of every picker that reads it, which is
 * the consequential direction, so that is the danger case.
 */
const props = defineProps<{
    /** The row being switched, or null when nothing is being asked. */
    target: { activating: boolean; name?: string } | null
    /** True while the confirmed request is in flight. */
    processing?: boolean
}>()

const emit = defineEmits<{
    (e: 'confirm'): void
    (e: 'close'): void
}>()

const { t } = useLocale()

// Read off the target rather than stored: the dialog is driven entirely by
// which row (if any) is pending.
const activating = computed(() => props.target?.activating === true)
</script>

<template>
    <ConfirmDialog
        :show="target !== null"
        :title="activating ? t.idp.settings.activateTitle : t.idp.settings.deactivateTitle"
        :message="activating ? t.idp.settings.confirmActivate : t.idp.settings.confirmDeactivate"
        :confirm-label="activating ? t.idp.settings.activate : t.idp.settings.deactivate"
        :cancel-label="t.idp.form.cancel"
        :variant="activating ? 'primary' : 'danger'"
        :icon="activating ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-minus'"
        :processing="processing"
        @confirm="emit('confirm')"
        @close="emit('close')"
    >
        <!-- Named like the delete prompt does, so the reader can see which row
             they hit before committing. A mapping has no name of its own; its
             screen passes the competency it maps instead. -->
        <p
            v-if="target?.name"
            class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
        >
            {{ target.name }}
        </p>
    </ConfirmDialog>
</template>
