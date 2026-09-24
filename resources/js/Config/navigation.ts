import type { LocaleMessages } from '@/Config/locales'
import { route } from '@/Config/route'

/**
 * The sidebar menu, described as plain data. Labels and section headings are
 * locale keys (resolved in `useNavigation`), not literal strings, so the menu
 * translates for free. `permission`, when set, gates the item against the
 * user's shared permissions — leave it off for always-visible items.
 *
 * An item may either link somewhere (`href`) or act as a collapsible parent
 * that groups `children`. A parent shows in the menu when at least one of its
 * children survives the permission filter.
 *
 * `badge` names a shared Inertia prop holding a count; the sidebar shows it as
 * a pill, and hides it when the count is zero.
 */

/** Shared Inertia props a nav item may draw its badge count from. */
export type NavBadge = 'pendingApprovals'

export interface NavChild {
    label: keyof LocaleMessages['nav']
    href: string
    permission?: string
    /** Also active on the pages beneath `href` (see the sidebar's isChildActive). */
    matchPrefix?: boolean
}

export interface NavItem {
    section: keyof LocaleMessages['nav']
    label: keyof LocaleMessages['nav']
    icon: string
    href?: string
    permission?: string
    badge?: NavBadge
    children?: NavChild[]
}

export const navigation: NavItem[] = [
    // --- Main ---
    // Dashboard is temporarily hidden from the menu (signing in lands on the
    // Task Box — see App\Support\Landing).
    // {
    //     section: 'main',
    //     label: 'dashboard',
    //     icon: 'fa-solid fa-gauge-high',
    //     href: '/dashboard',
    // },
    {
        section: 'main',
        label: 'approvals',
        icon: 'fa-solid fa-circle-check',
        href: route('approvals.inbox'),
        // How many IDP items are awaiting this user's decision.
        badge: 'pendingApprovals',
    },
    {
        // Split by whose plan it is: the user's own, and their team's.
        section: 'main',
        label: 'idp',
        icon: 'fa-solid fa-seedling',
        children: [
            { label: 'idpMine', href: route('idp.mine') },
            // One team member's plan (`/idp/{id}`) belongs to this item too.
            { label: 'idpTeam', href: route('idp.list'), matchPrefix: true },
        ],
    },
    {
        section: 'main',
        label: 'facecard',
        icon: 'fa-solid fa-id-card',
        href: route('facecard.list'),
    },

    // --- Administration ---
    {
        section: 'administration',
        label: 'masterData',
        icon: 'fa-solid fa-database',
        permission: 'view_idp_master',
        children: [
            {
                label: 'masterDataCompetencyType',
                href: route('master_data.competency_type'),
                permission: 'view_idp_master',
            },
            {
                label: 'masterDataCompetency',
                href: route('master_data.competency'),
                permission: 'view_idp_master',
            },
            {
                label: 'masterDataMasterImplementation',
                href: route('master_data.master_implementation'),
                permission: 'view_idp_master',
            },
        ],
    },
    {
        section: 'administration',
        label: 'idpSetting',
        icon: 'fa-solid fa-sliders',
        permission: 'view_idp_master',
        children: [
            {
                label: 'idpSettingDevelopmentModel',
                href: route('idp.setting.development_model'),
                permission: 'view_idp_master',
            },
            {
                label: 'idpSettingMasterTraining',
                href: route('idp.setting.master_training'),
                permission: 'view_idp_master',
            },
            {
                label: 'idpSettingMasterData',
                href: route('idp.setting.master_development'),
                permission: 'view_idp_master',
            },
            {
                label: 'idpSettingReviewTools',
                href: route('idp.setting.review_tools'),
                permission: 'view_idp_master',
            },
        ],
    },
    {
        section: 'administration',
        label: 'importCenter',
        icon: 'fa-solid fa-file-import',
        href: route('import.index'),
        permission: 'view_import_center',
    },
    {
        section: 'administration',
        label: 'report',
        icon: 'fa-solid fa-chart-column',
        href: route('report.show'),
        permission: 'view_report_menu',
    },
    {
        section: 'administration',
        label: 'approvalSetting',
        icon: 'fa-solid fa-list-check',
        href: route('approval.setting.index'),
        permission: 'view_approval_setting',
    },
    {
        section: 'administration',
        label: 'roles',
        icon: 'fa-solid fa-user-shield',
        href: route('roles.index'),
        permission: 'view_admin_setting',
    },
    {
        section: 'administration',
        label: 'userGuide',
        icon: 'fa-solid fa-book-open',
        href: route('user_guide.index'),
    },
]
