import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

/** What the delete dialog is asking about: the DELETE url, and a name to show. */
export interface PendingDelete {
    url: string
    name?: string
}

/**
 * The confirm-then-delete flow every master list shares: a row's delete button
 * sets `pendingDelete`, the ConfirmDialog shows while it is set, and
 * `confirmDelete()` sends the DELETE — reloading only `only`, keeping scroll and
 * page state (filters, open rows), and closing the dialog on success.
 */
export function useDeleteConfirm(only: string[]) {
    const pendingDelete = ref<PendingDelete | null>(null)
    const deleting = ref(false)

    function confirmDelete() {
        if (!pendingDelete.value) return

        router.delete(pendingDelete.value.url, {
            preserveScroll: true,
            preserveState: true,
            only,
            onStart: () => (deleting.value = true),
            onFinish: () => (deleting.value = false),
            onSuccess: () => (pendingDelete.value = null),
        })
    }

    /**
     * Close the dialog without deleting. Templates must call this rather than
     * assign `pendingDelete = null`: the ref arrives destructured from this
     * composable, and a template assignment to it does not reach the ref.
     */
    function cancelDelete() {
        pendingDelete.value = null
    }

    return { pendingDelete, deleting, confirmDelete, cancelDelete }
}
