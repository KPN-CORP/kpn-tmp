<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import Sidebar from '@/Components/Layout/Sidebar.vue'
import Topbar from '@/Components/Layout/Topbar.vue'

const sidebarOpen = ref(false)

// Close the mobile drawer after navigating to a new page.
let stopNavListener: (() => void) | undefined

onMounted(() => {
    stopNavListener = router.on('navigate', () => {
        sidebarOpen.value = false
    })
})

onUnmounted(() => {
    stopNavListener?.()
})

// --- Global flash messages (success / error) ---
const page = usePage()
const flash = computed(() => (page.props.flash ?? {}) as { success?: string | null; error?: string | null })

const banner = ref<{ type: 'success' | 'error'; message: string } | null>(null)
let hideTimer: ReturnType<typeof setTimeout> | undefined

watch(
    flash,
    (value) => {
        const next = value.error
            ? { type: 'error' as const, message: value.error }
            : value.success
                ? { type: 'success' as const, message: value.success }
                : null
        banner.value = next
        clearTimeout(hideTimer)
        // Auto-dismiss success quickly; keep errors up longer so they can be read.
        if (next) hideTimer = setTimeout(() => (banner.value = null), next.type === 'error' ? 12000 : 5000)
    },
    { immediate: true, deep: true },
)

onUnmounted(() => clearTimeout(hideTimer))
</script>

<template>
    <div class="flex min-h-screen bg-page">
        <Sidebar
            :open="sidebarOpen"
            @close="sidebarOpen = false"
        />

        <!-- Mobile backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-40 bg-black/50 lg:hidden"
            @click="sidebarOpen = false"
        />

        <div class="flex min-h-screen min-w-0 flex-1 flex-col lg:ml-[var(--sidebar-width)]">
            <Topbar @toggle-sidebar="sidebarOpen = !sidebarOpen" />

            <!-- Global flash banner -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="-translate-y-2 opacity-0"
                leave-active-class="transition duration-150 ease-in"
                leave-to-class="-translate-y-2 opacity-0"
            >
                <div
                    v-if="banner"
                    class="fixed right-4 top-4 z-[70] max-w-md"
                >
                    <div
                        class="flex items-start gap-3 rounded-lg border px-4 py-3 shadow-lg"
                        :class="banner.type === 'error'
                            ? 'border-red-200 bg-red-50 text-red-800'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800'"
                    >
                        <i
                            class="mt-0.5 text-sm"
                            :class="banner.type === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check'"
                        />
                        <p class="flex-1 text-sm leading-relaxed">{{ banner.message }}</p>
                        <button
                            type="button"
                            class="text-slate-400 transition hover:text-slate-600"
                            @click="banner = null"
                        >
                            <i class="fa-solid fa-xmark text-xs" />
                        </button>
                    </div>
                </div>
            </Transition>

            <main class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
