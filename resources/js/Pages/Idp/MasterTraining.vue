<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { useLocale } from '@/Composables/useLocale'
import { periodSuffix, remainingWindow } from '@/Composables/useEffectivePeriod'

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
    effective_start_date: string | null
    effective_end_date: string | null
}

interface ProficiencyLevel extends Localized {
    // The type this level is filed under; null = global, fits every type.
    competency_type_id: number | null
    effective_start_date: string | null
    effective_end_date: string | null
}

interface Training extends Localized {
    description_en: string | null
    description_id: string | null
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
 * the masters it points at have to be usable from now on too. An expired
 * competency or proficiency level is off the list; one that is merely
 * scheduled stays on it. The server enforces the same rule on save.
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

// Name + effective window, so the reason a master is or isn't on offer shows
// right where the choice is made.
function periodLabel(item: Competency | ProficiencyLevel): string {
    return (
        masterName(item) +
        periodSuffix(item, t.value.idp.settings.always, t.value.idp.settings.ongoing)
    )
}

const expired = (item: Competency | ProficiencyLevel) => remainingWindow(item).past

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

// Competencies of the chosen type, minus the expired ones.
const competencyOptions = computed<Option[]>(() => {
    const typeId = form.competency_type_id
    if (typeId == null) return []

    const options = props.competencies
        .filter((c) => c.competency_type_id === typeId && !expired(c))
        .map((c) => ({ value: String(c.id), label: periodLabel(c) }))

    // A competency saved earlier that has since expired keeps its place, so an
    // edit to some other field doesn't blank the select and lose the link.
    const current = selectedCompetency.value
    if (current && expired(current)) {
        options.unshift({ value: String(current.id), label: periodLabel(current) })
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
            .filter((p) => !expired(p) || pinned.has(String(p.id)))
            .map((p) => ({ value: String(p.id), label: periodLabel(p) }))
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
})

const selectedCompetency = computed<Competency | null>(() =>
    form.competency_id == null ? null : competencyById.value.get(form.competency_id) ?? null,
)

const selectedLevels = computed<ProficiencyLevel[]>(() =>
    form.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(Number(id)))
        .filter((p): p is ProficiencyLevel => !!p),
)

const competencyExpired = computed(
    () => !!selectedCompetency.value && expired(selectedCompetency.value),
)

// Pinned levels whose effective period has since ended.
const expiredLevelNames = computed(() =>
    selectedLevels.value.filter((p) => expired(p)).map((p) => masterName(p)),
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

    modal.value = true
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

        <Drawer :show="modal" :title="modalTitle" @close="modal = false">
            <form id="training-form" class="space-y-4" @submit.prevent="submit">
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
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                {{ t.idp.settings.trainingName }}
                            </label>
                            <input
                                v-model="form.value_en"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.value_en ? 'border-red-500' : 'border-border'"
                            >
                            <p v-if="form.errors.value_en" class="mt-1 text-xs text-red-600">
                                {{ form.errors.value_en }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="form.description_en"
                                rows="4"
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
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                {{ t.idp.settings.trainingName }}
                            </label>
                            <input
                                v-model="form.value_id"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.value_id ? 'border-red-500' : 'border-border'"
                            >
                            <p v-if="form.errors.value_id" class="mt-1 text-xs text-red-600">
                                {{ form.errors.value_id }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="form.description_id"
                                rows="4"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>

                <hr class="border-border/60">

                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ t.idp.settings.scope }}
                </p>

                <!-- Competency type -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competencyType }}
                    </label>
                    <SearchableSelect
                        :model-value="form.competency_type_id == null ? '' : String(form.competency_type_id)"
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        :invalid="!!form.errors.competency_type_id"
                        @update:model-value="form.competency_type_id = $event === '' ? null : Number($event)"
                    />
                    <p v-if="form.errors.competency_type_id" class="mt-1 text-xs text-red-600">
                        {{ form.errors.competency_type_id }}
                    </p>
                </div>

                <!-- Competency (filtered by type + effective period) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competency }}
                    </label>
                    <SearchableSelect
                        v-if="form.competency_type_id != null && competencyOptions.length > 0"
                        :model-value="form.competency_id == null ? '' : String(form.competency_id)"
                        :options="competencyOptions"
                        :placeholder="t.idp.settings.competencyPickHint"
                        :invalid="!!form.errors.competency_id"
                        @update:model-value="form.competency_id = $event === '' ? null : Number($event)"
                    />
                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            form.competency_type_id == null
                                ? t.idp.settings.pickTypeFirst
                                : t.idp.settings.noCompetenciesForType
                        }}
                    </p>
                    <p v-if="form.errors.competency_id" class="mt-1 text-xs text-red-600">
                        {{ form.errors.competency_id }}
                    </p>

                    <!-- A competency saved before its period ended. Kept so the
                         link isn't lost, but it can't stay as it is. -->
                    <p v-if="competencyExpired" class="mt-1 text-xs font-medium text-amber-600">
                        <i class="fa-solid fa-triangle-exclamation mr-1" />
                        {{ t.idp.settings.competencyExpiredForTraining }}
                    </p>
                </div>

                <!-- Proficiency level (filtered by its own effective period) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.proficiencyLevel }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>
                    <MultiSelect
                        v-if="proficiencyOptions.length > 0"
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
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            form.competency_type_id == null
                                ? t.idp.settings.pickTypeFirst
                                : typedProficiencyLevels.length === 0
                                    ? t.idp.settings.noProficiencyLevelsForType
                                    : t.idp.settings.noEffectiveProficiencyLevels
                        }}
                    </p>
                    <p v-if="form.errors.proficiency_level_ids" class="mt-1 text-xs text-red-600">
                        {{ form.errors.proficiency_level_ids }}
                    </p>

                    <!-- Levels pinned earlier whose period has since ended. -->
                    <p
                        v-if="expiredLevelNames.length > 0"
                        class="mt-1 text-xs font-medium text-amber-600"
                    >
                        <i class="fa-solid fa-triangle-exclamation mr-1" />
                        {{ t.idp.settings.proficiencyExpiredForTraining }}
                        {{ expiredLevelNames.join(', ') }}
                    </p>
                </div>

                <hr class="border-border/60">

                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ t.idp.settings.orgScope }}
                </p>

                <!-- Business unit -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.businessUnit }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
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

                <!-- Work location (corporate locations of the chosen unit) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.workLocation }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>
                    <MultiSelect
                        v-if="workLocationOptions.length > 0"
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
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            form.business_units.length === 0
                                ? t.idp.settings.pickBusinessUnitFirst
                                : t.idp.settings.noneForBusinessUnits
                        }}
                    </p>
                    <p v-if="form.errors.work_locations" class="mt-1 text-xs text-red-600">
                        {{ form.errors.work_locations }}
                    </p>
                </div>
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
