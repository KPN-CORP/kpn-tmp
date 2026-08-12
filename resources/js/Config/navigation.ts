import type { LocaleMessages } from '@/Config/locales'

/**
 * The sidebar menu, described as plain data. Labels and section headings are
 * locale keys (resolved in `useNavigation`), not literal strings, so the menu
 * translates for free. `permission`, when set, gates the item against the
 * user's shared permissions — leave it off for always-visible items.
 */
export interface NavItem {
    section: keyof LocaleMessages['nav']
    label: keyof LocaleMessages['nav']
    icon: string
    href: string
    permission?: string
}

export const navigation: NavItem[] = [
    // --- Main ---
    // Dashboard is temporarily hidden from the menu (facecard is the default landing).
    // {
    //     section: 'main',
    //     label: 'dashboard',
    //     icon: 'fa-solid fa-gauge-high',
    //     href: '/dashboard',
    // },
    {
        section: 'main',
        label: 'facecard',
        icon: 'fa-solid fa-id-card',
        href: '/facecard',
    },
    {
        section: 'main',
        label: 'idp',
        icon: 'fa-solid fa-seedling',
        href: '/idp',
    },
    {
        section: 'main',
        label: 'approvals',
        icon: 'fa-solid fa-circle-check',
        href: '/approvals',
    },

    // --- Talent ---
    {
        section: 'talent',
        label: 'report',
        icon: 'fa-solid fa-chart-column',
        href: '/report',
        permission: 'view_report_menu',
    },
    {
        section: 'talent',
        label: 'importCenter',
        icon: 'fa-solid fa-file-import',
        href: '/import-center',
        permission: 'view_import_center',
    },

    // --- Administration ---
    {
        section: 'administration',
        label: 'idpSetting',
        icon: 'fa-solid fa-sliders',
        href: '/idp-setting',
        permission: 'view_idp_master',
    },
    {
        section: 'administration',
        label: 'roles',
        icon: 'fa-solid fa-user-shield',
        href: '/admin/roles',
        permission: 'view_admin_setting',
    },
    {
        section: 'administration',
        label: 'approvalSetting',
        icon: 'fa-solid fa-list-check',
        href: '/approval-setting',
        permission: 'view_approval_setting',
    },
    {
        section: 'administration',
        label: 'userGuide',
        icon: 'fa-solid fa-book-open',
        href: '/user-guide',
    },
]
