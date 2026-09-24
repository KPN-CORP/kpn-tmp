<script setup lang="ts">
/**
 * An employee's development plan, as the two-stage workflow sees it.
 *
 *   1. Planning        — write the programs (this panel's plan drawer)
 *   2. Planning approval — submit the whole set once; it is frozen while it is
 *                          with an approver
 *   3. Results         — file each program's realization + evidence
 *   4. Result approval — each result walks the chain on its own
 *
 * The StageTracker at the top says where the plan stands and what to do next;
 * each PlanRow carries the same two stages for one program. This component
 * orchestrates them: it owns the plan form (and its master-driven cascade), the
 * drawers, and the calls that move the workflow along.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import StageTracker from '@/Components/Domain/Idp/StageTracker.vue'
import PlanSignOffCard from '@/Components/Domain/Idp/PlanSignOffCard.vue'
import PlanTable from '@/Components/Domain/Idp/PlanTable.vue'
import ResultDrawer from '@/Components/Domain/Idp/ResultDrawer.vue'
import DecisionDrawer from '@/Components/Domain/Idp/DecisionDrawer.vue'
import ApprovalChain from '@/Components/Domain/Idp/ApprovalChain.vue'
import PlanFilters from '@/Components/Domain/Idp/PlanFilters.vue'
import {
    blankPlanFilters,
    hasPlanFilters,
    matchesPlanFilters,
    type PlanFilterState,
} from '@/Components/Domain/Idp/planFilters'
import { timelineKey, type TimelineKey } from '@/Components/Domain/Idp/planStatus'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { formatDateTime } from '@/Composables/useDate'
import { route } from '@/Config/route'
import type {
    ApprovalInfo,
    DevelopmentModelView,
    MasterOption,
    PackageOption,
    Plan,
    PlanningState,
    ProgramOption,
    StageProgress,
} from '@/types/idp'

const { t, locale } = useLocale()

const props = withDefaults(
    defineProps<{
        employee: { employee_id: string; fullname: string; designation_name: string | null }
        developmentModels: DevelopmentModelView[]
        options: {
            competencyTypes: MasterOption[]
            competencyNames: MasterOption[]
            developmentPrograms: ProgramOption[]
            reviewTools: MasterOption[]
        }
        competencyMap: Record<string, ProgramOption[]>
        planning: PlanningState
        progress: StageProgress
        /** Every development-model cycle, for the picker. */
        packages: PackageOption[]
        selectedPackageId: number | null
        /** False for a closed cycle: its plans are readable, nothing more. */
        viewingActive: boolean
        /** Where to reload when another cycle is picked (the host page's route). */
        reloadUrl: string
        // Show the add / edit / delete / submit controls; the profile's inline
        // tab is view-only.
        canEdit?: boolean
        /** Pin the tracker under the top bar so the plans scroll beneath it. */
        stickyHeader?: boolean
    }>(),
    { canEdit: true, stickyHeader: false },
)

/**
 * A closed cycle is read-only whatever the viewer may normally do, so the row
 * actions are not merely disabled — the whole column goes.
 */
const rowsEditable = computed(() => props.canEdit && props.viewingActive)

/** Switching cycles is a filter: same page, different package. */
function selectPackage(id: number) {
    if (id === props.selectedPackageId) return

    router.get(props.reloadUrl, { package: id }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

const emp = props.employee

// Pick the model description in the active language (fall back to the other).
function modelDescription(model: DevelopmentModelView): string {
    const primary = locale.value === 'id' ? model.description_id : model.description_en
    return (primary || model.description_en || model.description_id || '').trim()
}

const hasDescriptions = computed(() => props.developmentModels.some((m) => modelDescription(m) !== ''))

// Split a model description into display lines; a leading "-" / "•" marks a
// bullet. Locale-agnostic (only the marker is detected, not the label text).
function descLines(model: DevelopmentModelView): Array<{ text: string; bullet: boolean }> {
    return modelDescription(model)
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) =>
            /^[-•]\s?/.test(line)
                ? { text: line.replace(/^[-•]\s?/, ''), bullet: true }
                : { text: line, bullet: false },
        )
}

const modalOpen = ref(false)
const editingId = ref<number | null>(null)

// The empty plan — PLANNING fields only. The realization date and the result
// evidence belong to stage 3 and are filed through the result drawer, so that
// editing a plan and reporting on it stay separate acts with separate approvals.
//
// Inertia rewrites a form's defaults to the submitted data on a successful
// visit, so `reset()` alone would refill the drawer with the plan that was just
// saved — hence every open seeds both the data and the defaults from here (or
// from the plan being edited).
function blankPlan() {
    return {
        employee_id: emp.employee_id,
        development_model_id: null as number | null,
        competency_type: '',
        competency_name: '',
        development_program: '',
        review_tools: '',
        expected_outcome: '',
        time_frame_start: '',
        time_frame_end: '',
    }
}

const form = useForm(blankPlan())

const currentModel = computed(() =>
    props.developmentModels.find((m) => m.id === form.development_model_id) ?? null,
)

// Localized display label for a master option; the canonical `value` stays the
// stored/matched key while value_en / value_id drive what the user sees.
function masterLabel(item: MasterOption): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// A competency is always filed under a type (the master form requires one), so
// the type scopes it strictly: an untyped competency is legacy data and belongs
// under no type at all. Mirrors the server-side check in
// StoreIndividualDevelopmentPlanRequest.
//
// The predicates take the type explicitly rather than reading the form, because
// the competency-type picker itself asks them "would THIS type offer anything?"
// — which cannot be answered against the type currently selected.
function matchesTypeName(item: MasterOption, type: string): boolean {
    const masterType = (item.competency_type ?? '').trim()
    return type !== '' && masterType !== '' && masterType.toLowerCase() === type.toLowerCase()
}

// Development programs are scoped the other way round: an untyped program is
// global and fits every type. They are narrowed primarily by the competency
// they build, and treating a missing type as "none" would empty the picker.
function fitsTypeName(item: MasterOption, type: string): boolean {
    const masterType = (item.competency_type ?? '').trim()
    return type === '' || masterType === '' || masterType.toLowerCase() === type.toLowerCase()
}

// A program is filed under one development model (the 70-20-10 split) and the
// plan is being added under one, so only that model's programs may fill it. A
// program with no model is legacy data and, by the same convention the
// competency type uses, counts as global.
function fitsModel(item: ProgramOption): boolean {
    const modelId = item.model_id ?? null
    return modelId === null || form.development_model_id === null || modelId === form.development_model_id
}

// The programs a competency builds, from the master link. Undefined when the
// competency has none at all, which by convention makes it global: the program
// picker then falls back to the full catalogue, and the server mirrors that.
function linksFor(name: string): ProgramOption[] | undefined {
    return name ? props.competencyMap[name.toLowerCase().trim()] : undefined
}

// A program the plan could actually pick: filed under this plan's development
// model, and under the given competency type.
function selectableFor(program: ProgramOption, type: string): boolean {
    return fitsModel(program) && fitsTypeName(program, type)
}

function selectable(program: ProgramOption): boolean {
    return selectableFor(program, form.competency_type.trim())
}

// A competency is only worth offering when it reaches a program this plan could
// pick — i.e. one filed under the development model the plan sits under. A
// competency with no linked programs at all is global, so it stays on offer.
function reachesModelFor(item: MasterOption, type: string): boolean {
    const links = linksFor(item.value)
    return !links?.length || links.some((program) => selectableFor(program, type))
}

function reachesModel(item: MasterOption): boolean {
    return reachesModelFor(item, form.competency_type.trim())
}

// The competencies filed under a given type, before the development-model
// narrowing — what tells "this type has none" apart from "none under this model".
function competenciesOfType(type: string): MasterOption[] {
    return props.options.competencyNames.filter((item) => matchesTypeName(item, type))
}

// Competencies of the chosen type, before the development-model narrowing.
const typedCompetencies = computed<MasterOption[]>(() =>
    competenciesOfType(form.competency_type.trim()),
)

// Competency names are narrowed by the competency type AND by the development
// model the plan is filed under. The catch-all "Others" type is no exception:
// it picks a master competency like every other type.
const competencyOptions = computed<MasterOption[]>(() =>
    typedCompetencies.value.filter(reachesModel),
)

// The programs that build the chosen competency, before the model / type
// narrowing — what `programOptions` starts from.
const linkedPrograms = computed<ProgramOption[] | undefined>(() =>
    linksFor(form.competency_name),
)

// Programs are narrowed by all three of the plan's own choices: the development
// model it is filed under, the competency it builds, and the competency type.
const programOptions = computed<ProgramOption[]>(() =>
    (linkedPrograms.value?.length ? linkedPrograms.value : props.options.developmentPrograms)
        .filter(selectable),
)

// Build select options with localized labels. Keep the currently-selected value
// visible even if it is no longer in the master list (legacy free-text plans),
// so editing never drops a saved value.
function toSelectOptions(items: MasterOption[], current: string): Option[] {
    const options = items.map((item) => ({ value: item.value, label: masterLabel(item) }))
    if (current && !items.some((item) => item.value === current)) {
        options.unshift({ value: current, label: current })
    }
    return options
}

const competencyNameOptions = computed(() => toSelectOptions(competencyOptions.value, form.competency_name))
const programSelectOptions = computed(() => toSelectOptions(programOptions.value, form.development_program))
const reviewToolsOptions = computed(() => toSelectOptions(props.options.reviewTools, form.review_tools))

// Canonical value → localized label maps, so the plans table also follows the
// active language (plans store the canonical English value).
function labelMap(items: MasterOption[]): Record<string, string> {
    return Object.fromEntries(items.map((item) => [item.value, masterLabel(item)]))
}
const competencyLabels = computed(() => labelMap(props.options.competencyNames))
const programLabels = computed(() => labelMap(props.options.developmentPrograms))
const reviewToolLabels = computed(() => labelMap(props.options.reviewTools))
const competencyTypeLabels = computed(() => labelMap(props.options.competencyTypes))

function localize(map: Record<string, string>, value: string | null): string {
    return value ? (map[value] ?? value) : ''
}

// The competency types are master data, not a fixed pair. They are narrowed by
// the development model the plan is filed under, the same way the two pickers
// below them are: a type is only worth offering when it holds a competency that
// reaches a development program filed under this model, since otherwise the
// cascade dead-ends on the very next field. A type whose competencies have no
// linked programs at all stays on offer — those are global, and the program
// picker then falls back to the model's whole catalogue.
function typeUsable(type: string): boolean {
    return competenciesOfType(type).some((item) => reachesModelFor(item, type))
}

const usableCompetencyTypes = computed<MasterOption[]>(() =>
    props.options.competencyTypes.filter((item) => typeUsable(item.value)),
)

// Plans store the type name verbatim, so a type the master no longer offers
// stays selectable on a plan that already uses it (same treatment the other
// masters get).
const competencyTypeOptions = computed<Option[]>(() =>
    toSelectOptions(usableCompetencyTypes.value, form.competency_type),
)

// No type at all can be used under this development model — a dead end worth
// spelling out, rather than rendering an empty control.
const noUsableTypes = computed(
    () => !usableCompetencyTypes.value.length && !form.competency_type,
)

// The plan stores a type the picker no longer offers. It stays selectable (the
// server exempts it too) but is flagged, telling "the master is gone" apart
// from "nothing under this development model".
const typeIsAMaster = computed(() =>
    props.options.competencyTypes.some((item) => item.value === form.competency_type),
)

const typeOffList = computed(
    () =>
        !!form.competency_type &&
        !usableCompetencyTypes.value.some((item) => item.value === form.competency_type),
)

// --- Plan form state: section progress, locked steps, and inline warnings ---

// Each section of the drawer turns "complete" once its required fields hold a
// value, so progress through the cascade is visible in the step badges.
const areaComplete = computed(() => !!form.competency_type && !!form.competency_name)
const programComplete = computed(() => !!form.development_program)
const timelineComplete = computed(() => !!form.time_frame_start)

// A dependent field stays locked (with an explanation) rather than showing an
// empty dropdown the user cannot account for.
const competencyLocked = computed(() => !form.competency_type)
const programLocked = computed(() => !form.competency_name)

// Whether the program list is narrowed to the chosen competency's linked
// programs, or fell back to the full catalogue because it has none.
const programNarrowed = computed(() => !!linkedPrograms.value?.length)

// The competency does build programs — just none of them under the development
// model this plan is filed against. That is a different problem from "this
// competency has no programs at all", so it gets its own note.
const programsWrongModel = computed(
    () => programNarrowed.value && !programOptions.value.length,
)

// Program names are activity descriptions running to a few hundred characters,
// so the picker's one-line trigger cannot show what was chosen. Read the full
// text back out underneath it instead.
const selectedProgramLabel = computed(() => {
    if (!form.development_program) return ''
    const match = programOptions.value.find((p) => p.value === form.development_program)
    return match ? masterLabel(match) : form.development_program
})

// A value the plan already stores but the picker no longer offers. It stays
// selectable — the server exempts it too — but is flagged so the user knows why
// it is not in the list. There are three reasons, and they are not the same
// problem to fix: the master was deactivated, it is filed under another
// competency type, or its programs all sit under another development model.
const competencyIsAMaster = computed(() =>
    props.options.competencyNames.some((c) => c.value === form.competency_name),
)

const competencyOffList = computed(
    () =>
        !!form.competency_name &&
        !competencyOptions.value.some((c) => c.value === form.competency_name),
)

// Filed under another competency type.
const competencyTypeMismatch = computed(
    () =>
        competencyOffList.value &&
        competencyIsAMaster.value &&
        !typedCompetencies.value.some((c) => c.value === form.competency_name),
)

// Right type, but every program it builds sits under another development model.
const competencyModelMismatch = computed(
    () =>
        competencyOffList.value &&
        competencyIsAMaster.value &&
        typedCompetencies.value.some((c) => c.value === form.competency_name),
)

// The type does have competencies — just none reachable from this plan's
// development model. A different problem from "this type has none at all", so
// it gets its own note.
const competenciesWrongModel = computed(
    () => !!typedCompetencies.value.length && !competencyOptions.value.length,
)
const reviewToolInactive = computed(
    () =>
        !!form.review_tools &&
        !props.options.reviewTools.some((r) => r.value === form.review_tools),
)

// Live read-out of the time frame, so a mistyped year is obvious before saving.
const timeframeDays = computed(() => {
    if (!form.time_frame_start || !form.time_frame_end) return null
    const start = new Date(form.time_frame_start)
    const end = new Date(form.time_frame_end)
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return null
    return Math.round((end.getTime() - start.getTime()) / 86_400_000)
})

const endBeforeStart = computed(() => (timeframeDays.value ?? 0) < 0)

const durationLabel = computed(() => {
    const days = timeframeDays.value
    if (days === null || days < 0) return ''
    const inclusive = days + 1
    return inclusive >= 60
        ? `${Math.round(inclusive / 30)} ${t.value.idp.form.durationMonths}`
        : `${inclusive} ${t.value.idp.form.durationDays}`
})

// Validation errors come back attached to fields spread down a scrolling
// drawer; summarise them at the top so a failed save is never silent.
const errorLabels = computed<Record<string, string>>(() => ({
    development_model_id: t.value.idp.form.developmentModel,
    competency_type: t.value.idp.form.type,
    competency_name: t.value.idp.form.competencyName,
    review_tools: t.value.idp.form.reviewTools,
    development_program: t.value.idp.form.program,
    expected_outcome: t.value.idp.form.expectedOutcome,
    time_frame_start: t.value.idp.form.start,
    time_frame_end: t.value.idp.form.end,
}))

// Required fields still empty, named in the footer so the user can see what a
// save is waiting on instead of discovering it from a rejection.
const missingRequired = computed(() => {
    const missing: string[] = []
    if (!form.competency_type) missing.push(t.value.idp.form.type)
    if (!form.competency_name) missing.push(t.value.idp.form.competencyName)
    if (!form.development_program) missing.push(t.value.idp.form.program)
    if (!form.time_frame_start) missing.push(t.value.idp.form.start)
    return missing
})

const errorList = computed(() =>
    Object.entries(form.errors)
        .filter(([, message]) => !!message)
        .map(([key, message]) => ({
            key,
            label: errorLabels.value[key] ?? key,
            message: message as string,
        })),
)

// --- The plans table -------------------------------------------------------

const statusStyle: Record<TimelineKey, { badge: string; dot: string }> = {
    completed: { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' },
    inProgress: { badge: 'bg-amber-50 text-amber-700 ring-amber-600/20', dot: 'bg-amber-500' },
    upcoming: { badge: 'bg-sky-50 text-sky-700 ring-sky-600/20', dot: 'bg-sky-500' },
    overdue: { badge: 'bg-red-50 text-red-700 ring-red-600/20', dot: 'bg-red-500' },
    planned: { badge: 'bg-slate-100 text-slate-600 ring-slate-500/20', dot: 'bg-slate-400' },
}

// `solid` is the filled percentage badge on a model's card; `chip` and `bar`
// are the tinted pill and the edge stripe.
const accents = [
    { bar: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700', solid: 'bg-emerald-500' },
    { bar: 'bg-sky-500', chip: 'bg-sky-50 text-sky-700', solid: 'bg-sky-500' },
    { bar: 'bg-violet-500', chip: 'bg-violet-50 text-violet-700', solid: 'bg-violet-500' },
    { bar: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700', solid: 'bg-amber-500' },
]

// The table is narrowed entirely in the browser: every plan of the cycle is
// already in the props, so filtering costs nothing and the tracker's totals —
// which describe the plan, not the current view of it — stay untouched.
const filters = ref<PlanFilterState>(blankPlanFilters())
const filtering = computed(() => hasPlanFilters(filters.value))

// A cycle switch reloads with `preserveState`, so this component — and its
// filters — survive it. Carried over to another cycle's plans they would match
// nothing while every select read its "All …" placeholder, since a value the new
// cycle does not offer resolves to no label; on a cycle with no plans at all they
// would also replace the per-model "Add plan" panels with "no plan matches".
watch(
    () => props.selectedPackageId,
    () => {
        filters.value = blankPlanFilters()
    },
)
const allPlans = computed(() => props.developmentModels.flatMap((m) => m.plans))

const modelsView = computed(() =>
    props.developmentModels.map((model, index) => ({
        ...model,
        accent: accents[index % accents.length],
        rows: model.plans
            .filter((plan) => matchesPlanFilters(plan, filters.value))
            .map((plan) => {
                const key = timelineKey(plan)
                return { plan, timeline: { key, label: t.value.idp.status[key], ...statusStyle[key] } }
            }),
    })),
)

const shownPlans = computed(() => modelsView.value.reduce((n, m) => n + m.rows.length, 0))

/**
 * While filtering, a model with no surviving row is dropped rather than shown
 * empty — three "no plans" panels say nothing about the one competency being
 * looked for. Unfiltered, every model stays visible: an empty one is where the
 * next plan gets added.
 */
const visibleModels = computed(() =>
    filtering.value ? modelsView.value.filter((m) => m.rows.length > 0) : modelsView.value,
)

/** Results that are filled in and could be submitted right now. */
const readyResults = computed(
    () =>
        props.developmentModels
            .flatMap((m) => m.plans)
            .filter((p) => p.stage.result.can_submit && p.stage.result.filled && p.stage.result.status !== 'rejected')
            .length,
)

// --- Plan form: open, cascade, save ---------------------------------------

// Suppresses the type → competency → program cascade while a row is being
// loaded: the watchers flush after the assignments below and would otherwise
// clear the values they had just restored.
const loadingForm = ref(false)

// Seeds the values as BOTH the form data and its defaults, so the drawer starts
// clean and `isDirty` measures this sitting's edits (see `seedForm`).
function loadForm(values: ReturnType<typeof blankPlan>) {
    loadingForm.value = true
    seedForm(form, values)
    modalOpen.value = true
    nextTick(() => (loadingForm.value = false))
}

function openCreate(modelId: number) {
    editingId.value = null
    loadForm({ ...blankPlan(), development_model_id: modelId })
}

function openEdit(plan: Plan) {
    editingId.value = plan.id
    loadForm({
        ...blankPlan(),
        development_model_id: plan.development_model_id,
        competency_type: plan.competency_type,
        competency_name: plan.competency_name,
        development_program: plan.development_program,
        review_tools: plan.review_tools ?? '',
        expected_outcome: plan.expected_outcome ?? '',
        time_frame_start: plan.time_frame_start?.slice(0, 10) ?? '',
        time_frame_end: plan.time_frame_end?.slice(0, 10) ?? '',
    })
}

/**
 * The plan set is signed off, so ANY change to it — a new program, an edit to
 * an existing one, or a removal — withdraws the approval for the whole plan.
 * Say so before the save rather than after.
 */
const withdrawsApproval = computed(() => props.planning.status === 'approved')

// Changing the development model drops a competency type that no longer fits
// under it; changing the competency type (or the model) drops a competency that
// no longer fits; changing the competency drops a program it does not build.
// Values loaded from an existing plan are left alone (see `loadingForm`), so
// editing an unrelated field never silently blanks what the row already stores.
watch(
    () => form.development_model_id,
    () => {
        if (loadingForm.value || !form.competency_type) return
        if (!usableCompetencyTypes.value.some((item) => item.value === form.competency_type)) {
            form.competency_type = ''
        }
    },
)

watch(
    () => [form.competency_type, form.development_model_id],
    () => {
        if (loadingForm.value || !form.competency_name) return
        if (!competencyOptions.value.some((c) => c.value === form.competency_name)) {
            form.competency_name = ''
        }
    },
)

watch(
    () => [form.development_model_id, form.competency_type, form.competency_name],
    () => {
        if (loadingForm.value || !form.development_program) return
        if (!programOptions.value.some((p) => p.value === form.development_program)) {
            form.development_program = ''
        }
    },
)

function submitPlan() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeModal(),
    }
    if (editingId.value) {
        form.put(route('idp.update', editingId.value), opts)
    } else {
        form.post(route('idp.store'), opts)
    }
}

function closeModal() {
    modalOpen.value = false
    seedForm(form, blankPlan())
}

// Closing a drawer throws its draft away, so each of this component's forms
// confirms first when there is something to lose. Backdrop click, Escape and
// Cancel all route through the guard.
const {
    confirming: confirmingPlan,
    requestClose: requestClosePlan,
    discard: discardPlan,
} = useUnsavedGuard(form, closeModal)

const pendingDelete = ref<Plan | null>(null)
const deleting = ref(false)

function askDelete(plan: Plan) {
    pendingDelete.value = plan
}

function doDelete() {
    if (!pendingDelete.value) return
    deleting.value = true
    router.delete(route('idp.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            deleting.value = false
            pendingDelete.value = null
        },
    })
}

// --- Upload development plan (Excel import) --------------------------------

const uploadOpen = ref(false)
const uploadForm = useForm<{ idp_file: File | null }>({ idp_file: null })

function onFileChange(event: Event) {
    uploadForm.idp_file = (event.target as HTMLInputElement).files?.[0] ?? null
}

function openUpload() {
    seedForm(uploadForm, { idp_file: null })
    uploadOpen.value = true
}

function closeUpload() {
    uploadOpen.value = false
    seedForm(uploadForm, { idp_file: null })
}

// A picked-but-unsent file is lost on close, so ask before throwing it away.
const {
    confirming: confirmingUpload,
    requestClose: requestCloseUpload,
    discard: discardUpload,
} = useUnsavedGuard(uploadForm, closeUpload)

function submitUpload() {
    uploadForm.post(route('idp.import.single', emp.employee_id), {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeUpload(),
    })
}

// --- Stage 2: submitting the plan set --------------------------------------

const submittingPlanning = ref(false)

// The planning sign-off lives under the plan list, not in the tracker: the
// whole set is submitted — and approved — at once, so it is reached after
// reading all of it. An approver whose turn it is decides; otherwise the owner
// may submit. (The two never coincide: a set with an approver is frozen.)
const signOffMode = computed<'decide' | 'submit' | null>(() => {
    if (props.planning.approval?.can_act) return 'decide'
    if (props.canEdit && props.planning.can_submit && props.planning.has_approvers && allPlans.value.length > 0) {
        return 'submit'
    }
    return null
})

function submitPlanning() {
    submittingPlanning.value = true
    router.post(route('idp.approval.submit_planning', emp.employee_id), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (submittingPlanning.value = false),
    })
}

// --- Stage 3: filing and submitting results --------------------------------

const resultOpen = ref(false)
const resultPlan = ref<Plan | null>(null)

function openResult(plan: Plan) {
    resultPlan.value = plan
    resultOpen.value = true
}

const submittingResultId = ref<number | null>(null)

function submitResult(plan: Plan) {
    submittingResultId.value = plan.id
    router.post(route('idp.approval.submit_result', plan.id), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (submittingResultId.value = null),
    })
}

const submittingResults = ref(false)

function submitAllResults() {
    submittingResults.value = true
    router.post(route('idp.approval.submit_all_results', emp.employee_id), {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (submittingResults.value = false),
    })
}

// --- Deciding (either stage) -----------------------------------------------

const decision = ref<{
    open: boolean
    kind: 'approve' | 'reject'
    approvalId: number | null
    subject: string
    detail: string | null
    level: number | null
    totalLevels: number | null
}>({ open: false, kind: 'approve', approvalId: null, subject: '', detail: null, level: null, totalLevels: null })

function decideOnPlanning(kind: 'approve' | 'reject') {
    const approval = props.planning.approval
    if (!approval) return
    decision.value = {
        open: true,
        kind,
        approvalId: approval.id,
        subject: `${emp.fullname} · ${props.planning.package?.name ?? ''}`,
        detail: t.value.idp.stage.decidingPlan.replace('{n}', String(props.planning.total_plans)),
        level: approval.current_level,
        totalLevels: approval.total_levels,
    }
}

function decideOnResult(plan: Plan, kind: 'approve' | 'reject') {
    const approval = plan.stage.result.approval
    if (!approval) return
    decision.value = {
        open: true,
        kind,
        approvalId: approval.id,
        subject: `${emp.fullname} · ${localize(competencyLabels.value, plan.competency_name)}`,
        detail: localize(programLabels.value, plan.development_program),
        level: approval.current_level,
        totalLevels: approval.total_levels,
    }
}

// --- Reading a chain -------------------------------------------------------

const chain = ref<{
    open: boolean
    title: string
    subtitle: string
    approval: ApprovalInfo | null
    preview: boolean
    history: ApprovalInfo[]
}>({ open: false, title: '', subtitle: '', approval: null, preview: false, history: [] })

function openPlanningChain() {
    chain.value = {
        open: true,
        title: t.value.idp.stage.stepPlanningApproval,
        subtitle: props.planning.package?.name ?? '',
        approval: props.planning.approval ?? props.planning.chain_preview,
        preview: !props.planning.approval,
        history: props.planning.history,
    }
}

function openResultChain(plan: Plan) {
    chain.value = {
        open: true,
        title: t.value.idp.stage.stepResultApproval,
        subtitle: localize(competencyLabels.value, plan.competency_name),
        approval: plan.stage.result.approval ?? plan.stage.result.chain_preview,
        preview: !plan.stage.result.approval,
        history: [],
    }
}

// Let the page header open the upload drawer (the drawer lives here).
defineExpose({ openUpload })
</script>

<template>
    <div>
        <!-- Who, which cycle, where the plan stands and what to do next — one
             card, pinned while the plans scroll beneath it -->
        <StageTracker
            :employee="emp"
            :sticky="stickyHeader"
            :planning="planning"
            :progress="progress"
            :ready-results="readyResults"
            :can-edit="canEdit"
            :submitting-results="submittingResults"
            :packages="packages"
            :selected-package-id="selectedPackageId"
            :viewing-active="viewingActive"
            @select-package="selectPackage"
            @submit-results="submitAllResults"
            @open-chain="openPlanningChain"
        >
            <template #tools>
                <!-- Narrow the table (client-side; the tracker's totals stay whole) -->
                <PlanFilters
                    v-if="allPlans.length"
                    v-model="filters"
                    :plans="allPlans"
                    :models="developmentModels"
                    :competency-labels="competencyLabels"
                    :type-labels="competencyTypeLabels"
                    :review-tool-labels="reviewToolLabels"
                    :shown="shownPlans"
                />
            </template>
        </StageTracker>

        <!-- 70-20-10 learning model explainer -->
        <div v-if="hasDescriptions" class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            <div
                v-for="(model, i) in developmentModels"
                :key="model.id"
                class="relative overflow-hidden rounded-xl border border-border bg-white p-5 shadow-sm"
            >
                <span class="absolute inset-x-0 top-0 h-1" :class="accents[i % accents.length].bar" />

                <div class="mb-3 flex items-center gap-3">
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-sm font-bold"
                        :class="accents[i % accents.length].chip"
                    >
                        {{ model.percentage }}%
                    </div>
                    <h3 class="font-bold leading-snug text-slate-800">{{ model.name }}</h3>
                </div>

                <div class="space-y-1.5">
                    <template v-for="(line, li) in descLines(model)" :key="li">
                        <div v-if="line.bullet" class="flex gap-2 text-sm leading-relaxed text-slate-500">
                            <span class="mt-[7px] h-1.5 w-1.5 shrink-0 rounded-full" :class="accents[i % accents.length].bar" />
                            <span>{{ line.text }}</span>
                        </div>
                        <p v-else class="text-sm font-medium leading-relaxed text-slate-600">{{ line.text }}</p>
                    </template>
                </div>
            </div>
        </div>


        <!-- Plans grouped by development model -->
        <div class="space-y-6">
            <PlanTable
                v-for="model in visibleModels"
                :key="model.id"
                :model="model"
                :rows="model.rows"
                :total="model.plans.length"
                :filtering="filtering"
                :competency-labels="competencyLabels"
                :type-labels="competencyTypeLabels"
                :program-labels="programLabels"
                :review-tool-labels="reviewToolLabels"
                :can-edit="canEdit"
                :viewing-active="viewingActive"
                :plans-editable="planning.plans_editable"
                :rows-editable="rowsEditable"
                :submitting-result-id="submittingResultId"
                @add="openCreate(model.id)"
                @edit="openEdit"
                @delete="askDelete"
                @file-result="openResult"
                @submit-result="submitResult"
                @act="decideOnResult"
                @open-chain="openResultChain"
            />

            <div
                v-if="filtering && !visibleModels.length"
                class="flex flex-col items-center gap-3 rounded-xl border border-border bg-white px-5 py-12 text-center shadow-sm"
            >
                <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                    <i class="fa-solid fa-filter-circle-xmark text-xl" />
                </div>
                <p class="text-sm text-slate-400">{{ t.idp.filters.noMatches }}</p>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                    @click="filters = blankPlanFilters()"
                >
                    <i class="fa-solid fa-xmark" />
                    {{ t.idp.filters.clear }}
                </button>
            </div>

            <!-- Submit or decide on the whole plan — below every program, so it
                 is read first -->
            <PlanSignOffCard
                v-if="signOffMode"
                :mode="signOffMode"
                :planning="planning"
                :models="developmentModels"
                :filtering="filtering"
                :submitting="submittingPlanning"
                @submit="submitPlanning"
                @act="decideOnPlanning"
                @clear-filters="filters = blankPlanFilters()"
            />
        </div>

        <!-- Add / edit plan (stage 1 — planning fields only) -->
        <Drawer :show="modalOpen" max-width="max-w-3xl" @close="requestClosePlan">
            <template #header>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-bold text-slate-800">
                            {{ editingId ? t.idp.editPlan : t.idp.addPlan }}
                        </h3>
                        <span
                            v-if="currentModel"
                            class="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                        >
                            <i class="fa-solid fa-layer-group text-[10px]" />
                            {{ currentModel.name }}
                            <span class="font-normal opacity-70">· {{ currentModel.percentage }}%</span>
                        </span>
                    </div>
                    <p class="mt-0.5 truncate text-sm text-slate-500">{{ emp.fullname }}</p>
                </div>
            </template>

            <form id="idp-form" class="space-y-4" @submit.prevent="submitPlan">
                <!-- Editing a signed-off row withdraws its approval; say so up
                     front rather than letting it happen silently on save. -->
                <div
                    v-if="withdrawsApproval"
                    class="flex items-start gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800"
                >
                    <i class="fa-solid fa-rotate mt-0.5 text-xs" />
                    <span>
                        {{ editingId ? t.idp.stage.editWithdrawsApproval : t.idp.stage.addWithdrawsApproval }}
                    </span>
                </div>

                <!-- Everything that failed validation, gathered at the top: the
                     fields themselves are spread down a scrolling drawer, so a
                     rejected save used to look like nothing happened. -->
                <div v-if="errorList.length" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3">
                    <p class="flex items-center gap-2 text-sm font-semibold text-red-800">
                        <i class="fa-solid fa-circle-exclamation text-xs" />
                        {{ t.idp.form.errorSummary }}
                    </p>
                    <ul class="mt-2 space-y-1 pl-6 text-xs text-red-700">
                        <li v-for="item in errorList" :key="item.key" class="list-disc">
                            <span class="font-medium">{{ item.label }}:</span> {{ item.message }}
                        </li>
                    </ul>
                </div>

                <!-- Step 1 — what this plan develops -->
                <FormSection
                    :step="1"
                    :title="t.idp.form.sectionArea"
                    icon="fa-solid fa-bullseye"
                    :complete="areaComplete"
                >
                    <p v-if="form.errors.development_model_id" class="text-xs text-red-600">
                        {{ form.errors.development_model_id }}
                    </p>

                    <!-- Competency type — scoped to the development model this
                         plan is filed under: only a type holding a competency
                         that reaches one of this model's programs is offered. -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.form.type }} <span class="text-red-500">*</span>
                        </label>

                        <p
                            v-if="noUsableTypes"
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400" />
                            <span>{{ t.idp.form.noTypesForModel }}</span>
                        </p>

                        <!-- Few types read best as one-click choices; a longer
                             master list falls back to a searchable select. -->
                        <div v-else-if="competencyTypeOptions.length <= 3" class="grid gap-2 sm:grid-cols-3">
                            <button
                                v-for="option in competencyTypeOptions"
                                :key="option.value"
                                type="button"
                                class="rounded-lg border px-3 py-2.5 text-sm font-medium transition"
                                :class="
                                    form.competency_type === option.value
                                        ? 'border-primary bg-primary/5 text-primary ring-1 ring-primary'
                                        : 'border-border bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'
                                "
                                @click="form.competency_type = option.value"
                            >
                                <i
                                    class="fa-solid mr-1.5 text-xs"
                                    :class="
                                        form.competency_type === option.value
                                            ? 'fa-circle-check'
                                            : 'fa-circle text-slate-300'
                                    "
                                />
                                {{ option.label }}
                            </button>
                        </div>
                        <SearchableSelect
                            v-else
                            :model-value="form.competency_type"
                            :options="competencyTypeOptions"
                            :placeholder="t.idp.form.selectPlaceholder"
                            :invalid="!!form.errors.competency_type"
                            @update:model-value="form.competency_type = $event"
                        />
                        <p v-if="form.errors.competency_type" class="mt-1 text-xs text-red-600">
                            {{ form.errors.competency_type }}
                        </p>
                        <p
                            v-else-if="typeOffList"
                            class="mt-1 flex items-start gap-1.5 text-xs font-medium text-amber-600"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                            <span>
                                {{ typeIsAMaster ? t.idp.form.typeModelMismatch : t.idp.form.inactiveMaster }}
                            </span>
                        </p>
                    </div>

                    <!-- Competency name — scoped to the type above and to the
                         development model this plan is filed under -->
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label class="text-sm font-medium text-slate-700">
                                {{ t.idp.form.competencyName }} <span class="text-red-500">*</span>
                            </label>
                            <span
                                v-if="!competencyLocked && competencyNameOptions.length"
                                class="text-[11px] text-slate-400"
                            >
                                {{ competencyNameOptions.length }} {{ t.idp.form.optionsCount }}
                            </span>
                        </div>

                        <p
                            v-if="competencyLocked"
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-lock mt-0.5 text-[10px] text-slate-300" />
                            <span>{{ t.idp.form.pickTypeFirst }}</span>
                        </p>

                        <p
                            v-else-if="!competencyNameOptions.length"
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400" />
                            <span>
                                {{
                                    competenciesWrongModel
                                        ? t.idp.form.noCompetenciesForModel
                                        : t.idp.form.noCompetenciesForType
                                }}
                            </span>
                        </p>

                        <SearchableSelect
                            v-else
                            :model-value="form.competency_name"
                            :options="competencyNameOptions"
                            :placeholder="t.idp.form.selectPlaceholder"
                            :invalid="!!form.errors.competency_name"
                            @update:model-value="form.competency_name = $event"
                        />

                        <p v-if="form.errors.competency_name" class="mt-1 text-xs text-red-600">
                            {{ form.errors.competency_name }}
                        </p>
                        <p
                            v-else-if="competencyOffList"
                            class="mt-1 flex items-start gap-1.5 text-xs font-medium text-amber-600"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                            <span>
                                {{
                                    competencyTypeMismatch
                                        ? t.idp.form.typeMismatch
                                        : competencyModelMismatch
                                            ? t.idp.form.modelMismatch
                                            : t.idp.form.inactiveMaster
                                }}
                            </span>
                        </p>
                    </div>

                    <!-- Review tools — optional -->
                    <div>
                        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                            {{ t.idp.form.reviewTools }}
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500">
                                {{ t.idp.form.optional }}
                            </span>
                        </label>
                        <SearchableSelect
                            :model-value="form.review_tools"
                            :options="reviewToolsOptions"
                            :placeholder="t.idp.form.selectPlaceholder"
                            :invalid="!!form.errors.review_tools"
                            @update:model-value="form.review_tools = $event"
                        />
                        <p v-if="form.errors.review_tools" class="mt-1 text-xs text-red-600">
                            {{ form.errors.review_tools }}
                        </p>
                        <p
                            v-else-if="reviewToolInactive"
                            class="mt-1 flex items-start gap-1.5 text-xs font-medium text-amber-600"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                            <span>{{ t.idp.form.inactiveMaster }}</span>
                        </p>
                    </div>
                </FormSection>

                <!-- Step 2 — how it will be developed -->
                <FormSection
                    :step="2"
                    :title="t.idp.form.sectionProgram"
                    icon="fa-solid fa-book-open"
                    :complete="programComplete"
                >
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label class="text-sm font-medium text-slate-700">
                                {{ t.idp.form.program }} <span class="text-red-500">*</span>
                            </label>
                            <span
                                v-if="!programLocked && programSelectOptions.length"
                                class="text-[11px] text-slate-400"
                            >
                                {{ programSelectOptions.length }} {{ t.idp.form.optionsCount }}
                            </span>
                        </div>

                        <p
                            v-if="programLocked"
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-lock mt-0.5 text-[10px] text-slate-300" />
                            <span>{{ t.idp.form.pickCompetencyFirst }}</span>
                        </p>

                        <p
                            v-else-if="!programSelectOptions.length"
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400" />
                            <span>
                                {{
                                    programsWrongModel
                                        ? t.idp.form.noProgramsForModel
                                        : t.idp.form.noProgramsForCompetency
                                }}
                            </span>
                        </p>

                        <template v-else>
                            <SearchableSelect
                                :model-value="form.development_program"
                                :options="programSelectOptions"
                                :placeholder="t.idp.form.selectPlaceholder"
                                :invalid="!!form.errors.development_program"
                                @update:model-value="form.development_program = $event"
                            />

                            <!-- Program names run to a few hundred characters and
                                 the picker truncates them to one line, so read the
                                 choice back out in full. -->
                            <div
                                v-if="selectedProgramLabel"
                                class="mt-2 rounded-lg border border-primary/20 bg-primary/5 px-3 py-2.5"
                            >
                                <p class="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-primary/80">
                                    <i class="fa-solid fa-quote-left text-[9px]" />
                                    {{ t.idp.form.selectedProgram }}
                                </p>
                                <p class="text-sm leading-relaxed text-slate-700">{{ selectedProgramLabel }}</p>
                            </div>
                        </template>

                        <p v-if="form.errors.development_program" class="mt-1 text-xs text-red-600">
                            {{ form.errors.development_program }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                            {{ t.idp.form.expectedOutcome }}
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500">
                                {{ t.idp.form.optional }}
                            </span>
                        </label>
                        <textarea
                            v-model="form.expected_outcome"
                            rows="4"
                            maxlength="500"
                            :placeholder="t.idp.form.expectedOutcomePlaceholder"
                            class="w-full resize-y rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :class="form.errors.expected_outcome ? 'border-red-500' : 'border-border'"
                        />
                        <div class="mt-1 flex items-start gap-3">
                            <p v-if="form.errors.expected_outcome" class="text-xs text-red-600">
                                {{ form.errors.expected_outcome }}
                            </p>
                            <span
                                class="ml-auto shrink-0 text-xs tabular-nums"
                                :class="(form.expected_outcome ?? '').length >= 500 ? 'text-amber-600' : 'text-slate-400'"
                            >
                                {{ (form.expected_outcome ?? '').length }} / 500
                            </span>
                        </div>
                    </div>
                </FormSection>

                <!-- Step 3 — when it runs -->
                <FormSection
                    :step="3"
                    :title="t.idp.form.sectionTimeline"
                    icon="fa-solid fa-calendar-days"
                    :complete="timelineComplete"
                >
                    <div>
                        <div class="mb-1.5 flex items-center justify-between gap-2">
                            <label class="text-sm font-medium text-slate-700">
                                {{ t.idp.form.timeframe }} <span class="text-red-500">*</span>
                            </label>
                            <span
                                v-if="durationLabel"
                                class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                            >
                                <i class="fa-regular fa-clock text-[9px]" />
                                {{ durationLabel }}
                            </span>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr_auto_1fr] sm:items-end">
                            <div>
                                <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    {{ t.idp.form.start }}
                                </span>
                                <DateInput v-model="form.time_frame_start" :invalid="!!form.errors.time_frame_start" />
                            </div>
                            <i class="fa-solid fa-arrow-right-long hidden shrink-0 pb-2.5 text-slate-300 sm:block" />
                            <div>
                                <span class="mb-1 block text-[11px] font-medium uppercase tracking-wide text-slate-400">
                                    {{ t.idp.form.end }}
                                </span>
                                <DateInput
                                    v-model="form.time_frame_end"
                                    :invalid="!!form.errors.time_frame_end || endBeforeStart"
                                />
                            </div>
                        </div>
                        <p v-if="form.errors.time_frame_start" class="mt-1 text-xs text-red-600">
                            {{ form.errors.time_frame_start }}
                        </p>
                        <p v-else-if="form.errors.time_frame_end" class="mt-1 text-xs text-red-600">
                            {{ form.errors.time_frame_end }}
                        </p>
                        <p v-else-if="endBeforeStart" class="mt-1 flex items-center gap-1.5 text-xs font-medium text-red-600">
                            <i class="fa-solid fa-circle-exclamation text-[10px]" />
                            {{ t.idp.form.endBeforeStart }}
                        </p>
                    </div>

                    <!-- The result is filed later, on its own — say so, so the
                         two missing fields do not read as an oversight. -->
                    <p class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500">
                        <i class="fa-solid fa-circle-info mt-0.5 text-[10px] text-slate-400" />
                        <span>{{ t.idp.stage.resultComesLater }}</span>
                    </p>
                </FormSection>
            </form>

            <template #footer>
                <!-- Which required fields are still missing, next to the button
                     that needs them — so a save that cannot succeed is visible
                     before it is attempted, not after. -->
                <p v-if="missingRequired.length" class="mr-auto hidden items-center gap-2 text-xs text-slate-500 sm:flex">
                    <i class="fa-solid fa-circle-info text-[10px] text-slate-400" />
                    <span>
                        {{ t.idp.form.stillNeeded }}
                        <span class="font-medium text-slate-600">{{ missingRequired.join(', ') }}</span>
                    </span>
                </p>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="requestClosePlan"
                >
                    {{ t.idp.form.cancel }}
                </button>
                <button
                    type="submit"
                    form="idp-form"
                    :disabled="form.processing"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >
                    <i v-if="form.processing" class="fa-solid fa-spinner fa-spin" />
                    {{ editingId ? t.idp.form.saveChanges : t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- Stage 3 — file a result -->
        <ResultDrawer
            :show="resultOpen"
            :plan="resultPlan"
            :competency-label="resultPlan ? localize(competencyLabels, resultPlan.competency_name) : ''"
            :program-label="resultPlan ? localize(programLabels, resultPlan.development_program) : ''"
            @close="resultOpen = false"
        />

        <!-- Approve / reject, either stage -->
        <DecisionDrawer
            :show="decision.open"
            :decision="decision.kind"
            :approval-id="decision.approvalId"
            :subject="decision.subject"
            :detail="decision.detail"
            :level="decision.level"
            :total-levels="decision.totalLevels"
            @close="decision.open = false"
        />

        <!-- Read a chain, at either stage -->
        <Drawer :show="chain.open" max-width="max-w-lg" @close="chain.open = false">
            <template #header>
                <div class="min-w-0">
                    <h3 class="font-bold text-slate-800">{{ chain.title }}</h3>
                    <p class="mt-0.5 truncate text-sm text-slate-500">{{ chain.subtitle }}</p>
                </div>
            </template>

            <div class="space-y-5">
                <ApprovalChain :approval="chain.approval" :preview="chain.preview" />

                <!-- Earlier rounds of the same set, so a revised plan keeps its
                     paper trail rather than replacing it. -->
                <div v-if="chain.history.length" class="border-t border-border pt-4">
                    <p class="mb-3 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                        <i class="fa-solid fa-clock-rotate-left text-[10px]" />
                        {{ t.idp.stage.previousSubmissions }}
                    </p>
                    <div class="space-y-4">
                        <div
                            v-for="(past, i) in chain.history"
                            :key="past.id ?? i"
                            class="rounded-lg border border-border bg-slate-50/60 p-3"
                        >
                            <p class="mb-2 flex items-center justify-between gap-2 text-xs text-slate-500">
                                <span class="font-medium">
                                    {{ t.idp.stage.revision }} {{ chain.history.length - i }}
                                </span>
                                <span>{{ formatDateTime(past.submitted_at) }}</span>
                            </p>
                            <ApprovalChain :approval="past" compact />
                        </div>
                    </div>
                </div>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="chain.open = false"
                >
                    {{ t.approvalFlow.cancel }}
                </button>
            </template>
        </Drawer>

        <!-- Unsaved-changes confirmations -->
        <UnsavedChangesDialog
            :show="confirmingPlan"
            :message="t.idp.form.discardMessage"
            @confirm="discardPlan"
            @close="confirmingPlan = false"
        />
        <UnsavedChangesDialog :show="confirmingUpload" @confirm="discardUpload" @close="confirmingUpload = false" />

        <!-- Delete confirmation -->
        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.idp.deleteTitle"
            :message="t.idp.deleteConfirm"
            :confirm-label="t.idp.form.delete"
            :cancel-label="t.idp.form.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="doDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ localize(competencyLabels, pendingDelete.competency_name) }}
            </p>
            <p
                v-if="withdrawsApproval"
                class="mt-2 flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-800"
            >
                <i class="fa-solid fa-rotate mt-0.5" />
                <span>{{ t.idp.stage.deleteWithdrawsApproval }}</span>
            </p>
        </ConfirmDialog>

        <!-- Upload development plan drawer -->
        <Drawer :show="uploadOpen" :title="t.idp.upload.title" max-width="max-w-lg" @close="requestCloseUpload">
            <form id="idp-upload-form" class="space-y-5" @submit.prevent="submitUpload">
                <div class="space-y-2">
                    <p class="text-sm font-semibold text-slate-700">{{ t.idp.upload.step1 }}</p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            :href="route('idp.template.download', emp.employee_id)"
                            class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-emerald-700 shadow-sm transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-excel" />
                            {{ t.idp.upload.downloadTemplate }}
                        </a>
                        <a
                            :href="route('idp.master_pdf')"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary shadow-sm transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-pdf" />
                            {{ t.idp.upload.downloadMaster }}
                        </a>
                    </div>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sky-900">
                    <p class="flex items-center gap-2 text-sm font-semibold">
                        <i class="fa-solid fa-circle-info" />
                        {{ t.idp.upload.instructionsTitle }}
                    </p>
                    <p class="mt-2 text-xs">
                        <i class="fa-solid fa-triangle-exclamation mr-1 text-amber-500" />
                        <strong>{{ t.idp.upload.noteLabel }}</strong> {{ t.idp.upload.note }}
                    </p>
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-xs leading-relaxed">
                        <li>{{ t.idp.upload.instr1 }}</li>
                        <li>{{ t.idp.upload.instr2 }}</li>
                        <li>{{ t.idp.upload.instr3 }}</li>
                    </ul>
                </div>

                <div class="space-y-2">
                    <label for="idp-upload-file" class="text-sm font-semibold text-slate-700">{{ t.idp.upload.step2 }}</label>
                    <input
                        id="idp-upload-file"
                        type="file"
                        accept=".xlsx,.xls"
                        required
                        class="block w-full rounded-lg border border-border text-sm text-slate-600 file:mr-3 file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200"
                        @change="onFileChange"
                    >
                    <p v-if="uploadForm.errors.idp_file" class="text-xs text-red-600">{{ uploadForm.errors.idp_file }}</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="requestCloseUpload"
                >
                    {{ t.idp.upload.close }}
                </button>
                <button
                    type="submit"
                    form="idp-upload-form"
                    :disabled="uploadForm.processing || !uploadForm.idp_file"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                >
                    <i v-if="uploadForm.processing" class="fa-solid fa-spinner fa-spin" />
                    {{ t.idp.upload.submit }}
                </button>
            </template>
        </Drawer>
    </div>
</template>
