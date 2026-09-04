<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
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
    is_active: boolean
}

interface ProficiencyLevel extends Localized {
    // The type this level is filed under; null = global, fits every type.
    competency_type_id: number | null
    is_active: boolean
}

interface Training extends Localized {
    description_en: string | null
    description_id: string | null
    // An inactive training stays listed here but is not offered as the name
    // source for a new development program.
    is_active: boolean
    competency_type_id: number | null
    competency_id: number | null
    // A training targets any number of proficiency levels, and is offered in
    // any number of business units / work locations.
    proficiency_level_ids: number[]
    business_units: string[]
    work_locations: string[]
}

const props = defineProps<{
    trainings: Training[]
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    // Corporate scope: business units, and the work locations of each.
    businessUnits: string[]
    workLocationsByBu: Record<string, string[]>
}>()

/**
 * Restrict server reloads after a mutation to this page's own data (+ flash),
 * so each save is a lightweight Inertia partial reload.
 */
const reloadOnly = ['trainings', 'flash']

// Localized name for a master row, falling back to the canonical `value`.
function masterName(
    item: { value: string; value_en?: string | null; value_id?: string | null } | null | undefined,
): string {
    if (!item) return ''
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized description (falls back to the other language).
function trainingDescription(item: Training): string {
    const preferred = locale.value === 'id' ? item.description_id : item.description_en
    const fallback = locale.value === 'id' ? item.description_en : item.description_id
    return (preferred ?? '').trim() !== '' ? (preferred as string) : (fallback ?? '')
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
 * Dropdown options
 * --------------------------------------------------------------------------
 *
 * A training has no effective period of its own — it applies from now on — so
 * the masters it points at have to be usable from now on too. An inactive
 * competency or proficiency level is off the list; one that is merely
 * scheduled stays on it. The server enforces the same rule on save.
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

// Only active masters may be picked; a deactivated one would make the training
// point at something no longer in use.
const inactive = (item: Competency | ProficiencyLevel) => !item.is_active

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

// Competencies of the chosen type, minus the expired ones.
const competencyOptions = computed<Option[]>(() => {
    const typeId = form.competency_type_id
    if (typeId == null) return []

    const options = props.competencies
        .filter((c) => c.competency_type_id === typeId && !inactive(c))
        .map((c) => ({ value: String(c.id), label: masterName(c) }))

    // A competency saved earlier that is now inactive keeps its place, so an
    // edit to some other field doesn't blank the select and lose the link.
    const current = selectedCompetency.value
    if (current && inactive(current)) {
        options.unshift({ value: String(current.id), label: masterName(current) })
    }

    return options
})

/**
 * The levels filed under the chosen competency type, plus the untyped (global)
 * ones. No type chosen means no levels to choose — the type is picked first,
 * exactly as on the competency form.
 */
const typedProficiencyLevels = computed<ProficiencyLevel[]>(() => {
    const typeId = form.competency_type_id
    if (typeId == null) return []

    return props.proficiencyLevels.filter(
        (p) => p.competency_type_id == null || p.competency_type_id === typeId,
    )
})

// Of those, the ones whose own effective period has not closed. A level is
// judged on its own dates here, not through the competency.
const proficiencyOptions = computed<Option[]>(() => {
    const pinned = new Set(form.proficiency_level_ids)

    return (
        typedProficiencyLevels.value
            // Levels already pinned to this training stay listed even once they
            // expire; dropping them would silently unpin them on the next save.
            // They are flagged below instead.
            .filter((p) => !inactive(p) || pinned.has(String(p.id)))
            .map((p) => ({ value: String(p.id), label: masterName(p) }))
    )
})

const businessUnitOptions = computed<Option[]>(() => toStringOptions(props.businessUnits))

/**
 * Work locations of the chosen business units (corporate `locations.area`) —
 * the union across them, since a training offered in several units may run at
 * a site of any of them.
 */
const availableWorkLocations = computed<string[]>(() => {
    const seen = new Set<string>()

    for (const unit of form.business_units) {
        for (const area of props.workLocationsByBu[unit] ?? []) seen.add(area)
    }

    return [...seen].sort()
})

const workLocationOptions = computed<Option[]>(() =>
    toStringOptions(availableWorkLocations.value),
)

/**
 * --------------------------------------------------------------------------
 * Create / edit (reuses the shared master-data routes with type training)
 * --------------------------------------------------------------------------
 */

const modal = ref(false)
const editingId = ref<number | null>(null)

const form = useForm({
    type: 'training',
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
    competency_type_id: null as number | null,
    competency_id: null as number | null,
    // MultiSelect binds string[]; converted to ints server-side.
    proficiency_level_ids: [] as string[],
    business_units: [] as string[],
    work_locations: [] as string[],
    // A new training is usable straight away.
    is_active: true,
})

const selectedCompetency = computed<Competency | null>(() =>
    form.competency_id == null ? null : competencyById.value.get(form.competency_id) ?? null,
)

const selectedLevels = computed<ProficiencyLevel[]>(() =>
    form.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(Number(id)))
        .filter((p): p is ProficiencyLevel => !!p),
)

const competencyInactive = computed(
    () => !!selectedCompetency.value && inactive(selectedCompetency.value),
)

// Pinned levels whose effective period has since ended.
const inactiveLevelNames = computed(() =>
    selectedLevels.value.filter((p) => inactive(p)).map((p) => masterName(p)),
)

// Changing the competency type drops the competency and any proficiency level
// that no longer belongs to it (an untyped level is global, so it survives).
watch(
    () => form.competency_type_id,
    (typeId) => {
        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            form.competency_id = null
        }

        const fits = new Set(typedProficiencyLevels.value.map((p) => String(p.id)))
        form.proficiency_level_ids = form.proficiency_level_ids.filter((id) => fits.has(id))
    },
)

// Dropping a business unit drops the work locations only it offered.
watch(
    () => form.business_units,
    () => {
        const available = new Set(availableWorkLocations.value)
        form.work_locations = form.work_locations.filter((l) => available.has(l))
    },
    { deep: true },
)

function openModal(item?: Training) {
    editingId.value = item?.id ?? null
    form.clearErrors()
    form.value_en = item?.value_en ?? item?.value ?? ''
    form.value_id = item?.value_id ?? ''
    form.description_en = item?.description_en ?? ''
    form.description_id = item?.description_id ?? ''

    // Assign the parents before the children so the cascade watchers don't wipe
    // the child values we're restoring on edit. Vue flushes watchers after this
    // synchronous block, so the final child assignments below win.
    form.competency_type_id = item?.competency_type_id ?? null
    form.competency_id = item?.competency_id ?? null
    form.proficiency_level_ids = (item?.proficiency_level_ids ?? []).map(String)
    form.business_units = [...(item?.business_units ?? [])]
    form.work_locations = [...(item?.work_locations ?? [])]
    form.is_active = item?.is_active ?? true

    modal.value = true
}

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the training and everything referencing it — a program
 * named from one holds a copy of that name — it only stops the training being
 * offered as a name source for new programs. Who flipped it is recorded in the
 * audit log on disk, which the history drawer reads back.
 */

const togglingId = ref<number | null>(null)

function toggleActive(training: Training) {
    router.put(
        `/idp-setting/masters/training/${training.id}/active`,
        { is_active: !training.is_active },
        {
            preserveScroll: true,
            preserveState: true,
            only: reloadOnly,
            onStart: () => (togglingId.value = training.id),
            onFinish: () => (togglingId.value = null),
        },
    )
}

const historyTraining = ref<Training | null>(null)

function openHistory(training: Training) {
    historyTraining.value = training
}

function submit() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (modal.value = false),
    }

    if (editingId.value) {
        form.put(`/idp-setting/masters/training/${editingId.value}`, opts)
    } else {
        form.post('/idp-setting/masters', opts)
    }
}

const modalTitle = computed(() =>
    editingId.value ? t.value.idp.settings.editTraining : t.value.idp.settings.addTraining,
)

/**
 * --------------------------------------------------------------------------
 * Form: step completion
 * --------------------------------------------------------------------------
 * The drawer is a cascade (scope → name → where it is offered), so each
 * section reports whether it is settled and the step badge turns into a check.
 */

// Scope is settled once the two required picks are made; the levels are optional.
const scopeComplete = computed(
    () => form.competency_type_id != null && form.competency_id != null,
)

const identityComplete = computed(() => form.value_en.trim() !== '')

/**
 * --------------------------------------------------------------------------
 * Search + table (client-side; ClientTable handles sort + pagination)
 * --------------------------------------------------------------------------
 */

const search = ref('')

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()

    const rows = props.trainings.map((r) => ({
        ...r,
        name: masterName(r),
        description: trainingDescription(r),
        type_name: masterName(
            r.competency_type_id != null
                ? competencyTypeById.value.get(r.competency_type_id)
                : null,
        ),
        competency_name: masterName(
            r.competency_id != null ? competencyById.value.get(r.competency_id) : null,
        ),
        proficiency_names: (r.proficiency_level_ids ?? [])
            .map((id) => masterName(proficiencyLevelById.value.get(id)))
            .filter((n) => n !== ''),
    }))

    return q
        ? rows.filter((r) =>
              [
                  r.name,
                  r.value,
                  r.description,
                  r.type_name,
                  r.competency_name,
                  ...r.proficiency_names,
                  ...(r.business_units ?? []),
                  ...(r.work_locations ?? []),
              ].some((v) => v.toLowerCase().includes(q)),
          )
        : rows
})

const columns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.trainingName, sortable: true, thClass: 'w-56' },
    {
        key: 'competency_name',
        label: t.value.idp.settings.competency,
        sortable: true,
        thClass: 'w-52',
    },
    { key: 'proficiency_names', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-44' },
    { key: 'business_units', label: t.value.idp.settings.businessUnit, thClass: 'w-44' },
    { key: 'work_locations', label: t.value.idp.settings.workLocation, thClass: 'w-48' },
    { key: 'description', label: t.value.idp.settings.description },
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
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteTraining(item: Training) {
    pendingDelete.value = {
        url: `/idp-setting/masters/training/${item.id}`,
        name: masterName(item),
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
    <Head :title="t.idp.settings.trainingTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.trainingTitle"
            :subtitle="t.idp.settings.trainingSubtitle"
        />

        <div class="space-y-6">
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <!-- Header: title · search · add -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.trainings }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ trainings.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.trainingsHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="search"
                                type="search"
                                :placeholder="t.idp.settings.searchTraining"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openModal()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.training }}
                        </button>
                    </div>
                </div>

                <!-- Table -->
                <ClientTable
                    :columns="columns"
                    :rows="filtered"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
                    </template>

                    <!-- Competency, with the type it is filed under beneath it. -->
                    <template #cell-competency_name="{ row }">
                        <div v-if="row.competency_name">
                            <span class="text-slate-700">{{ row.competency_name }}</span>
                            <span v-if="row.type_name" class="mt-0.5 block text-xs text-slate-400">
                                {{ row.type_name }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
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

                    <template #cell-business_units="{ row }">
                        <div v-if="row.business_units?.length" class="flex flex-wrap gap-1">
                            <span
                                v-for="(unit, i) in row.business_units"
                                :key="i"
                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                            >
                                {{ unit }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-work_locations="{ row }">
                        <div v-if="row.work_locations?.length" class="flex flex-wrap gap-1">
                            <span
                                v-for="(location, i) in row.work_locations"
                                :key="i"
                                class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-600"
                            >
                                <i class="fa-solid fa-location-dot text-[9px]" />
                                {{ location }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-description="{ row }">
                        <span
                            v-if="row.description"
                            class="whitespace-pre-wrap break-words text-slate-500"
                        >
                            {{ row.description }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">
                            {{ t.idp.settings.noDescription }}
                        </span>
                    </template>

                    <template #cell-status="{ row }">
                        <ActiveStateCell
                            :active="row.is_active"
                            :busy="togglingId === row.id"
                            @toggle="toggleActive(row as unknown as Training)"
                            @history="openHistory(row as unknown as Training)"
                        />
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <IconButton
                                icon="fa-solid fa-pen"
                                variant="edit"
                                :title="t.idp.settings.editTraining"
                                @click="openModal(row as unknown as Training)"
                            />
                            <IconButton
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :title="t.idp.settings.deleteTraining"
                                @click="deleteTraining(row as unknown as Training)"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{ search ? t.idp.settings.noTrainingsMatch : t.idp.settings.none }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             TRAINING MODAL
        ================================================================= -->

        <Drawer
            :show="modal"
            :title="modalTitle"
            max-width="max-w-3xl"
            @close="modal = false"
        >
            <form id="training-form" class="space-y-4" @submit.prevent="submit">
                <!-- ========================================================
                     1. Scope — what the training develops
                ========================================================= -->
                <FormSection
                    :step="1"
                    :title="t.idp.settings.scope"
                    :hint="t.idp.settings.trainingScopeHint"
                    icon="fa-solid fa-bullseye"
                    :complete="scopeComplete"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <!-- Competency type (scopes everything below it) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.competencyType }}
                                <span class="text-red-500">*</span>
                            </label>

                            <SearchableSelect
                                :model-value="
                                    form.competency_type_id == null
                                        ? ''
                                        : String(form.competency_type_id)
                                "
                                :options="competencyTypeOptions"
                                :placeholder="t.idp.settings.selectCompetencyType"
                                :invalid="!!form.errors.competency_type_id"
                                @update:model-value="
                                    form.competency_type_id = $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-if="form.errors.competency_type_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.competency_type_id }}
                            </p>
                        </div>

                        <!-- Competency (of that type, active only) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.competency }}
                                <span class="text-red-500">*</span>
                            </label>

                            <SearchableSelect
                                v-if="form.competency_type_id != null && competencyOptions.length"
                                :model-value="
                                    form.competency_id == null ? '' : String(form.competency_id)
                                "
                                :options="competencyOptions"
                                :placeholder="t.idp.settings.competencyPickHint"
                                :invalid="!!form.errors.competency_id"
                                @update:model-value="
                                    form.competency_id = $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        form.competency_type_id == null
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        form.competency_type_id == null
                                            ? t.idp.settings.pickTypeFirst
                                            : t.idp.settings.noCompetenciesForType
                                    }}
                                </span>
                            </p>

                            <p v-if="form.errors.competency_id" class="mt-1 text-xs text-red-600">
                                {{ form.errors.competency_id }}
                            </p>

                            <!-- A competency picked before it was switched off. Kept
                                 so the link isn't lost, but it can't stay as it is. -->
                            <p
                                v-if="competencyInactive"
                                class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                            >
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                                <span>{{ t.idp.settings.competencyInactiveForTraining }}</span>
                            </p>
                        </div>

                        <!-- Proficiency levels (filed under the type, or global) -->
                        <div class="sm:col-span-2">
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.proficiencyLevel }}
                            </label>

                            <MultiSelect
                                v-if="proficiencyOptions.length"
                                :model-value="form.proficiency_level_ids"
                                :options="proficiencyOptions"
                                :placeholder="t.idp.settings.proficiencyLevelPickHint"
                                :invalid="!!form.errors.proficiency_level_ids"
                                select-all
                                :select-all-label="t.idp.settings.selectAllLevels"
                                :clear-all-label="t.idp.settings.clearAllLevels"
                                @update:model-value="form.proficiency_level_ids = $event"
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        form.competency_type_id == null
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        form.competency_type_id == null
                                            ? t.idp.settings.pickTypeFirst
                                            : typedProficiencyLevels.length === 0
                                                ? t.idp.settings.noProficiencyLevelsForType
                                                : t.idp.settings.noActiveProficiencyLevelsForType
                                    }}
                                </span>
                            </p>

                            <p
                                v-if="form.errors.proficiency_level_ids"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ form.errors.proficiency_level_ids }}
                            </p>

                            <!-- Levels pinned earlier that have since been switched off. -->
                            <p
                                v-if="inactiveLevelNames.length"
                                class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                            >
                                <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                                <span>
                                    {{ t.idp.settings.proficiencyInactiveForTraining }}
                                    {{ inactiveLevelNames.join(', ') }}
                                </span>
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     2. Identity — what the training is called
                ========================================================= -->
                <FormSection
                    :step="2"
                    :title="t.idp.settings.trainingIdentity"
                    :hint="t.idp.settings.trainingIdentityHint"
                    icon="fa-solid fa-tag"
                    :complete="identityComplete"
                >
                    <!-- Bilingual name, side by side -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                            >
                                <span
                                    class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                >
                                    EN
                                </span>
                                {{ t.idp.settings.trainingName }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="form.value_en"
                                type="text"
                                :placeholder="t.idp.settings.namePlaceholderEn"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.value_en ? 'border-red-500' : 'border-border'"
                            >
                            <p v-if="form.errors.value_en" class="mt-1 text-xs text-red-600">
                                {{ form.errors.value_en }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                            >
                                <span
                                    class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                                >
                                    ID
                                </span>
                                {{ t.idp.settings.trainingName }}
                            </label>
                            <input
                                v-model="form.value_id"
                                type="text"
                                :placeholder="t.idp.settings.namePlaceholderId"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.value_id ? 'border-red-500' : 'border-border'"
                            >
                            <p v-if="form.errors.value_id" class="mt-1 text-xs text-red-600">
                                {{ form.errors.value_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Bilingual description, side by side -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                            >
                                <span
                                    class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                >
                                    EN
                                </span>
                                {{ t.idp.settings.description }}
                            </label>
                            <textarea
                                v-model="form.description_en"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionPlaceholderEn"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    form.errors.description_en ? 'border-red-500' : 'border-border'
                                "
                            />
                            <p v-if="form.errors.description_en" class="mt-1 text-xs text-red-600">
                                {{ form.errors.description_en }}
                            </p>
                        </div>

                        <div>
                            <label
                                class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                            >
                                <span
                                    class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                                >
                                    ID
                                </span>
                                {{ t.idp.settings.description }}
                            </label>
                            <textarea
                                v-model="form.description_id"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionPlaceholderId"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    form.errors.description_id ? 'border-red-500' : 'border-border'
                                "
                            />
                            <p v-if="form.errors.description_id" class="mt-1 text-xs text-red-600">
                                {{ form.errors.description_id }}
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     3. Organization scope — where the training is offered
                ========================================================= -->
                <FormSection
                    :step="3"
                    :title="t.idp.settings.orgScope"
                    :hint="t.idp.settings.trainingOrgScopeHint"
                    icon="fa-solid fa-building"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <!-- Business units -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.businessUnit }}
                            </label>

                            <MultiSelect
                                :model-value="form.business_units"
                                :options="businessUnitOptions"
                                :placeholder="t.idp.settings.businessUnitsPickHint"
                                :invalid="!!form.errors.business_units"
                                select-all
                                :select-all-label="t.idp.settings.selectAllBusinessUnits"
                                :clear-all-label="t.idp.settings.clearAllBusinessUnits"
                                @update:model-value="form.business_units = $event"
                            />
                            <p v-if="form.errors.business_units" class="mt-1 text-xs text-red-600">
                                {{ form.errors.business_units }}
                            </p>
                        </div>

                        <!-- Work locations (corporate sites of the chosen units) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.workLocation }}
                            </label>

                            <MultiSelect
                                v-if="workLocationOptions.length"
                                :model-value="form.work_locations"
                                :options="workLocationOptions"
                                :placeholder="t.idp.settings.workLocationsPickHint"
                                :invalid="!!form.errors.work_locations"
                                select-all
                                :select-all-label="t.idp.settings.selectAllWorkLocations"
                                :clear-all-label="t.idp.settings.clearAllWorkLocations"
                                @update:model-value="form.work_locations = $event"
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        form.business_units.length === 0
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        form.business_units.length === 0
                                            ? t.idp.settings.pickBusinessUnitFirst
                                            : t.idp.settings.noneForBusinessUnits
                                    }}
                                </span>
                            </p>

                            <p v-if="form.errors.work_locations" class="mt-1 text-xs text-red-600">
                                {{ form.errors.work_locations }}
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     4. Status — usable for new work, or retired
                ========================================================= -->
                <FormSection
                    :step="4"
                    :title="t.idp.settings.status"
                    icon="fa-solid fa-toggle-on"
                >
                    <ActiveStateField v-model="form.is_active" :error="form.errors.is_active" />
                </FormSection>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="modal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="training-form"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >
                    <i v-if="form.processing" class="fa-solid fa-circle-notch fa-spin text-xs" />
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
            :show="historyTraining !== null"
            :url="
                historyTraining
                    ? `/idp-setting/masters/training/${historyTraining.id}/status-history`
                    : null
            "
            :name="historyTraining ? masterName(historyTraining) : ''"
            @close="historyTraining = null"
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
