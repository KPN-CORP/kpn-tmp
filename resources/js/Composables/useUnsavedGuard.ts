import { ref } from 'vue'
import type { InertiaForm } from '@inertiajs/vue3'

/**
 * Confirm-before-discard for a drawer form.
 *
 * Closing a drawer throws the draft away, so `requestClose()` asks first when
 * the form has unsaved edits and closes straight away when it does not. Wire it
 * to every route out of the drawer — the `@close` (backdrop click + Escape) and
 * the Cancel button — and pair it with `<UnsavedChangesDialog>`:
 *
 *   const { confirming, requestClose, discard } = useUnsavedGuard(form, closeModal)
 *
 * The caller keeps ownership of the drawer's open state and of what closing
 * means (reset the form, clear the row id, …); this only decides whether the
 * question gets asked.
 *
 * `isDirty` measures the distance from the form's DEFAULTS, so a drawer that
 * loads an existing row must seed those defaults as it opens — see `seedForm`.
 * Assigning the fields alone would leave the form dirty from the first render
 * and prompt on a drawer nobody touched.
 */
export function useUnsavedGuard<T extends object>(form: InertiaForm<T>, close: () => void) {
    const confirming = ref(false)

    function requestClose() {
        if (form.isDirty) {
            confirming.value = true
            return
        }
        close()
    }

    function discard() {
        confirming.value = false
        close()
    }

    return { confirming, requestClose, discard }
}

/**
 * Load values into a form as BOTH its data and its defaults, so it starts
 * clean: `isDirty` then measures the edits made in this sitting rather than the
 * distance from a blank form.
 *
 * `reset()` assigns every field in one synchronous block, so cascade watchers —
 * which flush afterwards — see parents and children already consistent and
 * leave a restored row alone.
 */
export function seedForm<T extends object>(form: InertiaForm<T>, values: T) {
    form.defaults(values)
    form.reset()
    form.clearErrors()
}
