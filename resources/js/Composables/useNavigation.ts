import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useLocale } from '@/Composables/useLocale'
import { navigation } from '@/Config/navigation'

/**
 * Resolves the static `navigation` config into display-ready items for the
 * current language, dropping anything the user lacks permission for. Returns a
 * flat list; the sidebar groups it by `section`.
 */
export function useNavigation() {
    const page = usePage()
    const { t } = useLocale()

    return computed(() => {
        const permissions = (page.props.permissions as string[]) ?? []
        const can = (permission: string) => permissions.includes(permission)

        return navigation
            .filter((item) => !item.permission || can(item.permission))
            .map((item) => ({
                section: t.value.nav[item.section],
                label: t.value.nav[item.label],
                icon: item.icon,
                href: item.href,
            }))
    })
}
