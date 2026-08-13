<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import Pagination from '@/Components/UI/Pagination.vue'
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

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    related_program: number[]
    linked_programs: string[]
}

interface CompetencyType {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competencies_count: number
}

interface Program {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    development_model_id: number | null
    model_name: string | null
}

interface ReviewTool {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

const props = defineProps<{
    developmentModels: Model[]
    packages: Package[]
    activePackageId: number | null
    competencies: Competency[]
    competencyTypes: CompetencyType[]
    developmentPrograms: Program[]
    reviewTools: ReviewTool[]
}>()

/**
 * --------------------------------------------------------------------------
 * Tabs
 * --------------------------------------------------------------------------
 */

type Tab =
    | 'development-model'
    | 'competency'
    | 'development-program'
    | 'review-tools'

const activeTab = ref<Tab>('development-model')

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

const modelById = computed(() => {
    const m = new Map<number, Model>()
    for (const mod of props.developmentModels) m.set(mod.id, mod)
    return m
})

function modelNameById(id: number | null): string {
    if (id == null) return ''
    const m = modelById.value.get(id)
    return m ? modelName(m) : ''
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
        onSuccess: () => (modelModal.value = false),
    }

    if (editingModelId.value) {
        modelForm.put(
            `/idp-setting/models/${editingModelId.value}`,
            opts,
        )
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

// The package whose models tab 1 is currently showing. Defaults to the active
// package (falling back to the first available).
const selectedPackageId = ref<number | null>(
    props.activePackageId ?? props.packages[0]?.id ?? null,
)

// Keep the selection valid as packages are added/removed.
watch(
    () => props.packages,
    (list) => {
        if (!list.some((p) => p.id === selectedPackageId.value)) {
            selectedPackageId.value = props.activePackageId ?? list[0]?.id ?? null
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

// Models belonging to the selected package (tab 1 is package-scoped).
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
        onSuccess: () => (packageModal.value = false),
    }

    if (editingPackageId.value) {
        packageForm.put(`/idp-setting/packages/${editingPackageId.value}`, opts)
    } else {
        packageForm.post('/idp-setting/packages', opts)
    }
}

function deletePackage(pkg: Package) {
    pendingDelete.value = {
        url: `/idp-setting/packages/${pkg.id}`,
        name: pkg.name,
    }
}

/**
 * --------------------------------------------------------------------------
 * Master data
 * --------------------------------------------------------------------------
 */

type MasterType =
    | 'competency_name'
    | 'competency_type'
    | 'development_program'
    | 'review_tools'

const masterModal = ref(false)
const masterType = ref<MasterType>('competency_name')
const editingMasterId = ref<number | null>(null)

// Both competencies and competency types carry a bilingual description.
const masterHasDescription = computed(
    () =>
        masterType.value === 'competency_name' ||
        masterType.value === 'competency_type',
)

const masterForm = useForm({
    type: 'competency_name' as MasterType,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
    development_model_id: null as number | null,
    // Competency → competency type.
    competency_type_id: null as number | null,
    // Program → competencies. Linking now happens from the program side; the
    // competency form only carries name + description.
    related_competencies: [] as number[],
})

// Localized name for a competency / program, falling back to the canonical value.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized competency description (falls back to the other language).
function competencyDescription(c: Competency): string {
    const preferred =
        locale.value === 'id' ? c.description_id : c.description_en
    const fallback =
        locale.value === 'id' ? c.description_en : c.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

function openMaster(
    type: MasterType,
    item?: Competency | CompetencyType | Program | ReviewTool,
) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    masterForm.type = type

    const localized = item as Partial<Competency & Program> | undefined
    masterForm.value_en = localized?.value_en ?? item?.value ?? ''
    masterForm.value_id = localized?.value_id ?? ''
    masterForm.description_en = (item as Competency)?.description_en ?? ''
    masterForm.description_id = (item as Competency)?.description_id ?? ''

    masterForm.development_model_id =
        (item as Program)?.development_model_id ?? null

    masterForm.competency_type_id =
        (item as Competency)?.competency_type_id ?? null

    // Preselect the competencies this program is currently linked to.
    masterForm.related_competencies =
        type === 'development_program' && item
            ? props.competencies
                  .filter((c) =>
                      c.related_program.includes((item as Program).id),
                  )
                  .map((c) => c.id)
            : []

    masterModal.value = true
}

function submitMaster() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (masterModal.value = false),
    }

    if (editingMasterId.value) {
        masterForm.put(
            `/idp-setting/masters/${editingMasterId.value}`,
            opts,
        )
    } else {
        masterForm.post('/idp-setting/masters', opts)
    }
}

function deleteMaster(id: number, name?: string) {
    pendingDelete.value = {
        url: `/idp-setting/masters/${id}`,
        name,
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
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}

const masterTitle = () => {
    const type =
        masterType.value === 'competency_name'
            ? t.value.idp.settings.competency
            : masterType.value === 'competency_type'
              ? t.value.idp.settings.competencyType
              : masterType.value === 'development_program'
                ? t.value.idp.settings.program
                : t.value.idp.settings.reviewTool

    const prefix = editingMasterId.value
        ? t.value.idp.settings.edit
        : t.value.idp.settings.add

    return `${prefix} ${type}`
}

// Development models as dropdown options — existing models only (no empty
// entry), with the weighting shown after the name so it's clear at a glance.
const modelOptions = computed<Option[]>(() =>
    props.developmentModels.map((m) => ({
        value: String(m.id),
        label: `${modelName(m)} (${m.percentage}%)`,
    })),
)

// Competencies as MultiSelect options (string values — MultiSelect binds string[]).
const competencyOptions = computed<Option[]>(() =>
    props.competencies.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

// Bridge the numeric related_competencies to MultiSelect's string[] model.
const relatedCompetencyValues = computed<string[]>({
    get: () => masterForm.related_competencies.map(String),
    set: (vals) => (masterForm.related_competencies = vals.map(Number)),
})

/**
 * --------------------------------------------------------------------------
 * Competency types
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const ct of props.competencyTypes) m.set(ct.id, ct)
    return m
})

// Localized name for the competency type linked to a competency (or '').
function competencyTypeName(id: number | null): string {
    if (id == null) return ''
    const ct = competencyTypeById.value.get(id)
    return ct ? masterName(ct) : ''
}

// Localized description of a competency type (falls back to the other language).
function typeDescription(ct: CompetencyType): string {
    const preferred =
        locale.value === 'id' ? ct.description_id : ct.description_en
    const fallback =
        locale.value === 'id' ? ct.description_en : ct.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

// Competency types as dropdown options for the competency form.
const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((ct) => ({
        value: String(ct.id),
        label: masterName(ct),
    })),
)

// Selected competency-type filter for the competencies table (null = all;
// 0 = the "untyped" bucket).
const selectedTypeFilter = ref<number | null>(null)

// Keep the filter valid as types are added/removed.
watch(
    () => props.competencyTypes,
    (list) => {
        if (
            selectedTypeFilter.value !== null &&
            selectedTypeFilter.value !== 0 &&
            !list.some((ct) => ct.id === selectedTypeFilter.value)
        ) {
            selectedTypeFilter.value = null
        }
    },
)

// Competencies with no type assigned (drives the "Untyped" filter chip).
const untypedCompetencyCount = computed(
    () => props.competencies.filter((c) => c.competency_type_id == null).length,
)

/**
 * --------------------------------------------------------------------------
 * Review tools (tab 3) — client-side search + pagination
 * --------------------------------------------------------------------------
 */

const reviewSearch = ref('')
const reviewPage = ref(1)
const reviewPerPage = ref(10)

// null = original order; cycles null → asc → desc → null.
const reviewSort = ref<'asc' | 'desc' | null>(null)

function toggleReviewSort() {
    reviewSort.value =
        reviewSort.value === 'asc'
            ? 'desc'
            : reviewSort.value === 'desc'
              ? null
              : 'asc'
}

const filteredReviewTools = computed(() => {
    const q = reviewSearch.value.trim().toLowerCase()
    return q
        ? props.reviewTools.filter(
              (r) =>
                  masterName(r).toLowerCase().includes(q) ||
                  r.value.toLowerCase().includes(q),
          )
        : props.reviewTools
})

const sortedReviewTools = computed(() => {
    if (!reviewSort.value) return filteredReviewTools.value

    const dir = reviewSort.value === 'asc' ? 1 : -1
    return [...filteredReviewTools.value].sort(
        (a, b) => masterName(a).localeCompare(masterName(b)) * dir,
    )
})

const reviewTotalPages = computed(() =>
    Math.max(1, Math.ceil(filteredReviewTools.value.length / reviewPerPage.value)),
)

const pagedReviewTools = computed(() => {
    const start = (reviewPage.value - 1) * reviewPerPage.value
    return sortedReviewTools.value.slice(start, start + reviewPerPage.value)
})

const reviewFrom = computed(() =>
    filteredReviewTools.value.length === 0
        ? 0
        : (reviewPage.value - 1) * reviewPerPage.value + 1,
)

// Reset to the first page on a new search, and keep the page in range as the
// (filtered) list shrinks — e.g. after a delete.
watch(reviewSearch, () => (reviewPage.value = 1))
watch(reviewTotalPages, (total) => {
    if (reviewPage.value > total) reviewPage.value = total
})

/**
 * --------------------------------------------------------------------------
 * Competency ↔ Program relation (tab 2)
 * --------------------------------------------------------------------------
 * The relation table is competency-centric: each competency lists its linked
 * programs grouped by development model. All derived on the client from the
 * data already shared — no extra request.
 */

const competencySearch = ref('')

const modelIndexById = computed(() => {
    const m = new Map<number, number>()
    props.developmentModels.forEach((mod, i) => m.set(mod.id, i))
    return m
})

const modelPercentById = computed(() => {
    const m = new Map<number, number>()
    for (const mod of props.developmentModels) m.set(mod.id, mod.percentage)
    return m
})

const neutralColor = {
    bar: 'bg-slate-400',
    soft: 'bg-slate-100',
    text: 'text-slate-500',
    ring: 'ring-slate-100',
}

const groupColor = (i: number) => (i < 0 ? neutralColor : colorFor(i))

/**
 * Competency tab — the competency list, filtered by the active type chip and
 * the search box. Programs are managed on their own tab.
 */
const competencyRows = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()
    const typeFilter = selectedTypeFilter.value

    return props.competencies.filter((c) => {
        // Competency-type filter (0 = untyped bucket).
        if (typeFilter !== null) {
            if (typeFilter === 0) {
                if (c.competency_type_id != null) return false
            } else if (c.competency_type_id !== typeFilter) {
                return false
            }
        }

        if (!q) return true
        return (
            masterName(c).toLowerCase().includes(q) ||
            c.value.toLowerCase().includes(q)
        )
    })
})

// Column-sort helper shared by the competency and program tables. Clicking a
// column cycles asc → desc → off; clicking another column starts it at asc.
type SortDir = 'asc' | 'desc'
interface SortState {
    key: string
    dir: SortDir
}
function nextSort(current: SortState | null, key: string): SortState | null {
    if (current?.key !== key) return { key, dir: 'asc' }
    if (current.dir === 'asc') return { key, dir: 'desc' }
    return null
}
function sortIcon(state: SortState | null, key: string): string {
    if (state?.key !== key) return 'fa-solid fa-sort text-slate-300'
    return state.dir === 'asc'
        ? 'fa-solid fa-sort-up text-primary'
        : 'fa-solid fa-sort-down text-primary'
}

const competencySort = ref<SortState | null>(null)
function toggleCompetencySort(key: string) {
    competencySort.value = nextSort(competencySort.value, key)
}

const sortedCompetencies = computed(() => {
    const s = competencySort.value
    if (!s) return competencyRows.value
    const dir = s.dir === 'asc' ? 1 : -1
    const val = (c: Competency) =>
        s.key === 'type'
            ? competencyTypeName(c.competency_type_id)
            : masterName(c)
    return [...competencyRows.value].sort(
        (a, b) => val(a).localeCompare(val(b)) * dir,
    )
})

const competencyPage = ref(1)
const competencyPerPage = ref(10)

const competencyTotalPages = computed(() =>
    Math.max(1, Math.ceil(competencyRows.value.length / competencyPerPage.value)),
)

const pagedCompetencies = computed(() => {
    const start = (competencyPage.value - 1) * competencyPerPage.value
    return sortedCompetencies.value.slice(start, start + competencyPerPage.value)
})

const competencyFrom = computed(() =>
    competencyRows.value.length === 0
        ? 0
        : (competencyPage.value - 1) * competencyPerPage.value + 1,
)

watch([competencySearch, selectedTypeFilter], () => (competencyPage.value = 1))
watch(competencyTotalPages, (total) => {
    if (competencyPage.value > total) competencyPage.value = total
})

/**
 * --------------------------------------------------------------------------
 * Development program tab — program-centric list with each program's model
 * and the competencies linked to it (linking is edited from the program form).
 * --------------------------------------------------------------------------
 */

const programSearch = ref('')

interface ProgramRow {
    program: Program
    modelName: string
    percentage: number | null
    colorIndex: number
    competencies: Competency[]
}

const programRows = computed<ProgramRow[]>(() => {
    const q = programSearch.value.trim().toLowerCase()

    return props.developmentPrograms
        .map((p) => ({
            program: p,
            modelName:
                modelNameById(p.development_model_id) || (p.model_name ?? ''),
            percentage:
                p.development_model_id == null
                    ? null
                    : modelPercentById.value.get(p.development_model_id) ?? null,
            colorIndex:
                p.development_model_id == null
                    ? -1
                    : modelIndexById.value.get(p.development_model_id) ?? -1,
            competencies: props.competencies.filter((c) =>
                c.related_program.includes(p.id),
            ),
        }))
        .filter((row) => {
            if (!q) return true
            if (masterName(row.program).toLowerCase().includes(q)) return true
            if (row.program.value.toLowerCase().includes(q)) return true
            return row.competencies.some((c) =>
                masterName(c).toLowerCase().includes(q),
            )
        })
})

const programSort = ref<SortState | null>(null)
function toggleProgramSort(key: string) {
    programSort.value = nextSort(programSort.value, key)
}

const sortedPrograms = computed(() => {
    const s = programSort.value
    if (!s) return programRows.value
    const dir = s.dir === 'asc' ? 1 : -1
    const val = (r: ProgramRow) =>
        s.key === 'model' ? r.modelName : masterName(r.program)
    return [...programRows.value].sort(
        (a, b) => val(a).localeCompare(val(b)) * dir,
    )
})

const programPage = ref(1)
const programPerPage = ref(10)

const programTotalPages = computed(() =>
    Math.max(1, Math.ceil(programRows.value.length / programPerPage.value)),
)

const pagedPrograms = computed(() => {
    const start = (programPage.value - 1) * programPerPage.value
    return sortedPrograms.value.slice(start, start + programPerPage.value)
})

const programFrom = computed(() =>
    programRows.value.length === 0
        ? 0
        : (programPage.value - 1) * programPerPage.value + 1,
)

watch(programSearch, () => (programPage.value = 1))
watch(programTotalPages, (total) => {
    if (programPage.value > total) programPage.value = total
})
</script>

<template>
    <Head :title="t.idp.settings.title" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.title"
            :subtitle="t.idp.settings.subtitle"
        />

        <!-- ================================================================
            Tabs
        ================================================================= -->

        <div class="mb-6 rounded-xl border border-border bg-white p-1.5 shadow-sm">
            <nav
                class="grid grid-cols-1 gap-1 sm:grid-cols-2 lg:grid-cols-4"
                aria-label="Settings tabs"
            >
                <!-- Development Model -->
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeTab === 'development-model'
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeTab = 'development-model'"
                >
                    <i class="fa-solid fa-diagram-project" />

                    <span>
                        Development Model
                    </span>
                </button>

                <!-- Competency -->
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeTab === 'competency'
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeTab = 'competency'"
                >
                    <i class="fa-solid fa-lightbulb" />

                    <span>
                        {{ t.idp.settings.competencies }}
                    </span>
                </button>

                <!-- Development Program -->
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeTab === 'development-program'
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeTab = 'development-program'"
                >
                    <i class="fa-solid fa-layer-group" />

                    <span>
                        {{ t.idp.settings.programs }}
                    </span>
                </button>

                <!-- Review Tools -->
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeTab === 'review-tools'
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeTab = 'review-tools'"
                >
                    <i class="fa-solid fa-clipboard-check" />

                    <span>
                        Review Tools
                    </span>
                </button>
            </nav>
        </div>

        <!-- ================================================================
             TAB 1: DEVELOPMENT MODEL
        ================================================================= -->

        <div v-if="activeTab === 'development-model'" class="space-y-6">
            <!-- ------------------------------------------------------------
                 Packages (period-scoped bundles)
            ------------------------------------------------------------- -->
            <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-800">
                            {{ t.idp.settings.packages }}
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

                <!-- Package cards (selectable) -->
                <div
                    v-if="packages.length"
                    class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <button
                        v-for="pkg in packages"
                        :key="pkg.id"
                        type="button"
                        class="rounded-xl border p-4 text-left transition"
                        :class="
                            pkg.id === selectedPackageId
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : 'border-border bg-white hover:bg-slate-50'
                        "
                        @click="selectedPackageId = pkg.id"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="truncate font-semibold text-slate-800">
                                    {{ pkg.name }}
                                </div>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    <i class="fa-regular fa-calendar mr-1" />
                                    {{ packagePeriod(pkg) }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editPackage"
                                    @click.stop="openPackage(pkg)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deletePackage"
                                    @click.stop="deletePackage(pkg)"
                                />
                            </div>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-1.5">
                            <span
                                v-if="pkg.is_active"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-600"
                            >
                                <i class="fa-solid fa-circle-check text-[9px]" />
                                {{ t.idp.settings.activeBadge }}
                            </span>
                            <span
                                v-if="pkg.is_current"
                                class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-600"
                            >
                                <i class="fa-solid fa-thumbtack text-[9px]" />
                                {{ t.idp.settings.currentBadge }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                            >
                                {{ pkg.models_count }} {{ t.idp.settings.modelsLabel }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="
                                    pkg.total_percentage === 100
                                        ? 'bg-emerald-50 text-emerald-600'
                                        : 'bg-amber-50 text-amber-600'
                                "
                            >
                                {{ pkg.total_percentage }}%
                            </span>
                        </div>
                    </button>
                </div>

                <!-- No packages yet -->
                <div
                    v-else
                    class="mt-4 rounded-lg border border-dashed border-border px-6 py-8 text-center text-sm text-slate-400"
                >
                    {{ t.idp.settings.noPackages }}
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 Selected package · weighting summary + models
            ------------------------------------------------------------- -->
            <template v-if="selectedPackage">
                <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h3 class="text-base font-semibold text-slate-800">
                                {{ t.idp.settings.models }}
                                <span class="text-slate-400">· {{ selectedPackage.name }}</span>
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
                </section>

                <!-- Model cards -->
                <div
                    v-if="packageModels.length"
                    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="(model, i) in packageModels"
                        :key="model.id"
                        class="group relative flex flex-col overflow-hidden rounded-xl border border-border bg-white shadow-sm transition hover:shadow-md"
                    >
                        <span
                            class="absolute inset-x-0 top-0 h-1"
                            :class="colorFor(i).bar"
                        />

                        <div class="flex items-start justify-between gap-3 p-5 pb-3">
                            <div class="min-w-0">
                                <h4 class="truncate font-semibold text-slate-800">
                                    {{ modelName(model) }}
                                </h4>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    {{ t.idp.settings.ofTotal }}
                                </p>
                            </div>

                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full text-sm font-bold ring-4"
                                :class="[
                                    colorFor(i).soft,
                                    colorFor(i).text,
                                    colorFor(i).ring,
                                ]"
                            >
                                {{ model.percentage }}%
                            </div>
                        </div>

                        <p
                            v-if="modelDescription(model)"
                            class="whitespace-pre-wrap break-words px-5 text-sm leading-relaxed text-slate-500"
                        >
                            {{ modelDescription(model) }}
                        </p>
                        <p v-else class="px-5 text-sm italic text-slate-300">
                            {{ t.idp.settings.noDescription }}
                        </p>

                        <div
                            class="mt-auto flex items-center justify-between gap-2 border-t border-border/60 px-5 py-3"
                        >
                            <div class="flex items-center gap-3 text-xs text-slate-400">
                                <span
                                    class="inline-flex items-center gap-1"
                                    :title="t.idp.settings.programs"
                                >
                                    <i class="fa-solid fa-book-open text-[11px]" />
                                    {{ model.development_programs_count }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1"
                                    :title="t.idp.settings.plansInUse"
                                >
                                    <i class="fa-solid fa-user-check text-[11px]" />
                                    {{ model.individual_development_plans_count }}
                                </span>
                            </div>

                            <div class="flex items-center gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editModel"
                                    @click="openModel(model)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteModel"
                                    @click="deleteModel(model)"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Package has no models yet -->
                <div
                    v-else
                    class="rounded-xl border border-dashed border-border bg-white px-6 py-14 text-center"
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
            </template>
        </div>

        <!-- ================================================================
             TAB 2: COMPETENCIES & DEVELOPMENT PROGRAMS
        ================================================================= -->

        <div
            v-if="activeTab === 'competency'"
            class="space-y-6"
        >
            <!-- ------------------------------------------------------------
                 Competency types · manage + filter chips
            ------------------------------------------------------------- -->
            <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-800">
                            {{ t.idp.settings.competencyTypes }}
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.competencyTypesHint }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                        @click="openMaster('competency_type')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competencyType }}
                    </button>
                </div>

                <!-- Filter chips -->
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <!-- All -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            selectedTypeFilter === null
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="selectedTypeFilter = null"
                    >
                        {{ t.idp.settings.allTypes }}
                        <span
                            class="rounded-full px-1.5 text-xs"
                            :class="
                                selectedTypeFilter === null
                                    ? 'bg-white/20'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{ competencies.length }}
                        </span>
                    </button>

                    <!-- Each type (clickable to filter; edit/delete on hover) -->
                    <div
                        v-for="ct in competencyTypes"
                        :key="ct.id"
                        class="group inline-flex items-center rounded-full border transition"
                        :class="
                            selectedTypeFilter === ct.id
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 py-1.5 pl-3 pr-1 text-sm font-medium"
                            :title="typeDescription(ct) || masterName(ct)"
                            @click="
                                selectedTypeFilter =
                                    selectedTypeFilter === ct.id ? null : ct.id
                            "
                        >
                            <i class="fa-solid fa-tag text-[10px] opacity-70" />
                            {{ masterName(ct) }}
                            <span
                                class="rounded-full px-1.5 text-xs"
                                :class="
                                    selectedTypeFilter === ct.id
                                        ? 'bg-white/20'
                                        : 'bg-slate-100 text-slate-500'
                                "
                            >
                                {{ ct.competencies_count }}
                            </span>
                        </button>
                        <span
                            class="flex items-center gap-0.5 pr-1.5 opacity-60 transition group-hover:opacity-100"
                        >
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs transition hover:bg-black/10"
                                :title="t.idp.settings.editCompetencyType"
                                @click.stop="openMaster('competency_type', ct)"
                            >
                                <i class="fa-solid fa-pen" />
                            </button>
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs transition hover:bg-black/10"
                                :title="t.idp.settings.deleteCompetencyType"
                                @click.stop="deleteMaster(ct.id, masterName(ct))"
                            >
                                <i class="fa-solid fa-trash" />
                            </button>
                        </span>
                    </div>

                    <!-- Untyped bucket -->
                    <button
                        v-if="untypedCompetencyCount"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            selectedTypeFilter === 0
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-500 hover:bg-slate-50'
                        "
                        @click="selectedTypeFilter = selectedTypeFilter === 0 ? null : 0"
                    >
                        {{ t.idp.settings.untyped }}
                        <span
                            class="rounded-full px-1.5 text-xs"
                            :class="
                                selectedTypeFilter === 0
                                    ? 'bg-white/20'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{ untypedCompetencyCount }}
                        </span>
                    </button>

                    <span
                        v-if="!competencyTypes.length"
                        class="text-sm text-slate-400"
                    >
                        {{ t.idp.settings.noTypesYet }}
                    </span>
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 Header: title · search · add competency
            ------------------------------------------------------------- -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.idp.settings.competencies }}
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.competenciesHint }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="competencySearch"
                            type="search"
                            :placeholder="t.idp.settings.searchCompetency"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openMaster('competency_name')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competency }}
                    </button>
                </div>
            </div>

            <!-- ------------------------------------------------------------
                 Competency table
            ------------------------------------------------------------- -->
            <div
                class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm"
            >
                <table class="w-full min-w-[640px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-14 px-5 py-3 text-center">#</th>
                            <th class="w-72 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleCompetencySort('name')"
                                >
                                    {{ t.idp.settings.competency }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(competencySort, 'name')"
                                    />
                                </button>
                            </th>
                            <th class="w-48 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleCompetencySort('type')"
                                >
                                    {{ t.idp.settings.competencyType }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(competencySort, 'type')"
                                    />
                                </button>
                            </th>
                            <th class="px-5 py-3">
                                {{ t.idp.settings.description }}
                            </th>
                            <th
                                class="w-24 border-l border-border/60 px-5 py-3 text-center"
                            >
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(c, i) in pagedCompetencies"
                            :key="c.id"
                            class="border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/60"
                        >
                            <td class="px-5 py-4 text-center text-slate-400">
                                {{ competencyFrom + i }}
                            </td>
                            <td class="px-5 py-4 font-semibold text-slate-800">
                                {{ masterName(c) }}
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    v-if="competencyTypeName(c.competency_type_id)"
                                    class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                                >
                                    <i class="fa-solid fa-tag text-[9px]" />
                                    {{ competencyTypeName(c.competency_type_id) }}
                                </span>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.untyped }}
                                </span>
                            </td>
                            <td class="px-5 py-4 text-slate-500">
                                <span
                                    v-if="competencyDescription(c)"
                                    class="whitespace-pre-wrap break-words"
                                >
                                    {{ competencyDescription(c) }}
                                </span>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.noDescription }}
                                </span>
                            </td>
                            <td
                                class="border-l border-border/60 px-5 py-4 text-center align-middle"
                            >
                                <div class="inline-flex items-center gap-1">
                                    <IconButton
                                        icon="fa-solid fa-pen"
                                        variant="edit"
                                        :title="t.idp.settings.editCompetency"
                                        @click="openMaster('competency_name', c)"
                                    />
                                    <IconButton
                                        icon="fa-solid fa-trash"
                                        variant="delete"
                                        :title="t.idp.settings.deleteCompetency"
                                        @click="deleteMaster(c.id, masterName(c))"
                                    />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="competencyRows.length === 0">
                            <td
                                colspan="5"
                                class="px-5 py-10 text-center text-sm text-slate-400"
                            >
                                {{
                                    competencySearch || selectedTypeFilter !== null
                                        ? t.idp.settings.noMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <Pagination
                :page="competencyPage"
                :per-page="competencyPerPage"
                :total="competencyRows.length"
                @update:page="competencyPage = $event"
                @update:per-page="competencyPerPage = $event; competencyPage = 1"
            />
        </div>

        <!-- ================================================================
             TAB: DEVELOPMENT PROGRAM
        ================================================================= -->

        <div
            v-if="activeTab === 'development-program'"
            class="space-y-6"
        >
            <!-- Header: title · search · add program -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.idp.settings.programs }}
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.relationHint }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="programSearch"
                            type="search"
                            :placeholder="t.idp.settings.searchProgram"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openMaster('development_program')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.program }}
                    </button>
                </div>
            </div>

            <!-- Program table (program → model + linked competencies) -->
            <div
                class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm"
            >
                <table class="w-full min-w-[820px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-14 px-5 py-3 text-center">#</th>
                            <th class="w-72 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleProgramSort('program')"
                                >
                                    {{ t.idp.settings.program }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(programSort, 'program')"
                                    />
                                </button>
                            </th>
                            <th class="w-56 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleProgramSort('model')"
                                >
                                    {{ t.idp.settings.model }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(programSort, 'model')"
                                    />
                                </button>
                            </th>
                            <th class="px-5 py-3">
                                {{ t.idp.settings.linkedCompetencies }}
                            </th>
                            <th
                                class="w-24 border-l border-border/60 px-5 py-3 text-center"
                            >
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(row, i) in pagedPrograms"
                            :key="row.program.id"
                            class="border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/60"
                        >
                            <td class="px-5 py-4 text-center text-slate-400">
                                {{ programFrom + i }}
                            </td>
                            <td class="px-5 py-4 font-semibold text-slate-800">
                                {{ masterName(row.program) }}
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    v-if="row.modelName"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="[
                                        groupColor(row.colorIndex).soft,
                                        groupColor(row.colorIndex).text,
                                    ]"
                                >
                                    <span
                                        class="h-2 w-2 rounded-full"
                                        :class="groupColor(row.colorIndex).bar"
                                    />
                                    {{ row.modelName }}
                                    <span v-if="row.percentage !== null">
                                        ({{ row.percentage }}%)
                                    </span>
                                </span>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.noModel }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div
                                    v-if="row.competencies.length"
                                    class="flex flex-wrap gap-1.5"
                                >
                                    <span
                                        v-for="c in row.competencies"
                                        :key="c.id"
                                        class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                                    >
                                        {{ masterName(c) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.noCompetenciesLinked }}
                                </span>
                            </td>
                            <td
                                class="border-l border-border/60 px-5 py-4 text-center align-middle"
                            >
                                <div class="inline-flex items-center gap-1">
                                    <IconButton
                                        icon="fa-solid fa-pen"
                                        variant="edit"
                                        :title="t.idp.settings.editProgram"
                                        @click="openMaster('development_program', row.program)"
                                    />
                                    <IconButton
                                        icon="fa-solid fa-trash"
                                        variant="delete"
                                        :title="t.idp.settings.deleteProgram"
                                        @click="deleteMaster(row.program.id, masterName(row.program))"
                                    />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="programRows.length === 0">
                            <td
                                colspan="5"
                                class="px-5 py-10 text-center text-sm text-slate-400"
                            >
                                {{
                                    programSearch
                                        ? t.idp.settings.noProgramsMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <Pagination
                :page="programPage"
                :per-page="programPerPage"
                :total="programRows.length"
                @update:page="programPage = $event"
                @update:per-page="programPerPage = $event; programPage = 1"
            />
        </div>

        <!-- ================================================================
             TAB 3: REVIEW TOOLS
        ================================================================= -->

        <div v-if="activeTab === 'review-tools'" class="space-y-6">
            <!-- ------------------------------------------------------------
                 Header: title · search · add
            ------------------------------------------------------------- -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.idp.settings.reviewTools }}
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.reviewToolsHint }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="reviewSearch"
                            type="search"
                            :placeholder="t.idp.settings.searchReviewTool"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openMaster('review_tools')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.reviewTool }}
                    </button>
                </div>
            </div>

            <!-- ------------------------------------------------------------
                 Table
            ------------------------------------------------------------- -->
            <div
                class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm"
            >
                <table class="w-full min-w-[420px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-14 px-5 py-3 text-center">#</th>
                            <th class="px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleReviewSort"
                                >
                                    {{ t.idp.settings.reviewTool }}
                                    <i
                                        class="text-[10px]"
                                        :class="
                                            reviewSort === 'asc'
                                                ? 'fa-solid fa-sort-up text-primary'
                                                : reviewSort === 'desc'
                                                  ? 'fa-solid fa-sort-down text-primary'
                                                  : 'fa-solid fa-sort text-slate-300'
                                        "
                                    />
                                </button>
                            </th>
                            <th
                                class="w-24 border-l border-border/60 px-5 py-3 text-center"
                            >
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(r, i) in pagedReviewTools"
                            :key="r.id"
                            class="border-b border-border/60 transition last:border-0 hover:bg-slate-50/60"
                        >
                            <td class="px-5 py-3 text-center text-slate-400">
                                {{ reviewFrom + i }}
                            </td>
                            <td class="px-5 py-3 font-medium text-slate-700">
                                {{ masterName(r) }}
                            </td>
                            <td
                                class="border-l border-border/60 px-5 py-3 text-center align-middle"
                            >
                                <div class="inline-flex items-center gap-1">
                                    <IconButton
                                        icon="fa-solid fa-pen"
                                        variant="edit"
                                        :title="t.idp.settings.editReviewTool"
                                        @click="openMaster('review_tools', r)"
                                    />
                                    <IconButton
                                        icon="fa-solid fa-trash"
                                        variant="delete"
                                        :title="t.idp.settings.deleteReviewTool"
                                        @click="deleteMaster(r.id, masterName(r))"
                                    />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="pagedReviewTools.length === 0">
                            <td
                                colspan="3"
                                class="px-5 py-10 text-center text-sm text-slate-400"
                            >
                                {{
                                    reviewSearch
                                        ? t.idp.settings.noToolsMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <Pagination
                :page="reviewPage"
                :per-page="reviewPerPage"
                :total="filteredReviewTools.length"
                @update:page="reviewPage = $event"
                @update:per-page="reviewPerPage = $event; reviewPage = 1"
            />
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
                    class="flex items-start gap-2.5 rounded-lg border border-border bg-slate-50/60 p-3"
                >
                    <input
                        v-model="packageForm.is_current"
                        type="checkbox"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-slate-700">
                            {{ t.idp.settings.setCurrent }}
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-400">
                            {{ t.idp.settings.setCurrentHint }}
                        </span>
                    </span>
                </label>
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
             MASTER DATA MODAL
        ================================================================= -->

        <Drawer
            :show="masterModal"
            :title="masterTitle()"
            @close="masterModal = false"
        >
            <form
                id="master-form"
                class="space-y-4"
                @submit.prevent="submitMaster"
            >
                <!-- Bilingual name (+ description for competency), grouped by
                     language. Applies to competency, program and review tool. -->
                <!-- English section -->
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
                                    v-model="masterForm.value_en"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        masterForm.errors.value_en
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="masterForm.errors.value_en"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ masterForm.errors.value_en }}
                                </p>
                            </div>

                            <div v-if="masterHasDescription">
                                <label
                                    class="mb-1 block text-xs font-medium text-slate-500"
                                >
                                    {{ t.idp.settings.description }}
                                    <span class="font-normal text-slate-400">
                                        ({{ t.idp.settings.optional }})
                                    </span>
                                </label>
                                <textarea
                                    v-model="masterForm.description_en"
                                    rows="4"
                                    :placeholder="t.idp.settings.descriptionHint"
                                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Bahasa Indonesia section -->
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
                                    v-model="masterForm.value_id"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        masterForm.errors.value_id
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="masterForm.errors.value_id"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ masterForm.errors.value_id }}
                                </p>
                            </div>

                            <div v-if="masterHasDescription">
                                <label
                                    class="mb-1 block text-xs font-medium text-slate-500"
                                >
                                    {{ t.idp.settings.description }}
                                    <span class="font-normal text-slate-400">
                                        ({{ t.idp.settings.optional }})
                                    </span>
                                </label>
                                <textarea
                                    v-model="masterForm.description_id"
                                    rows="4"
                                    :placeholder="t.idp.settings.descriptionHint"
                                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>
                    </div>

                <!-- Competency -> Competency type -->
                <div v-if="masterType === 'competency_name'">
                    <label
                        class="mb-1.5 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.competencyType }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>

                    <SearchableSelect
                        :model-value="
                            masterForm.competency_type_id == null
                                ? ''
                                : String(masterForm.competency_type_id)
                        "
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        @update:model-value="
                            masterForm.competency_type_id =
                                $event === '' ? null : Number($event)
                        "
                    />
                </div>

                <!-- Development program -> Development model -->
                <div v-if="masterType === 'development_program'">
                    <label
                        class="mb-1.5 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.model }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>

                    <SearchableSelect
                        :model-value="
                            masterForm.development_model_id == null
                                ? ''
                                : String(masterForm.development_model_id)
                        "
                        :options="modelOptions"
                        :placeholder="t.idp.settings.modelPickHint"
                        @update:model-value="
                            masterForm.development_model_id =
                                $event === '' ? null : Number($event)
                        "
                    />
                </div>

                <!-- Development program -> Competencies -->
                <div v-if="masterType === 'development_program'">
                    <label
                        class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.competencies }}
                        <span
                            v-if="masterForm.related_competencies.length"
                            class="rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                        >
                            {{ masterForm.related_competencies.length }}
                        </span>
                    </label>

                    <MultiSelect
                        v-model="relatedCompetencyValues"
                        :options="competencyOptions"
                        :placeholder="t.idp.settings.searchCompetency"
                        selected-below
                    />

                    <p class="mt-1.5 text-xs text-slate-400">
                        {{ t.idp.settings.competencyHint }}
                    </p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="masterModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="master-form"
                    :disabled="masterForm.processing"
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
