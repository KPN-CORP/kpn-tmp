import type { LocaleMessages } from '@/Config/locales'

/**
 * The sidebar menu, described as plain data. Labels and section headings are
 * locale keys (resolved in `useNavigation`), not literal strings, so the menu
 * translates for free. `permission`, when set, gates the item against the
 * user's shared permissions — leave it off for always-visible items.
 *
 * Menus are placeholders for now; wire the real routes/permissions here later.
 */
export interface NavItem {
    section: keyof LocaleMessages['nav']
    label: keyof LocaleMessages['nav']
    icon: string
    href: string
    permission?: string
}

export const navigation: NavItem[] = [
    {
        section: 'main',
        label: 'dashboard',
        icon: 'fa-solid fa-gauge-high',
        href: '/dashboard',
    },
    {
        section: 'main',
        label: 'activity',
        icon: 'fa-solid fa-clock-rotate-left',
        href: '/activity',
    },
    {
        section: 'management',
        label: 'users',
        icon: 'fa-solid fa-users',
        href: '/users',
        // permission: 'users.view',
    },
    {
        section: 'management',
        label: 'reports',
        icon: 'fa-solid fa-file-lines',
        href: '/reports',
        // permission: 'reports.view',
    },
    {
        section: 'system',
        label: 'settings',
        icon: 'fa-solid fa-gear',
        href: '/settings',
    },
    {
        section: 'system',
        label: 'profile',
        icon: 'fa-solid fa-user',
        href: '/profile',
    },
]
