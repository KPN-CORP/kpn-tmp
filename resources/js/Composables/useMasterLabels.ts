import { useLocale } from '@/Composables/useLocale'

/** A master option as the IDP master screens receive it (`value` = the English name). */
export interface MasterNamed {
    value: string
    value_en?: string | null
    value_id?: string | null
}

export interface MasterDescribed {
    description_en?: string | null
    description_id?: string | null
}

/**
 * Locale-aware labels for master data: the name and the description in the
 * active language, falling back to the other one when it was left blank.
 */
export function useMasterLabels() {
    const { locale } = useLocale()

    function masterName(item: MasterNamed | null | undefined): string {
        if (!item) return ''
        const preferred = locale.value === 'id' ? item.value_id : item.value_en
        return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
    }

    function rowDescription(item: MasterDescribed): string {
        const preferred = locale.value === 'id' ? item.description_id : item.description_en
        const fallback = locale.value === 'id' ? item.description_en : item.description_id
        return (preferred || fallback || '').trim()
    }

    return { masterName, rowDescription }
}
