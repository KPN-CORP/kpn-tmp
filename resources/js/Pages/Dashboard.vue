<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import { useLocale } from '@/Composables/useLocale'
import { usePermission } from '@/Composables/usePermission'

const { t } = useLocale()
const { can } = usePermission()
const page = usePage()

const userName = computed(() => (page.props.auth as any)?.user?.name ?? '')

const links = computed(() =>
    [
        { label: t.value.nav.facecard, href: '/facecard', icon: 'fa-solid fa-id-card', show: true },
        { label: t.value.nav.idp, href: '/idp', icon: 'fa-solid fa-seedling', show: true },
        { label: t.value.nav.report, href: '/report', icon: 'fa-solid fa-chart-column', show: can('view_report_menu') },
        { label: t.value.nav.importCenter, href: '/import-center', icon: 'fa-solid fa-file-import', show: can('view_import_center') },
        { label: t.value.nav.roles, href: '/admin/roles', icon: 'fa-solid fa-user-shield', show: can('view_admin_setting') },
        { label: t.value.nav.userGuide, href: '/user-guide', icon: 'fa-solid fa-book-open', show: true },
    ].filter((l) => l.show),
)
</script>

<template>
    <Head :title="t.dashboard.title" />

    <AppLayout>
        <PageHeader
            :title="userName ? `${t.dashboard.welcome}, ${userName}` : t.dashboard.title"
            :subtitle="t.dashboard.subtitle"
        />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link
                v-for="link in links"
                :key="link.href"
                :href="link.href"
                class="flex items-center gap-4 rounded-xl border border-border bg-white p-5 shadow-sm transition hover:border-primary/40 hover:shadow"
            >
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-red-50 text-primary">
                    <i :class="link.icon" />
                </span>
                <span class="font-semibold text-slate-700">{{ link.label }}</span>
                <i class="fa-solid fa-chevron-right ml-auto text-xs text-slate-300" />
            </Link>
        </div>
    </AppLayout>
</template>
