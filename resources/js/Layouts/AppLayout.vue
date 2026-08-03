<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
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

            <main class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
                <slot />
            </main>
        </div>
    </div>
</template>
