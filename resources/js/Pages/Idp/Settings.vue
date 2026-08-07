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
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface Model {
    id: number
    name: string
    name_en: string | null
    name_id: string | null
    percentage: number
    description_en: string | null
    description_id: string | null
    development_programs_count: number
    individual_development_plans_count: number
}

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    related_program: number[]
    linked_programs: string[]
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
    totalPercentage: number
    competencies: Competency[]
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
    | 'competencies-programs'
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
    name_en: '',
    name_id: '',
    percentage: 10,
    description_en: '',
    description_id: '',
})

function openModel(model?: Model) {
    editingModelId.value = model?.id ?? null

    modelForm.clearErrors()

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
 * Master data
 * --------------------------------------------------------------------------
 */

type MasterType =
    | 'competency_name'
    | 'development_program'
    | 'review_tools'

const masterModal = ref(false)
const masterType = ref<MasterType>('competency_name')
const editingMasterId = ref<number | null>(null)

const masterForm = useForm({
    type: 'competency_name' as MasterType,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
    development_model_id: null as number | null,
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
    item?: Competency | Program | ReviewTool,
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
 * Review tools (tab 3) — client-side search + pagination
 * --------------------------------------------------------------------------
 */

const reviewSearch = ref('')
const reviewPage = ref(1)
const reviewPerPage = 9

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
    Math.max(1, Math.ceil(filteredReviewTools.value.length / reviewPerPage)),
)

const pagedReviewTools = computed(() => {
    const start = (reviewPage.value - 1) * reviewPerPage
    return sortedReviewTools.value.slice(start, start + reviewPerPage)
})

const reviewFrom = computed(() =>
    filteredReviewTools.value.length === 0
        ? 0
        : (reviewPage.value - 1) * reviewPerPage + 1,
)
const reviewTo = computed(() =>
    Math.min(reviewPage.value * reviewPerPage, filteredReviewTools.value.length),
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

const programById = computed(() => {
    const m = new Map<number, Program>()
    for (const p of props.developmentPrograms) m.set(p.id, p)
    return m
})

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

interface RelationGroup {
    key: string
    modelName: string
    percentage: number | null
    colorIndex: number
    programs: Program[]
}

function programGroups(c: Competency): RelationGroup[] {
    const groups = new Map<string, RelationGroup>()

    for (const pid of c.related_program) {
        const p = programById.value.get(pid)
        if (!p) continue

        const key =
            p.development_model_id == null
                ? 'none'
                : String(p.development_model_id)

        if (!groups.has(key)) {
            const idx =
                p.development_model_id == null
                    ? -1
                    : modelIndexById.value.get(p.development_model_id) ?? -1

            groups.set(key, {
                key,
                modelName:
                    modelNameById(p.development_model_id) ||
                    (p.model_name ?? ''),
                percentage:
                    p.development_model_id == null
                        ? null
                        : modelPercentById.value.get(p.development_model_id) ??
                          null,
                colorIndex: idx,
                programs: [],
            })
        }

        groups.get(key)!.programs.push(p)
    }

    // Highest weighting first, mirroring the tab-1 ordering.
    return [...groups.values()].sort(
        (a, b) => (b.percentage ?? -1) - (a.percentage ?? -1),
    )
}

const relationRows = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()

    return props.competencies
        .filter((c) => {
            if (!q) return true
            if (masterName(c).toLowerCase().includes(q)) return true
            if (c.value.toLowerCase().includes(q)) return true

            // Also match on any linked development program's name.
            return c.related_program.some((pid) => {
                const p = programById.value.get(pid)
                return (
                    !!p &&
                    (masterName(p).toLowerCase().includes(q) ||
                        p.value.toLowerCase().includes(q))
                )
            })
        })
        .map((c) => {
            const groups = programGroups(c)

            // Always render at least one row so the competency cell shows even
            // when nothing is linked yet.
            return {
                competency: c,
                groups: groups.length
                    ? groups
                    : [
                          {
                              key: 'empty',
                              modelName: '',
                              percentage: null,
                              colorIndex: -1,
                              programs: [] as Program[],
                          },
                      ],
            }
        })
})

// Programs not linked to any competency — surfaced so they aren't orphaned.
const unlinkedPrograms = computed(() => {
    const linked = new Set<number>()
    for (const c of props.competencies) {
        for (const pid of c.related_program) linked.add(pid)
    }
    return props.developmentPrograms.filter((p) => !linked.has(p.id))
})

// Client-side pagination of the relation table, by competency.
const competencyPage = ref(1)
const competencyPerPage = 8

const competencyTotalPages = computed(() =>
    Math.max(1, Math.ceil(relationRows.value.length / competencyPerPage)),
)

const pagedRelationRows = computed(() => {
    const start = (competencyPage.value - 1) * competencyPerPage
    return relationRows.value.slice(start, start + competencyPerPage)
})

const competencyFrom = computed(() =>
    relationRows.value.length === 0
        ? 0
        : (competencyPage.value - 1) * competencyPerPage + 1,
)
const competencyTo = computed(() =>
    Math.min(competencyPage.value * competencyPerPage, relationRows.value.length),
)

watch(competencySearch, () => (competencyPage.value = 1))
watch(competencyTotalPages, (total) => {
    if (competencyPage.value > total) competencyPage.value = total
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
                class="grid grid-cols-1 gap-1 sm:grid-cols-3"
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

                <!-- Competencies & Development Programs -->
                <button
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeTab === 'competencies-programs'
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeTab = 'competencies-programs'"
                >
                    <i class="fa-solid fa-layer-group" />

                    <span>
                        Competencies & Development Programs
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
                 Weighting summary
            ------------------------------------------------------------- -->
            <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-800">
                            {{ t.idp.settings.models }}
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
                <div v-if="developmentModels.length" class="mt-5">
                    <div
                        class="flex h-3 w-full overflow-hidden rounded-full bg-slate-100"
                    >
                        <div
                            v-for="(model, i) in developmentModels"
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
                                v-for="(model, i) in developmentModels"
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
                                totalPercentage === 100
                                    ? 'bg-emerald-50 text-emerald-600'
                                    : 'bg-amber-50 text-amber-600'
                            "
                        >
                            <i
                                :class="
                                    totalPercentage === 100
                                        ? 'fa-solid fa-circle-check'
                                        : 'fa-solid fa-triangle-exclamation'
                                "
                            />
                            {{ t.idp.settings.total }} {{ totalPercentage }}% ·
                            {{
                                totalPercentage === 100
                                    ? t.idp.settings.balanced
                                    : t.idp.settings.adjust
                            }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 Model cards
            ------------------------------------------------------------- -->
            <div
                v-if="developmentModels.length"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"
            >
                <div
                    v-for="(model, i) in developmentModels"
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

            <!-- ------------------------------------------------------------
                 Empty state
            ------------------------------------------------------------- -->
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
        </div>

        <!-- ================================================================
             TAB 2: COMPETENCIES & DEVELOPMENT PROGRAMS
        ================================================================= -->

        <div
            v-if="activeTab === 'competencies-programs'"
            class="space-y-6"
        >
            <!-- ------------------------------------------------------------
                 Header: title · search · add
            ------------------------------------------------------------- -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.idp.settings.relationTitle }}
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
                            v-model="competencySearch"
                            type="search"
                            :placeholder="t.idp.settings.searchCompetency"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                        @click="openMaster('development_program')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.program }}
                    </button>

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
                 Relation table
            ------------------------------------------------------------- -->
            <div
                class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm"
            >
                <table class="w-full min-w-[820px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-64 px-5 py-3">
                                {{ t.idp.settings.competency }}
                            </th>
                            <th class="w-56 px-5 py-3">
                                {{ t.idp.settings.model }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t.idp.settings.programs }}
                            </th>
                            <th
                                class="w-16 border-l border-border/60 px-5 py-3 text-center"
                            >
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <template
                            v-for="row in pagedRelationRows"
                            :key="row.competency.id"
                        >
                            <tr
                                v-for="(g, gi) in row.groups"
                                :key="row.competency.id + '-' + g.key"
                                class="border-b border-border/60 align-top"
                            >
                                <!-- Competency (spans all model rows) -->
                                <td
                                    v-if="gi === 0"
                                    :rowspan="row.groups.length"
                                    class="border-r border-border/60 px-5 py-4 align-top"
                                >
                                    <div class="font-semibold text-slate-800">
                                        {{ masterName(row.competency) }}
                                    </div>
                                    <div class="mt-0.5 flex items-center gap-2">
                                        <span class="text-xs text-slate-400">
                                            {{ row.competency.related_program.length }}
                                            {{ t.idp.settings.programsLabel }}
                                        </span>
                                        <IconButton
                                            icon="fa-solid fa-pen"
                                            variant="edit"
                                            :title="t.idp.settings.editCompetency"
                                            @click="
                                                openMaster(
                                                    'competency_name',
                                                    row.competency,
                                                )
                                            "
                                        />
                                    </div>

                                    <p
                                        v-if="competencyDescription(row.competency)"
                                        class="mt-1 whitespace-pre-wrap break-words text-xs text-slate-400"
                                    >
                                        {{ competencyDescription(row.competency) }}
                                    </p>
                                </td>

                                <!-- Dev model badge -->
                                <td
                                    class="border-r border-border/60 px-5 py-4 align-top"
                                >
                                    <span
                                        v-if="g.modelName"
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="[
                                            groupColor(g.colorIndex).soft,
                                            groupColor(g.colorIndex).text,
                                        ]"
                                    >
                                        <span
                                            class="h-2 w-2 rounded-full"
                                            :class="groupColor(g.colorIndex).bar"
                                        />
                                        {{ g.modelName }}
                                        <span v-if="g.percentage !== null">
                                            ({{ g.percentage }}%)
                                        </span>
                                    </span>
                                    <span
                                        v-else
                                        class="text-xs italic text-slate-300"
                                    >
                                        {{ t.idp.settings.noModel }}
                                    </span>
                                </td>

                                <!-- Programs -->
                                <td class="px-5 py-4">
                                    <ul
                                        v-if="g.programs.length"
                                        class="space-y-1.5"
                                    >
                                        <li
                                            v-for="p in g.programs"
                                            :key="p.id"
                                            class="flex items-center gap-2"
                                        >
                                            <i
                                                class="fa-solid fa-circle text-[4px] text-slate-300"
                                            />
                                            <span class="flex-1 text-slate-600">
                                                {{ masterName(p) }}
                                            </span>
                                            <IconButton
                                                icon="fa-solid fa-pen"
                                                variant="edit"
                                                :title="t.idp.settings.editProgram"
                                                @click="
                                                    openMaster(
                                                        'development_program',
                                                        p,
                                                    )
                                                "
                                            />
                                            <IconButton
                                                icon="fa-solid fa-trash"
                                                variant="delete"
                                                :title="t.idp.settings.deleteProgram"
                                                @click="deleteMaster(p.id, masterName(p))"
                                            />
                                        </li>
                                    </ul>
                                    <span
                                        v-else
                                        class="text-xs italic text-slate-300"
                                    >
                                        {{ t.idp.settings.noProgramsLinked }}
                                    </span>
                                </td>

                                <!-- Action: delete competency -->
                                <td
                                    v-if="gi === 0"
                                    :rowspan="row.groups.length"
                                    class="border-l border-border/60 px-5 py-4 text-center align-middle"
                                >
                                    <IconButton
                                        icon="fa-solid fa-trash"
                                        variant="delete"
                                        :title="t.idp.settings.deleteCompetency"
                                        @click="
                                            deleteMaster(
                                                row.competency.id,
                                                masterName(row.competency),
                                            )
                                        "
                                    />
                                </td>
                            </tr>
                        </template>

                        <tr v-if="relationRows.length === 0">
                            <td
                                colspan="4"
                                class="px-5 py-10 text-center text-sm text-slate-400"
                            >
                                {{
                                    competencySearch
                                        ? t.idp.settings.noMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ------------------------------------------------------------
                 Pagination (by competency)
            ------------------------------------------------------------- -->
            <div
                v-if="relationRows.length > competencyPerPage"
                class="flex flex-col items-center justify-between gap-4 sm:flex-row"
            >
                <p class="text-sm text-slate-500">
                    {{ competencyFrom }}–{{ competencyTo }}
                    {{ t.pagination.of }}
                    {{ relationRows.length }}
                </p>

                <nav class="flex items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                        :disabled="competencyPage === 1"
                        :aria-label="t.pagination.previous"
                        @click="competencyPage--"
                    >
                        <i class="fa-solid fa-chevron-left text-xs" />
                    </button>

                    <button
                        v-for="p in competencyTotalPages"
                        :key="p"
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition"
                        :class="
                            p === competencyPage
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="competencyPage = p"
                    >
                        {{ p }}
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                        :disabled="competencyPage === competencyTotalPages"
                        :aria-label="t.pagination.next"
                        @click="competencyPage++"
                    >
                        <i class="fa-solid fa-chevron-right text-xs" />
                    </button>
                </nav>
            </div>

            <!-- ------------------------------------------------------------
                 Unlinked programs
            ------------------------------------------------------------- -->
            <section
                v-if="unlinkedPrograms.length"
                class="rounded-xl border border-dashed border-amber-300 bg-amber-50/40 p-5"
            >
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-link-slash text-amber-500" />
                    <h4 class="text-sm font-semibold text-slate-700">
                        {{ t.idp.settings.unlinkedPrograms }}
                    </h4>
                    <span
                        class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-600"
                    >
                        {{ unlinkedPrograms.length }}
                    </span>
                </div>
                <p class="mt-1 text-xs text-slate-500">
                    {{ t.idp.settings.unlinkedHint }}
                </p>

                <ul class="mt-3 flex flex-wrap gap-2">
                    <li
                        v-for="p in unlinkedPrograms"
                        :key="p.id"
                        class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-3 py-1.5 text-sm text-slate-600"
                    >
                        <span>{{ masterName(p) }}</span>
                        <span
                            v-if="p.model_name"
                            class="text-xs text-slate-400"
                        >
                            · {{ modelNameById(p.development_model_id) || p.model_name }}
                        </span>
                        <IconButton
                            icon="fa-solid fa-pen"
                            variant="edit"
                            :title="t.idp.settings.editProgram"
                            @click="openMaster('development_program', p)"
                        />
                        <IconButton
                            icon="fa-solid fa-trash"
                            variant="delete"
                            :title="t.idp.settings.deleteProgram"
                            @click="deleteMaster(p.id, masterName(p))"
                        />
                    </li>
                </ul>
            </section>
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

            <!-- ------------------------------------------------------------
                 Pagination
            ------------------------------------------------------------- -->
            <div
                v-if="filteredReviewTools.length > reviewPerPage"
                class="flex flex-col items-center justify-between gap-4 sm:flex-row"
            >
                <p class="text-sm text-slate-500">
                    {{ reviewFrom }}–{{ reviewTo }}
                    {{ t.pagination.of }}
                    {{ filteredReviewTools.length }}
                </p>

                <nav class="flex items-center gap-1">
                    <button
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                        :disabled="reviewPage === 1"
                        :aria-label="t.pagination.previous"
                        @click="reviewPage--"
                    >
                        <i class="fa-solid fa-chevron-left text-xs" />
                    </button>

                    <button
                        v-for="p in reviewTotalPages"
                        :key="p"
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 text-sm transition"
                        :class="
                            p === reviewPage
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="reviewPage = p"
                    >
                        {{ p }}
                    </button>

                    <button
                        type="button"
                        class="inline-flex h-9 min-w-9 items-center justify-center rounded-md border border-border bg-white px-3 text-sm text-slate-600 transition hover:bg-slate-50 disabled:pointer-events-none disabled:opacity-40"
                        :disabled="reviewPage === reviewTotalPages"
                        :aria-label="t.pagination.next"
                        @click="reviewPage++"
                    >
                        <i class="fa-solid fa-chevron-right text-xs" />
                    </button>
                </nav>
            </div>
        </div>

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
                <!-- Review tools: single-language name -->
                <!-- Bilingual name (+ description for competency), grouped by
                     language. Applies to competency, program and review tool. -->
                <template>
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

                            <div v-if="masterType === 'competency_name'">
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

                            <div v-if="masterType === 'competency_name'">
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
                </template>

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
