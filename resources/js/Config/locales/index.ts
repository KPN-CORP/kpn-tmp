import en from './en'
import id from './id'

// `en` is the reference shape; typing `id` against it keeps both
// dictionaries in sync at compile time.
export type LocaleMessages = typeof en

export const locales: Record<'en' | 'id', LocaleMessages> = {
    en,
    id,
}
