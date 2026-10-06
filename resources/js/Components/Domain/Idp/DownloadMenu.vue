<script setup lang="ts">
/**
 * One "Download" button for an employee's development plan, opening the two
 * formats it comes in: the PDF and the Excel export. Closes on a pick, an
 * outside click or Escape.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'

const props = defineProps<{ employeeId: string }>()

const { t } = useLocale()

const open = ref(false)
const root = ref<HTMLElement | null>(null)

function onDocumentClick(event: MouseEvent) {
    if (open.value && root.value && !root.value.contains(event.target as Node)) {
        open.value = false
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') open.value = false
}

onMounted(() => {
    document.addEventListener('mousedown', onDocumentClick)
    document.addEventListener('keydown', onKeydown)
})
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onDocumentClick)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="open = !open"
        >
            <i class="fa-solid fa-download text-xs" />
            {{ t.idp.download }}
            <i class="fa-solid fa-chevron-down text-[10px] transition" :class="open ? 'rotate-180' : ''" />
        </button>

        <div
            v-if="open"
            role="menu"
            class="absolute right-0 z-30 mt-2 w-48 overflow-hidden rounded-lg border border-border bg-white py-1 shadow-lg"
        >
            <a
                role="menuitem"
                :href="route('idp.download_pdf', { employeeId: props.employeeId })"
                class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                @click="open = false"
            >
                <i class="fa-solid fa-file-pdf w-4 text-center text-red-500" />
                {{ t.idp.downloadPdf }}
            </a>
            <a
                role="menuitem"
                :href="route('idp.export', { employeeId: props.employeeId })"
                class="flex items-center gap-2.5 px-3.5 py-2 text-sm text-slate-700 transition hover:bg-slate-50"
                @click="open = false"
            >
                <i class="fa-solid fa-file-excel w-4 text-center text-emerald-600" />
                {{ t.idp.exportExcel }}
            </a>
        </div>
    </div>
</template>
