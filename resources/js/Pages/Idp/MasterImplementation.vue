<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import { useLocale } from '@/Composables/useLocale'


const { t, locale } = useLocale()

interface Localized {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface CompetencyType extends Localized {
    competencies_count: number
}

interface Competency extends Localized {
    competency_type_id: number | null
    // The proficiency levels available for this competency.
    proficiency_level_ids: number[]
    // Only an active competency can be mapped.
    is_active: boolean
}

interface ProficiencyLevel extends Localized {
    is_active: boolean
}

interface Implementation {
    id: number
    competency_type_id: number | null
    competency_id: number | null
    // One or more proficiency levels pinned to this implementation.
    proficiency_level_ids: number[]
    // The grades this implementation covers; empty means every grade.
    grades: string[]
    // A mapping covers any number of business units; empty means it is not
    // narrowed to one.
    business_units: string[]
    // An inactive mapping keeps its row but no longer applies.
    is_active: boolean
    job_family: string | null
    function_name: string | null
    position: string | null
}

const props = defineProps<{
    implementations: Implementation[]
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    grades: string[]
    // Dynamic org-scope hierarchy: business unit → job family / function → position.
    businessUnits: string[]
    jobFamiliesByBu: Record<string, string[]>
    functionsByBu: Record<string, string[]>
    positionsByBuFunction: Record<string, Record<string, string[]>>
}>()

/**
 * After a mutation the server redirects back here; restrict the reload to this
 * page's own data (+ flash) so every save is a lightweight partial reload.
 */
const reloadOnly = ['implementations', 'flash']

// Localized name for a master row, falling back to the canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
} | null | undefined): string {
    if (!item) return ''
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

/**
 * --------------------------------------------------------------------------
 * Lookup maps (id → row) for resolving labels in the table
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const c of props.competencyTypes) m.set(c.id, c)
    return m
})

const competencyById = computed(() => {
    const m = new Map<number, Competency>()
    for (const c of props.competencies) m.set(c.id, c)
    return m
})

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const p of props.proficiencyLevels) m.set(p.id, p)
    return m
})

/**
 * --------------------------------------------------------------------------
 * Dropdown options (string lists → { value, label })
 * --------------------------------------------------------------------------
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

/**
 * An implementation maps masters that must be usable now, so only active ones
 * are offered: a deactivated competency or proficiency level would produce a
 * mapping that applies to nobody. The server enforces the same rule on save.
 */

// Competencies filtered by the chosen competency type, minus the inactive ones.
const competencyOptions = computed<Option[]>(() => {
    const typeId = implForm.competency_type_id
    if (typeId == null) return []

    const options = props.competencies
        .filter((c) => c.competency_type_id === typeId && c.is_active)
        .map((c) => ({ value: String(c.id), label: masterName(c) }))

    // A competency saved earlier that has since been switched off keeps its
    // place, so an edit to some other field doesn't blank the select and lose
    // the mapping.
    const current = selectedCompetency.value
    if (current && !current.is_active) {
        options.unshift({ value: String(current.id), label: masterName(current) })
    }

    return options
})

const competencyInactive = computed(
    () => !!selectedCompetency.value && !selectedCompetency.value.is_active,
)

const gradeOptions = computed<Option[]>(() => toStringOptions(props.grades))

// --- Cascading org hierarchy ---

const businessUnitOptions = computed<Option[]>(() => toStringOptions(props.businessUnits))

/**
 * The children of the hierarchy are the **union** across the selected business
 * units — a mapping that covers several units may reach a job family, function
 * or position belonging to any of them. Same convention Master Training uses
 * for the work locations of several units.
 *
 * These three are not rendered today (the org-scope section only offers the
 * business units), but the columns are still stored and submitted, so they stay
 * in step with the selection.
 */
function unionFor(map: Record<string, string[]>): Option[] {
    const values = new Set<string>()
    for (const bu of implForm.business_units) {
        for (const value of map[bu] ?? []) values.add(value)
    }
    return toStringOptions([...values].sort())
}

const jobFamilyOptions = computed<Option[]>(() => unionFor(props.jobFamiliesByBu))

const functionOptions = computed<Option[]>(() => unionFor(props.functionsByBu))

const positionOptions = computed<Option[]>(() => {
    const fn = implForm.function_name
    if (!fn) return []

    const values = new Set<string>()
    for (const bu of implForm.business_units) {
        for (const value of props.positionsByBuFunction[bu]?.[fn] ?? []) values.add(value)
    }
    return toStringOptions([...values].sort())
})

/**
 * --------------------------------------------------------------------------
 * Implementation form (create / edit)
 * --------------------------------------------------------------------------
 */

const implModal = ref(false)
const editingImplId = ref<number | null>(null)

const implForm = useForm({
    competency_type_id: null as number | null,
    competency_id: null as number | null,
    // MultiSelect binds string[]; converted to ints server-side.
    proficiency_level_ids: [] as string[],
    grades: [] as string[],
    business_units: [] as string[],
    // A new mapping applies straight away.
    is_active: true,
    job_family: '',
    function_name: '',
    position: '',
})

// The competency currently chosen in the form (scopes the proficiency options).
const selectedCompetency = computed<Competency | null>(() =>
    implForm.competency_id == null
        ? null
        : competencyById.value.get(implForm.competency_id) ?? null,
)

// The active levels the chosen competency offers.
const proficiencyOptions = computed<Option[]>(() => {
    const c = selectedCompetency.value
    if (!c) return []

    const pinned = new Set(implForm.proficiency_level_ids)

    return c.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(id))
        .filter((p): p is ProficiencyLevel => !!p)
        // Levels already stored on this implementation stay listed even once
        // switched off; dropping them would silently unpin them on the next
        // save. They are flagged below instead.
        .filter((p) => p.is_active || pinned.has(String(p.id)))
        .map((p) => ({ value: String(p.id), label: masterName(p) }))
})

// Pinned levels that have since been switched off.
const inactivePinnedLevelNames = computed(() =>
    implForm.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(Number(id)))
        .filter((p): p is ProficiencyLevel => !!p && !p.is_active)
        .map((p) => masterName(p)),
)

// Drop any pinned proficiency level the newly chosen competency doesn't offer.
watch(selectedCompetency, (c) => {
    const valid = new Set((c?.proficiency_level_ids ?? []).map(String))
    implForm.proficiency_level_ids = implForm.proficiency_level_ids.filter((id) =>
        valid.has(id),
    )
})

// Changing the competency type drops a competency that no longer belongs to it.
watch(
    () => implForm.competency_type_id,
    (typeId) => {
        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            implForm.competency_id = null
        }
    },
)

// Changing the business units invalidates every child in the hierarchy — but
// not while a row is being loaded into the form, which would wipe the very
// values being restored (watchers flush after the assignments in openImpl).
const loadingForm = ref(false)

watch(
    () => implForm.business_units,
    () => {
        if (loadingForm.value) return

        implForm.job_family = ''
        implForm.function_name = ''
        implForm.position = ''
    },
)

// Changing the function invalidates the position.
watch(
    () => implForm.function_name,
    () => {
        implForm.position = ''
    },
)

function openImpl(item?: Implementation) {
    editingImplId.value = item?.id ?? null
    implForm.clearErrors()

    // Suppress the cascade watchers for the duration of the load, so restoring
    // a row never clears its own children.
    loadingForm.value = true

    implForm.competency_type_id = item?.competency_type_id ?? null
    implForm.competency_id = item?.competency_id ?? null
    implForm.proficiency_level_ids = (item?.proficiency_level_ids ?? []).map(String)
    implForm.grades = [...(item?.grades ?? [])]
    implForm.business_units = [...(item?.business_units ?? [])]
    implForm.is_active = item?.is_active ?? true
    implForm.job_family = item?.job_family ?? ''
    implForm.function_name = item?.function_name ?? ''
    implForm.position = item?.position ?? ''

    implModal.value = true

    // Release once the watchers for these assignments have flushed.
    nextTick(() => (loadingForm.value = false))
}

function submitImpl() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (implModal.value = false),
    }

    if (editingImplId.value) {
        implForm.put(`/idp-setting/implementations/${editingImplId.value}`, opts)
    } else {
        implForm.post('/idp-setting/implementations', opts)
    }
}

const implTitle = computed(() =>
    editingImplId.value
        ? t.value.idp.settings.editImplementation
        : t.value.idp.settings.addImplementation,
)

/**
 * --------------------------------------------------------------------------
 * Implementation table — search → ClientTable (sort + pagination)
 * --------------------------------------------------------------------------
 */

const implSearch = ref('')

const implRows = computed(() => {
    const q = implSearch.value.trim().toLowerCase()

    return props.implementations
        .map((row) => {
            const competency = row.competency_id != null
                ? competencyById.value.get(row.competency_id)
                : null
            const type = row.competency_type_id != null
                ? competencyTypeById.value.get(row.competency_type_id)
                : null
            const proficiencyNames = (row.proficiency_level_ids ?? [])
                .map((id) => masterName(proficiencyLevelById.value.get(id)))
                .filter((n) => n !== '')

            return {
                ...row,
                competency_name: masterName(competency),
                type_name: masterName(type),
                proficiency_names: proficiencyNames,
            }
        })
        .filter((row) => {
            if (!q) return true
            return [
                row.competency_name,
                row.type_name,
                ...row.proficiency_names,
                ...(row.grades ?? []),
                ...(row.business_units ?? []),
                row.job_family ?? '',
                row.function_name ?? '',
                row.position ?? '',
            ].some((v) => v.toLowerCase().includes(q))
        })
})

const implColumns = computed<Column[]>(() => [
    { key: 'competency_name', label: t.value.idp.settings.competency, sortable: true, thClass: 'w-56' },
    { key: 'proficiency_names', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-48' },
    { key: 'grades', label: t.value.idp.settings.grade, thClass: 'w-40' },
    { key: 'business_units', label: t.value.idp.settings.businessUnit, thClass: 'w-48' },
    {
        key: 'status',
        label: t.value.idp.settings.status,
        sortable: true,
        sortKey: 'is_active',
        thClass: 'w-52',
    },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate a mapping + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the mapping and its scope; it only stops the mapping
 * applying from now on. Who flipped it is recorded in the audit log on disk,
 * which the history drawer reads back.
 */

const togglingId = ref<number | null>(null)

function toggleActive(row: Implementation) {
    router.put(
        `/idp-setting/implementations/${row.id}/active`,
        { is_active: !row.is_active },
        {
            preserveScroll: true,
            preserveState: true,
            only: reloadOnly,
            onStart: () => (togglingId.value = row.id),
            onFinish: () => (togglingId.value = null),
        },
    )
}

const historyImpl = ref<Implementation | null>(null)

function openHistory(row: Implementation) {
    historyImpl.value = row
}

// A mapping has no name of its own, so the competency it maps labels it.
function implLabel(row: Implementation | null): string {
    if (!row || row.competency_id == null) return ''
    return masterName(competencyById.value.get(row.competency_id))
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteImpl(row: { id: number; competency_name: string }) {
    pendingDelete.value = {
        url: `/idp-setting/implementations/${row.id}`,
        name: row.competency_name,
    }
}

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
    <Head :title="t.idp.settings.implementationTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.implementationTitle"
            :subtitle="t.idp.settings.implementationSubtitle"
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Implementations · header + toolbar + table
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.implementations }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ implementations.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.implementationsHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="implSearch"
                                type="search"
                                :placeholder="t.idp.settings.searchImplementation"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openImpl()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addImplementation }}
                        </button>
                    </div>
                </div>

                <ClientTable
                    :columns="implColumns"
                    :rows="implRows"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-competency_name="{ row }">
                        <div class="flex flex-col items-start gap-1">
                            <span class="font-semibold text-slate-800">{{ row.competency_name || '—' }}</span>
                            <span
                                v-if="row.type_name"
                                class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                            >
                                <i class="fa-solid fa-tag text-[9px]" />
                                {{ row.type_name }}
                            </span>
                        </div>
                    </template>

                    <template #cell-proficiency_names="{ row }">
                        <div v-if="row.proficiency_names.length" class="flex flex-wrap gap-1">
                            <span
                                v-for="(name, i) in row.proficiency_names"
                                :key="i"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                            >
                                <i class="fa-solid fa-signal text-[9px]" />
                                {{ name }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-grades="{ row }">
                        <div v-if="row.grades?.length" class="flex flex-wrap gap-1">
                            <span
                                v-for="(grade, i) in row.grades"
                                :key="i"
                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                            >
                                {{ grade }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-status="{ row }">
                        <ActiveStateCell
                            :active="row.is_active"
                            :busy="togglingId === row.id"
                            @toggle="toggleActive(row as unknown as Implementation)"
                            @history="openHistory(row as unknown as Implementation)"
                        />
                    </template>

                    <template #cell-business_units="{ row }">
                        <div v-if="row.business_units?.length" class="flex flex-wrap gap-1">
                            <span
                                v-for="bu in row.business_units"
                                :key="bu"
                                class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                            >
                                {{ bu }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <IconButton
                                icon="fa-solid fa-pen"
                                variant="edit"
                                :title="t.idp.settings.editImplementation"
                                @click="openImpl(row as unknown as Implementation)"
                            />
                            <IconButton
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :title="t.idp.settings.deleteImplementation"
                                @click="deleteImpl(row as unknown as { id: number; competency_name: string })"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{ implSearch ? t.idp.settings.noImplementationsMatch : t.idp.settings.noImplementations }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             IMPLEMENTATION MODAL
        ================================================================= -->

        <Drawer :show="implModal" :title="implTitle" @close="implModal = false">
            <form id="impl-form" class="space-y-4" @submit.prevent="submitImpl">
                <!-- Competency type -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competencyType }}
                    </label>
                    <SearchableSelect
                        :model-value="implForm.competency_type_id == null ? '' : String(implForm.competency_type_id)"
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        :invalid="!!implForm.errors.competency_type_id"
                        @update:model-value="implForm.competency_type_id = $event === '' ? null : Number($event)"
                    />
                    <p v-if="implForm.errors.competency_type_id" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.competency_type_id }}
                    </p>
                </div>

                <!-- Competency name (filtered by type) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competency }}
                    </label>
                    <SearchableSelect
                        v-if="implForm.competency_type_id != null && competencyOptions.length > 0"
                        :model-value="implForm.competency_id == null ? '' : String(implForm.competency_id)"
                        :options="competencyOptions"
                        :placeholder="t.idp.settings.competencyPickHint"
                        :invalid="!!implForm.errors.competency_id"
                        @update:model-value="implForm.competency_id = $event === '' ? null : Number($event)"
                    />
                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            implForm.competency_type_id == null
                                ? t.idp.settings.pickTypeFirst
                                : t.idp.settings.noCompetenciesForType
                        }}
                    </p>
                    <p v-if="implForm.errors.competency_id" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.competency_id }}
                    </p>

                    <!-- A competency saved before it was switched off. Kept so
                         the mapping isn't lost, but it can't stay as it is. -->
                    <p v-if="competencyInactive" class="mt-1 text-xs font-medium text-amber-600">
                        <i class="fa-solid fa-triangle-exclamation mr-1" />
                        {{ t.idp.settings.competencyInactiveForImplementation }}
                    </p>
                </div>

                <!-- Proficiency levels (multi-select, scoped to the competency) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.proficiencyLevel }}
                    </label>

                    <MultiSelect
                        v-if="selectedCompetency && proficiencyOptions.length"
                        :model-value="implForm.proficiency_level_ids"
                        :options="proficiencyOptions"
                        :placeholder="t.idp.settings.proficiencyLevelPickHint"
                        :invalid="!!implForm.errors.proficiency_level_ids"
                        select-all
                        :select-all-label="t.idp.settings.selectAllLevels"
                        :clear-all-label="t.idp.settings.clearAllLevels"
                        @update:model-value="implForm.proficiency_level_ids = $event"
                    />
                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            !selectedCompetency
                                ? t.idp.settings.proficiencyFromCompetency
                                : selectedCompetency.proficiency_level_ids.length > 0
                                    ? t.idp.settings.noActiveProficiencyForCompetency
                                    : t.idp.settings.noProficiencyForCompetency
                        }}
                    </p>
                    <p v-if="implForm.errors.proficiency_level_ids" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.proficiency_level_ids }}
                    </p>

                    <!-- Levels pinned earlier that have since been switched
                         off. -->
                    <p
                        v-if="inactivePinnedLevelNames.length > 0"
                        class="mt-1 text-xs font-medium text-amber-600"
                    >
                        <i class="fa-solid fa-triangle-exclamation mr-1" />
                        {{ t.idp.settings.inactiveLevelsPinned }}
                        {{ inactivePinnedLevelNames.join(', ') }}
                    </p>
                </div>

                <!-- Grades (multi-select; empty means every grade) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.grade }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>
                    <MultiSelect
                        :model-value="implForm.grades"
                        :options="gradeOptions"
                        :placeholder="t.idp.settings.gradePickHint"
                        :invalid="!!implForm.errors.grades"
                        select-all
                        :select-all-label="t.idp.settings.selectAllGrades"
                        :clear-all-label="t.idp.settings.clearAllGrades"
                        @update:model-value="implForm.grades = $event"
                    />
                    <p v-if="implForm.errors.grades" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.grades }}
                    </p>
                </div>

                <hr class="border-border/60">

                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ t.idp.settings.orgScope }}
                </p>

                <!-- Business units (any number; empty = not narrowed) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.businessUnit }}
                    </label>
                    <MultiSelect
                        :model-value="implForm.business_units"
                        :options="businessUnitOptions"
                        :placeholder="t.idp.settings.businessUnitsPickHint"
                        :invalid="!!implForm.errors.business_units"
                        select-all
                        :select-all-label="t.idp.settings.selectAllBusinessUnits"
                        :clear-all-label="t.idp.settings.clearAllBusinessUnits"
                        @update:model-value="implForm.business_units = $event"
                    />
                    <p v-if="implForm.errors.business_units" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.business_units }}
                    </p>
                </div>

                <!-- Active / inactive -->
                <ActiveStateField
                    v-model="implForm.is_active"
                    :error="implForm.errors.is_active"
                />
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="implModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="impl-form"
                    :disabled="implForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->
        <!-- ================================================================
             ACTIVATION HISTORY
        ================================================================= -->

        <MasterStatusHistory
            :show="historyImpl !== null"
            :url="
                historyImpl
                    ? `/idp-setting/implementations/${historyImpl.id}/status-history`
                    : null
            "
            :name="implLabel(historyImpl)"
            @close="historyImpl = null"
        />


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
