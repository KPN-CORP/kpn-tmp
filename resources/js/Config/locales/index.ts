import { shallowReactive } from 'vue'
import en from './en'

// `en` is the reference shape; typing `id` against it keeps both
// dictionaries in sync at compile time.
export type LocaleMessages = typeof en

export type Locale = 'en' | 'id'

// English ships in the main bundle (it is the reference type and the fallback
// while another language loads). Every other language is its own chunk, fetched
// only when chosen — most sessions never download it.
const loaders: Record<Exclude<Locale, 'en'>, () => Promise<LocaleMessages>> = {
    id: () => import('./id').then((m) => m.default),
}

/** The dictionaries loaded so far, keyed by locale. */
export const locales = shallowReactive<Partial<Record<Locale, LocaleMessages>>>({ en })

/** Load a locale's dictionary if it is not loaded yet. */
export async function loadLocale(locale: Locale): Promise<void> {
    if (locales[locale] || locale === 'en') return
    locales[locale] = await loaders[locale]()
}
