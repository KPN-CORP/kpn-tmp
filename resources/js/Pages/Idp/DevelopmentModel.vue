<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface Model {
    id: number
    development_model_package_id: number
    name: string
    name_en: string | null
    name_id: string | null
    percentage: number
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
 * After a create/update/delete the server redirects back here. We only need
 * this page's own data (and the flash message) refreshed — restricting the
 * reload to these props turns every save into an Inertia partial reload, so the
 * expensive shared props (corporate employee lookup, approval counts, the
 * notification feed) are not re-evaluated on each mutation.
 */
const reloadOnly = ['packages', 'developmentModels', 'flash']

/**
 * --------------------------------------------------------------------------
 * Development model
 * --------------------------------------------------------------------------
 */

// Accent palette cycled across model cards / the weighting bar so each
// weighting (e.g. 70-20-10) is visually distinct. Class strings are kept
// literal so Tailwind can see them.
const modelPalette = [
    { bar: 'bg-indigo-500', soft: 'bg-indigo-50', text: 'text-indigo-600', ring: 'ring-indigo-100' },
    { bar: 'bg-sky-500', soft: 'bg-sky-50', text: 'text-sky-600', ring: 'ring-sky-100' },
    { bar: 'bg-amber-500', soft: 'bg-amber-50', text: 'text-amber-600', ring: 'ring-amber-100' },
    { bar: 'bg-emerald-500', soft: 'bg-emerald-50', text: 'text-emerald-600', ring: 'ring-emerald-100' },
    { bar: 'bg-rose-500', soft: 'bg-rose-50', text: 'text-rose-600', ring: 'ring-rose-100' },
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
function modelName(model: {
    name: string
    name_en?: string | null
    name_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? model.name_id : model.name_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : model.name
}

const modelModal = ref(false)
const editingModelId = ref<number | null>(null)

const modelForm = useForm({
    development_model_package_id: null as number | null,
    name_en: '',
    name_id: '',
    percentage: 10,
    description_en: '',
    description_id: '',
})

function openModel(model?: Model) {
    editingModelId.value = model?.id ?? null

    modelForm.clearErrors()

    // A new model belongs to the package currently being viewed; editing keeps
    // the model in its own package.
    modelForm.development_model_package_id =
        model?.development_model_package_id ?? selectedPackageId.value

    modelForm.name_en = model?.name_en ?? ''
    modelForm.name_id = model?.name_id ?? ''
    modelForm.percentage = model?.percentage ?? 10
    modelForm.description_en = model?.description_en ?? ''
    modelForm.description_id = model?.description_id ?? ''

    modelModal.value = true
}

function submitModel() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (modelModal.value = false),
    }

    if (editingModelId.value) {
        modelForm.put(`/idp-setting/models/${editingModelId.value}`, opts)
    } else {
        modelForm.post('/idp-setting/models', opts)
    }
}

// Percentage accepts whole numbers only — block the characters a `number`
// input would otherwise allow (exponent, sign, decimal point).
function blockNonNumeric(e: KeyboardEvent) {
    if (['e', 'E', '+', '-', '.', ','].includes(e.key)) e.preventDefault()
}

function deleteModel(model: Model) {
    pendingDelete.value = {
        url: `/idp-setting/models/${model.id}`,
        name: model.name,
    }
}

/**
 * --------------------------------------------------------------------------
 * Development model packages (period-scoped bundles)
 * --------------------------------------------------------------------------
 */

// The default package to show: the first one active today, else the first row.
const defaultPackageId = (list: Package[]): number | null =>
    list.find((p) => p.is_active)?.id ?? list[0]?.id ?? null

// The package whose models the page is currently showing.
const selectedPackageId = ref<number | null>(defaultPackageId(props.packages))

// Keep the selection valid as packages are added/removed.
watch(
    () => props.packages,
    (list) => {
        if (!list.some((p) => p.id === selectedPackageId.value)) {
            selectedPackageId.value = defaultPackageId(list)
        }
    },
)

const selectedPackage = computed<Package | null>(
    () => props.packages.find((p) => p.id === selectedPackageId.value) ?? null,
)

// The package a model in the add/edit drawer belongs to (shown read-only).
const modelFormPackageName = computed(
    () =>
        props.packages.find(
            (p) => p.id === modelForm.development_model_package_id,
        )?.name ?? '',
)

// Models belonging to the selected package.
const packageModels = computed<Model[]>(() =>
    selectedPackageId.value == null
        ? []
        : props.developmentModels.filter(
              (m) => m.development_model_package_id === selectedPackageId.value,
          ),
)

const packageTotalPercentage = computed(() =>
    packageModels.value.reduce((sum, m) => sum + m.percentage, 0),
)

// Selected package's models as ClientTable rows: carry the localized name /
// description ClientTable sorts + renders, plus the palette accent used for the
// name dot (matched to the weighting bar above).
const modelRows = computed(() =>
    packageModels.value.map((m, i) => ({
        ...m,
        _name: modelName(m),
        _description: modelDescription(m),
        _bar: colorFor(i).bar,
    })),
)

const modelColumns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.name, sortable: true, sortKey: '_name', thClass: 'w-64' },
    { key: 'percentage', label: t.value.idp.settings.percentage, sortable: true, align: 'center', thClass: 'w-28' },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'programs', label: t.value.idp.settings.programs, sortable: true, sortKey: 'development_programs_count', align: 'center', thClass: 'w-24' },
    { key: 'plans', label: t.value.idp.settings.plansInUse, sortable: true, sortKey: 'individual_development_plans_count', align: 'center', thClass: 'w-24' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

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

const packageModal = ref(false)
const editingPackageId = ref<number | null>(null)

const packageForm = useForm({
    name: '',
    start_date: '',
    end_date: '',
    is_current: false,
})

function openPackage(pkg?: Package) {
    editingPackageId.value = pkg?.id ?? null

    packageForm.clearErrors()
    packageForm.name = pkg?.name ?? ''
    packageForm.start_date = pkg?.start_date ?? ''
    packageForm.end_date = pkg?.end_date ?? ''
    packageForm.is_current = pkg?.is_current ?? false

    packageModal.value = true
}

function submitPackage() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (packageModal.value = false),
    }

    if (editingPackageId.value) {
        packageForm.put(`/idp-setting/packages/${editingPackageId.value}`, opts)
    } else {
        packageForm.post('/idp-setting/packages', opts)
    }
}

// A package can only be pinned active while its period covers today. Mirror the
// server rule so the checkbox is disabled (and any stale tick cleared) the
// moment the chosen dates no longer include today.
const packageValidToday = computed(() => {
    if (!packageForm.start_date) return false

    const today = new Date()
    today.setHours(0, 0, 0, 0)

    const start = new Date(packageForm.start_date)
    if (Number.isNaN(start.getTime()) || start.getTime() > today.getTime()) {
        return false
    }

    if (packageForm.end_date) {
        const end = new Date(packageForm.end_date)
        if (Number.isNaN(end.getTime()) || end.getTime() < today.getTime()) {
            return false
        }
    }

    return true
})

watch(packageValidToday, (valid) => {
    if (!valid) packageForm.is_current = false
})

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
 * Package table · search / filter / sort / pagination (client-side)
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
    { key: 'name', label: t.value.idp.settings.packageName, sortable: true },
    { key: 'period_label', label: t.value.idp.settings.colPeriod, sortable: true, sortKey: 'start_date' },
    { key: 'models_count', label: t.value.idp.settings.models, sortable: true, align: 'center' },
    { key: 'total_percentage', label: t.value.idp.settings.colWeight, sortable: true, align: 'center' },
    { key: 'status', label: t.value.idp.settings.colStatus, sortable: true, sortKey: 'status_rank' },
    { key: 'actions', label: t.value.idp.settings.colActions, align: 'right' },
])

function deletePackage(pkg: Package) {
    // The active/current package can't be deleted — the button is disabled, but
    // guard here too.
    if (pkg.is_active || pkg.is_current) return

    pendingDelete.value = {
        url: `/idp-setting/packages/${pkg.id}`,
        name: pkg.name,
    }
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation (shared dialog)
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function confirmDelete() {
    if (!pendingDelete.value) return

    router.delete(pendingDelete.value.url, {
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
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Packages (period-scoped bundles)
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="border-b border-border/60 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
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

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openPackage()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addPackage }}
                        </button>
                    </div>

                    <!-- Toolbar: search + filters -->
                    <div
                        v-if="packages.length"
                        class="mt-4 flex flex-wrap items-center gap-3"
                    >
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

                <!-- Package table (sort · filter · search · pagination) -->
                <ClientTable
                    v-if="packages.length"
                    :columns="packageColumns"
                    :rows="filteredPackages"
                    row-key="id"
                    :per-page="10"
                    :initial-sort="{ key: 'start_date', dir: 'desc' }"
                    selectable
                    :selected-key="selectedPackageId"
                    @row-click="selectedPackageId = ($event as { id: number }).id"
                >
                        <template #cell-name="{ row }">
                            <div class="flex items-center gap-2 font-semibold text-slate-800">
                                <span
                                    v-if="row.id === selectedPackageId"
                                    class="h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                                />
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
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editPackage"
                                    @click.stop="openPackage(row as unknown as Package)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :disabled="row.is_active"
                                    :title="
                                        row.is_active
                                            ? t.idp.settings.deleteActiveBlocked
                                            : t.idp.settings.deletePackage
                                    "
                                    @click.stop="deletePackage(row as unknown as Package)"
                                />
                            </div>
                        </template>

                        <template #empty>
                            {{ t.idp.settings.noMatchingPackages }}
                        </template>
                    </ClientTable>

                <!-- No packages yet -->
                <div
                    v-else
                    class="m-5 rounded-lg border border-dashed border-border px-6 py-8 text-center text-sm text-slate-400"
                >
                    {{ t.idp.settings.noPackages }}
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 Selected package · weighting summary + models
            ------------------------------------------------------------- -->
            <template v-if="selectedPackage">
                <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                    <div class="border-b border-border/60 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-800">
                                {{ t.idp.settings.models }}
                                <span class="text-slate-400">· {{ selectedPackage.name }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                    {{ packageModels.length }}
                                </span>
                            </h3>
                            <p class="mt-0.5 text-sm text-slate-400">
                                {{ t.idp.settings.modelsHint }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openModel()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addModel }}
                        </button>
                    </div>

                    <!-- Stacked weighting bar -->
                    <div v-if="packageModels.length" class="mt-5">
                        <div
                            class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100"
                        >
                            <div
                                v-for="(model, i) in packageModels"
                                :key="model.id"
                                class="h-full transition-all duration-300"
                                :class="colorFor(i).bar"
                                :style="{ width: model.percentage + '%' }"
                                :title="`${model.name} · ${model.percentage}%`"
                            />
                        </div>

                        <div
                            class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
                        >
                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span
                                    v-for="(model, i) in packageModels"
                                    :key="model.id"
                                    class="flex items-center gap-1.5 text-xs text-slate-500"
                                >
                                    <span
                                        class="h-2.5 w-2.5 rounded-full"
                                        :class="colorFor(i).bar"
                                    />
                                    {{ modelName(model) }}
                                    <span class="font-semibold text-slate-700">
                                        {{ model.percentage }}%
                                    </span>
                                </span>
                            </div>

                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                                :class="
                                    packageTotalPercentage === 100
                                        ? 'bg-emerald-50 text-emerald-600'
                                        : 'bg-amber-50 text-amber-600'
                                "
                            >
                                <i
                                    :class="
                                        packageTotalPercentage === 100
                                            ? 'fa-solid fa-circle-check'
                                            : 'fa-solid fa-triangle-exclamation'
                                    "
                                />
                                {{ t.idp.settings.total }} {{ packageTotalPercentage }}% ·
                                {{
                                    packageTotalPercentage === 100
                                        ? t.idp.settings.balanced
                                        : t.idp.settings.adjust
                                }}
                            </span>
                        </div>
                    </div>
                    </div>

                    <!-- Model table -->
                    <ClientTable
                        v-if="packageModels.length"
                        :columns="modelColumns"
                        :rows="modelRows"
                        row-key="id"
                        :per-page="10"
                        numbered
                    >
                        <template #cell-name="{ row }">
                            <div class="flex items-center gap-2 font-semibold text-slate-800">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="row._bar" />
                                {{ row._name }}
                            </div>
                        </template>

                        <template #cell-percentage="{ row }">
                            <span
                                class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-bold text-slate-600"
                            >
                                {{ row.percentage }}%
                            </span>
                        </template>

                        <template #cell-description="{ row }">
                            <span
                                v-if="row._description"
                                class="whitespace-pre-wrap break-words text-slate-500"
                            >
                                {{ row._description }}
                            </span>
                            <span v-else class="text-xs italic text-slate-300">
                                {{ t.idp.settings.noDescription }}
                            </span>
                        </template>

                        <template #cell-programs="{ row }">
                            <span class="inline-flex items-center gap-1 text-slate-500">
                                <i class="fa-solid fa-book-open text-[11px] text-slate-400" />
                                {{ row.development_programs_count }}
                            </span>
                        </template>

                        <template #cell-plans="{ row }">
                            <span class="inline-flex items-center gap-1 text-slate-500">
                                <i class="fa-solid fa-user-check text-[11px] text-slate-400" />
                                {{ row.individual_development_plans_count }}
                            </span>
                        </template>

                        <template #cell-actions="{ row }">
                            <div class="flex items-center justify-end gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editModel"
                                    @click="openModel(row as unknown as Model)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteModel"
                                    @click="deleteModel(row as unknown as Model)"
                                />
                            </div>
                        </template>
                    </ClientTable>

                <!-- Package has no models yet -->
                <div
                    v-else
                    class="m-5 rounded-xl border border-dashed border-border bg-white px-6 py-14 text-center"
                >
                    <div
                        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"
                    >
                        <i class="fa-solid fa-diagram-project text-xl" />
                    </div>
                    <h4 class="mt-4 font-semibold text-slate-700">
                        {{ t.idp.settings.emptyTitle }}
                    </h4>
                    <p class="mx-auto mt-1 max-w-md text-sm text-slate-400">
                        {{ t.idp.settings.emptyBody }}
                    </p>
                    <button
                        type="button"
                        class="mt-5 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openModel()"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.addModel }}
                    </button>
                </div>
                </section>
            </template>
        </div>

        <!-- ================================================================
             PACKAGE MODAL
        ================================================================= -->

        <Drawer
            :show="packageModal"
            :title="
                editingPackageId
                    ? t.idp.settings.editPackage
                    : t.idp.settings.addPackage
            "
            @close="packageModal = false"
        >
            <form
                id="package-form"
                class="space-y-4"
                @submit.prevent="submitPackage"
            >
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.packageName }}
                    </label>
                    <input
                        v-model="packageForm.name"
                        :placeholder="t.idp.settings.packageNamePlaceholder"
                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="
                            packageForm.errors.name
                                ? 'border-red-500'
                                : 'border-border'
                        "
                    >
                    <p v-if="packageForm.errors.name" class="mt-1 text-xs text-red-600">
                        {{ packageForm.errors.name }}
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.startDate }}
                        </label>
                        <DateInput
                            v-model="packageForm.start_date"
                            :invalid="!!packageForm.errors.start_date"
                        />
                        <p v-if="packageForm.errors.start_date" class="mt-1 text-xs text-red-600">
                            {{ packageForm.errors.start_date }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.endDate }}
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>
                        <DateInput
                            v-model="packageForm.end_date"
                            :invalid="!!packageForm.errors.end_date"
                        />
                        <p v-if="packageForm.errors.end_date" class="mt-1 text-xs text-red-600">
                            {{ packageForm.errors.end_date }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-start gap-2.5 rounded-lg border p-3"
                    :class="
                        packageValidToday
                            ? 'border-border bg-slate-50/60'
                            : 'cursor-not-allowed border-border bg-slate-100/60 opacity-70'
                    "
                >
                    <input
                        v-model="packageForm.is_current"
                        type="checkbox"
                        :disabled="!packageValidToday"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary disabled:cursor-not-allowed"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-slate-700">
                            {{ t.idp.settings.setCurrent }}
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-400">
                            {{ t.idp.settings.setCurrentHint }}
                        </span>
                        <!-- Why the pin is unavailable for these dates. -->
                        <span
                            v-if="!packageValidToday"
                            class="mt-1 block text-xs font-medium text-amber-600"
                        >
                            <i class="fa-solid fa-triangle-exclamation mr-1" />
                            {{ t.idp.settings.setCurrentUnavailable }}
                        </span>
                    </span>
                </label>
                <p v-if="packageForm.errors.is_current" class="text-xs text-red-600">
                    {{ packageForm.errors.is_current }}
                </p>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="packageModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="package-form"
                    :disabled="packageForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             DEVELOPMENT MODEL MODAL
        ================================================================= -->

        <Drawer
            :show="modelModal"
            :title="
                editingModelId
                    ? t.idp.settings.editModel
                    : t.idp.settings.addModel
            "
            @close="modelModal = false"
        >
            <form
                id="model-form"
                class="space-y-4"
                @submit.prevent="submitModel"
            >
                <!-- Target package: which package this model belongs to.
                     Fixed to the package the model is created under / lives in. -->
                <div
                    class="flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5 text-sm"
                >
                    <i class="fa-solid fa-box-open text-primary" />
                    <span class="text-slate-500">{{ t.idp.settings.targetPackage }}:</span>
                    <span class="font-semibold text-slate-800">
                        {{ modelFormPackageName || '—' }}
                    </span>
                </div>

                <!-- English section: name + description -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                        >
                            EN
                        </span>
                        <span class="text-sm font-semibold text-slate-700">
                            {{ t.idp.settings.english }}
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.name }}
                            </label>
                            <input
                                v-model="modelForm.name_en"
                                :placeholder="t.idp.settings.namePlaceholderEn"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    modelForm.errors.name_en
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="modelForm.errors.name_en"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ modelForm.errors.name_en }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="modelForm.description_en"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>

                <!-- Bahasa Indonesia section: name + description -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <div class="mb-3 flex items-center gap-2">
                        <span
                            class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                        >
                            ID
                        </span>
                        <span class="text-sm font-semibold text-slate-700">
                            {{ t.idp.settings.bahasa }}
                        </span>
                    </div>

                    <div class="space-y-3">
                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.name }}
                            </label>
                            <input
                                v-model="modelForm.name_id"
                                :placeholder="t.idp.settings.namePlaceholderId"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    modelForm.errors.name_id
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="modelForm.errors.name_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ modelForm.errors.name_id }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="modelForm.description_id"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>

                <!-- Percentage (whole numbers only) -->
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.percentage }}
                    </label>

                    <div class="relative w-40">
                        <input
                            v-model.number="modelForm.percentage"
                            type="number"
                            min="1"
                            max="100"
                            step="1"
                            inputmode="numeric"
                            class="w-full rounded-md border px-3 py-2 pr-8 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :class="
                                modelForm.errors.percentage
                                    ? 'border-red-500'
                                    : 'border-border'
                            "
                            @keydown="blockNonNumeric"
                        >
                        <span
                            class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                        >
                            %
                        </span>
                    </div>

                    <p
                        v-if="modelForm.errors.percentage"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ modelForm.errors.percentage }}
                    </p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="modelModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="model-form"
                    :disabled="modelForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

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
