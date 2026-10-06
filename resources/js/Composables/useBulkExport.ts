import { onBeforeUnmount, reactive } from 'vue'
import { useLocale } from '@/Composables/useLocale'

/** Headers for a same-origin JSON POST through `fetch`, carrying Laravel's XSRF cookie. */
export function xsrfHeaders(): Record<string, string> {
    const token = decodeURIComponent(
        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
    )

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': token,
    }
}

interface BulkRoutes {
    /** POST — starts the job, answers `{ job_id }`. */
    start: string
    /** GET — `{ progress, ready, error }` for a job. */
    status: (jobId: string) => string
    /** GET — the finished zip. */
    file: (jobId: string) => string
}

/**
 * A background zip export: start the job, poll its progress, then download the
 * file. Shared by the Facecard and IDP lists.
 *
 * Polling stops when the page is left — otherwise it keeps running and can
 * still redirect to the zip from whatever page the user moved on to — and a
 * poll answered after that is ignored.
 */
export function useBulkExport(routes: BulkRoutes, intervalMs = 1500) {
    const { t } = useLocale()
    const bulk = reactive({ running: false, progress: 0, error: '' })
    let poll: ReturnType<typeof setInterval> | undefined

    function stop() {
        clearInterval(poll)
        poll = undefined
        bulk.running = false
    }

    function watchJob(jobId: string) {
        clearInterval(poll)
        poll = setInterval(async () => {
            try {
                const res = await fetch(routes.status(jobId), { headers: { Accept: 'application/json' } })
                const data = await res.json()
                // Stopped, or the page was left, while this request was in flight.
                if (poll === undefined) return
                bulk.progress = data.progress ?? 0
                if (data.error) {
                    bulk.error = data.error
                    stop()
                } else if (data.ready) {
                    stop()
                    window.location.href = routes.file(jobId)
                }
            } catch {
                bulk.error = t.value.common.exportLost
                stop()
            }
        }, intervalMs)
    }

    async function start(employeeIds: string[]) {
        bulk.running = true
        bulk.progress = 0
        bulk.error = ''
        try {
            const res = await fetch(routes.start, {
                method: 'POST',
                headers: xsrfHeaders(),
                body: JSON.stringify({ employee_ids: employeeIds }),
            })
            if (!res.ok) throw new Error(String(res.status))
            const { job_id } = await res.json()
            watchJob(job_id)
        } catch {
            bulk.error = t.value.common.exportFailed
            bulk.running = false
        }
    }

    onBeforeUnmount(stop)

    return { bulk, start }
}
