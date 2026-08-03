<script setup lang="ts">
import type { Locale } from '@/Composables/useLocale'

/**
 * Flags are drawn inline rather than pulled from an icon font or emoji.
 * Regional-indicator emoji (🇮🇩) render as bare letters on Windows, and an
 * image under public/ would need bundling — an inline SVG sidesteps both.
 */
defineProps<{
    locale: Locale
}>()
</script>

<template>
    <span
        class="inline-block h-[15px] w-[21px] shrink-0 overflow-hidden rounded-[3px] ring-1 ring-black/10"
        aria-hidden="true"
    >
        <!-- Indonesia: red over white -->
        <svg
            v-if="locale === 'id'"
            viewBox="0 0 21 15"
            class="block h-full w-full"
        >
            <rect width="21" height="7.5" fill="#CE1126" />
            <rect y="7.5" width="21" height="7.5" fill="#FFFFFF" />
        </svg>

        <!-- United States: 13 stripes, canton with a suggestion of stars -->
        <svg
            v-else
            viewBox="0 0 21 15"
            class="block h-full w-full"
        >
            <rect width="21" height="15" fill="#FFFFFF" />

            <g fill="#B22234">
                <rect y="0" width="21" height="1.154" />
                <rect y="2.308" width="21" height="1.154" />
                <rect y="4.615" width="21" height="1.154" />
                <rect y="6.923" width="21" height="1.154" />
                <rect y="9.231" width="21" height="1.154" />
                <rect y="11.538" width="21" height="1.154" />
                <rect y="13.846" width="21" height="1.154" />
            </g>

            <rect width="9" height="8.077" fill="#3C3B6E" />

            <g fill="#FFFFFF">
                <circle cx="1.8" cy="1.7" r="0.5" />
                <circle cx="4.5" cy="1.7" r="0.5" />
                <circle cx="7.2" cy="1.7" r="0.5" />
                <circle cx="3.15" cy="3.4" r="0.5" />
                <circle cx="5.85" cy="3.4" r="0.5" />
                <circle cx="1.8" cy="5.1" r="0.5" />
                <circle cx="4.5" cy="5.1" r="0.5" />
                <circle cx="7.2" cy="5.1" r="0.5" />
                <circle cx="3.15" cy="6.8" r="0.5" />
                <circle cx="5.85" cy="6.8" r="0.5" />
            </g>
        </svg>
    </span>
</template>
