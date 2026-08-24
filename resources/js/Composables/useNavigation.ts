import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { navigation } from '@/Config/navigation'

export interface ResolvedNavChild {
    label: string
    href: string
}

export interface ResolvedNavItem {
    section: string
    label: string
    icon: string
    href?: string
    children?: ResolvedNavChild[]
}

/**
 * Resolves the static `navigation` config into display-ready items for the
 * current language, dropping anything the user lacks permission for. Returns a
 * flat list; the sidebar groups it by `section`. Parent items keep their
 * (permission-filtered) `children`; a parent with no surviving children is
 * dropped entirely.
 */
export function useNavigation() {
    const page = usePage()
    const { t } = useLocale()

    return computed<ResolvedNavItem[]>(() => {
        const permissions = (page.props.permissions as string[]) ?? []
        const can = (permission?: string) => !permission || permissions.includes(permission)

        return navigation
            .filter((item) => can(item.permission))
            .map((item) => {
                const children = item.children
                    ?.filter((child) => can(child.permission))
                    .map((child) => ({
                        label: t.value.nav[child.label],
                        href: child.href,
                    }))

                return {
                    section: t.value.nav[item.section],
                    label: t.value.nav[item.label],
                    icon: item.icon,
                    href: item.href,
                    children,
                }
            })
            // Drop a parent whose children were all filtered out.
            .filter((item) => !item.children || item.children.length > 0)
    })
}
