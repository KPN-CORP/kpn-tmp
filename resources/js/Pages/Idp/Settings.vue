<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import { type Option } from '@/Components/UI/MultiSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import ProgramFormDrawer from '@/Components/Domain/Idp/ProgramFormDrawer.vue'
import { localizedModelName } from '@/Components/Domain/Idp/programScope'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'
import { useDeleteConfirm } from '@/Composables/useDeleteConfirm'
import { useMasterLabels } from '@/Composables/useMasterLabels'
import { useProgramFilters } from '@/Composables/useProgramFilters'
import type {
    DevCompetency as Competency,
    DevCompetencyType as CompetencyType,
    DevImplementation as Implementation,
    DevModel as Model,
    DevPackage as Package,
    DevProficiencyLevel as ProficiencyLevel,
    DevProgram as Program,
    DevTraining as Training,
    MasterType,
    ProgramRow,
} from '@/types/masterDevelopment'

const { t, locale } = useLocale()
const { masterName, rowDescription } = useMasterLabels()

const props = defineProps<{
    developmentModels: Model[]
    packages: Package[]
    activePackageId: number | null
    competencies: Competency[]
    developmentPrograms: Program[]
    competencyTypes: CompetencyType[]
    proficiencyLevels: ProficiencyLevel[]
    implementations: Implementation[]
    trainings: Training[]
    grades: string[]
}>()

/**
 * --------------------------------------------------------------------------
 * Development models (read-only here — managed on their own page)
 * --------------------------------------------------------------------------
 * The master-data screen still needs the models list to label programs and to
 * drive the program form's package/model dropdowns; model + package CRUD now
 * lives in `Pages/Idp/DevelopmentModel.vue`.
 */

// Display the model name in the active UI language, falling back to the
// canonical `name` when the preferred localized name is empty.
function modelName(model: {
    name: string
    name_en?: string | null
    name_id?: string | null
}): string {
    return localizedModelName(model, locale.value)
}

/**
 * --------------------------------------------------------------------------
 * Program form drawer
 * --------------------------------------------------------------------------
 * The form, its cascade and its watchers live in ProgramFormDrawer; the page
 * only opens it and says what a save reloads.
 */

const programForm = ref<InstanceType<typeof ProgramFormDrawer> | null>(null)

function openMaster(type: MasterType, item?: Program) {
    programForm.value?.open(type, item)
}

// What a program save or delete can change: the programs themselves, and the
// competencies' links to them. Reloading only these skips the rest of the
// screen's option lists (trainings, implementations, grades, rungs, …).
const reloadOnly = ['developmentPrograms', 'competencies', 'flash']

function deleteMaster(type: MasterType, id: number, name?: string) {
    pendingDelete.value = {
        url: route('idp.setting.masters.destroy', [type, id]),
        name,
    }
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation (shared dialog)
 * --------------------------------------------------------------------------
 */

const { pendingDelete, deleting, confirmDelete, cancelDelete } = useDeleteConfirm(reloadOnly)

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const ct of props.competencyTypes) m.set(ct.id, ct)
    return m
})

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const pl of props.proficiencyLevels) m.set(pl.id, pl)
    return m
})

/**
 * --------------------------------------------------------------------------
 * Development program table — grouped competency type → competency → program
 * --------------------------------------------------------------------------
 * The grain is one row per (program × linked competency): a program carries at
 * most one competency, but three legacy rows carry two, and a program with none
 * still needs a line. Rows are pre-sorted type → competency → program so the
 * two leading columns merge into runs (ClientTable spans them); sorting the
 * table by another column simply breaks those runs apart, which is honest.
 * The development model is a tab rather than a column, so `modelKey` is here to
 * pick the tab's rows out, not to be rendered.
 * Search is external; ClientTable handles sort + pagination.
 */

const programSearch = ref('')

// The tab a program with no development model falls into.
const NO_MODEL_KEY = 'none'

// Every row, sorted but unfiltered. The filter option lists read this, so
// they offer what the data holds rather than what the current filters left.
const baseRows = computed<ProgramRow[]>(() => {
    const rows: ProgramRow[] = []

    for (const p of props.developmentPrograms) {
        const level =
            p.proficiency_level_id == null
                ? null
                : proficiencyLevelById.value.get(p.proficiency_level_id) ?? null

        const base = {
            id: p.id,
            program: p,
            name: masterName(p),
            modelKey:
                p.development_model_id == null
                    ? NO_MODEL_KEY
                    : String(p.development_model_id),
            // Free-typed proficiency (Others) falls back onto the picked level.
            proficiency: level
                ? masterName(level)
                : p.custom_proficiency_level ?? '',
            proficiencySequence: level?.sequence ?? null,
            proficiencyCode: level?.code ?? null,
            proficiencyDescription: level ? rowDescription(level) : '',
            proficiencyActive: level?.is_active ?? true,
            grades: p.grades ?? [],
        }

        // The type comes off the competency, so it groups the same way the
        // competency does; a program with none falls back to its own scope.
        const typeCell = (typeId: number | null) => {
            const ct = typeId == null ? null : competencyTypeById.value.get(typeId)
            return {
                competencyTypeKey: ct ? String(ct.id) : 'none',
                competencyTypeCode: ct?.code ?? '',
                competencyTypeName: ct ? masterName(ct) : '',
            }
        }

        const linked = props.competencies.filter((c) =>
            c.related_program.includes(p.id),
        )

        if (linked.length === 0) {
            rows.push({
                ...base,
                ...typeCell(p.competency_type_id),
                key: `${p.id}-0`,
                competencyKey: 'none',
                competencyCode: '',
                competencyName: '',
            })
        } else {
            for (const c of linked) {
                rows.push({
                    ...base,
                    ...typeCell(c.competency_type_id ?? p.competency_type_id),
                    key: `${p.id}-${c.id}`,
                    competencyKey: String(c.id),
                    competencyCode: c.code ?? '',
                    competencyName: masterName(c),
                })
            }
        }
    }

    // An unnamed master sorts after the named ones, so a legacy row with no
    // type or no competency lands at the foot of its group rather than the top.
    const byName = (a: string, b: string) => {
        if ((a !== '') !== (b !== '')) return a !== '' ? -1 : 1
        return a.localeCompare(b)
    }

    rows.sort((a, b) => {
        const byType = byName(a.competencyTypeName, b.competencyTypeName)
        if (byType !== 0) return byType
        const byCompetency = byName(a.competencyName, b.competencyName)
        if (byCompetency !== 0) return byCompetency
        return a.name.localeCompare(b.name)
    })

    return rows
})

// Column filters + search: see useProgramFilters.
const {
    filterType,
    filterCompetency,
    filterLevel,
    filterGrade,
    hasFilters,
    clearFilters,
    typeFilterOptions,
    competencyFilterOptions,
    levelFilterOptions,
    gradeFilterOptions,
    programRows,
} = useProgramFilters(baseRows, programSearch)

/**
 * --------------------------------------------------------------------------
 * One tab per development model
 * --------------------------------------------------------------------------
 * A model is the coarsest grouping, so it is a tab rather than a merged first
 * column: the whole table then answers "what does THIS model develop?" and the
 * remaining columns nest below it. Counts are of the SEARCHED rows, so a search
 * that matches nothing here but something next door says so on the other tab
 * instead of reading as no results at all.
 */

// Which package each model belongs to, so a row can be counted under its
// package without walking the model list per row.
const packageKeyOfModel = computed(() => {
    const m = new Map<string, string>()
    for (const mod of props.developmentModels) {
        m.set(String(mod.id), String(mod.development_model_package_id))
    }
    return m
})

// A program filed under no model belongs to no package either, so the orphan
// bucket sits in the PACKAGE strip; picking it leaves nothing to sub-divide.
const orphanCount = computed(
    () =>
        props.developmentPrograms.filter((p) => p.development_model_id == null)
            .length,
)

interface PackageTab {
    key: string
    label: string
    isActive: boolean
    count: number
}

const packageTabs = computed<PackageTab[]>(() => {
    const counts = new Map<string, number>()
    for (const row of programRows.value) {
        const key = packageKeyOfModel.value.get(row.modelKey) ?? NO_MODEL_KEY
        counts.set(key, (counts.get(key) ?? 0) + 1)
    }

    const tabs: PackageTab[] = props.packages.map((pk) => ({
        key: String(pk.id),
        label: pk.name,
        isActive: pk.id === props.activePackageId,
        count: counts.get(String(pk.id)) ?? 0,
    }))

    if (orphanCount.value > 0) {
        tabs.push({
            key: NO_MODEL_KEY,
            label: t.value.idp.settings.noModel,
            isActive: false,
            count: counts.get(NO_MODEL_KEY) ?? 0,
        })
    }

    return tabs
})

// The package is a select rather than a strip of buttons: it is a "which cycle
// am I looking at" choice, made once, and packages accumulate over the years.
// The count rides in the label so it survives in the closed trigger, which
// shows the label alone.
const packageFilterOptions = computed<Option[]>(() =>
    packageTabs.value.map((tab) => ({
        value: tab.key,
        label: `${tab.label} (${tab.count})`,
        description: tab.isActive ? t.value.idp.settings.activeBadge : undefined,
    })),
)

// Open on the package in force — the cycle being worked on.
const selectedPackageKey = ref<string | null>(null)

const activePackageKey = computed<string>(() => {
    const tabs = packageTabs.value
    const chosen = tabs.find((tab) => tab.key === selectedPackageKey.value)
    if (chosen) return chosen.key

    const current = tabs.find(
        (tab) => tab.key === String(props.activePackageId),
    )

    return current?.key ?? tabs[0]?.key ?? NO_MODEL_KEY
})

const activePackageTab = computed(() =>
    packageTabs.value.find((tab) => tab.key === activePackageKey.value) ?? null,
)

interface ModelTab {
    key: string
    label: string
    percentage: number
    count: number
}

// Only the open package's models: a model belongs to exactly one package, and
// mixing two packages' weightings in one strip is what made this ambiguous.
const modelTabs = computed<ModelTab[]>(() => {
    if (activePackageKey.value === NO_MODEL_KEY) return []

    const counts = new Map<string, number>()
    for (const row of programRows.value) {
        counts.set(row.modelKey, (counts.get(row.modelKey) ?? 0) + 1)
    }

    return props.developmentModels
        .filter(
            (m) =>
                String(m.development_model_package_id) === activePackageKey.value,
        )
        .map((m) => ({
            key: String(m.id),
            label: modelName(m),
            percentage: m.percentage,
            count: counts.get(String(m.id)) ?? 0,
        }))
})

// The chosen model only survives while it belongs to the open package, so
// switching package lands on that package's first model rather than an empty
// table.
const selectedModelKey = ref<string | null>(null)

const activeModelKey = computed<string>(() => {
    const tabs = modelTabs.value
    if (!tabs.length) return NO_MODEL_KEY

    const chosen = tabs.find((tab) => tab.key === selectedModelKey.value)

    return chosen?.key ?? tabs[0].key
})

const visibleRows = computed(() =>
    programRows.value.filter((row) => row.modelKey === activeModelKey.value),
)

// How many PROGRAMS the tabs + filters + search leave, not how many rows: a
// program with two competencies is one program on two lines.
const visibleProgramCount = computed(
    () => new Set(visibleRows.value.map((row) => row.id)).size,
)

const programColumns = computed<Column[]>(() => [
    {
        key: 'competencyTypeName',
        label: t.value.idp.settings.competencyType,
        sortable: true,
        merge: true,
        mergeKey: 'competencyTypeKey',
        thClass: 'w-44',
    },
    {
        key: 'competencyName',
        label: t.value.idp.settings.competency,
        sortable: true,
        merge: true,
        mergeKey: 'competencyKey',
        thClass: 'w-52',
    },
    { key: 'name', label: t.value.idp.settings.program, sortable: true },
    { key: 'proficiency', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-56' },
    { key: 'grades', label: t.value.idp.settings.grade, thClass: 'w-36' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])
</script>

<template>
    <Head :title="t.idp.settings.masterDevelopmentTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.masterDevelopmentTitle"
            :subtitle="t.idp.settings.masterDevelopmentSubtitle"
        />

        <!-- ================================================================
             DEVELOPMENT PROGRAM
        ================================================================= -->

        <div class="space-y-6">
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <!-- Header: title · search · add program -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.programs }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ visibleProgramCount }}
                                <span
                                    v-if="visibleProgramCount !== developmentPrograms.length"
                                    class="font-normal text-slate-400"
                                >/ {{ developmentPrograms.length }}</span>
                            </span>
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

                <!-- Which cycle: the model package, then which of ITS
                     development models. A model belongs to exactly one package,
                     so keeping them on one line — select, divider, tabs — says
                     which models the package on the left is offering. -->
                <div
                    v-if="packageTabs.length > 1 || modelTabs.length"
                    class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border/60 px-5 py-3"
                >
                    <template v-if="packageTabs.length > 1">
                        <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                            {{ t.idp.settings.packages }}
                        </span>
                        <div class="w-64">
                            <SearchableSelect
                                :model-value="activePackageKey"
                                :options="packageFilterOptions"
                                @update:model-value="selectedPackageKey = $event"
                            />
                        </div>
                        <!-- The closed trigger shows the label alone, so the
                             pin saying which cycle is in force sits outside. -->
                        <span
                            v-if="activePackageTab?.isActive"
                            class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-600"
                        >
                            {{ t.idp.settings.activeBadge }}
                        </span>

                        <span
                            v-if="modelTabs.length"
                            aria-hidden="true"
                            class="mx-1 hidden h-7 w-px bg-border sm:block"
                        />
                    </template>

                    <button
                        v-for="tab in modelTabs"
                        :key="tab.key"
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            activeModelKey === tab.key
                                ? 'border-primary bg-primary/5 text-primary'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="selectedModelKey = tab.key"
                    >
                        {{ tab.label }}
                        <span class="text-xs font-normal text-slate-400">
                            {{ tab.percentage }}%
                        </span>
                        <span
                            class="rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
                            :class="activeModelKey === tab.key ? 'bg-primary/15' : 'bg-slate-100 text-slate-500'"
                        >
                            {{ tab.count }}
                        </span>
                    </button>
                </div>

                <!-- Filters, one per grouping column in the table's own nesting
                     order; each child's options are narrowed by its parent. -->
                <div class="border-b border-border/60 bg-slate-50/60 px-5 py-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <SearchableSelect
                            v-model="filterType"
                            :options="typeFilterOptions"
                            :placeholder="t.idp.settings.allCompetencyTypes"
                        />

                        <SearchableSelect
                            v-model="filterCompetency"
                            :options="competencyFilterOptions"
                            :placeholder="t.idp.settings.allCompetencies"
                        />

                        <SearchableSelect
                            v-model="filterLevel"
                            :options="levelFilterOptions"
                            :placeholder="t.idp.settings.allProficiencyLevels"
                        />

                        <div class="flex gap-2">
                            <SearchableSelect
                                v-model="filterGrade"
                                class="min-w-0 flex-1"
                                :options="gradeFilterOptions"
                                :placeholder="t.idp.settings.allGrades"
                            />

                            <button
                                v-if="hasFilters"
                                type="button"
                                class="shrink-0 rounded-md border border-border bg-white px-3 text-sm text-slate-500 transition hover:bg-slate-50"
                                :title="t.idp.settings.clearFilters"
                                @click="clearFilters"
                            >
                                <i class="fa-solid fa-xmark" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Grouped: competency type → competency → program -->
                <ClientTable
                    :columns="programColumns"
                    :rows="visibleRows"
                    row-key="key"
                    :per-page="20"
                    bordered
                >
                    <!-- One cell per competency type, merged across the
                         programs under it. Code above, name below — the code identifies it,
                         the name reads it. -->
                    <template #cell-competencyTypeName="{ row }">
                        <div v-if="row.competencyTypeName || row.competencyTypeCode">
                            <span
                                v-if="row.competencyTypeCode"
                                class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                            >
                                {{ row.competencyTypeCode }}
                            </span>
                            <div
                                v-if="row.competencyTypeName"
                                class="mt-0.5 font-medium text-slate-700"
                            >
                                {{ row.competencyTypeName }}
                            </div>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">
                            &#8212;
                        </span>
                    </template>

                    <!-- Merged within its type: one cell per competency. -->
                    <template #cell-competencyName="{ row }">
                        <div v-if="row.competencyName || row.competencyCode">
                            <span
                                v-if="row.competencyCode"
                                class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                            >
                                {{ row.competencyCode }}
                            </span>
                            <div
                                v-if="row.competencyName"
                                class="mt-0.5 font-medium text-slate-700"
                            >
                                {{ row.competencyName }}
                            </div>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">
                            &#8212;
                        </span>
                    </template>

                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
                    </template>

                    <!-- Laid out as on the Master Competency list. A
                         free-typed proficiency has no rung behind it, so it
                         arrives with no sequence and shows no badge. -->
                    <template #cell-proficiency="{ row }">
                        <ProficiencyLevelCell
                            v-if="row.proficiency"
                            :name="row.proficiency"
                            :sequence="row.proficiencySequence"
                            :code="row.proficiencyCode"
                            :description="row.proficiencyDescription"
                            :active="row.proficiencyActive"
                        />
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
                    </template>

                    <template #cell-grades="{ row }">
                        <div v-if="row.grades.length" class="flex flex-wrap gap-1.5">
                            <span
                                v-for="g in row.grades"
                                :key="g"
                                class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600"
                            >
                                {{ g }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
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
                                @click="deleteMaster('development_program', row.program.id, row.name)"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{
                            programSearch || hasFilters
                                ? t.idp.settings.noProgramsMatch
                                : t.idp.settings.none
                        }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             MASTER DATA MODAL (+ its unsaved-changes confirmation)
        ================================================================= -->

        <ProgramFormDrawer
            ref="programForm"
            :development-models="developmentModels"
            :packages="packages"
            :active-package-id="activePackageId"
            :competencies="competencies"
            :competency-types="competencyTypes"
            :proficiency-levels="proficiencyLevels"
            :implementations="implementations"
            :trainings="trainings"
            :grades="grades"
            :reload-only="reloadOnly"
        />

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
            @close="cancelDelete"
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
