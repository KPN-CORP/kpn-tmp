<script setup lang="ts">
import { computed } from 'vue'

/**
 * The talent 9-box. Rows are Potential (High → Low), columns are Performance
 * (Low → High). The cell whose label matches `talentBox` is highlighted.
 */
const props = defineProps<{
    talentBox?: string | null
}>()

interface Box {
    label: string
    n: number
}

// Top row = High potential; left column = Low performance.
const grid: Box[][] = [
    [
        { label: 'Potential Gems', n: 5 },
        { label: 'High Potentials', n: 2 },
        { label: 'Stars', n: 1 },
    ],
    [
        { label: 'Effective Employee', n: 7 },
        { label: 'Core Players', n: 6 },
        { label: 'High Impact Performers', n: 3 },
    ],
    [
        { label: 'Deadwood', n: 9 },
        { label: 'Inconsistent Performers', n: 8 },
        { label: 'Trusted Professional', n: 4 },
    ],
]

const potentialLabels = ['High', 'Medium', 'Low']
const performanceLabels = ['Low', 'Medium', 'High']

const activeN = computed(() => {
    if (!props.talentBox) return null
    const match = props.talentBox.match(/\((\d)\)/)
    return match ? Number(match[1]) : null
})

function isActive(box: Box): boolean {
    return activeN.value === box.n
}
</script>

<template>
    <div class="flex gap-2">
        <!-- Y axis label -->
        <div class="flex flex-col items-center justify-center">
            <span class="rotate-180 text-[10px] font-semibold uppercase tracking-wider text-slate-400 [writing-mode:vertical-rl]">
                Potential
            </span>
        </div>

        <div class="flex-1">
            <div class="flex gap-2">
                <!-- Potential row labels -->
                <div class="flex flex-col justify-around py-1 text-[10px] font-medium text-slate-400">
                    <span v-for="p in potentialLabels" :key="p" class="h-16 leading-[4rem]">{{ p }}</span>
                </div>

                <!-- 3x3 grid -->
                <div class="grid flex-1 grid-cols-3 gap-1.5">
                    <template v-for="(row, ri) in grid" :key="ri">
                        <div
                            v-for="box in row"
                            :key="box.n"
                            class="flex h-16 flex-col items-center justify-center rounded-md border p-1 text-center transition"
                            :class="isActive(box)
                                ? 'border-primary bg-primary text-white shadow-sm'
                                : 'border-border bg-slate-50 text-slate-500'"
                        >
                            <span class="text-[10px] font-bold leading-tight">{{ box.label }}</span>
                            <span class="text-[9px] opacity-70">({{ box.n }})</span>
                        </div>
                    </template>
                </div>
            </div>

            <!-- X axis -->
            <div class="ml-[3.25rem] mt-1 flex justify-around text-[10px] font-medium text-slate-400">
                <span v-for="p in performanceLabels" :key="p">{{ p }}</span>
            </div>
            <div class="ml-[3.25rem] mt-0.5 text-center text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                Performance
            </div>
        </div>
    </div>
</template>
