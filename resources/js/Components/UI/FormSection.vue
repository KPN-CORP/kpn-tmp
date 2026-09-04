<script setup lang="ts">
/**
 * A titled block inside a form drawer: a step badge, a heading with an optional
 * icon, a one-line hint, and the fields as the default slot. Long forms read as
 * a short sequence of steps rather than a flat wall of inputs.
 *
 * `complete` swaps the step number for a check, so progress through a cascading
 * form (pick a type → pick a competency → …) is visible at a glance.
 */
withDefaults(
    defineProps<{
        step?: number | string
        title: string
        hint?: string
        icon?: string
        complete?: boolean
    }>(),
    { complete: false },
)
</script>

<template>
    <!-- No `overflow-hidden` here: MultiSelect renders its menu inline and
         absolutely positioned, so clipping the section would swallow the
         option list. The header rounds its own top corners instead. -->
    <section class="rounded-xl border border-border bg-white">
        <header
            class="flex items-start gap-3 rounded-t-xl border-b border-border/60 bg-slate-50/70 px-4 py-3"
        >
            <span
                v-if="step !== undefined"
                class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold transition"
                :class="
                    complete
                        ? 'bg-emerald-500 text-white'
                        : 'bg-primary/10 text-primary'
                "
            >
                <i v-if="complete" class="fa-solid fa-check text-[10px]" />
                <template v-else>{{ step }}</template>
            </span>

            <div class="min-w-0 flex-1">
                <h4 class="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                    <i v-if="icon" :class="icon" class="text-xs text-slate-400" />
                    {{ title }}
                </h4>
                <p v-if="hint" class="mt-0.5 text-xs leading-relaxed text-slate-500">
                    {{ hint }}
                </p>
            </div>

            <slot name="aside" />
        </header>

        <div class="space-y-4 p-4">
            <slot />
        </div>
    </section>
</template>
