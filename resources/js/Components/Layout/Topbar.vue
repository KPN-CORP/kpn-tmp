<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import NotificationBell from '@/Components/Layout/NotificationBell.vue'
import { useLocale } from '@/Composables/useLocale'

defineEmits<{
    'toggle-sidebar': []
}>()

const { t } = useLocale()
const page = usePage()

const user = computed(() => (page.props.auth as any)?.user)

// First letters of the name, for the avatar fallback.
const initials = computed(() => {
    const name: string = user.value?.name ?? t.value.topbar.guest
    return name
        .split(' ')
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase()
})
</script>

<template>
    <header
        class="sticky top-0 z-30 flex h-[var(--topbar-height)] items-center border-b border-border bg-white px-4 sm:px-8"
    >
        <!-- Mobile hamburger -->
        <button
            class="mr-3 text-slate-500 transition-colors hover:text-primary lg:hidden"
            :aria-label="t.topbar.openMenu"
            @click="$emit('toggle-sidebar')"
        >
            <i class="fa-solid fa-bars text-lg" />
        </button>

        <div class="ml-auto flex min-w-0 items-center gap-3 sm:gap-5">
            <!-- Notifications -->
            <NotificationBell />

            <!-- Language switcher -->
            <LanguageSwitcher
                align="right"
                collapse-label
            />

            <!-- Divider -->
            <span
                class="h-8 w-px shrink-0 bg-slate-200"
                aria-hidden="true"
            />

            <!-- Signed-in user -->
            <div class="flex min-w-0 items-center gap-3">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-500 text-sm font-semibold text-white"
                >
                    {{ initials }}
                </div>

                <!-- A long name truncates rather than pushing the row wider
                     than the header. -->
                <div class="hidden min-w-0 leading-tight sm:block">
                    <div class="truncate text-sm font-bold text-slate-800">
                        {{ user?.name ?? t.topbar.guest }}
                    </div>
                    <div class="truncate text-xs text-slate-400">
                        {{ user?.email ?? user?.employee_id ?? '' }}
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
