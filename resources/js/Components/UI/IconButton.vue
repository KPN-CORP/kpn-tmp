<script setup lang="ts">
/**
 * Small square icon-only action button with consistent, shared styling.
 *
 * Use `variant` to keep every edit / delete affordance identical across the
 * app:
 *   <IconButton icon="fa-solid fa-pen"   variant="edit"   :title="t..." @click="…" />
 *   <IconButton icon="fa-solid fa-trash" variant="delete" :title="t..." @click="…" />
 */
withDefaults(
    defineProps<{
        icon: string
        variant?: 'edit' | 'delete' | 'default'
        title?: string
    }>(),
    { variant: 'default' },
)
</script>

<template>
    <button
        type="button"
        class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition"
        :class="
            variant === 'delete'
                ? 'hover:bg-red-50 hover:text-red-600'
                : 'hover:bg-slate-100 hover:text-primary'
        "
        :title="title"
    >
        <i :class="icon" class="text-xs" />
    </button>
</template>
