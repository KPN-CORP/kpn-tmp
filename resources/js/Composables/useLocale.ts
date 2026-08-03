import { computed, ref, watch } from 'vue'
import { locales } from '@/Config/locales'

export type Locale = keyof typeof locales

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

export function useLocale() {
    const t = computed(() => locales[currentLocale.value])

    function setLocale(locale: Locale) {
        currentLocale.value = locale
    }

    return {
        locale: currentLocale,
        t,
        setLocale,
    }
}
