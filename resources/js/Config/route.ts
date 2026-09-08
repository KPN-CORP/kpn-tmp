import { route as ziggy } from 'ziggy-js'

/**
 * `route('facecard.list')` — the project's single way to turn a Laravel route
 * name into a URL. Wraps Ziggy, whose route table is published by the
 * `@routes` directive in `app.blade.php`, so the names here are the ones the
 * server actually registered: rename a path in `routes/web/*.php` and every
 * caller follows automatically. Nothing in the UI should hard-code a path.
 *
 * The only thing this adds to Ziggy is `absolute: false`. Ziggy returns a full
 * `http://host/...` URL by default, but the app compares hrefs against
 * Inertia's `page.url` (a bare path) to decide which sidebar item is active,
 * so an absolute URL would silently stop every menu item highlighting.
 *
 * Params follow Ziggy: a bare value for a single-parameter route
 * (`route('idp.show', employeeId)`), an array or object for several
 * (`route('idp.setting.masters.update', [type, id])`). Anything left over
 * becomes a query string.
 */
export function route(
    name: Parameters<typeof ziggy>[0],
    params?: Parameters<typeof ziggy>[1],
): string {
    return ziggy(name, params, false) as string
}
