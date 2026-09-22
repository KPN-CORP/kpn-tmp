<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import ConfirmActiveStateDialog from '@/Components/Domain/ConfirmActiveStateDialog.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import ActiveStateCell from '@/Components/Domain/ActiveStateCell.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import { useLocale } from '@/Composables/useLocale'
import { useActiveStateToggle } from '@/Composables/useActiveStateToggle'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'

const { t, locale } = useLocale()

interface Localized {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface CompetencyType extends Localized {
    // Short identifier, printed in front of the name. Null on the types that
    // predate the column.
    code: string | null
    competencies_count: number
}

interface Competency extends Localized {
    code: string | null
    competency_type_id: number | null
    is_active: boolean
}

/**
 * One rung of a competency's own proficiency ladder. There is no shared
 * proficiency-level master any more, so a level belongs to exactly one
 * competency and is offered only once that competency is chosen.
 */
interface ProficiencyLevel extends Localized {
    competency_id: number
    sequence: number
    // The rung's short identifier, unique within its competency. Null on the
    // rows that predate the column.
    code: string | null
    is_active: boolean
    description_en: string | null
    description_id: string | null
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

// Localized description (falls back to the other language). Read by both the
// trainings table and the proficiency-level picker.
function rowDescription(item: {
    description_en?: string | null
    description_id?: string | null
}): string {
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
 * A training applies from now on, so the masters it points at have to be
 * usable from now on too: an inactive competency or proficiency level is off
 * the list. The server enforces the same rule on save.
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

// Only active masters may be picked; a deactivated one would make the training
// point at something no longer in use.
const inactive = (item: Competency | ProficiencyLevel) => !item.is_active

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

// Competencies of the chosen type, minus the inactive ones.
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
 * The rungs of the chosen competency's own ladder — a training targets the
 * levels of the competency it builds, so nothing is on offer until that
 * competency is picked.
 */
const competencyProficiencyLevels = computed<ProficiencyLevel[]>(() => {
    const competencyId = form.competency_id
    if (competencyId == null) return []

    return props.proficiencyLevels.filter((p) => p.competency_id === competencyId)
})

// Of those, the ones still switched on.
const proficiencyOptions = computed<Option[]>(() => {
    const pinned = new Set(form.proficiency_level_ids)

    return (
        competencyProficiencyLevels.value
            // Levels already pinned to this training stay listed even once they
            // are switched off; dropping them would silently unpin them on the
            // next save. They are flagged below instead.
            .filter((p) => !inactive(p) || pinned.has(String(p.id)))
            // The description says what the rung means, which is the only
            // thing telling PL1 from PL2 apart on a form.
            .map((p) => ({
                value: String(p.id),
                label: masterName(p),
                description: rowDescription(p) || undefined,
            }))
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
// Suppresses the cascade watchers while a row is being loaded into the form.
const loadingForm = ref(false)

function blankTraining() {
    return {
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
    }
}

const form = useForm(blankTraining())

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

// Pinned levels that have since been switched off.
const inactiveLevelNames = computed(() =>
    selectedLevels.value.filter((p) => inactive(p)).map((p) => masterName(p)),
)

// Changing the competency type drops a competency that no longer belongs to it.
// Suppressed while a row is being loaded, so opening a training whose stored
// pair has since drifted apart neither blanks it nor marks the form dirty.
watch(
    () => form.competency_type_id,
    (typeId) => {
        if (loadingForm.value) return

        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            form.competency_id = null
        }
    },
)

// Levels belong to one competency, so changing the competency drops every
// level the new one does not own. Suppressed while a row is being loaded, so
// opening an existing training never blanks what it stores.
watch(
    () => form.competency_id,
    () => {
        if (loadingForm.value) return

        const fits = new Set(competencyProficiencyLevels.value.map((p) => String(p.id)))
        form.proficiency_level_ids = form.proficiency_level_ids.filter((id) => fits.has(id))
    },
)

// Dropping a business unit drops the work locations only it offered. Suppressed
// while a row is being loaded, so what the training already stores survives
// opening the drawer even if kpncorp no longer offers it.
watch(
    () => form.business_units,
    () => {
        if (loadingForm.value) return

        const available = new Set(availableWorkLocations.value)
        form.work_locations = form.work_locations.filter((l) => available.has(l))
    },
    { deep: true },
)

function loadForm(values: ReturnType<typeof blankTraining>) {
    loadingForm.value = true
    seedForm(form, values)
    modal.value = true

    // Release the cascade guard once Vue has flushed the watchers the reset
    // queued, so restoring a row never trips them.
    nextTick(() => {
        loadingForm.value = false
    })
}

function openModal(item?: Training) {
    editingId.value = item?.id ?? null
    loadForm({
        ...blankTraining(),
        value_en: item?.value_en ?? item?.value ?? '',
        value_id: item?.value_id ?? '',
        description_en: item?.description_en ?? '',
        description_id: item?.description_id ?? '',
        competency_type_id: item?.competency_type_id ?? null,
        competency_id: item?.competency_id ?? null,
        proficiency_level_ids: (item?.proficiency_level_ids ?? []).map(String),
        business_units: [...(item?.business_units ?? [])],
        work_locations: [...(item?.work_locations ?? [])],
        is_active: item?.is_active ?? true,
    })
}

function closeModal() {
    modal.value = false
    seedForm(form, blankTraining())
}

// Closing the drawer throws the draft away, so confirm first when there is
// something to lose. Backdrop click, Escape and Cancel all route through here.
const { confirming, requestClose, discard } = useUnsavedGuard(form, closeModal)

/**
 * --------------------------------------------------------------------------
 * Activate / deactivate + its audit trail
 * --------------------------------------------------------------------------
 * Deactivating keeps the training and everything referencing it — a program
 * named from one holds a copy of that name — it only stops the training being
 * offered as a name source for new programs. Who flipped it is recorded in the
 * audit log on disk, which the history drawer reads back.
 */

const { pendingToggle, togglingId, requestToggle, confirmToggle, cancelToggle } =
    useActiveStateToggle(reloadOnly)

function toggleActive(training: Training) {
    requestToggle({
        id: training.id,
        activating: !training.is_active,
        url: route('idp.setting.masters.active', ['training', training.id]),
        name: masterName(training),
    })
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
        onSuccess: () => closeModal(),
    }

    if (editingId.value) {
        form.put(route('idp.setting.masters.update', ['training', editingId.value]), opts)
    } else {
        form.post(route('idp.setting.masters.store'), opts)
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
 *
 * The table reads as competency type → competency → training, so a training
 * is flattened into ONE ROW PER PROFICIENCY LEVEL it targets and every other
 * column merges back over that run. A training targeting no level still
 * renders one row, so it is never invisible.
 *
 * Merging needs the rows of a group to be adjacent, which is why the rows are
 * pre-sorted down that same hierarchy before ClientTable sees them.
 */

const search = ref('')

/**
 * --------------------------------------------------------------------------
 * Filters: competency type -> competency, business unit -> work location
 * --------------------------------------------------------------------------
 *
 * Every option is derived from the trainings on this page rather than from the
 * masters, so picking one can never leave the table empty: a competency type
 * no training is filed under, or a corporate site no training is offered at,
 * is simply not offered.
 *
 * Two of them cascade. A competency can be picked on its own — the type only
 * SHORTENS its option list — and the same holds for a work location and its
 * business unit. Picking a parent that no longer offers the child's current
 * value drops that value (the watchers below), so the table can never be
 * narrowed by something the dropdown does not show.
 */

const typeFilter = ref('')
const competencyFilter = ref('')
const businessUnitFilter = ref('')
const workLocationFilter = ref('')

const hasFilters = computed(
    () =>
        typeFilter.value !== '' ||
        competencyFilter.value !== '' ||
        businessUnitFilter.value !== '' ||
        workLocationFilter.value !== '',
)

function clearFilters() {
    typeFilter.value = ''
    competencyFilter.value = ''
    businessUnitFilter.value = ''
    workLocationFilter.value = ''
}

// "All …" first, then one option per distinct value, by label.
function withAllOption(label: string, options: Option[]): Option[] {
    return [
        { value: '', label },
        ...options.sort((a, b) => a.label.localeCompare(b.label)),
    ]
}

const typeFilterOptions = computed<Option[]>(() => {
    const seen = new Map<number, string>()

    for (const tr of props.trainings) {
        if (tr.competency_type_id == null) continue
        const type = competencyTypeById.value.get(tr.competency_type_id)
        seen.set(tr.competency_type_id, masterName(type) || String(tr.competency_type_id))
    }

    return withAllOption(
        t.value.idp.settings.allCompetencyTypes,
        [...seen].map(([id, label]) => ({ value: String(id), label })),
    )
})

// The competencies of the trainings the type filter leaves — every training's
// competency when no type is picked.
const competencyFilterOptions = computed<Option[]>(() => {
    const seen = new Map<number, string>()

    for (const tr of props.trainings) {
        if (tr.competency_id == null) continue
        if (typeFilter.value !== '' && String(tr.competency_type_id ?? '') !== typeFilter.value) {
            continue
        }

        const competency = competencyById.value.get(tr.competency_id)
        seen.set(tr.competency_id, masterName(competency) || String(tr.competency_id))
    }

    return withAllOption(
        t.value.idp.settings.allCompetencies,
        [...seen].map(([id, label]) => ({ value: String(id), label })),
    )
})

// Business units stand on their own — they are not narrowed by the competency
// cascade, since where a training is offered says nothing about what it builds.
const businessUnitFilterOptions = computed<Option[]>(() => {
    const seen = new Set<string>()

    for (const tr of props.trainings) {
        for (const unit of tr.business_units ?? []) seen.add(unit)
    }

    return withAllOption(
        t.value.idp.settings.allBusinessUnits,
        [...seen].map((unit) => ({ value: unit, label: unit })),
    )
})

/**
 * The sites of the trainings the business-unit filter leaves — and, once a
 * unit is picked, only the sites corporate files under THAT unit. A training
 * offered in several units carries the sites of all of them, so without the
 * second narrowing picking "Property" would still list plantation estates.
 */
const workLocationFilterOptions = computed<Option[]>(() => {
    const unit = businessUnitFilter.value
    const ofUnit = unit === '' ? null : new Set(props.workLocationsByBu[unit] ?? [])
    const seen = new Set<string>()

    for (const tr of props.trainings) {
        if (unit !== '' && !(tr.business_units ?? []).includes(unit)) continue

        for (const location of tr.work_locations ?? []) {
            // A site corporate no longer maps to the chosen unit stays
            // filterable with no unit picked, just not under that unit.
            if (ofUnit && !ofUnit.has(location)) continue
            seen.add(location)
        }
    }

    return withAllOption(
        t.value.idp.settings.allWorkLocations,
        [...seen].map((location) => ({ value: location, label: location })),
    )
})

// Drop a child value its narrowed list no longer offers. Watching the option
// list rather than the parent covers a reload changing the trainings too.
watch(competencyFilterOptions, (options) => {
    if (competencyFilter.value !== '' && !options.some((o) => o.value === competencyFilter.value)) {
        competencyFilter.value = ''
    }
})

watch(workLocationFilterOptions, (options) => {
    if (
        workLocationFilter.value !== '' &&
        !options.some((o) => o.value === workLocationFilter.value)
    ) {
        workLocationFilter.value = ''
    }
})

// Whole trainings are filtered, never single proficiency lines — keeping only
// the matching line would break the group it belongs to.
function matchesFilters(tr: Training): boolean {
    if (typeFilter.value !== '' && String(tr.competency_type_id ?? '') !== typeFilter.value) {
        return false
    }

    if (competencyFilter.value !== '' && String(tr.competency_id ?? '') !== competencyFilter.value) {
        return false
    }

    if (
        businessUnitFilter.value !== '' &&
        !(tr.business_units ?? []).includes(businessUnitFilter.value)
    ) {
        return false
    }

    if (
        workLocationFilter.value !== '' &&
        !(tr.work_locations ?? []).includes(workLocationFilter.value)
    ) {
        return false
    }

    return true
}

interface TrainingRow {
    key: string
    training: Training
    id: number
    // Stable identities for the merge: two masters may read alike, ids do not.
    // A row with no type / competency keys off its own training, so unrelated
    // trainings never merge into one blank cell.
    typeKey: string
    competencyKey: string
    trainingKey: string
    type_name: string
    type_code: string
    competency_name: string
    competency_code: string
    name: string
    description: string
    proficiency: string
    proficiency_sequence: number | null
    proficiency_code: string | null
    proficiency_description: string
    proficiency_active: boolean
    business_units: string[]
    work_locations: string[]
    is_active: boolean
}

const filtered = computed<TrainingRow[]>(() => {
    const q = search.value.trim().toLowerCase()

    const trainings = props.trainings
        // The dropdowns first, then the search box below.
        .filter(matchesFilters)
        .map((r) => {
            const levels = (r.proficiency_level_ids ?? [])
                .map((id) => proficiencyLevelById.value.get(id))
                .filter((l): l is ProficiencyLevel => l != null)
                .sort((a, b) => a.sequence - b.sequence || a.id - b.id)

            const type =
                r.competency_type_id != null
                    ? competencyTypeById.value.get(r.competency_type_id)
                    : null
            const competency =
                r.competency_id != null ? competencyById.value.get(r.competency_id) : null

            return {
                training: r,
                name: masterName(r),
                description: rowDescription(r),
                type_name: masterName(type),
                type_code: type?.code ?? '',
                competency_name: masterName(competency),
                competency_code: competency?.code ?? '',
                levels,
            }
        })
        // Filter whole trainings, not single lines: a search that kept only
        // the matching proficiency row would break the group it belongs to.
        .filter((r) =>
            q
                ? [
                      r.name,
                      r.training.value,
                      r.description,
                      r.type_name,
                      r.type_code,
                      r.competency_name,
                      r.competency_code,
                      ...r.levels.map((l) => masterName(l)),
                      ...r.levels.map((l) => l.code ?? ''),
                      ...(r.training.business_units ?? []),
                      ...(r.training.work_locations ?? []),
                  ].some((v) => v.toLowerCase().includes(q))
                : true,
        )

    trainings.sort(
        (a, b) =>
            // An untyped / unlinked training sorts after the named ones.
            Number(a.type_name === '') - Number(b.type_name === '') ||
            a.type_name.localeCompare(b.type_name) ||
            Number(a.competency_name === '') - Number(b.competency_name === '') ||
            a.competency_name.localeCompare(b.competency_name) ||
            a.name.localeCompare(b.name),
    )

    const rows: TrainingRow[] = []

    for (const r of trainings) {
        // `tr`, not `t`: the locale bundle is `t` in this file's scope.
        const tr = r.training

        const base = {
            training: tr,
            id: tr.id,
            typeKey: tr.competency_type_id == null ? `t${tr.id}` : `T${tr.competency_type_id}`,
            competencyKey: tr.competency_id == null ? `c${tr.id}` : `C${tr.competency_id}`,
            trainingKey: String(tr.id),
            type_name: r.type_name,
            type_code: r.type_code,
            competency_name: r.competency_name,
            competency_code: r.competency_code,
            name: r.name,
            description: r.description,
            business_units: tr.business_units ?? [],
            work_locations: tr.work_locations ?? [],
            is_active: tr.is_active,
        }

        if (r.levels.length === 0) {
            rows.push({
                ...base,
                key: `${tr.id}-0`,
                proficiency: '',
                proficiency_sequence: null,
                proficiency_code: null,
                proficiency_description: '',
                proficiency_active: true,
            })
            continue
        }

        for (const level of r.levels) {
            rows.push({
                ...base,
                key: `${tr.id}-${level.id}`,
                proficiency: masterName(level),
                proficiency_sequence: level.sequence,
                proficiency_code: level.code,
                proficiency_description: rowDescription(level),
                proficiency_active: level.is_active,
            })
        }
    }

    return rows
})

// How many TRAININGS the search + filters leave, not how many rows: a
// training spans one row per proficiency level it targets.
const visibleTrainingCount = computed(() => new Set(filtered.value.map((r) => r.id)).size)

const columns = computed<Column[]>(() => [
    {
        key: 'type_name',
        label: t.value.idp.settings.competencyType,
        sortable: true,
        merge: true,
        mergeKey: 'typeKey',
        thClass: 'w-44',
    },
    // Only the outermost group is sortable. Sorting by anything below it
    // would interleave the types and shatter the merged cells above; the
    // order inside a type is fixed (competency, then training name) instead.
    {
        key: 'competency_name',
        label: t.value.idp.settings.competency,
        merge: true,
        mergeKey: 'competencyKey',
        thClass: 'w-48',
    },
    {
        key: 'name',
        label: t.value.idp.settings.trainingName,
        merge: true,
        mergeKey: 'trainingKey',
        thClass: 'w-56',
    },
    { key: 'description', label: t.value.idp.settings.description, merge: true, mergeKey: 'trainingKey' },
    // The one column that is NOT merged: it is what splits the row.
    { key: 'proficiency', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-56' },
    {
        key: 'business_units',
        label: t.value.idp.settings.businessUnit,
        merge: true,
        mergeKey: 'trainingKey',
        thClass: 'w-44',
    },
    {
        key: 'work_locations',
        label: t.value.idp.settings.workLocation,
        merge: true,
        mergeKey: 'trainingKey',
        thClass: 'w-48',
    },
    // Status lives here too: the cell stacks the Active/Inactive toggle over
    // the edit / delete buttons, so one column covers everything acting on a
    // training.
    {
        key: 'actions',
        label: t.value.idp.settings.action,
        align: 'right',
        merge: true,
        mergeKey: 'trainingKey',
        thClass: 'w-48',
    },
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
        url: route('idp.setting.masters.destroy', ['training', item.id]),
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
                                {{ visibleTrainingCount }}
                                <span
                                    v-if="visibleTrainingCount !== trainings.length"
                                    class="font-normal text-slate-400"
                                >/ {{ trainings.length }}</span>
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

                <!-- Filters: competency type -> competency, business unit ->
                     work location. Each child's options are narrowed by its
                     parent; either can still be picked on its own. -->
                <div class="border-b border-border/60 bg-slate-50/60 px-5 py-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <SearchableSelect
                            v-model="typeFilter"
                            :options="typeFilterOptions"
                            :placeholder="t.idp.settings.allCompetencyTypes"
                        />

                        <SearchableSelect
                            v-model="competencyFilter"
                            :options="competencyFilterOptions"
                            :placeholder="t.idp.settings.allCompetencies"
                        />

                        <SearchableSelect
                            v-model="businessUnitFilter"
                            :options="businessUnitFilterOptions"
                            :placeholder="t.idp.settings.allBusinessUnits"
                        />

                        <div class="flex gap-2">
                            <SearchableSelect
                                v-model="workLocationFilter"
                                class="min-w-0 flex-1"
                                :options="workLocationFilterOptions"
                                :placeholder="t.idp.settings.allWorkLocations"
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

                <!-- Grouped: competency type -> competency -> training, split
                     one line per proficiency level. -->
                <!-- 20 rather than 10: a row is one proficiency line now,
                     so a page of 10 would cut nearly every training in half. -->
                <ClientTable
                    :columns="columns"
                    :rows="filtered"
                    row-key="key"
                    :per-page="20"
                    bordered
                >
                    <!-- Merged: one cell per competency type. The code sits
                         on its own line above the name; the chip is inline and
                         the name a block, which is what breaks the line. -->
                    <template #cell-type_name="{ row }">
                        <div v-if="row.type_name">
                            <span
                                v-if="row.type_code"
                                class="mb-1 inline-flex items-center rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-indigo-700"
                            >
                                {{ row.type_code }}
                            </span>
                            <div class="font-semibold text-slate-700">{{ row.type_name }}</div>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
                    </template>

                    <!-- Merged within its type: one cell per competency. -->
                    <template #cell-competency_name="{ row }">
                        <div v-if="row.competency_name">
                            <span
                                v-if="row.competency_code"
                                class="mb-1 inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                            >
                                {{ row.competency_code }}
                            </span>
                            <div class="font-medium text-slate-700">{{ row.competency_name }}</div>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
                    </template>

                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
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

                    <!-- The one unmerged column: a line per level, laid out
                         as on the Master Competency list. -->
                    <template #cell-proficiency="{ row }">
                        <ProficiencyLevelCell
                            v-if="row.proficiency"
                            :name="row.proficiency"
                            :sequence="row.proficiency_sequence"
                            :code="row.proficiency_code"
                            :description="row.proficiency_description"
                            :active="row.proficiency_active"
                        />
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
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
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
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
                        <span v-else class="text-xs italic text-slate-300">&#8212;</span>
                    </template>

                    <!-- Status over the row actions: both act on the training,
                         so they share one merged cell. -->
                    <template #cell-actions="{ row }">
                        <div class="flex flex-col items-end gap-1.5">
                            <ActiveStateCell
                                :active="row.is_active"
                                :busy="togglingId === row.id"
                                @toggle="toggleActive(row.training)"
                                @history="openHistory(row.training)"
                            />

                            <div class="flex items-center gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editTraining"
                                    @click="openModal(row.training)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteTraining"
                                    @click="deleteTraining(row.training)"
                                />
                            </div>
                        </div>
                    </template>

                    <template #empty>
                        {{ search || hasFilters ? t.idp.settings.noTrainingsMatch : t.idp.settings.none }}
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
            @close="requestClose"
        >
            <form id="training-form" class="space-y-4" @submit.prevent="submit">
                <!-- ========================================================
                     1. Scope — what the training develops
                ========================================================= -->
                <FormSection
                    :step="1"
                    :title="t.idp.settings.scope"
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

                        <!-- Proficiency levels (rungs of the chosen competency) -->
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
                                        form.competency_id == null
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        form.competency_id == null
                                            ? t.idp.settings.pickCompetencyFirst
                                            : competencyProficiencyLevels.length === 0
                                                ? t.idp.settings.noProficiencyForCompetency
                                                : t.idp.settings.noActiveProficiencyForCompetency
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
                    @click="requestClose"
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
             UNSAVED-CHANGES CONFIRMATION
        ================================================================= -->
        <!-- Shown when the training drawer is closed with a dirty form. -->

        <UnsavedChangesDialog
            :show="confirming"
            @confirm="discard"
            @close="confirming = false"
        />

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->
        <!-- ================================================================
             ACTIVATION HISTORY
        ================================================================= -->

        <!-- Activating / deactivating writes on the spot, so the badge asks. -->
        <ConfirmActiveStateDialog
            :target="pendingToggle"
            :processing="togglingId !== null"
            @confirm="confirmToggle"
            @close="cancelToggle"
        />

        <MasterStatusHistory
            :show="historyTraining !== null"
            :url="
                historyTraining
                    ? route('idp.setting.masters.statusHistory', ['training', historyTraining.id])
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
