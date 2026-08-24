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

interface Program {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    development_model_id: number | null
    model_name: string | null
}

const props = defineProps<{
    developmentModels: Model[]
    packages: Package[]
    activePackageId: number | null
    competencies: Competency[]
    developmentPrograms: Program[]
}>()

/**
 * --------------------------------------------------------------------------
 * Development models (read-only here — managed on their own page)
 * --------------------------------------------------------------------------
 * The master-data screen still needs the models list to label programs and to
 * drive the program form's package/model dropdowns; model + package CRUD now
 * lives in `Pages/Idp/DevelopmentModel.vue`.
 */

// Accent palette cycled across program badges so each weighting is visually
// distinct. Class strings are kept literal so Tailwind can see them.
const modelPalette = [
    { bar: 'bg-indigo-500', soft: 'bg-indigo-50', text: 'text-indigo-600', ring: 'ring-indigo-100' },
    { bar: 'bg-sky-500', soft: 'bg-sky-50', text: 'text-sky-600', ring: 'ring-sky-100' },
    { bar: 'bg-amber-500', soft: 'bg-amber-50', text: 'text-amber-600', ring: 'ring-amber-100' },
    { bar: 'bg-emerald-500', soft: 'bg-emerald-50', text: 'text-emerald-600', ring: 'ring-emerald-100' },
    { bar: 'bg-rose-500', soft: 'bg-rose-50', text: 'text-rose-600', ring: 'ring-rose-100' },
]

const colorFor = (i: number) => modelPalette[i % modelPalette.length]

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

/**
 * --------------------------------------------------------------------------
 * Master data
 * --------------------------------------------------------------------------
 */

type MasterType = 'development_program' | 'review_tools'

const masterModal = ref(false)
const masterType = ref<MasterType>('development_program')
const editingMasterId = ref<number | null>(null)

// For a development program, the model dropdown is scoped to a chosen package.
const masterPackageId = ref<number | null>(null)

const masterForm = useForm({
    type: 'development_program' as MasterType,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    development_model_id: null as number | null,
    // Program → competencies. Linking happens from the program side.
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

function openMaster(type: MasterType, item?: Program) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    masterForm.type = type

    const localized = item as Partial<Program> | undefined
    masterForm.value_en = localized?.value_en ?? item?.value ?? ''
    masterForm.value_id = localized?.value_id ?? ''

    masterForm.development_model_id =
        (item as Program)?.development_model_id ?? null

    // Resolve the package the model dropdown should be scoped to: from the
    // program's current model when editing, else default to the active package.
    if (type === 'development_program') {
        const modelId = (item as Program)?.development_model_id ?? null
        const model =
            modelId != null
                ? props.developmentModels.find((m) => m.id === modelId)
                : null
        masterPackageId.value =
            model?.development_model_package_id ?? props.activePackageId ?? null
    } else {
        masterPackageId.value = null
    }

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
        masterType.value === 'development_program'
            ? t.value.idp.settings.program
            : t.value.idp.settings.reviewTool

    const prefix = editingMasterId.value
        ? t.value.idp.settings.edit
        : t.value.idp.settings.add

    return `${prefix} ${type}`
}

// Packages as dropdown options (active one flagged) for the program form.
const packageOptions = computed<Option[]>(() =>
    props.packages.map((p) => ({
        value: String(p.id),
        label: p.is_active
            ? `${p.name} · ${t.value.idp.settings.activeBadge}`
            : p.name,
    })),
)

// Development models for the chosen package only — the weighting is shown after
// the name so it's clear at a glance. Empty until a package is picked.
const packageModelOptions = computed<Option[]>(() =>
    masterPackageId.value == null
        ? []
        : props.developmentModels
              .filter(
                  (m) => m.development_model_package_id === masterPackageId.value,
              )
              .map((m) => ({
                  value: String(m.id),
                  label: `${modelName(m)} (${m.percentage}%)`,
              })),
)

// Switching package clears a model that no longer belongs to it.
function onProgramPackageChange(value: string) {
    masterPackageId.value = value === '' ? null : Number(value)

    const model = props.developmentModels.find(
        (m) => m.id === masterForm.development_model_id,
    )
    if (!model || model.development_model_package_id !== masterPackageId.value) {
        masterForm.development_model_id = null
    }
}

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
 * Development model lookups (for labelling programs by their model)
 * --------------------------------------------------------------------------
 */

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

// Column-sort helper for the program table. Clicking a column cycles
// asc → desc → off; clicking another column starts it at asc.
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
             DEVELOPMENT PROGRAM
        ================================================================= -->

        <div class="space-y-6">
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
                        </div>
                    </div>

                <!-- Development program -> Model package (scopes the model list) -->
                <div v-if="masterType === 'development_program'">
                    <label
                        class="mb-1.5 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.modelPackage }}
                    </label>

                    <SearchableSelect
                        :model-value="
                            masterPackageId == null ? '' : String(masterPackageId)
                        "
                        :options="packageOptions"
                        :placeholder="t.idp.settings.packagePickHint"
                        @update:model-value="onProgramPackageChange($event)"
                    />
                </div>

                <!-- Development program -> Development model (within package) -->
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
                        :options="packageModelOptions"
                        :disabled="masterPackageId == null"
                        :placeholder="
                            masterPackageId == null
                                ? t.idp.settings.selectPackageFirst
                                : t.idp.settings.modelPickHint
                        "
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
