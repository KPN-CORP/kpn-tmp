<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { useNavigation } from '@/Composables/useNavigation'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()
const page = usePage()

defineProps<{
    open?: boolean
}>()

defineEmits<{
    close: []
}>()

const menus = useNavigation()

// Group the flat list by section for the headed blocks in the menu.
const groupedMenus = computed(() =>
    menus.value.reduce(
        (groups, menu) => {
            ;(groups[menu.section] ??= []).push(menu)
            return groups
        },
        {} as Record<string, typeof menus.value>,
    ),
)

// Active when the current path matches the item exactly, or sits beneath it
// (so `/users/5` still highlights `Users`). No ziggy — Inertia's `page.url`
// is the current path.
function isActive(href: string) {
    const url = page.url
    return url === href || url.startsWith(href + '/')
}
</script>

<template>
    <aside
        class="fixed left-0 top-0 z-50 flex h-screen w-[var(--sidebar-width)] transform flex-col border-r border-border bg-white transition-transform duration-200 ease-in-out lg:translate-x-0"
        :class="open ? 'translate-x-0' : '-translate-x-full'"
    >
        <!-- Brand -->
        <div
            class="flex h-[var(--topbar-height)] shrink-0 items-center gap-3 border-b border-border px-6"
        >
            <div
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary text-lg font-bold text-white"
            >
                K
            </div>

            <span class="truncate font-bold text-primary">
                {{ t.app.name }}
            </span>

            <!-- Mobile close button -->
            <button
                class="ml-auto text-slate-400 transition-colors hover:text-primary lg:hidden"
                :aria-label="t.topbar.closeMenu"
                @click="$emit('close')"
            >
                <i class="fa-solid fa-xmark text-lg" />
            </button>
        </div>

        <!-- Menu -->
        <nav class="flex-1 overflow-y-auto py-4">
            <div
                v-for="(items, section) in groupedMenus"
                :key="section"
                class="mb-6"
            >
                <div
                    class="px-6 pb-2 text-xs font-semibold uppercase tracking-wider text-slate-300"
                >
                    {{ section }}
                </div>

                <Link
                    v-for="menu in items"
                    :key="menu.href"
                    :href="menu.href"
                    class="flex items-center gap-3 border-l-4 border-transparent px-6 py-3 text-sm transition-all"
                    :class="
                        isActive(menu.href)
                            ? 'border-primary bg-red-50 font-bold text-primary'
                            : 'text-text hover:bg-red-50 hover:text-primary'
                    "
                >
                    <i
                        :class="menu.icon"
                        class="w-5 text-center"
                    />
                    <span>{{ menu.label }}</span>
                </Link>
            </div>
        </nav>

        <!-- Footer -->
        <div class="mt-auto">
            <button
                type="button"
                class="flex w-full items-center gap-3 border-t border-l-4 border-transparent px-6 py-3 text-sm text-text transition-all hover:bg-red-50 hover:font-bold hover:text-primary"
                @click="router.post('/logout')"
            >
                <i class="fa-solid fa-arrow-right-from-bracket w-5 text-center" />
                <span>{{ t.nav.logout }}</span>
            </button>

            <div class="border-t border-border px-6 py-4">
                <div class="text-xs text-slate-400">
                    {{ t.app.name }}
                </div>
                <div class="mt-1 text-xs text-slate-400">
                    {{ t.app.version }}
                </div>
            </div>
        </div>
    </aside>
</template>
