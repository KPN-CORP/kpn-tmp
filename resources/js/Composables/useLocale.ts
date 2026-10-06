import { computed, ref, watch } from 'vue'
import { loadLocale, locales, type Locale } from '@/Config/locales'

export type { Locale }

const STORAGE_KEY = 'app-locale'

function initialLocale(): Locale {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY)

        if (stored === 'en' || stored === 'id') {
            return stored
        }
    } catch {
        // localStorage unavailable (e.g. privacy mode) — fall through.
    }

    return 'en'
}

// A single shared ref so every component reacts to the same language.
const currentLocale = ref<Locale>(initialLocale())

watch(
    currentLocale,
    (locale) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, locale)
        } catch {
            // Ignore storage failures; the choice still applies this session.
        }

        document.documentElement.lang = locale
    },
    { immediate: true },
)

/**
 * Load the stored language before the app mounts, so the first render is
 * already in it rather than flashing English.
 */
export function prepareLocale(): Promise<void> {
    return loadLocale(currentLocale.value).catch(() => {
        // Chunk failed to load: fall back to English for this session.
        currentLocale.value = 'en'
    })
}

export function useLocale() {
    // Falls back to English for the moment a newly chosen language is loading.
    const t = computed(() => locales[currentLocale.value] ?? locales.en!)

    async function setLocale(locale: Locale) {
        await loadLocale(locale)
        currentLocale.value = locale
    }

    return {
        locale: currentLocale,
        t,
        setLocale,
    }
}
