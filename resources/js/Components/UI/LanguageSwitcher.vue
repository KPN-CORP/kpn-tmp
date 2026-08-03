<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useLocale, type Locale } from '@/Composables/useLocale'
import FlagIcon from '@/Components/UI/FlagIcon.vue'

/**
 * The EN/ID switcher. Shared between the top bar and (later) the login screen
 * so the two cannot drift apart.
 */
const props = withDefaults(
    defineProps<{
        /** Which edge the menu is anchored to. */
        align?: 'left' | 'right'

        /** Drop the label below `sm`, leaving just the flag (top bar does). */
        collapseLabel?: boolean
    }>(),
    {
        align: 'left',
        collapseLabel: false,
    },
)

const { locale, setLocale } = useLocale()

// Endonyms: a language is always listed in its own language, so the switcher
// reads the same whichever locale is active.
const languages: { code: Locale; label: string }[] = [
    { code: 'id', label: 'Bahasa' },
    { code: 'en', label: 'English' },
]

const activeLanguage = computed(
    () =>
        languages.find((language) => language.code === locale.value) ??
        languages[0],
)

// Only the alternatives are listed — the current language is already on the
// trigger, so repeating it in the menu is just another thing to read past.
const otherLanguages = computed(
    () => languages.filter((language) => language.code !== locale.value),
)

const open = ref(false)

function choose(code: Locale) {
    setLocale(code)
    open.value = false
}

const closeOnEscape = (event: KeyboardEvent) => {
    if (open.value && event.key === 'Escape') {
        open.value = false
    }
}

onMounted(() => document.addEventListener('keydown', closeOnEscape))
onUnmounted(() => document.removeEventListener('keydown', closeOnEscape))

const labelClass = computed(() =>
    props.collapseLabel ? 'hidden sm:inline' : 'inline',
)

// The chevron is wrapped rather than carrying `hidden` itself: FontAwesome's
// .fa-solid sets its own display and, loading after Tailwind, wins. A plain
// span has no such rule, so the toggle sticks.
const chevronClass = computed(() =>
    props.collapseLabel ? 'hidden sm:inline-block' : 'inline-block',
)

const menuClass = computed(() =>
    props.align === 'right'
        ? 'right-0 origin-top-right'
        : 'left-0 origin-top-left',
)
</script>

<template>
    <div class="relative shrink-0">
        <button
            type="button"
            class="flex items-center gap-2 rounded-md px-1 py-1.5 text-sm font-semibold text-primary transition-colors hover:bg-slate-50"
            aria-haspopup="menu"
            :aria-expanded="open"
            :aria-label="activeLanguage.label"
            @click="open = !open"
        >
            <FlagIcon :locale="activeLanguage.code" />

            <span :class="labelClass">
                {{ activeLanguage.label }}
            </span>

            <span :class="chevronClass">
                <i
                    class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"
                    :class="open && 'rotate-180'"
                />
            </span>
        </button>

        <!-- Click-away catcher, so the menu closes on any outside click -->
        <div
            v-show="open"
            class="fixed inset-0 z-40"
            @click="open = false"
        />

        <Transition
            enter-active-class="transition ease-out duration-200"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-show="open"
                class="absolute z-50 mt-2 min-w-[150px] rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-black/5"
                :class="menuClass"
                role="menu"
            >
                <button
                    v-for="language in otherLanguages"
                    :key="language.code"
                    type="button"
                    class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
                    role="menuitem"
                    @click="choose(language.code)"
                >
                    <FlagIcon :locale="language.code" />

                    <span>{{ language.label }}</span>
                </button>
            </div>
        </Transition>
    </div>
</template>
