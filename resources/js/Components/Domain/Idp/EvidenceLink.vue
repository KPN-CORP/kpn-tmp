<script setup lang="ts">
/**
 * One result's evidence, shown the way it can actually be opened:
 *  - a web link opens in a new tab;
 *  - a network path (shared folder, mapped drive) is shown as the path with a
 *    Copy button — a browser will not open \\server\… or file:// from a web
 *    page, so the approver pastes it into File Explorer instead;
 *  - older free-text evidence is shown as plain text.
 */
import { computed, onBeforeUnmount, ref } from 'vue'
import { useLocale } from '@/Composables/useLocale'
import { storedEvidenceKind } from '@/Components/Domain/Idp/evidence'

const props = withDefaults(
    defineProps<{
        value: string | null
        /** Compact: a short label instead of the full link/path (the plan table). */
        compact?: boolean
    }>(),
    { compact: false },
)

const { t } = useLocale()
const kind = computed(() => storedEvidenceKind(props.value))
const path = computed(() => (props.value ?? '').trim())

const copied = ref(false)
let resetTimer: ReturnType<typeof setTimeout> | undefined

async function copyPath() {
    try {
        await navigator.clipboard.writeText(path.value)
    } catch {
        // Clipboard blocked (e.g. a non-secure origin): select-and-copy by hand
        // still works from the visible path.
        return
    }
    copied.value = true
    clearTimeout(resetTimer)
    resetTimer = setTimeout(() => (copied.value = false), 2000)
}

onBeforeUnmount(() => clearTimeout(resetTimer))
</script>

<template>
    <a
        v-if="kind === 'web'"
        :href="path"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 font-medium text-primary hover:underline"
        :class="compact ? '' : 'break-all'"
        :title="path"
    >
        <i class="fa-solid fa-link text-[10px]" />
        {{ compact ? t.idp.evidenceLabel : path }}
    </a>

    <span
        v-else-if="kind === 'network'"
        class="inline-flex max-w-full items-center gap-1.5"
        :title="path"
    >
        <i class="fa-solid fa-folder-open text-[10px] text-amber-500" />
        <span v-if="compact" class="font-medium text-slate-600">{{ t.idp.evidenceSharedFolder }}</span>
        <span v-else class="break-all font-mono text-[11px] text-slate-700">{{ path }}</span>
        <button
            type="button"
            class="inline-flex shrink-0 items-center gap-1 rounded border border-border bg-white px-1.5 py-0.5 text-[10px] font-medium text-slate-500 transition hover:border-primary/40 hover:text-primary"
            :title="t.idp.copyPathHint"
            @click.stop="copyPath"
        >
            <i :class="copied ? 'fa-solid fa-check text-emerald-600' : 'fa-regular fa-copy'" class="text-[9px]" />
            {{ copied ? t.idp.pathCopied : t.idp.copyPath }}
        </button>
    </span>

    <span v-else-if="kind === 'text'" class="whitespace-pre-line text-slate-500">{{ path }}</span>
</template>
