<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()
const page = usePage()

interface NotificationItem {
    id: number
    type: string
    title: string
    message: string | null
    link: string | null
    read_at: string | null
    created_at: string | null
}

const notifications = computed(
    () => (page.props.notifications as { items: NotificationItem[]; unread: number } | undefined) ?? { items: [], unread: 0 },
)

const open = ref(false)

function toggle() {
    open.value = !open.value
}

function close() {
    open.value = false
}

// Icon + accent per notification type.
function accent(type: string): { icon: string; color: string } {
    switch (type) {
        case 'approval_approved':
            return { icon: 'fa-solid fa-circle-check', color: 'text-emerald-500' }
        case 'approval_rejected':
            return { icon: 'fa-solid fa-circle-xmark', color: 'text-red-500' }
        default:
            return { icon: 'fa-solid fa-bell', color: 'text-amber-500' }
    }
}

// Compact relative time ("3m", "2h", "5d"); falls back to the raw string.
function ago(value: string | null): string {
    if (!value) return ''
    const then = new Date(value.replace(' ', 'T')).getTime()
    if (Number.isNaN(then)) return ''
    const diff = Math.max(0, Date.now() - then)
    const m = Math.floor(diff / 60000)
    if (m < 1) return 'now'
    if (m < 60) return `${m}m`
    const h = Math.floor(m / 60)
    if (h < 24) return `${h}h`
    return `${Math.floor(h / 24)}d`
}

function onItemClick(item: NotificationItem) {
    close()
    router.post(
        `/notifications/${item.id}/read`,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                if (item.link) router.visit(item.link)
            },
        },
    )
}

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: true })
}
</script>

<template>
    <div class="relative">
        <button
            type="button"
            class="relative flex h-9 w-9 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100 hover:text-primary"
            :aria-label="t.topbar.notifications.title"
            @click="toggle"
        >
            <i class="fa-solid fa-bell text-lg" />
            <span
                v-if="notifications.unread > 0"
                class="absolute -right-0.5 -top-0.5 flex min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-[18px] text-white"
            >
                {{ notifications.unread > 99 ? '99+' : notifications.unread }}
            </span>
        </button>

        <!-- Click-away backdrop -->
        <div
            v-if="open"
            class="fixed inset-0 z-40"
            @click="close"
        />

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="translate-y-1 opacity-0"
        >
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-80 overflow-hidden rounded-xl border border-border bg-white shadow-xl sm:w-96"
            >
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-border px-4 py-3">
                    <h3 class="text-sm font-bold text-slate-800">{{ t.topbar.notifications.title }}</h3>
                    <button
                        v-if="notifications.unread > 0"
                        type="button"
                        class="text-xs font-medium text-primary transition hover:underline"
                        @click="markAllRead"
                    >
                        {{ t.topbar.notifications.markAllRead }}
                    </button>
                </div>

                <!-- List -->
                <div class="max-h-96 overflow-y-auto">
                    <button
                        v-for="item in notifications.items"
                        :key="item.id"
                        type="button"
                        class="flex w-full items-start gap-3 border-b border-border/60 px-4 py-3 text-left transition last:border-0 hover:bg-slate-50"
                        :class="{ 'bg-sky-50/50': !item.read_at }"
                        @click="onItemClick(item)"
                    >
                        <i class="mt-0.5 text-sm" :class="[accent(item.type).icon, accent(item.type).color]" />
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <p class="truncate text-sm font-semibold text-slate-800">{{ item.title }}</p>
                                <span class="shrink-0 text-[11px] text-slate-400">{{ ago(item.created_at) }}</span>
                            </div>
                            <p v-if="item.message" class="mt-0.5 line-clamp-2 text-xs text-slate-500">{{ item.message }}</p>
                        </div>
                        <span v-if="!item.read_at" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-sky-500" />
                    </button>

                    <div
                        v-if="notifications.items.length === 0"
                        class="flex flex-col items-center gap-2 px-4 py-8 text-center"
                    >
                        <i class="fa-regular fa-bell-slash text-2xl text-slate-300" />
                        <p class="text-sm text-slate-400">{{ t.topbar.notifications.empty }}</p>
                    </div>
                </div>

                <!-- Footer -->
                <Link
                    href="/approvals"
                    class="block border-t border-border px-4 py-2.5 text-center text-xs font-semibold text-primary transition hover:bg-slate-50"
                    @click="close"
                >
                    {{ t.topbar.notifications.viewAll }}
                </Link>
            </div>
        </Transition>
    </div>
</template>
