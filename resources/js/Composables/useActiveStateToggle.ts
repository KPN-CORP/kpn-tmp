import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * The row whose Active/Inactive badge has been clicked, while the prompt in
 * front of it is still open.
 */
export interface ActiveStateTarget {
    /** The row's id — what the badge's busy state keys on. */
    id: number
    /** Where it is going: true activates, false deactivates. */
    activating: boolean
    /** That row's toggle endpoint. */
    url: string
    /** What the prompt names — the master's own name, when it has one. */
    name?: string
}

/**
 * Guards the Active/Inactive badge in a master table with a confirmation.
 *
 * The badge writes straight to the database — one click and every picker
 * reading that master changes, and the transition is recorded in the audit
 * trail — so the click only ASKS. The write happens when the prompt is
 * confirmed, which is also what keeps a mis-click on a dense table from
 * silently switching a master off.
 *
 * `reloadOnly` is the page's own partial-reload list, so a confirmed toggle
 * stays exactly as light a reload as an unguarded one was.
 */
export function useActiveStateToggle(reloadOnly: string[]) {
    // The row waiting on the prompt; null when nothing is being asked.
    const pendingToggle = ref<ActiveStateTarget | null>(null)
    // The row whose request is in flight — the badge disables itself on it.
    const togglingId = ref<number | null>(null)

    function requestToggle(target: ActiveStateTarget) {
        pendingToggle.value = target
    }

    function confirmToggle() {
        const target = pendingToggle.value
        if (!target) return

        router.put(
            target.url,
            { is_active: target.activating },
            {
                preserveScroll: true,
                preserveState: true,
                only: reloadOnly,
                onStart: () => (togglingId.value = target.id),
                onFinish: () => {
                    togglingId.value = null
                    pendingToggle.value = null
                },
            },
        )
    }

    function cancelToggle() {
        // Ignore a backdrop click or Escape once the request has left, so the
        // prompt cannot be dismissed out from under its own spinner.
        if (togglingId.value !== null) return

        pendingToggle.value = null
    }

    return { pendingToggle, togglingId, requestToggle, confirmToggle, cancelToggle }
}
