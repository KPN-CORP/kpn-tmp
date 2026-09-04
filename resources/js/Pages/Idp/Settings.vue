<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
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

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
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
    development_model_id: number | null
    model_name: string | null
    competency_type_id: number | null
    // The training the name was taken from, or null when it was typed.
    training_id: number | null
    proficiency_level_id: number | null
    custom_proficiency_level: string | null
    grades: string[]
}

interface CompetencyType {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface ProficiencyLevel {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

/** A training in the Master Training catalogue, as a name option. */
interface Training {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
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
    // Program → the training its name was taken from, or null when typed. The
    // name itself still travels in value_en / value_id; the server copies it off
    // the training on save so the two can never disagree.
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
})

/**
 * Where a development program's name comes from: typed into the bilingual
 * fields, or taken from the Master Training catalogue.
 */
type NameSource = 'program' | 'training'

const nameSource = ref<NameSource>('program')

// The typed name, held while a training is driving the name, so switching back
// restores what was written instead of the training's text.
const typedName = ref({ en: '', id: '' })

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

function openMaster(type: MasterType, item?: Program) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    // Seed the form without the competency-type watcher reacting (it would wipe
    // the loaded selection); a fresh drawer starts with an empty snapshot cache.
    applyingOpen.value = true
    typeCache.value = {}

    masterForm.type = type

    const localized = item as Partial<Program> | undefined
    masterForm.value_en = localized?.value_en ?? item?.value ?? ''
    masterForm.value_id = localized?.value_id ?? ''

    masterForm.development_model_id =
        (item as Program)?.development_model_id ?? null

    // A program that stored a training took its name from there; everything
    // else typed it.
    masterForm.training_id =
        type === 'development_program' ? (item as Program)?.training_id ?? null : null
    nameSource.value =
        masterForm.training_id != null ? 'training' : 'program'
    typedName.value =
        nameSource.value === 'training'
            ? { en: '', id: '' }
            : { en: masterForm.value_en, id: masterForm.value_id }

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

    // Preselect the competency this program is currently linked to. A handful
    // of legacy programs carry two; the form develops one, so it opens on the
    // first and saving settles the link on it.
    masterForm.related_competencies =
        type === 'development_program' && item
            ? props.competencies
                  .filter((c) =>
                      c.related_program.includes((item as Program).id),
                  )
                  .map((c) => c.id)
                  .slice(0, 1)
            : []

    // Program scope fields (competency type / proficiency level / grades).
    const program = item as Partial<Program> | undefined
    masterForm.competency_type_id =
        type === 'development_program' ? program?.competency_type_id ?? null : null
    masterForm.proficiency_level_id =
        type === 'development_program' ? program?.proficiency_level_id ?? null : null
    masterForm.custom_proficiency_level =
        type === 'development_program' ? program?.custom_proficiency_level ?? '' : ''
    masterForm.grades =
        type === 'development_program' ? [...(program?.grades ?? [])] : []

    loadedCompetencyIds.value = [...masterForm.related_competencies]
    loadedProficiencyLevelId.value = masterForm.proficiency_level_id
    loadedGrades.value = [...masterForm.grades]
    loadedTrainingId.value = masterForm.training_id

    // Seed the cache with the loaded type's selection so that leaving it and
    // coming back restores exactly what was stored.
    if (type === 'development_program' && masterForm.competency_type_id != null) {
        typeCache.value[masterForm.competency_type_id] = snapshotType()
    }

    // Let the watcher run again once this synchronous seeding has settled.
    nextTick(() => (applyingOpen.value = false))

    masterModal.value = true
}

function submitMaster() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (masterModal.value = false),
    }

    if (editingMasterId.value) {
        masterForm.put(
            `/idp-setting/masters/${masterType.value}/${editingMasterId.value}`,
            opts,
        )
    } else {
        masterForm.post('/idp-setting/masters', opts)
    }
}

function deleteMaster(type: MasterType, id: number, name?: string) {
    pendingDelete.value = {
        url: `/idp-setting/masters/${type}/${id}`,
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
 * Program name: typed, or taken from Master Training
 * --------------------------------------------------------------------------
 */

const nameSourceOptions = computed<
    { value: NameSource; label: string; icon: string }[]
>(() => [
    {
        value: 'program',
        label: t.value.idp.settings.nameSourceProgram,
        icon: 'fa-solid fa-keyboard',
    },
    {
        value: 'training',
        label: t.value.idp.settings.nameSourceTraining,
        icon: 'fa-solid fa-graduation-cap',
    },
])

// Only active trainings can name a new program. One the program was loaded
// with keeps its place even once switched off, so editing some other field
// never silently blanks the name source.
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

// The bilingual name fields are hidden while a training supplies the name;
// every other master always types it.
const showNameInputs = computed(
    () =>
        masterType.value !== 'development_program' ||
        nameSource.value === 'program',
)

// Mirror the chosen training's name into the form, so the drawer shows exactly
// what will be stored. The server copies it again on save — that is what the
// saved name actually relies on.
function applyTrainingName() {
    const training = props.trainings.find(
        (tr) => tr.id === masterForm.training_id,
    )

    masterForm.value_en = training?.value_en ?? training?.value ?? ''
    masterForm.value_id = training?.value_id ?? ''
}

// Switching the source stashes the typed name and restores it on the way back,
// so flipping between the two never loses what was written.
watch(nameSource, (source) => {
    if (applyingOpen.value) return

    if (source === 'training') {
        typedName.value = { en: masterForm.value_en, id: masterForm.value_id }
        applyTrainingName()

        return
    }

    masterForm.training_id = null
    masterForm.value_en = typedName.value.en
    masterForm.value_id = typedName.value.id
})

watch(
    () => masterForm.training_id,
    () => {
        if (!applyingOpen.value && nameSource.value === 'training') {
            applyTrainingName()
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

// Localized proficiency-level name for the program table (or '').
function proficiencyLevelName(id: number | null): string {
    if (id == null) return ''
    const pl = proficiencyLevelById.value.get(id)
    return pl ? masterName(pl) : ''
}

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

/**
 * --------------------------------------------------------------------------
 * Development program table — program-centric list with each program's model
 * and the competencies linked to it (linking is edited from the program form).
 * Search is external; ClientTable handles sort + pagination.
 * --------------------------------------------------------------------------
 */

const programSearch = ref('')

interface ProgramRow {
    id: number
    program: Program
    name: string
    modelName: string
    percentage: number | null
    colorIndex: number
    competencies: Competency[]
    proficiency: string
    grades: string[]
}

const programRows = computed<ProgramRow[]>(() => {
    const q = programSearch.value.trim().toLowerCase()

    return props.developmentPrograms
        .map((p) => ({
            id: p.id,
            program: p,
            name: masterName(p),
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
            // Free-typed proficiency (Others) falls back onto the picked level.
            proficiency:
                proficiencyLevelName(p.proficiency_level_id) ||
                (p.custom_proficiency_level ?? ''),
            grades: p.grades ?? [],
        }))
        .filter((row) => {
            if (!q) return true
            if (row.name.toLowerCase().includes(q)) return true
            if (row.program.value.toLowerCase().includes(q)) return true
            return row.competencies.some((c) =>
                masterName(c).toLowerCase().includes(q),
            )
        })
})

/**
 * --------------------------------------------------------------------------
 * Program form: step completion
 * --------------------------------------------------------------------------
 * The form is a cascade (name → competency scope → placement), so each section
 * reports whether it is settled — the step badge turns into a check.
 */

const isProgram = computed(() => masterType.value === 'development_program')

const identityComplete = computed(() =>
    nameSource.value === 'training'
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
    { key: 'name', label: t.value.idp.settings.program, sortable: true, thClass: 'w-64' },
    { key: 'modelName', label: t.value.idp.settings.model, sortable: true, thClass: 'w-48' },
    { key: 'competencies', label: t.value.idp.settings.linkedCompetencies },
    { key: 'scope', label: t.value.idp.settings.scope, thClass: 'w-48' },
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
                                {{ developmentPrograms.length }}
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

                <!-- Program table (program → model + linked competencies) -->
                <ClientTable
                    :columns="programColumns"
                    :rows="programRows"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
                    </template>

                    <template #cell-modelName="{ row }">
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
                    </template>

                    <template #cell-competencies="{ row }">
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
                    </template>

                    <template #cell-scope="{ row }">
                        <div
                            v-if="row.proficiency || row.grades.length"
                            class="flex flex-wrap gap-1.5"
                        >
                            <span
                                v-if="row.proficiency"
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                                :title="t.idp.settings.proficiencyLevel"
                            >
                                <i class="fa-solid fa-signal text-[9px]" />
                                {{ row.proficiency }}
                            </span>
                            <span
                                v-for="g in row.grades"
                                :key="g"
                                class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600"
                                :title="t.idp.settings.grade"
                            >
                                <i class="fa-solid fa-layer-group text-[9px]" />
                                {{ g }}
                            </span>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
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
                            programSearch
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
            @close="masterModal = false"
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
                     3. Identity — what the program is called
                ========================================================= -->
                <FormSection
                    :step="3"
                    :title="isProgram ? t.idp.settings.programIdentity : t.idp.settings.name"
                    icon="fa-solid fa-tag"
                    :complete="identityComplete"
                >
                    <!-- Where the name comes from: typed, or a master training -->
                    <div
                        v-if="isProgram"
                        class="grid gap-2 sm:grid-cols-2"
                        role="radiogroup"
                        :aria-label="t.idp.settings.nameSource"
                    >
                        <button
                            v-for="option in nameSourceOptions"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="nameSource === option.value"
                            class="flex items-center gap-2.5 rounded-lg border p-3 text-left transition"
                            :class="
                                nameSource === option.value
                                    ? 'border-primary bg-primary/5 ring-1 ring-primary/20'
                                    : 'border-border bg-white hover:border-slate-300 hover:bg-slate-50'
                            "
                            @click="nameSource = option.value"
                        >
                            <span
                                class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full border transition"
                                :class="
                                    nameSource === option.value
                                        ? 'border-primary'
                                        : 'border-slate-300'
                                "
                            >
                                <span
                                    v-if="nameSource === option.value"
                                    class="h-2 w-2 rounded-full bg-primary"
                                />
                            </span>

                            <span
                                class="flex min-w-0 items-center gap-1.5 text-sm font-medium text-slate-800"
                            >
                                <i :class="option.icon" class="text-xs text-slate-400" />
                                {{ option.label }}
                            </span>
                        </button>
                    </div>

                    <!-- 3a. Name taken from the Master Training catalogue -->
                    <div v-if="isProgram && nameSource === 'training'">
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

                        <!-- The name the training resolves to, in both languages -->
                        <div
                            v-if="masterForm.training_id !== null"
                            class="mt-3 rounded-lg border border-border bg-slate-50/60 px-3 py-2.5"
                        >
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
                    </div>

                    <!-- 3b. Bilingual name, typed side by side. A program name is
                         an activity description, often a full sentence, so it wraps
                         in a textarea instead of scrolling sideways in a one-line
                         input. Enter is swallowed: the name is stored verbatim in
                         lists, exports and PDFs, where a line break has no meaning. -->
                    <div v-if="showNameInputs" class="grid gap-4 sm:grid-cols-2">
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
                </FormSection>
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
