<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'

const { t, locale } = useLocale()

interface Model {
    id: number
    development_model_package_id: number
    name: string
    name_en: string | null
    name_id: string | null
    percentage: number
    // The model decides where its programs' name + description come from: this
    // flag means the Master Training catalogue, otherwise they are typed.
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

interface Competency {
    id: number
    // The competency's short identifier; null on rows predating the column.
    code: string | null
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    // The rungs of this competency's own ladder.
    proficiency_level_ids: number[]
    related_program: number[]
    linked_programs: string[]
    is_active: boolean
}

interface Program {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    development_model_id: number | null
    model_name: string | null
    competency_type_id: number | null
    // The training the name + description were taken from, or null when typed.
    training_id: number | null
    proficiency_level_id: number | null
    custom_proficiency_level: string | null
    grades: string[]
}

interface CompetencyType {
    id: number
    // The type's short identifier; null on rows predating the column.
    code: string | null
    value: string
    value_en: string | null
    value_id: string | null
}

/**
 * One rung of a competency's own proficiency ladder — there is no shared
 * proficiency-level master any more, so every level belongs to exactly one
 * competency. Which of them a program may target is narrowed further by the
 * implementation map below.
 */
interface ProficiencyLevel {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    competency_id: number
    sequence: number
    // The rung's short identifier, unique within its competency. Null on the
    // rows that predate the column.
    code: string | null
    description_en: string | null
    description_id: string | null
    // A rung switched off is struck through in the list; the program keeps it,
    // since what a row already stores is never rejected.
    is_active: boolean
}

/** A training in the Master Training catalogue, as a name option. */
interface Training {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    // Carried so the form can read back what the program will store; the
    // server copies both again on save.
    description_en: string | null
    description_id: string | null
    // An inactive training is no longer offered as a program's name source.
    is_active: boolean
}

/**
 * One master-implementation mapping, flattened: the proficiency levels a
 * competency is implemented at and the grades that mapping covers. An empty
 * `grades` list means it covers every grade.
 */
interface Implementation {
    competency_id: number | null
    proficiency_level_ids: number[]
    grades: string[]
}

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
    const preferred = locale.value === 'id' ? model.name_id : model.name_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : model.name
}

const modelById = computed(() => {
    const m = new Map<number, Model>()
    for (const mod of props.developmentModels) m.set(mod.id, mod)
    return m
})

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

function blankMaster() {
    return {
    type: 'development_program' as MasterType,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    // Program → what the activity covers, in both languages.
    description_en: '',
    description_id: '',
    development_model_id: null as number | null,
    // Program → the training its name + description were taken from, or null
    // when typed. The values themselves still travel in value_en / value_id and
    // description_en / description_id; the server copies them off the training
    // on save so the two can never disagree.
    training_id: null as number | null,
    // Program → competency type (scopes the competency picker below).
    competency_type_id: null as number | null,
    // Program → the one competency it develops. Still posted as a list: the
    // link is a pivot (a competency reaches many programs), and the Competency
    // screen edits the other side of it.
    related_competencies: [] as number[],
    // Program → proficiency level (options come from the master
    // implementations of the picked competencies).
    proficiency_level_id: null as number | null,
    // "Others"-type program → free-typed proficiency level. Its competency
    // is picked from the masters filed under "Others", like any other type.
    custom_proficiency_level: '' as string,
    // Program → corporate scope: the grades the implementation covers for the
    // chosen proficiency level (any number of them).
    grades: [] as string[],
    }
}

const masterForm = useForm(blankMaster())

/**
 * The typed name + description, held while a training is supplying them, so
 * moving the program back onto a model that types its own never loses what was
 * written.
 */
const typedText = ref({ en: '', id: '', descEn: '', descId: '' })

/**
 * What the program held when the drawer opened. The pickers narrow to what is
 * still effective / still implemented, which would otherwise quietly drop a
 * selection made back when the masters looked different — so whatever was
 * loaded stays on offer, and the server exempts it from the same checks.
 */
const loadedCompetencyIds = ref<number[]>([])
const loadedProficiencyLevelId = ref<number | null>(null)
const loadedGrades = ref<string[]>([])
const loadedTrainingId = ref<number | null>(null)

// Localized name for a competency / program, falling back to the canonical value.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized description, falling back to the other language. '' when there is
// none.
function rowDescription(item: {
    description_en?: string | null
    description_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.description_id : item.description_en
    const fallback = locale.value === 'id' ? item.description_en : item.description_id

    return (preferred || fallback || '').trim()
}

function openMaster(type: MasterType, item?: Program) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    // Seed the form without the competency-type watcher reacting (it would wipe
    // the loaded selection); a fresh drawer starts with an empty snapshot cache.
    applyingOpen.value = true
    typeCache.value = {}

    const program = item as Partial<Program> | undefined
    const isProgram = type === 'development_program'

    const values = {
        ...blankMaster(),
        type,
        value_en: program?.value_en ?? item?.value ?? '',
        value_id: program?.value_id ?? '',
        description_en: isProgram ? program?.description_en ?? '' : '',
        description_id: isProgram ? program?.description_id ?? '' : '',
        development_model_id: (item as Program)?.development_model_id ?? null,
        // A program that stored a training took its name + description from
        // there; everything else typed them.
        training_id: isProgram ? program?.training_id ?? null : null,
        // Preselect the competency this program is currently linked to. A
        // handful of legacy programs carry two; the form develops one, so it
        // opens on the first and saving settles the link on it.
        related_competencies:
            isProgram && item
                ? props.competencies
                      .filter((c) => c.related_program.includes((item as Program).id))
                      .map((c) => c.id)
                      .slice(0, 1)
                : [],
        // Program scope fields (competency type / proficiency level / grades).
        competency_type_id: isProgram ? program?.competency_type_id ?? null : null,
        proficiency_level_id: isProgram ? program?.proficiency_level_id ?? null : null,
        custom_proficiency_level: isProgram ? program?.custom_proficiency_level ?? '' : '',
        grades: isProgram ? [...(program?.grades ?? [])] : [],
    }

    // Loaded as both data and defaults, so `isDirty` — which drives the discard
    // prompt — measures this sitting's edits (see `seedForm`).
    seedForm(masterForm, values)

    // Stash what was typed, unless a training supplied it — in which case
    // there is nothing of the user's own to come back to.
    typedText.value =
        values.training_id != null
            ? { en: '', id: '', descEn: '', descId: '' }
            : {
                  en: values.value_en,
                  id: values.value_id,
                  descEn: values.description_en,
                  descId: values.description_id,
              }

    // Resolve the package the model dropdown should be scoped to: from the
    // program's current model when editing, else default to the active package.
    if (isProgram) {
        const modelId = values.development_model_id
        const model =
            modelId != null
                ? props.developmentModels.find((m) => m.id === modelId)
                : null
        masterPackageId.value =
            model?.development_model_package_id ?? props.activePackageId ?? null
    } else {
        masterPackageId.value = null
    }

    loadedCompetencyIds.value = [...values.related_competencies]
    loadedProficiencyLevelId.value = values.proficiency_level_id
    loadedGrades.value = [...values.grades]
    loadedTrainingId.value = values.training_id

    // Seed the cache with the loaded type's selection so that leaving it and
    // coming back restores exactly what was stored.
    if (isProgram && values.competency_type_id != null) {
        typeCache.value[values.competency_type_id] = snapshotType()
    }

    // Let the watcher run again once this synchronous seeding has settled.
    nextTick(() => (applyingOpen.value = false))

    masterModal.value = true
}

function closeMaster() {
    masterModal.value = false
    seedForm(masterForm, blankMaster())
}

// Closing the drawer throws the draft away, so confirm first when there is
// something to lose. Backdrop click, Escape and Cancel all route through here.
const { confirming, requestClose, discard } = useUnsavedGuard(masterForm, closeMaster)

function submitMaster() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => closeMaster(),
    }

    if (editingMasterId.value) {
        masterForm.put(
            route('idp.setting.masters.update', [masterType.value, editingMasterId.value]),
            opts,
        )
    } else {
        masterForm.post(route('idp.setting.masters.store'), opts)
    }
}

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

// Competencies offered to the program: those of the chosen competency type
// (all of them when no type is picked) that are still active — a deactivated
// competency can no longer be developed by new work.
//
// A competency the program was loaded with keeps its place even once switched
// off, so editing some other field never silently unlinks it.
const competencyOptions = computed<Option[]>(() => {
    const loaded = new Set(loadedCompetencyIds.value)

    return props.competencies
        .filter(
            (c) =>
                (masterForm.competency_type_id == null ||
                    c.competency_type_id === masterForm.competency_type_id) &&
                (c.is_active || loaded.has(c.id)),
        )
        .map((c) => ({ value: String(c.id), label: masterName(c) }))
})

// A program develops exactly one competency; the pivot behind it still takes a
// list, so the single select reads and writes the first (only) entry.
const selectedCompetencyValue = computed<string>({
    get: () => {
        const [id] = masterForm.related_competencies
        return id == null ? '' : String(id)
    },
    set: (value) => {
        masterForm.related_competencies = value === '' ? [] : [Number(value)]
    },
})

/**
 * --------------------------------------------------------------------------
 * Program name + description: typed, or taken from Master Training
 * --------------------------------------------------------------------------
 * Which of the two applies is not the program's choice: the development model
 * it is filed under declares it (`uses_master_training`). A model that draws
 * from the catalogue asks for a training and copies its name + description; any
 * other model asks for both to be written out. The server decides the same way.
 */

// Whether the program's development model takes its programs from Master
// Training. No model chosen means the text is typed.
const usesMasterTraining = computed<boolean>(() => {
    if (masterType.value !== 'development_program') return false
    if (masterForm.development_model_id == null) return false

    return (
        modelById.value.get(masterForm.development_model_id)
            ?.uses_master_training === true
    )
})

// Only active trainings can name a new program. One the program was loaded
// with keeps its place even once switched off, so editing some other field
// never silently blanks the name.
const trainingOptions = computed<Option[]>(() =>
    props.trainings
        .filter((tr) => tr.is_active || tr.id === loadedTrainingId.value)
        .map((training) => ({
            value: String(training.id),
            label: masterName(training),
        })),
)

const selectedTrainingValue = computed<string>({
    get: () =>
        masterForm.training_id == null ? '' : String(masterForm.training_id),
    set: (value) => {
        masterForm.training_id = value === '' ? null : Number(value)
    },
})

// Mirror the chosen training's name + description into the form, so the drawer
// shows exactly what will be stored. The server copies them again on save —
// that is what the saved values actually rely on.
function applyTrainingText() {
    const training = props.trainings.find(
        (tr) => tr.id === masterForm.training_id,
    )

    masterForm.value_en = training?.value_en ?? training?.value ?? ''
    masterForm.value_id = training?.value_id ?? ''
    masterForm.description_en = training?.description_en ?? ''
    masterForm.description_id = training?.description_id ?? ''
}

// Moving the program onto a model that draws from the catalogue stashes the
// typed text and restores it on the way back, so switching models never loses
// what was written.
watch(usesMasterTraining, (uses) => {
    if (applyingOpen.value) return

    if (uses) {
        typedText.value = {
            en: masterForm.value_en,
            id: masterForm.value_id,
            descEn: masterForm.description_en,
            descId: masterForm.description_id,
        }
        applyTrainingText()

        return
    }

    masterForm.training_id = null
    masterForm.value_en = typedText.value.en
    masterForm.value_id = typedText.value.id
    masterForm.description_en = typedText.value.descEn
    masterForm.description_id = typedText.value.descId
})

watch(
    () => masterForm.training_id,
    () => {
        if (!applyingOpen.value && usesMasterTraining.value) {
            applyTrainingText()
        }
    },
)

// Competency types as SearchableSelect options for the program form.
const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((ct) => ({
        value: String(ct.id),
        label: masterName(ct),
    })),
)

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const ct of props.competencyTypes) m.set(ct.id, ct)
    return m
})

// Whether the picked competency type is the catch-all "Others" — programs on it
// free-type their competencies + proficiency level instead of picking masters.
const isOthersType = computed<boolean>(() => {
    if (masterForm.competency_type_id == null) return false
    const v = (
        competencyTypeById.value.get(masterForm.competency_type_id)?.value ?? ''
    )
        .trim()
        .toLowerCase()
    return v === 'others' || v === 'other' || v === 'lainnya'
})

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const pl of props.proficiencyLevels) m.set(pl.id, pl)
    return m
})

/**
 * --------------------------------------------------------------------------
 * Program scope, derived from the master implementations
 * --------------------------------------------------------------------------
 * Master Implementation is what says at which proficiency levels a competency
 * is actually rolled out, and to which grades. A program therefore offers only
 * the levels its competencies are implemented at, and only the grades that
 * mapping covers. The server enforces the same rule on save.
 */

// The implementation rows covering the competencies this program develops.
const implementationScopes = computed<Implementation[]>(() => {
    const selected = new Set(masterForm.related_competencies)

    return props.implementations.filter(
        (i) => i.competency_id != null && selected.has(i.competency_id),
    )
})

// The grades those implementations cover for one proficiency level, in
// corporate grade order. A mapping that lists no grades of its own covers every
// grade. Empty when no implementation maps the level at all.
function gradesForLevel(levelId: number): string[] {
    const scopes = implementationScopes.value.filter((i) =>
        i.proficiency_level_ids.includes(levelId),
    )

    if (scopes.length === 0) return []
    if (scopes.some((i) => i.grades.length === 0)) return [...props.grades]

    const covered = new Set(scopes.flatMap((i) => i.grades))

    return [
        ...props.grades.filter((g) => covered.has(g)),
        // Grades the corporate list doesn't know about (an unreachable
        // kpncorp, or a value that has since gone) still belong to the mapping.
        ...[...covered].filter((g) => !props.grades.includes(g)).sort(),
    ]
}

// Grades as compact ranges — `2-3` rather than `2, 3` — by collapsing runs that
// sit next to each other in the corporate grade order. Anything outside that
// order is listed as-is.
function gradeRangeLabel(grades: string[]): string {
    const order = new Map(props.grades.map((g, i) => [g, i]))
    const parts: string[] = []
    let run: string[] = []

    const flush = () => {
        if (run.length === 0) return
        parts.push(run.length > 1 ? `${run[0]}-${run[run.length - 1]}` : run[0])
        run = []
    }

    for (const grade of grades.filter((g) => order.has(g))) {
        const prev = run[run.length - 1]
        if (prev !== undefined && order.get(grade)! !== order.get(prev)! + 1) {
            flush()
        }
        run.push(grade)
    }
    flush()

    return [...parts, ...grades.filter((g) => !order.has(g))].join(', ')
}

// Proficiency levels available to the program: the levels its competencies are
// implemented at, each labelled with the grades that mapping covers —
// "PL1 (Grade Level 2-3)". Empty until a competency with an implementation is
// picked. The level the program was loaded with stays listed even if its
// implementation has since gone, so an edit never silently drops it.
const proficiencyLevelOptions = computed<Option[]>(() => {
    const levelIds = new Set<number>()

    for (const scope of implementationScopes.value) {
        for (const id of scope.proficiency_level_ids) levelIds.add(id)
    }

    if (loadedProficiencyLevelId.value != null) {
        levelIds.add(loadedProficiencyLevelId.value)
    }

    return [...levelIds]
        .map((id) => proficiencyLevelById.value.get(id))
        .filter((pl): pl is ProficiencyLevel => pl != null)
        .map((pl) => {
            const grades = gradeRangeLabel(gradesForLevel(pl.id))

            return {
                value: String(pl.id),
                label: grades
                    ? `${masterName(pl)} (${t.value.idp.settings.gradeLevel} ${grades})`
                    : masterName(pl),
                // What the rung means — the only thing telling PL1 from PL2
                // apart on a form.
                description: rowDescription(pl) || undefined,
            }
        })
})

// Grades offered to the program: exactly what the implementation map covers for
// the chosen proficiency level — nothing at all until a level is chosen, and
// nothing when no implementation covers it. Grades the program was loaded with
// stay listed so an edit never silently drops them.
const gradeOptions = computed<Option[]>(() => {
    const levelId = masterForm.proficiency_level_id
    const list = levelId == null ? [] : gradesForLevel(levelId)

    return [
        ...list,
        ...loadedGrades.value.filter((g) => !list.includes(g)),
    ].map((g) => ({ value: g, label: g }))
})

// Per-type snapshot of the competency-related fields, so switching competency
// type resets the selection but returning to a type restores what was chosen
// under it (kept only for the lifetime of one open drawer). Seeded on open.
interface TypeSnapshot {
    competencies: number[]
    proficiencyLevelId: number | null
    customProficiency: string
}
const typeCache = ref<Record<number, TypeSnapshot>>({})

// Guards the watcher below while openMaster is seeding the form, so loading a
// program for edit never wipes its stored competencies.
const applyingOpen = ref(false)

// Snapshot the competency-related fields as they currently stand in the form.
function snapshotType(): TypeSnapshot {
    return {
        competencies: [...masterForm.related_competencies],
        proficiencyLevelId: masterForm.proficiency_level_id,
        customProficiency: masterForm.custom_proficiency_level,
    }
}

// React to a change of competency type: stash the outgoing type's selection,
// then restore (or reset) the incoming type's. The competency itself is picked
// from the masters under every type, "Others" included; what "Others" swaps to
// free typing is the proficiency level.
watch(
    () => masterForm.competency_type_id,
    (typeId, oldTypeId) => {
        if (applyingOpen.value) return

        if (oldTypeId != null) {
            typeCache.value[oldTypeId] = snapshotType()
        }

        const snap = typeId != null ? typeCache.value[typeId] : undefined

        if (typeId == null) {
            masterForm.related_competencies = []
            masterForm.proficiency_level_id = null
            masterForm.custom_proficiency_level = ''
            return
        }

        // Restore the cached selection for this type, keeping only competencies
        // that still belong to it; else start empty.
        masterForm.related_competencies = (snap?.competencies ?? []).filter(
            (id) =>
                props.competencies.find((c) => c.id === id)?.competency_type_id ===
                typeId,
        )

        if (isOthersType.value) {
            // Free-typed level — restore any previously typed text.
            masterForm.proficiency_level_id = null
            masterForm.custom_proficiency_level = snap?.customProficiency ?? ''
            return
        }

        masterForm.custom_proficiency_level = ''
        masterForm.proficiency_level_id = snap?.proficiencyLevelId ?? null
    },
)

// If the picked competencies are no longer implemented at the chosen
// proficiency level, clear it so the form never submits an out-of-range level.
watch(proficiencyLevelOptions, (opts) => {
    if (
        masterForm.proficiency_level_id != null &&
        !opts.some((o) => o.value === String(masterForm.proficiency_level_id))
    ) {
        masterForm.proficiency_level_id = null
    }
})

// Changing the proficiency level re-scopes the grades to that level's
// implementation; drop any selection it no longer covers.
watch(gradeOptions, (opts) => {
    const offered = new Set(opts.map((o) => o.value))
    masterForm.grades = masterForm.grades.filter((g) => offered.has(g))
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

interface ProgramRow {
    key: string
    id: number
    program: Program
    name: string
    // Stable identities for the merge: two masters may read alike, ids do not.
    modelKey: string
    competencyTypeKey: string
    competencyKey: string
    competencyTypeCode: string
    competencyTypeName: string
    competencyCode: string
    competencyName: string
    proficiency: string
    proficiencySequence: number | null
    proficiencyCode: string | null
    proficiencyDescription: string
    proficiencyActive: boolean
    grades: string[]
}

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

/**
 * --------------------------------------------------------------------------
 * Column filters
 * --------------------------------------------------------------------------
 * One per grouping column, in the same order the table nests them. Each one's
 * options are drawn from the rows themselves, narrowed by the filters ABOVE it
 * in the cascade, so a filter can never offer a value that would empty the
 * table — and a choice that stops being offered is cleared rather than left
 * silently applied.
 *
 * They deliberately ignore the package/model tabs: the tab counts already point
 * at where the matches are, so keeping the option lists stable across tabs is
 * more useful than hiding a competency that lives in the next cycle.
 */

const filterType = ref('')
const filterCompetency = ref('')
const filterLevel = ref('')
const filterGrade = ref('')

const hasFilters = computed(
    () =>
        filterType.value !== '' ||
        filterCompetency.value !== '' ||
        filterLevel.value !== '' ||
        filterGrade.value !== '',
)

function clearFilters() {
    filterType.value = ''
    filterCompetency.value = ''
    filterLevel.value = ''
    filterGrade.value = ''
}

// "All …" first, then one option per distinct value, by label — the same shape
// Master Training's filters use, so the two toolbars behave identically.
function withAllOption(
    label: string,
    options: Option[],
    sorted = true,
): Option[] {
    return [
        { value: '', label },
        ...(sorted
            ? options.sort((a, b) => a.label.localeCompare(b.label))
            : options),
    ]
}

// Distinct (value, label) pairs drawn from the rows. A blank value is skipped:
// "no competency at all" is legacy data, not something worth offering.
function distinctOptions(
    rows: ProgramRow[],
    value: (row: ProgramRow) => string,
    label: (row: ProgramRow) => string,
): Option[] {
    const seen = new Map<string, string>()
    for (const row of rows) {
        const v = value(row)
        const l = label(row)
        if (v === '' || l === '' || seen.has(v)) continue
        seen.set(v, l)
    }
    return [...seen].map(([v, l]) => ({ value: v, label: l }))
}

// The cascade: each stage is the rows left after the filters above it.
const rowsForType = computed(() => baseRows.value)

const rowsForCompetency = computed(() =>
    filterType.value === ''
        ? rowsForType.value
        : rowsForType.value.filter(
              (row) => row.competencyTypeKey === filterType.value,
          ),
)

const rowsForLevel = computed(() =>
    filterCompetency.value === ''
        ? rowsForCompetency.value
        : rowsForCompetency.value.filter(
              (row) => row.competencyKey === filterCompetency.value,
          ),
)

const rowsForGrade = computed(() =>
    filterLevel.value === ''
        ? rowsForLevel.value
        : rowsForLevel.value.filter(
              (row) => String(row.proficiencySequence ?? '') === filterLevel.value,
          ),
)

const typeFilterOptions = computed<Option[]>(() =>
    withAllOption(
        t.value.idp.settings.allCompetencyTypes,
        distinctOptions(
            rowsForType.value,
            (row) => row.competencyTypeKey,
            (row) => row.competencyTypeName,
        ),
    ),
)

const competencyFilterOptions = computed<Option[]>(() =>
    withAllOption(
        t.value.idp.settings.allCompetencies,
        distinctOptions(
            rowsForCompetency.value,
            (row) => row.competencyKey,
            (row) => row.competencyName,
        ),
    ),
)

/**
 * Levels are keyed on the rung's SEQUENCE, not its id and not its name.
 *
 * A rung belongs to exactly one competency (Phase 5.19), so the id would answer
 * "this competency's first rung" when the question is "everything at rung 1".
 * The sequence is the rung's position on its competency's ladder, which is the
 * thing every competency has in common — and an integer compare rather than a
 * string one.
 *
 * The label is still the name, because that is what the table prints. Ladders
 * almost always name rung N the same way, so one name normally covers the whole
 * option; when they disagree, every name at that rung is listed rather than one
 * being picked to stand for the rest.
 */
const levelFilterOptions = computed<Option[]>(() => {
    const names = new Map<number, Set<string>>()

    for (const row of rowsForLevel.value) {
        const seq = row.proficiencySequence
        // A free-typed level (an "Others" program) has no rung, so it has no
        // sequence to file under and is not offered here.
        if (seq == null) continue
        if (!names.has(seq)) names.set(seq, new Set())
        if (row.proficiency !== '') names.get(seq)!.add(row.proficiency)
    }

    const options = [...names]
        .sort(([a], [b]) => a - b)
        .map(([seq, labels]) => ({
            value: String(seq),
            label: labels.size ? [...labels].sort().join(' / ') : String(seq),
        }))

    return withAllOption(
        t.value.idp.settings.allProficiencyLevels,
        options,
        false,
    )
})

const gradeFilterOptions = computed<Option[]>(() => {
    const seen = new Set<string>()
    for (const row of rowsForGrade.value) {
        for (const g of row.grades) seen.add(g)
    }
    return withAllOption(
        t.value.idp.settings.allGrades,
        [...seen].map((g) => ({ value: g, label: g })),
    )
})

// A choice the cascade no longer offers is dropped, so the table never filters
// on something the toolbar cannot show.
watch(competencyFilterOptions, (opts) => {
    if (
        filterCompetency.value !== '' &&
        !opts.some((o) => o.value === filterCompetency.value)
    ) {
        filterCompetency.value = ''
    }
})

watch(levelFilterOptions, (opts) => {
    if (
        filterLevel.value !== '' &&
        !opts.some((o) => o.value === filterLevel.value)
    ) {
        filterLevel.value = ''
    }
})

watch(gradeFilterOptions, (opts) => {
    if (
        filterGrade.value !== '' &&
        !opts.some((o) => o.value === filterGrade.value)
    ) {
        filterGrade.value = ''
    }
})

// Search is applied last and deliberately does NOT narrow the option lists:
// free text that reshuffles four dropdowns as it is typed is unreadable.
const programRows = computed<ProgramRow[]>(() => {
    const rows =
        filterGrade.value === ''
            ? rowsForGrade.value
            : rowsForGrade.value.filter((row) =>
                  row.grades.includes(filterGrade.value),
              )

    const q = programSearch.value.trim().toLowerCase()
    if (!q) return rows

    return rows.filter((row) =>
        [
            row.name,
            row.program.value,
            row.competencyName,
            row.competencyCode,
            row.competencyTypeName,
            row.competencyTypeCode,
            row.proficiency,
        ].some((field) => field.toLowerCase().includes(q)),
    )
})

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

/**
 * --------------------------------------------------------------------------
 * Program form: step completion
 * --------------------------------------------------------------------------
 * The form is a cascade (name → competency scope → placement), so each section
 * reports whether it is settled — the step badge turns into a check.
 */

const isProgram = computed(() => masterType.value === 'development_program')

const identityComplete = computed(() =>
    usesMasterTraining.value
        ? masterForm.training_id != null && masterForm.value_en.trim() !== ''
        : masterForm.value_en.trim() !== '',
)

const scopeComplete = computed(
    () =>
        masterForm.competency_type_id != null &&
        masterForm.related_competencies.length > 0,
)

const placementComplete = computed(
    () => masterForm.development_model_id !== null,
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
             MASTER DATA MODAL
        ================================================================= -->

        <Drawer
            :show="masterModal"
            :title="masterTitle()"
            max-width="max-w-3xl"
            @close="requestClose"
        >
            <form
                id="master-form"
                class="space-y-4"
                @submit.prevent="submitMaster"
            >
                <!-- ========================================================
                     1. Scope — what the program develops, and for whom
                ========================================================= -->
                <FormSection
                    v-if="isProgram"
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
                                    masterForm.competency_type_id == null
                                        ? ''
                                        : String(masterForm.competency_type_id)
                                "
                                :options="competencyTypeOptions"
                                :placeholder="t.idp.settings.selectCompetencyType"
                                :invalid="!!masterForm.errors.competency_type_id"
                                @update:model-value="
                                    masterForm.competency_type_id =
                                        $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-if="masterForm.errors.competency_type_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.competency_type_id }}
                            </p>
                        </div>

                        <!-- Competency — a master filed under the chosen type -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.competency }}
                                <span class="text-red-500">*</span>
                            </label>

                            <!-- Waiting on a competency type -->
                            <p
                                v-if="masterForm.competency_type_id == null"
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i class="fa-solid fa-lock mt-0.5 text-[10px] text-slate-300" />
                                <span>{{ t.idp.settings.pickTypeFirst }}</span>
                            </p>

                            <template v-else>
                                <SearchableSelect
                                    v-if="competencyOptions.length"
                                    v-model="selectedCompetencyValue"
                                    :options="competencyOptions"
                                    :placeholder="t.idp.settings.searchCompetency"
                                    :invalid="!!masterForm.errors.related_competencies"
                                />
                                <p
                                    v-else
                                    class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                                >
                                    <i
                                        class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400"
                                    />
                                    <span>{{ t.idp.settings.noCompetenciesForType }}</span>
                                </p>

                                <p
                                    v-if="masterForm.errors.related_competencies"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ masterForm.errors.related_competencies }}
                                </p>
                            </template>
                        </div>

                        <!-- Proficiency level (from the master implementation) -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.proficiencyLevel }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>

                            <p
                                v-if="masterForm.competency_type_id == null"
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i class="fa-solid fa-lock mt-0.5 text-[10px] text-slate-300" />
                                <span>{{ t.idp.settings.pickTypeFirst }}</span>
                            </p>

                            <template v-else-if="!isOthersType">
                                <SearchableSelect
                                    v-if="proficiencyLevelOptions.length"
                                    :model-value="
                                        masterForm.proficiency_level_id == null
                                            ? ''
                                            : String(masterForm.proficiency_level_id)
                                    "
                                    :options="proficiencyLevelOptions"
                                    :placeholder="t.idp.settings.proficiencyLevelPickHint"
                                    :invalid="!!masterForm.errors.proficiency_level_id"
                                    @update:model-value="
                                        masterForm.proficiency_level_id =
                                            $event === '' ? null : Number($event)
                                    "
                                />
                                <p
                                    v-else
                                    class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                                >
                                    <i
                                        class="mt-0.5 text-[10px] text-slate-300"
                                        :class="
                                            masterForm.related_competencies.length
                                                ? 'fa-solid fa-circle-info'
                                                : 'fa-solid fa-lock'
                                        "
                                    />
                                    <span>
                                        {{
                                            masterForm.related_competencies.length
                                                ? t.idp.settings.noImplementedProficiency
                                                : t.idp.settings.pickCompetencyFirst
                                        }}
                                    </span>
                                </p>

                                <p
                                    v-if="masterForm.errors.proficiency_level_id"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ masterForm.errors.proficiency_level_id }}
                                </p>
                            </template>

                            <!-- "Others" type → free-type the proficiency level -->
                            <input
                                v-else
                                v-model="masterForm.custom_proficiency_level"
                                type="text"
                                :placeholder="t.idp.settings.customProficiencyPlaceholder"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <!-- Grades covered by that implementation -->
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.settings.grade }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>

                            <MultiSelect
                                v-if="gradeOptions.length"
                                v-model="masterForm.grades"
                                :options="gradeOptions"
                                :placeholder="t.idp.settings.gradePickHint"
                                :invalid="!!masterForm.errors.grades"
                                select-all
                                :select-all-label="t.idp.settings.selectAllGrades"
                                :clear-all-label="t.idp.settings.clearAllGrades"
                            />
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i
                                    class="mt-0.5 text-[10px] text-slate-300"
                                    :class="
                                        masterForm.proficiency_level_id == null
                                            ? 'fa-solid fa-lock'
                                            : 'fa-solid fa-circle-info'
                                    "
                                />
                                <span>
                                    {{
                                        masterForm.proficiency_level_id == null
                                            ? t.idp.settings.pickProficiencyFirst
                                            : t.idp.settings.noGradesForProficiency
                                    }}
                                </span>
                            </p>

                            <p
                                v-if="masterForm.errors.grades"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.grades }}
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     2. Placement — package + development model
                ========================================================= -->
                <FormSection
                    v-if="isProgram"
                    :step="2"
                    :title="t.idp.settings.programPlacement"
                    icon="fa-solid fa-cubes"
                    :complete="placementComplete"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
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

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
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
                                :invalid="!!masterForm.errors.development_model_id"
                                :placeholder="
                                    masterPackageId == null
                                        ? t.idp.settings.selectPackageFirst
                                        : t.idp.settings.selectModel
                                "
                                @update:model-value="
                                    masterForm.development_model_id =
                                        $event === '' ? null : Number($event)
                                "
                            />
                            <p
                                v-if="masterForm.errors.development_model_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.development_model_id }}
                            </p>
                        </div>
                    </div>
                </FormSection>

                <!-- ========================================================
                     3. Identity — what the program is called. Where the name
                     and description come from is the development model's call
                     (`uses_master_training`), not a choice made here.
                ========================================================= -->
                <FormSection
                    :step="3"
                    :title="isProgram ? t.idp.settings.programIdentity : t.idp.settings.name"
                    icon="fa-solid fa-tag"
                    :complete="identityComplete"
                >
                    <!-- Says why the fields below look the way they do -->
                    <p
                        v-if="usesMasterTraining"
                        class="flex items-start gap-2 rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-xs text-slate-600"
                    >
                        <i class="fa-solid fa-graduation-cap mt-0.5 text-[10px] text-primary" />
                        <span>{{ t.idp.settings.nameFromModelTraining }}</span>
                    </p>

                    <!-- 3a. Name + description taken from Master Training -->
                    <div v-if="usesMasterTraining">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.training }}
                            <span class="text-red-500">*</span>
                        </label>

                        <SearchableSelect
                            v-if="trainingOptions.length"
                            v-model="selectedTrainingValue"
                            :options="trainingOptions"
                            :placeholder="t.idp.settings.searchTraining"
                            :invalid="
                                !!masterForm.errors.training_id ||
                                !!masterForm.errors.value_en
                            "
                        />
                        <p
                            v-else
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400" />
                            <span>{{ t.idp.settings.noTrainings }}</span>
                        </p>

                        <p
                            v-if="masterForm.errors.training_id || masterForm.errors.value_en"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ masterForm.errors.training_id || masterForm.errors.value_en }}
                        </p>

                        <!-- What the training resolves to — the name and the
                             description, in both languages, exactly as they
                             will be stored on the program. -->
                        <div
                            v-if="masterForm.training_id !== null"
                            class="mt-3 space-y-3 rounded-lg border border-border bg-slate-50/60 px-3 py-2.5"
                        >
                            <div>
                                <p
                                    class="text-[10px] font-semibold uppercase tracking-wide text-slate-400"
                                >
                                    {{ t.idp.settings.savedName }}
                                </p>
                                <div class="mt-1.5 space-y-1.5">
                                    <p class="flex items-start gap-2 text-sm text-slate-700">
                                        <span
                                            class="mt-0.5 inline-flex shrink-0 items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                        >
                                            EN
                                        </span>
                                        <span class="min-w-0 break-words">
                                            {{ masterForm.value_en || '—' }}
                                        </span>
                                    </p>
                                    <p class="flex items-start gap-2 text-sm text-slate-700">
                                        <span
                                            class="mt-0.5 inline-flex shrink-0 items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                                        >
                                            ID
                                        </span>
                                        <span class="min-w-0 break-words">
                                            {{ masterForm.value_id || '—' }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div class="border-t border-border/60 pt-2.5">
                                <p
                                    class="text-[10px] font-semibold uppercase tracking-wide text-slate-400"
                                >
                                    {{ t.idp.settings.description }}
                                </p>
                                <div class="mt-1.5 space-y-1.5">
                                    <p class="flex items-start gap-2 text-sm text-slate-700">
                                        <span
                                            class="mt-0.5 inline-flex shrink-0 items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                        >
                                            EN
                                        </span>
                                        <span class="min-w-0 break-words">
                                            {{ masterForm.description_en || '—' }}
                                        </span>
                                    </p>
                                    <p class="flex items-start gap-2 text-sm text-slate-700">
                                        <span
                                            class="mt-0.5 inline-flex shrink-0 items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                                        >
                                            ID
                                        </span>
                                        <span class="min-w-0 break-words">
                                            {{ masterForm.description_id || '—' }}
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 3b. Bilingual name, typed side by side. A program name is
                         an activity description, often a full sentence, so it wraps
                         in a textarea instead of scrolling sideways in a one-line
                         input. Enter is swallowed: the name is stored verbatim in
                         lists, exports and PDFs, where a line break has no meaning. -->
                    <div v-if="!usesMasterTraining" class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label
                                class="mb-1.5 flex items-center gap-1.5 text-sm font-medium text-slate-700"
                            >
                                <span
                                    class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                >
                                    EN
                                </span>
                                {{ t.idp.settings.english }}
                                <span class="text-red-500">*</span>
                            </label>
                            <textarea
                                v-model="masterForm.value_en"
                                rows="3"
                                :placeholder="t.idp.settings.namePlaceholderEn"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.value_en
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                                @keydown.enter.prevent
                            />
                            <p
                                v-if="masterForm.errors.value_en"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.value_en }}
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
                                {{ t.idp.settings.bahasa }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="masterForm.value_id"
                                rows="3"
                                :placeholder="t.idp.settings.namePlaceholderId"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.value_id
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                                @keydown.enter.prevent
                            />
                            <p
                                v-if="masterForm.errors.value_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.value_id }}
                            </p>
                        </div>
                    </div>

                    <!-- 3c. Bilingual description — what the activity covers.
                         Only a program carries one; a review tool is a bare
                         label. Line breaks are kept here: unlike the name, a
                         description is only ever read as a block of text. -->
                    <div
                        v-if="isProgram && !usesMasterTraining"
                        class="grid gap-4 sm:grid-cols-2"
                    >
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
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="masterForm.description_en"
                                rows="3"
                                :placeholder="t.idp.settings.programDescriptionPlaceholderEn"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.description_en
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            />
                            <p
                                v-if="masterForm.errors.description_en"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.description_en }}
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
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="masterForm.description_id"
                                rows="3"
                                :placeholder="t.idp.settings.programDescriptionPlaceholderId"
                                class="w-full resize-y rounded-md border bg-white px-3 py-2 text-sm leading-relaxed focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.description_id
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            />
                            <p
                                v-if="masterForm.errors.description_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.description_id }}
                            </p>
                        </div>
                    </div>
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
                    form="master-form"
                    :disabled="masterForm.processing"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >
                    <i
                        v-if="masterForm.processing"
                        class="fa-solid fa-circle-notch fa-spin text-xs"
                    />
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             UNSAVED-CHANGES CONFIRMATION
        ================================================================= -->

        <UnsavedChangesDialog
            :show="confirming"
            @confirm="discard"
            @close="confirming = false"
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
