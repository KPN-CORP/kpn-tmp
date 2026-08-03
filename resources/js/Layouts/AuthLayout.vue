<script setup lang="ts">
/**
 * The auth shell: a single centered card. Brand, language switcher and title
 * live inside each page (Login / ForgotPassword / ResetPassword), matching the
 * kpn-coi structure.
 */
</script>

<template>
    <div
        class="flex min-h-screen items-center justify-center bg-gradient-to-b from-slate-100 to-slate-200 px-4 py-10"
    >
        <div
            class="w-full max-w-md rounded-2xl bg-white p-8 shadow-lg ring-1 ring-black/5 sm:p-10"
        >
            <slot />
        </div>
    </div>
</template>
