<script setup lang="ts">
import { ref } from 'vue'

/**
 * A small pill (e.g. "L1") that reveals a detail tooltip on hover/focus. The
 * tooltip is teleported to <body> and positioned with `fixed`, so it is never
 * clipped by an ancestor's overflow (the table scrolls horizontally).
 */
defineProps<{ label: string; tip: string }>()

const show = ref(false)
const x = ref(0)
const y = ref(0)

function enter(e: FocusEvent | MouseEvent) {
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
    x.value = rect.left + rect.width / 2
    y.value = rect.top
    show.value = true
}

function leave() {
    show.value = false
}
</script>

<template>
    <span
        tabindex="0"
        class="inline-flex cursor-default items-center rounded-md bg-primary/10 px-2 py-0.5 text-xs font-bold text-primary outline-none transition hover:bg-primary/20 focus:ring-2 focus:ring-primary/30"
        @mouseenter="enter"
        @mouseleave="leave"
        @focus="enter"
        @blur="leave"
    >
        {{ label }}
    </span>

    <Teleport to="body">
        <div
            v-if="show"
            class="pointer-events-none fixed z-50 max-w-xs -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-slate-800 px-2.5 py-1.5 text-xs font-medium text-white shadow-lg"
            :style="{ left: `${x}px`, top: `${y - 6}px` }"
        >
            {{ tip }}
            <span
                class="absolute left-1/2 top-full -translate-x-1/2 border-4 border-transparent border-t-slate-800"
            />
        </div>
    </Teleport>
</template>
