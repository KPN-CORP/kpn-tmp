<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { route } from '@/Config/route'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface Model {
    id: number
    development_model_package_id: number
    name: string
    name_en: string | null
    name_id: string | null
    percentage: number
    uses_master_training: boolean
    description_en: string | null
    description_id: string | null
    development_programs_count: number
    individual_development_plans_count: number
}

interface Package {
    id: number
    name: string
    start_date: string
    end_date: string | null
    is_current: boolean
    is_active: boolean
    models_count: number
    total_percentage: number
}

const props = defineProps<{
    developmentModels: Model[]
    packages: Package[]
}>()

/**
 * After a delete the server redirects back here. We only need this page's own
 * data (and the flash message) refreshed — restricting the reload to these
 * props turns the delete into an Inertia partial reload, so the expensive
 * shared props (corporate employee lookup, approval counts, the notification
 * feed) are not re-evaluated.
 *
 * Add / edit are full pages now (`/idp-setting/packages/create` and
 * `…/{id}/edit`), which redirect back to this list on save.
 */
const reloadOnly = ['packages', 'developmentModels', 'flash']

/**
 * --------------------------------------------------------------------------
 * Display helpers
 * --------------------------------------------------------------------------
 */

// Accent palette cycled across a package's models so each weighting (e.g.
// 70-20-10) is visually distinct. Class strings are kept literal so Tailwind
// can see them.
const modelPalette = [
    'bg-indigo-500',
    'bg-sky-500',
    'bg-amber-500',
    'bg-emerald-500',
    'bg-rose-500',
]

const colorFor = (i: number) => modelPalette[i % modelPalette.length]

// Show the description in the active UI language, falling back to the other
// language when the preferred one is empty.
function modelDescription(model: Model): string {
    const preferred =
        locale.value === 'id' ? model.description_id : model.description_en
    const fallback =
        locale.value === 'id' ? model.description_en : model.description_id

    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

// Display the model name in the active UI language, falling back to the
// canonical `name` when the preferred localized name is empty.
function modelName(model: Model): string {
    const preferred = locale.value === 'id' ? model.name_id : model.name_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : model.name
}

// Format a package period as "start – end" (open-ended when no end date).
function packagePeriod(p: Package): string {
    const end = p.end_date ? formatDate(p.end_date) : t.value.idp.settings.ongoing
    return `${formatDate(p.start_date)} – ${end}`
}

function formatDate(iso: string): string {
    const d = new Date(iso)
    return Number.isNaN(d.getTime())
        ? iso
        : d.toLocaleDateString(locale.value === 'id' ? 'id-ID' : 'en-GB', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
}

/**
 * --------------------------------------------------------------------------
 * Package rows · the models nested under each
 * --------------------------------------------------------------------------
 */

// A package's models, weightiest first, decorated with what the expanded panel
// renders (localized name / description + the palette accent).
function modelsOf(packageId: number) {
    return props.developmentModels
        .filter((m) => m.development_model_package_id === packageId)
        .map((m, i) => ({
            ...m,
            _name: modelName(m),
            _description: modelDescription(m),
            _bar: colorFor(i),
        }))
}

// A package's lifecycle status for the table's Status column.
type PackageStatus = 'active' | 'scheduled' | 'expired'

function packageStatus(pkg: Package): PackageStatus {
    if (pkg.is_active) return 'active'

    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const start = new Date(pkg.start_date)

    // Not active and starts in the future ⇒ scheduled; otherwise its window has
    // passed ⇒ ended.
    return !Number.isNaN(start.getTime()) && start.getTime() > today.getTime()
        ? 'scheduled'
        : 'expired'
}

/**
 * --------------------------------------------------------------------------
 * Search / filter / sort / pagination (client-side)
 * --------------------------------------------------------------------------
 * All packages are already loaded, so the toolbar filters the in-memory list
 * and ClientTable handles sort + pagination over the result.
 */

const packageSearch = ref('')
const filterStatus = ref<'' | PackageStatus>('')

// Sort rank so the Status column sorts active → scheduled → ended (not A–Z).
const statusRank: Record<PackageStatus, number> = {
    active: 0,
    scheduled: 1,
    expired: 2,
}

// Rows enriched with the derived fields ClientTable sorts/filters on.
const decoratedPackages = computed(() =>
    props.packages.map((p) => {
        const status = packageStatus(p)
        return {
            ...p,
            status,
            status_rank: statusRank[status],
            period_label: packagePeriod(p),
        }
    }),
)

type PackageRow = (typeof decoratedPackages.value)[number]

const filteredPackages = computed<PackageRow[]>(() => {
    const q = packageSearch.value.trim().toLowerCase()

    return decoratedPackages.value.filter((p) => {
        if (q && !p.name.toLowerCase().includes(q)) return false
        if (filterStatus.value && p.status !== filterStatus.value) return false
        return true
    })
})

const hasPackageFilters = computed(
    () => !!packageSearch.value || !!filterStatus.value,
)

function clearPackageFilters() {
    packageSearch.value = ''
    filterStatus.value = ''
}

const packageColumns = computed<Column[]>(() => [
    { key: 'expand', label: '', thClass: 'w-12' },
    { key: 'name', label: t.value.idp.settings.packageName, sortable: true },
    { key: 'period_label', label: t.value.idp.settings.colPeriod, sortable: true, sortKey: 'start_date' },
    { key: 'models_count', label: t.value.idp.settings.models, sortable: true, align: 'center' },
    { key: 'total_percentage', label: t.value.idp.settings.colWeight, sortable: true, align: 'center' },
    { key: 'status', label: t.value.idp.settings.colStatus, sortable: true, sortKey: 'status_rank' },
    { key: 'actions', label: t.value.idp.settings.colActions, align: 'right' },
])

/**
 * Which packages have their models showing. Several may be open at once —
 * comparing two periods' weightings side by side is the point of the table.
 */
const expanded = ref<number[]>([])

function toggleExpanded(id: number) {
    const at = expanded.value.indexOf(id)
    if (at === -1) {
        expanded.value.push(id)
    } else {
        expanded.value.splice(at, 1)
    }
}

// Open the active package's models on arrival, so the weighting in force is
// visible without a click.
const activeId = props.packages.find((p) => p.is_active)?.id
if (activeId != null) expanded.value.push(activeId)

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ id: number; name: string } | null>(null)
const deleting = ref(false)

function deletePackage(pkg: Package) {
    // The active/current package can't be deleted — the button is disabled, but
    // guard here too.
    if (pkg.is_active || pkg.is_current) return

    pendingDelete.value = { id: pkg.id, name: pkg.name }
}

function confirmDelete() {
    if (!pendingDelete.value) return

    router.delete(route('idp.setting.packages.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}
</script>

<template>
    <Head :title="t.idp.settings.developmentModelTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.developmentModelTitle"
            :subtitle="t.idp.settings.developmentModelSubtitle"
        >
            <template #actions>
                <Link
                    :href="route('idp.setting.packages.create')"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addPackage }}
                </Link>
            </template>
        </PageHeader>

        <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
            <div class="border-b border-border/60 p-5">
                <div>
                    <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                        {{ t.idp.settings.packages }}
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                            {{ packages.length }}
                        </span>
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.packagesHint }}
                    </p>
                </div>

                <!-- Toolbar: search + filters -->
                <div v-if="packages.length" class="mt-4 flex flex-wrap items-center gap-3">
                    <div class="relative min-w-[200px] flex-1">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="packageSearch"
                            type="text"
                            :placeholder="t.idp.settings.searchPackages"
                            class="w-full rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <select
                        v-model="filterStatus"
                        class="rounded-md border border-border bg-white px-3 py-2 text-sm text-slate-600 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ t.idp.settings.allStatuses }}</option>
                        <option value="active">{{ t.idp.settings.statusActive }}</option>
                        <option value="scheduled">{{ t.idp.settings.statusScheduled }}</option>
                        <option value="expired">{{ t.idp.settings.statusExpired }}</option>
                    </select>

                    <button
                        v-if="hasPackageFilters"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-2 text-sm text-slate-500 transition hover:bg-slate-50"
                        @click="clearPackageFilters"
                    >
                        <i class="fa-solid fa-xmark text-xs" />
                        {{ t.idp.settings.clearFilters }}
                    </button>
                </div>
            </div>

            <!-- Package table. Each row expands to show its development
                 models underneath. -->
            <ClientTable
                v-if="packages.length"
                :columns="packageColumns"
                :rows="filteredPackages"
                row-key="id"
                :per-page="10"
                :initial-sort="{ key: 'start_date', dir: 'desc' }"
                :expanded-keys="expanded"
            >
                <!-- Show / hide this package's models. -->
                <template #cell-expand="{ row }">
                    <button
                        type="button"
                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                        :title="
                            expanded.includes(row.id)
                                ? t.idp.settings.hideModels
                                : t.idp.settings.showModels
                        "
                        :aria-expanded="expanded.includes(row.id)"
                        @click="toggleExpanded(row.id)"
                    >
                        <i
                            class="fa-solid text-xs transition-transform"
                            :class="
                                expanded.includes(row.id)
                                    ? 'fa-chevron-down'
                                    : 'fa-chevron-right'
                            "
                        />
                    </button>
                </template>

                <template #cell-name="{ row }">
                    <div class="font-semibold text-slate-800">
                        {{ row.name }}
                    </div>
                </template>

                <template #cell-period_label="{ row }">
                    <span class="whitespace-nowrap text-slate-500">
                        <i class="fa-regular fa-calendar mr-1 text-slate-400" />
                        {{ row.period_label }}
                    </span>
                </template>

                <template #cell-total_percentage="{ row }">
                    <span
                        class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="
                            row.total_percentage === 100
                                ? 'bg-emerald-50 text-emerald-600'
                                : 'bg-amber-50 text-amber-600'
                        "
                    >
                        {{ row.total_percentage }}%
                    </span>
                </template>

                <!-- Lifecycle status -->
                <template #cell-status="{ row }">
                    <span
                        v-if="row.status === 'active'"
                        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-600"
                        :title="
                            row.is_current
                                ? t.idp.settings.activePinnedHint
                                : t.idp.settings.activeAutoHint
                        "
                    >
                        <i
                            :class="
                                row.is_current
                                    ? 'fa-solid fa-thumbtack'
                                    : 'fa-solid fa-circle-check'
                            "
                            class="text-[9px]"
                        />
                        {{ t.idp.settings.statusActive }}
                    </span>
                    <span
                        v-else-if="row.status === 'scheduled'"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-600"
                    >
                        <i class="fa-regular fa-clock text-[9px]" />
                        {{ t.idp.settings.statusScheduled }}
                    </span>
                    <span
                        v-else
                        class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                    >
                        <i class="fa-regular fa-circle text-[9px]" />
                        {{ t.idp.settings.statusExpired }}
                    </span>
                </template>

                <template #cell-actions="{ row }">
                    <div class="flex items-center justify-end gap-1">
                        <!-- Styled to match IconButton, but a real link: the
                             form is its own page now. -->
                        <Link
                            :href="route('idp.setting.packages.edit', row.id)"
                            class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                            :title="t.idp.settings.editPackage"
                        >
                            <i class="fa-solid fa-pen text-xs" />
                        </Link>
                        <IconButton
                            icon="fa-solid fa-trash"
                            variant="delete"
                            :disabled="row.is_active"
                            :title="
                                row.is_active
                                    ? t.idp.settings.deleteActiveBlocked
                                    : t.idp.settings.deletePackage
                            "
                            @click="deletePackage(row as unknown as Package)"
                        />
                    </div>
                </template>

                <!-- The package's development models, shown under its row. -->
                <template #expanded="{ row }">
                    <div class="px-5 py-4">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                            <h4 class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                                <i class="fa-solid fa-diagram-project text-xs text-slate-400" />
                                {{ t.idp.settings.models }}
                                <span class="text-slate-400">· {{ row.name }}</span>
                            </h4>

                            <Link
                                :href="route('idp.setting.packages.edit', row.id)"
                                class="inline-flex items-center gap-1.5 rounded-md border border-border bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                            >
                                <i class="fa-solid fa-pen text-[10px]" />
                                {{ t.idp.settings.editModels }}
                            </Link>
                        </div>

                        <template v-if="modelsOf(row.id).length">
                            <!-- Stacked weighting bar -->
                            <div class="flex h-2.5 w-full overflow-hidden rounded-full bg-slate-200">
                                <div
                                    v-for="model in modelsOf(row.id)"
                                    :key="model.id"
                                    class="h-full"
                                    :class="model._bar"
                                    :style="{ width: model.percentage + '%' }"
                                    :title="`${model._name} · ${model.percentage}%`"
                                />
                            </div>

                            <!-- One line per model -->
                            <ul class="mt-3 divide-y divide-border/60 overflow-hidden rounded-lg border border-border bg-white">
                                <li
                                    v-for="model in modelsOf(row.id)"
                                    :key="model.id"
                                    class="flex flex-wrap items-start gap-3 px-4 py-3"
                                >
                                    <span
                                        class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full"
                                        :class="model._bar"
                                    />

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-semibold text-slate-800">
                                                {{ model._name }}
                                            </span>
                                            <span
                                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600"
                                            >
                                                {{ model.percentage }}%
                                            </span>

                                            <!-- Draws what it develops from the
                                                 Master Training catalogue. -->
                                            <span
                                                v-if="model.uses_master_training"
                                                class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-[11px] font-semibold text-primary"
                                                :title="t.idp.settings.useMasterTrainingHint"
                                            >
                                                <i class="fa-solid fa-graduation-cap text-[9px]" />
                                                {{ t.idp.settings.useMasterTraining }}
                                            </span>
                                        </div>

                                        <p
                                            v-if="model._description"
                                            class="mt-1 whitespace-pre-wrap break-words text-xs text-slate-500"
                                        >
                                            {{ model._description }}
                                        </p>
                                        <p v-else class="mt-1 text-xs italic text-slate-300">
                                            {{ t.idp.settings.noDescription }}
                                        </p>
                                    </div>

                                    <!-- Usage: why a model may not be removable. -->
                                    <div class="flex shrink-0 items-center gap-4 text-xs text-slate-500">
                                        <span
                                            class="inline-flex items-center gap-1"
                                            :title="t.idp.settings.programs"
                                        >
                                            <i class="fa-solid fa-book-open text-[11px] text-slate-400" />
                                            {{ model.development_programs_count }}
                                        </span>
                                        <span
                                            class="inline-flex items-center gap-1"
                                            :title="t.idp.settings.plansInUse"
                                        >
                                            <i class="fa-solid fa-user-check text-[11px] text-slate-400" />
                                            {{ model.individual_development_plans_count }}
                                        </span>
                                    </div>
                                </li>
                            </ul>
                        </template>

                        <!-- A package always saves at 100%, so this only shows
                             for legacy rows saved before that rule. -->
                        <div
                            v-else
                            class="rounded-lg border border-dashed border-border bg-white px-6 py-6 text-center text-sm text-slate-400"
                        >
                            {{ t.idp.settings.emptyTitle }}
                        </div>
                    </div>
                </template>

                <template #empty>
                    {{ t.idp.settings.noMatchingPackages }}
                </template>
            </ClientTable>

            <!-- No packages yet -->
            <div
                v-else
                class="m-5 rounded-xl border border-dashed border-border px-6 py-14 text-center"
            >
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <i class="fa-solid fa-box-open text-xl" />
                </div>
                <h4 class="mt-4 font-semibold text-slate-700">
                    {{ t.idp.settings.noPackages }}
                </h4>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-400">
                    {{ t.idp.settings.emptyBody }}
                </p>
                <Link
                    :href="route('idp.setting.packages.create')"
                    class="mt-5 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addPackage }}
                </Link>
            </div>
        </section>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->

        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.idp.settings.deleteTitle"
            :message="t.idp.settings.confirmDelete"
            :confirm-label="t.idp.settings.delete"
            :cancel-label="t.idp.form.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete?.name"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ pendingDelete.name }}
            </p>
        </ConfirmDialog>
    </AppLayout>
</template>
