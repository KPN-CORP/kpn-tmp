<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmt, formatDateTime as fmtDateTime } from '@/Composables/useDate'

const { t, locale } = useLocale()

interface MasterOption {
    value: string
    value_en: string | null
    value_id: string | null
    // The competency type this master is filed under, as the plan stores it (a
    // name string). Null means untyped, which by convention is global and fits
    // every competency type.
    competency_type?: string | null
}

// A development program additionally carries the development model it is filed
// under. Null is legacy data with no model, which — like an untyped master —
// counts as global.
interface ProgramOption extends MasterOption {
    model_id?: number | null
}

interface ApprovalStep {
    level: number
    approver_id: string
    approver_name: string | null
    status: 'pending' | 'approved' | 'rejected'
    note: string | null
    acted_by_name: string | null
    acted_at: string | null
}

interface ApprovalInfo {
    id: number | null
    status: 'draft' | 'pending' | 'approved' | 'rejected'
    current_level: number | null
    total_levels: number
    submitted_at: string | null
    steps: ApprovalStep[]
    can_submit: boolean
    can_act: boolean
}

interface Plan {
    id: number
    development_model_id: number
    competency_type: string
    competency_name: string
    development_program: string
    review_tools: string | null
    expected_outcome: string | null
    time_frame_start: string | null
    time_frame_end: string | null
    realization_date: string | null
    result_evidence: string | null
    approval?: ApprovalInfo
}

interface Model {
    id: number
    name: string
    percentage: number
    description_en: string | null
    description_id: string | null
    // Only models in the active package accept new plans; historical models
    // (from a previous package) render read-only.
    can_add: boolean
    plans: Plan[]
}

const props = withDefaults(
    defineProps<{
        employee: { employee_id: string; fullname: string; designation_name: string | null }
        developmentModels: Model[]
        options: {
            competencyTypes: MasterOption[]
            competencyNames: MasterOption[]
            developmentPrograms: ProgramOption[]
            reviewTools: MasterOption[]
        }
        competencyMap: Record<string, ProgramOption[]>
        // Show the add / edit / delete plan controls; the profile's inline tab
        // is view-only.
        canEdit?: boolean
    }>(),
    { canEdit: true },
)

const emp = props.employee

// Pick the model description in the active language (fall back to the other).
function modelDescription(model: Model): string {
    const primary = locale.value === 'id' ? model.description_id : model.description_en
    return (primary || model.description_en || model.description_id || '').trim()
}

const hasDescriptions = computed(() => props.developmentModels.some((m) => modelDescription(m) !== ''))

// Split a model description into display lines; a leading "-" / "•" marks a
// bullet. Locale-agnostic (only the marker is detected, not the label text).
function descLines(model: Model): Array<{ text: string; bullet: boolean }> {
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

const initials = computed(() =>
    emp.fullname
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
)

const stats = computed(() => {
    const all = props.developmentModels.flatMap((m) => m.plans)
    const completed = all.filter((p) => p.realization_date).length
    return { total: all.length, completed, ongoing: all.length - completed }
})

const modalOpen = ref(false)
const editingId = ref<number | null>(null)

// The empty plan. Inertia rewrites a form's defaults to the submitted data on a
// successful visit, so `reset()` alone would refill the drawer with the plan
// that was just saved — hence every open seeds both the data and the defaults
// from here (or from the plan being edited).
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
        realization_date: '',
        result_evidence: '',
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
function matchesType(item: MasterOption): boolean {
    const type = form.competency_type.trim()
    const masterType = (item.competency_type ?? '').trim()
    return type !== '' && masterType !== '' && masterType.toLowerCase() === type.toLowerCase()
}

// Development programs are scoped the other way round: an untyped program is
// global and fits every type. They are narrowed primarily by the competency
// they build, and treating a missing type as "none" would empty the picker.
function fitsType(item: MasterOption): boolean {
    const type = form.competency_type.trim()
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
// model, and under this plan's competency type.
function selectable(program: ProgramOption): boolean {
    return fitsModel(program) && fitsType(program)
}

// A competency is only worth offering when it reaches a program this plan could
// pick — i.e. one filed under the development model the plan sits under. A
// competency with no linked programs at all is global, so it stays on offer.
function reachesModel(item: MasterOption): boolean {
    const links = linksFor(item.value)
    return !links?.length || links.some(selectable)
}

// Competencies of the chosen type, before the development-model narrowing —
// what tells "this type has none" apart from "none under this model".
const typedCompetencies = computed<MasterOption[]>(() =>
    props.options.competencyNames.filter(matchesType),
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

function localize(map: Record<string, string>, value: string | null): string {
    return value ? (map[value] ?? value) : ''
}

// The competency types are master data, not a fixed pair. Plans store the name
// verbatim, so a type the master no longer lists stays selectable on a plan
// that already uses it (same treatment the other masters get).
const competencyTypeOptions = computed<Option[]>(() =>
    toSelectOptions(props.options.competencyTypes, form.competency_type),
)

const competencyTypeLabels = computed(() => labelMap(props.options.competencyTypes))

// --- Plan form state: section progress, locked steps, and inline warnings ---

// Each section of the drawer turns "complete" once its required fields hold a
// value, so progress through the cascade is visible in the step badges.
const areaComplete = computed(() => !!form.competency_type && !!form.competency_name)
const programComplete = computed(() => !!form.development_program)
const timelineComplete = computed(() => !!form.time_frame_start)
const resultComplete = computed(() => !!form.realization_date && !!form.result_evidence)

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

// Result evidence is only required once a realization date is set (the server
// rule is `required_with:realization_date`), so the field reflects that.
const evidenceRequired = computed(() => !!form.realization_date)

// An item may only be submitted for approval once it has been realized, which
// nothing in the form used to say.
const readyToSubmit = computed(() => resultComplete.value)

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
    realization_date: t.value.idp.form.realization,
    result_evidence: t.value.idp.form.resultEvidence,
}))

// Required fields still empty, named in the footer so the user can see what a
// save is waiting on instead of discovering it from a rejection.
const missingRequired = computed(() => {
    const missing: string[] = []
    if (!form.competency_type) missing.push(t.value.idp.form.type)
    if (!form.competency_name) missing.push(t.value.idp.form.competencyName)
    if (!form.development_program) missing.push(t.value.idp.form.program)
    if (!form.time_frame_start) missing.push(t.value.idp.form.start)
    if (evidenceRequired.value && !form.result_evidence) {
        missing.push(t.value.idp.form.resultEvidence)
    }
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

type StatusKey = 'completed' | 'overdue' | 'inProgress' | 'upcoming' | 'planned'

const statusStyle: Record<StatusKey, { badge: string; dot: string }> = {
    completed: { badge: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' },
    inProgress: { badge: 'bg-amber-50 text-amber-700 ring-amber-600/20', dot: 'bg-amber-500' },
    upcoming: { badge: 'bg-sky-50 text-sky-700 ring-sky-600/20', dot: 'bg-sky-500' },
    overdue: { badge: 'bg-red-50 text-red-700 ring-red-600/20', dot: 'bg-red-500' },
    planned: { badge: 'bg-slate-100 text-slate-600 ring-slate-500/20', dot: 'bg-slate-400' },
}

const accents = [
    { bar: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700' },
    { bar: 'bg-sky-500', chip: 'bg-sky-50 text-sky-700' },
    { bar: 'bg-violet-500', chip: 'bg-violet-50 text-violet-700' },
    { bar: 'bg-amber-500', chip: 'bg-amber-50 text-amber-700' },
]

function statusKey(plan: Plan): StatusKey {
    if (plan.realization_date) return 'completed'
    const today = new Date()
    today.setHours(0, 0, 0, 0)
    const start = plan.time_frame_start ? new Date(plan.time_frame_start) : null
    const end = plan.time_frame_end ? new Date(plan.time_frame_end) : null
    if (end && today > end) return 'overdue'
    if (start && today < start) return 'upcoming'
    if (start) return 'inProgress'
    return 'planned'
}

function typeBadge(type: string): string {
    return type === 'Technical Competency'
        ? 'bg-teal-50 text-teal-700'
        : 'bg-indigo-50 text-indigo-700'
}

function isUrl(value: string | null): boolean {
    return !!value && /^https?:\/\//i.test(value.trim())
}

const modelsView = computed(() =>
    props.developmentModels.map((model, index) => ({
        ...model,
        accent: accents[index % accents.length],
        plans: model.plans.map((plan) => {
            const key = statusKey(plan)
            return { plan, status: { key, label: t.value.idp.status[key], ...statusStyle[key] } }
        }),
    })),
)

// Suppresses the type → competency → program cascade while a row is being
// loaded into the form: the watchers flush after the assignments below and
// would otherwise clear the values they had just restored.
const loadingForm = ref(false)

// Load a set of values as BOTH the form data and its defaults, so the drawer
// starts clean: `isDirty` (which drives the discard prompt) then measures edits
// made in this sitting rather than the distance from a stale default.
function loadForm(values: ReturnType<typeof blankPlan>) {
    loadingForm.value = true
    form.defaults(values)
    form.reset()
    form.clearErrors()
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
        realization_date: plan.realization_date?.slice(0, 10) ?? '',
        result_evidence: plan.result_evidence ?? '',
    })
}

// Changing the competency type (or the development model the plan is filed
// under) drops a competency that no longer fits; changing the competency drops
// a program it does not build. Values loaded from an existing plan are left
// alone (see `loadingForm`), so editing an unrelated field never silently
// blanks what the row already stores.
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

function submit() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            modalOpen.value = false
            form.defaults(blankPlan())
            form.reset()
        },
    }
    if (editingId.value) {
        form.put(`/idp/${editingId.value}`, opts)
    } else {
        form.post('/idp', opts)
    }
}

// Closing the drawer throws the draft away, so confirm first when there is
// something to lose. Backdrop click and Escape both route through here.
const confirmDiscard = ref(false)

function requestClose() {
    if (form.isDirty) {
        confirmDiscard.value = true
        return
    }
    modalOpen.value = false
}

function discardChanges() {
    confirmDiscard.value = false
    modalOpen.value = false
    form.defaults(blankPlan())
    form.reset()
    form.clearErrors()
}

const pendingDelete = ref<Plan | null>(null)
const deleting = ref(false)

function askDelete(plan: Plan) {
    pendingDelete.value = plan
}

function doDelete() {
    if (!pendingDelete.value) return
    deleting.value = true
    router.delete(`/idp/${pendingDelete.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            deleting.value = false
            pendingDelete.value = null
        },
    })
}

// --- Upload development plan (Excel import) ---
const uploadOpen = ref(false)
const uploadForm = useForm<{ idp_file: File | null }>({ idp_file: null })

function onFileChange(event: Event) {
    uploadForm.idp_file = (event.target as HTMLInputElement).files?.[0] ?? null
}

function submitUpload() {
    uploadForm.post(`/idp/${emp.employee_id}/import`, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            uploadOpen.value = false
            uploadForm.reset()
        },
    })
}

// --- Approval workflow (staged L1 → L2 → … per item) ---

// Whether there is at least one item the viewer may (re)submit for approval.
const hasSubmittable = computed(() =>
    props.developmentModels.some((m) => m.plans.some((p) => p.approval?.can_submit)),
)

function approvalBadge(approval?: ApprovalInfo): { label: string; cls: string; dot: string } {
    const status = approval?.status ?? 'draft'
    if (status === 'approved') {
        return { label: t.value.approvalFlow.statusApproved, cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', dot: 'bg-emerald-500' }
    }
    if (status === 'rejected') {
        return { label: t.value.approvalFlow.statusRejected, cls: 'bg-red-50 text-red-700 ring-red-600/20', dot: 'bg-red-500' }
    }
    if (status === 'pending') {
        const lvl = approval?.current_level ?? 1
        if (approval?.can_act) {
            return { label: t.value.approvalFlow.needsYourApproval, cls: 'bg-amber-100 text-amber-800 ring-amber-600/30', dot: 'bg-amber-500' }
        }
        return {
            label: `${t.value.approvalFlow.waiting} ${t.value.approvalFlow.layerShort}${lvl}`,
            cls: 'bg-amber-50 text-amber-700 ring-amber-600/20',
            dot: 'bg-amber-500',
        }
    }
    return { label: t.value.approvalFlow.statusDraft, cls: 'bg-slate-100 text-slate-600 ring-slate-500/20', dot: 'bg-slate-400' }
}

// Submit a single item for approval.
const submittingId = ref<number | null>(null)
function submitItem(plan: Plan) {
    submittingId.value = plan.id
    router.post(`/idp/${plan.id}/submit-approval`, {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (submittingId.value = null),
    })
}

// Submit every draft / rejected item at once.
const submittingAll = ref(false)
function submitAllApprovals() {
    submittingAll.value = true
    router.post(`/idp/${emp.employee_id}/submit-all-approval`, {}, {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (submittingAll.value = false),
    })
}

// Approve / reject dialog (note required).
const actOpen = ref(false)
const actDecision = ref<'approve' | 'reject'>('approve')
const actApprovalId = ref<number | null>(null)
const actPlan = ref<Plan | null>(null)
const actForm = useForm({ note: '' })

function openAct(plan: Plan, decision: 'approve' | 'reject') {
    actDecision.value = decision
    actApprovalId.value = plan.approval?.id ?? null
    actPlan.value = plan
    actForm.reset()
    actForm.clearErrors()
    actOpen.value = true
}

function submitAct() {
    if (!actApprovalId.value) return
    actForm.post(`/idp-approvals/${actApprovalId.value}/${actDecision.value}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            actOpen.value = false
            actForm.reset()
        },
    })
}

// Approval-chain detail drawer.
const chainOpen = ref(false)
const chainPlan = ref<Plan | null>(null)
function openChain(plan: Plan) {
    chainPlan.value = plan
    chainOpen.value = true
}

function stepIcon(status: string): { icon: string; color: string } {
    if (status === 'approved') return { icon: 'fa-solid fa-circle-check', color: 'text-emerald-500' }
    if (status === 'rejected') return { icon: 'fa-solid fa-circle-xmark', color: 'text-red-500' }
    return { icon: 'fa-regular fa-circle', color: 'text-slate-300' }
}

// Let the page header open the upload drawer (the drawer lives here).
defineExpose({ openUpload: () => (uploadOpen.value = true) })
</script>

<template>
    <div>
        <!-- Employee banner + progress stats -->
        <div class="mb-6 flex flex-col gap-4 rounded-xl border border-border bg-white p-5 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary/10 text-base font-bold text-primary">
                    {{ initials }}
                </div>
                <div class="min-w-0">
                    <h2 class="truncate text-lg font-bold text-slate-800">{{ emp.fullname }}</h2>
                    <p class="truncate text-sm text-slate-500">{{ emp.employee_id }} · {{ emp.designation_name ?? '—' }}</p>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2 sm:flex sm:gap-3">
                <div class="rounded-lg border border-border bg-slate-50/60 px-3 py-2 text-center sm:min-w-20">
                    <div class="text-lg font-bold text-slate-800">{{ stats.total }}</div>
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">{{ t.idp.stats.total }}</div>
                </div>
                <div class="rounded-lg border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-center sm:min-w-20">
                    <div class="text-lg font-bold text-emerald-600">{{ stats.completed }}</div>
                    <div class="text-[11px] uppercase tracking-wide text-emerald-600/70">{{ t.idp.stats.completed }}</div>
                </div>
                <div class="rounded-lg border border-amber-100 bg-amber-50/60 px-3 py-2 text-center sm:min-w-20">
                    <div class="text-lg font-bold text-amber-600">{{ stats.ongoing }}</div>
                    <div class="text-[11px] uppercase tracking-wide text-amber-600/70">{{ t.idp.stats.ongoing }}</div>
                </div>
            </div>
        </div>

        <!-- Submit-all-for-approval bar -->
        <div
            v-if="canEdit && hasSubmittable"
            class="mb-6 flex flex-col items-start gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="flex items-center gap-2 text-sm text-amber-800">
                <i class="fa-solid fa-paper-plane" />
                {{ t.approvalFlow.submitAll }}
            </p>
            <button
                type="button"
                :disabled="submittingAll"
                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-600 disabled:opacity-60"
                @click="submitAllApprovals"
            >
                <i v-if="submittingAll" class="fa-solid fa-spinner fa-spin" />
                <i v-else class="fa-solid fa-paper-plane" />
                {{ t.approvalFlow.submitAll }}
            </button>
        </div>

        <!-- 70-20-10 learning model explainer -->
        <div
            v-if="hasDescriptions"
            class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3"
        >
            <div
                v-for="(model, i) in developmentModels"
                :key="model.id"
                class="relative overflow-hidden rounded-xl border border-border bg-white p-5 shadow-sm"
            >
                <!-- Colored accent bar -->
                <span class="absolute inset-x-0 top-0 h-1" :class="accents[i % accents.length].bar" />

                <!-- Header: percentage badge + name -->
                <div class="mb-3 flex items-center gap-3">
                    <div
                        class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl text-sm font-bold"
                        :class="accents[i % accents.length].chip"
                    >
                        {{ model.percentage }}%
                    </div>
                    <h3 class="font-bold leading-snug text-slate-800">{{ model.name }}</h3>
                </div>

                <!-- Description: intro lines + bulleted examples -->
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
            <section
                v-for="model in modelsView"
                :key="model.id"
                class="overflow-hidden rounded-xl border border-border bg-white shadow-sm"
            >
                <!-- Model header -->
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3.5">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="h-9 w-1.5 shrink-0 rounded-full" :class="model.accent.bar" />
                        <div class="min-w-0">
                            <h3 class="truncate font-semibold text-slate-800">{{ model.name }}</h3>
                            <p class="text-xs text-slate-400">
                                {{ model.plans.length }}
                                {{ model.plans.length === 1 ? t.idp.planSingular : t.idp.planPlural }}
                            </p>
                        </div>
                        <span
                            class="ml-1 shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold"
                            :class="model.accent.chip"
                        >
                            {{ model.percentage }}%
                        </span>
                    </div>
                    <span
                        v-if="!model.can_add"
                        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500"
                        :title="t.idp.historicalModelHint"
                    >
                        <i class="fa-solid fa-clock-rotate-left text-[10px]" />
                        {{ t.idp.historicalModel }}
                    </span>
                    <button
                        v-if="canEdit && model.can_add"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                        @click="openCreate(model.id)"
                    >
                        <i class="fa-solid fa-plus" />
                        {{ t.idp.addPlan }}
                    </button>
                </div>

                <!-- Plans table -->
                <div v-if="model.plans.length" class="overflow-x-auto">
                    <table class="w-full min-w-[900px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.table.competency }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.table.program }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.table.timeframe }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.table.status }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.table.realization }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.approvalFlow.heading }}</th>
                                <th v-if="canEdit" class="px-5 py-2.5 text-right font-semibold" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="{ plan, status } in model.plans"
                                :key="plan.id"
                                class="group border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/70"
                            >
                                <!-- Competency -->
                                <td class="px-5 py-3.5">
                                    <div class="font-medium text-slate-800">{{ localize(competencyLabels, plan.competency_name) }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span
                                            class="rounded px-1.5 py-0.5 text-[11px] font-medium"
                                            :class="typeBadge(plan.competency_type)"
                                        >
                                            {{ localize(competencyTypeLabels, plan.competency_type) }}
                                        </span>
                                        <span
                                            v-if="plan.review_tools"
                                            class="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-500"
                                        >
                                            <i class="fa-solid fa-clipboard-check text-[10px]" />
                                            {{ localize(reviewToolLabels, plan.review_tools) }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Program -->
                                <td class="px-5 py-3.5">
                                    <div class="font-medium text-slate-700">{{ localize(programLabels, plan.development_program) }}</div>
                                    <div v-if="plan.expected_outcome" class="mt-0.5 max-w-xs whitespace-pre-line text-xs text-slate-400">
                                        <span class="font-medium">{{ t.idp.outcomeLabel }}:</span>
                                        {{ plan.expected_outcome }}
                                    </div>
                                </td>

                                <!-- Timeframe -->
                                <td class="px-5 py-3.5 text-slate-500">
                                    <div class="flex items-center gap-1.5 whitespace-nowrap">
                                        <i class="fa-regular fa-calendar text-xs text-slate-300" />
                                        <span>{{ fmt(plan.time_frame_start) }}</span>
                                        <i class="fa-solid fa-arrow-right-long text-[10px] text-slate-300" />
                                        <span>{{ plan.time_frame_end ? fmt(plan.time_frame_end) : '—' }}</span>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-5 py-3.5">
                                    <span
                                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                        :class="status.badge"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full" :class="status.dot" />
                                        {{ status.label }}
                                    </span>
                                </td>

                                <!-- Realization / evidence -->
                                <td class="px-5 py-3.5 text-slate-500">
                                    <div v-if="plan.realization_date" class="whitespace-nowrap">{{ fmt(plan.realization_date) }}</div>
                                    <div v-else class="text-slate-300">—</div>
                                    <a
                                        v-if="isUrl(plan.result_evidence)"
                                        :href="plan.result_evidence!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                                    >
                                        <i class="fa-solid fa-link text-[10px]" />
                                        {{ t.idp.evidenceLabel }}
                                    </a>
                                    <div v-else-if="plan.result_evidence" class="mt-0.5 max-w-xs text-xs text-slate-400">
                                        {{ plan.result_evidence }}
                                    </div>
                                </td>

                                <!-- Approval -->
                                <td class="px-5 py-3.5">
                                    <div class="flex flex-col items-start gap-1.5">
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset"
                                            :class="approvalBadge(plan.approval).cls"
                                        >
                                            <span class="h-1.5 w-1.5 rounded-full" :class="approvalBadge(plan.approval).dot" />
                                            {{ approvalBadge(plan.approval).label }}
                                        </span>

                                        <!-- Actions for the current approver -->
                                        <div v-if="plan.approval?.can_act" class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-md bg-emerald-500 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-emerald-600"
                                                @click="openAct(plan, 'approve')"
                                            >
                                                <i class="fa-solid fa-check" />
                                                {{ t.approvalFlow.approve }}
                                            </button>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-md bg-red-500 px-2 py-1 text-[11px] font-semibold text-white transition hover:bg-red-600"
                                                @click="openAct(plan, 'reject')"
                                            >
                                                <i class="fa-solid fa-xmark" />
                                                {{ t.approvalFlow.reject }}
                                            </button>
                                        </div>

                                        <!-- Submit / resubmit -->
                                        <button
                                            v-else-if="plan.approval?.can_submit"
                                            type="button"
                                            :disabled="submittingId === plan.id"
                                            class="inline-flex items-center gap-1 rounded-md border border-primary/40 px-2 py-1 text-[11px] font-semibold text-primary transition hover:bg-primary hover:text-white disabled:opacity-60"
                                            @click="submitItem(plan)"
                                        >
                                            <i v-if="submittingId === plan.id" class="fa-solid fa-spinner fa-spin" />
                                            <i v-else class="fa-solid fa-paper-plane" />
                                            {{ plan.approval?.status === 'rejected' ? t.approvalFlow.resubmit : t.approvalFlow.submit }}
                                        </button>

                                        <!-- Not yet completed → submit is blocked -->
                                        <span
                                            v-else-if="canEdit
                                                && (plan.approval?.status === 'draft' || plan.approval?.status === 'rejected')
                                                && status.key !== 'completed'"
                                            class="inline-flex items-center gap-1 text-[11px] text-slate-400"
                                            :title="t.approvalFlow.completeFirstHint"
                                        >
                                            <i class="fa-solid fa-circle-info" />
                                            {{ t.approvalFlow.completeFirst }}
                                        </span>

                                        <!-- View chain -->
                                        <button
                                            v-if="plan.approval && plan.approval.steps.length"
                                            type="button"
                                            class="text-[11px] font-medium text-slate-400 transition hover:text-primary hover:underline"
                                            @click="openChain(plan)"
                                        >
                                            <i class="fa-solid fa-list-ol mr-0.5" />
                                            {{ t.approvalFlow.viewChain }}
                                        </button>
                                    </div>
                                </td>

                                <!-- Actions -->
                                <td v-if="canEdit" class="px-5 py-3.5 text-right">
                                    <div class="inline-flex gap-1 opacity-60 transition group-hover:opacity-100">
                                        <button
                                            type="button"
                                            class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                                            :title="t.idp.editPlan"
                                            @click="openEdit(plan)"
                                        >
                                            <i class="fa-solid fa-pen text-xs" />
                                        </button>
                                        <button
                                            type="button"
                                            class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                            :title="t.idp.form.delete"
                                            @click="askDelete(plan)"
                                        >
                                            <i class="fa-solid fa-trash text-xs" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Empty state -->
                <div v-else class="flex flex-col items-center gap-3 px-5 py-10 text-center">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                        <i class="fa-regular fa-folder-open text-xl" />
                    </div>
                    <p class="text-sm text-slate-400">{{ t.idp.noPlans }}</p>
                    <button
                        v-if="canEdit && model.can_add"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                        @click="openCreate(model.id)"
                    >
                        <i class="fa-solid fa-plus" />
                        {{ t.idp.addPlan }}
                    </button>
                </div>
            </section>
        </div>

        <!-- Add / edit drawer -->
        <Drawer
            :show="modalOpen"
            max-width="max-w-3xl"
            @close="requestClose"
        >
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

            <form
                id="idp-form"
                class="space-y-4"
                @submit.prevent="submit"
            >
                <!-- Everything that failed validation, gathered at the top: the
                     fields themselves are spread down a scrolling drawer, so a
                     rejected save used to look like nothing happened. -->
                <div
                    v-if="errorList.length"
                    class="rounded-xl border border-red-200 bg-red-50 px-4 py-3"
                >
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
                    :hint="t.idp.form.sectionAreaHint"
                    icon="fa-solid fa-bullseye"
                    :complete="areaComplete"
                >
                    <p v-if="form.errors.development_model_id" class="text-xs text-red-600">
                        {{ form.errors.development_model_id }}
                    </p>

                    <!-- Competency type — two choices, so a segmented control
                         rather than a dropdown: one click instead of three. -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.form.type }} <span class="text-red-500">*</span>
                        </label>
                        <!-- Few types read best as one-click choices; a longer
                             master list falls back to a searchable select. -->
                        <div v-if="competencyTypeOptions.length <= 3" class="grid gap-2 sm:grid-cols-3">
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
                            <span
                                class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500"
                            >
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
                        <p v-else class="mt-1.5 text-xs text-slate-400">{{ t.idp.form.reviewToolsHint }}</p>
                    </div>
                </FormSection>

                <!-- Step 2 — how it will be developed -->
                <FormSection
                    :step="2"
                    :title="t.idp.form.sectionProgram"
                    :hint="t.idp.form.sectionProgramHint"
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
                                <p
                                    class="mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-primary/80"
                                >
                                    <i class="fa-solid fa-quote-left text-[9px]" />
                                    {{ t.idp.form.selectedProgram }}
                                </p>
                                <p class="text-sm leading-relaxed text-slate-700">{{ selectedProgramLabel }}</p>
                            </div>
                        </template>

                        <p v-if="form.errors.development_program" class="mt-1 text-xs text-red-600">
                            {{ form.errors.development_program }}
                        </p>
                        <p v-else-if="!programLocked" class="mt-1.5 text-xs text-slate-400">
                            {{ programNarrowed ? t.idp.form.programScopeHint : t.idp.form.programScopeAllHint }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                            {{ t.idp.form.expectedOutcome }}
                            <span
                                class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500"
                            >
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
                        <div class="mt-1 flex items-start justify-between gap-3">
                            <p v-if="form.errors.expected_outcome" class="text-xs text-red-600">
                                {{ form.errors.expected_outcome }}
                            </p>
                            <p v-else class="text-xs text-slate-400">{{ t.idp.form.outcomeHint }}</p>
                            <span
                                class="shrink-0 text-xs tabular-nums"
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
                    :hint="t.idp.form.sectionTimelineHint"
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
                </FormSection>

                <!-- Step 4 — what came of it -->
                <FormSection
                    :step="4"
                    :title="t.idp.form.sectionResult"
                    :hint="t.idp.form.sectionResultHint"
                    icon="fa-solid fa-circle-check"
                    :complete="resultComplete"
                >
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                {{ t.idp.form.realization }}
                                <span
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500"
                                >
                                    {{ t.idp.form.optional }}
                                </span>
                            </label>
                            <DateInput v-model="form.realization_date" :invalid="!!form.errors.realization_date" />
                            <p v-if="form.errors.realization_date" class="mt-1 text-xs text-red-600">
                                {{ form.errors.realization_date }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1.5 flex items-center gap-2 text-sm font-medium text-slate-700">
                                {{ t.idp.form.resultEvidence }}
                                <span v-if="evidenceRequired" class="text-red-500">*</span>
                                <span
                                    v-else
                                    class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-slate-500"
                                >
                                    {{ t.idp.form.optional }}
                                </span>
                            </label>

                            <!-- Evidence is `required_with:realization_date` on the
                                 server; mirror that by only opening the field once
                                 a realization date is set. -->
                            <p
                                v-if="!evidenceRequired"
                                class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                            >
                                <i class="fa-solid fa-lock mt-0.5 text-[10px] text-slate-300" />
                                <span>{{ t.idp.form.evidenceLocked }}</span>
                            </p>

                            <template v-else>
                                <input
                                    v-model="form.result_evidence"
                                    type="text"
                                    :placeholder="t.idp.form.resultEvidencePlaceholder"
                                    class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="form.errors.result_evidence ? 'border-red-500' : 'border-border'"
                                >
                                <p v-if="form.errors.result_evidence" class="mt-1 text-xs text-red-600">
                                    {{ form.errors.result_evidence }}
                                </p>
                                <p v-else class="mt-1.5 text-xs text-slate-400">
                                    {{ t.idp.form.evidenceRequiredHint }}
                                </p>
                            </template>
                        </div>
                    </div>

                    <p
                        v-if="readyToSubmit"
                        class="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700"
                    >
                        <i class="fa-solid fa-paper-plane mt-0.5 text-[10px]" />
                        <span>{{ t.idp.form.readyToSubmit }}</span>
                    </p>
                </FormSection>
            </form>

            <template #footer>
                <!-- Which required fields are still missing, next to the button
                     that needs them — so a save that cannot succeed is visible
                     before it is attempted, not after. -->
                <p
                    v-if="missingRequired.length"
                    class="mr-auto hidden items-center gap-2 text-xs text-slate-500 sm:flex"
                >
                    <i class="fa-solid fa-circle-info text-[10px] text-slate-400" />
                    <span>
                        {{ t.idp.form.stillNeeded }}
                        <span class="font-medium text-slate-600">{{ missingRequired.join(', ') }}</span>
                    </span>
                </p>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="requestClose"
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

        <!-- Unsaved-changes confirmation, shown when the plan drawer is
             closed with a dirty form. -->
        <ConfirmDialog
            :show="confirmDiscard"
            :title="t.idp.form.discardTitle"
            :message="t.idp.form.discardMessage"
            :confirm-label="t.idp.form.discardConfirm"
            :cancel-label="t.idp.form.keepEditing"
            variant="danger"
            @confirm="discardChanges"
            @close="confirmDiscard = false"
        />

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
        </ConfirmDialog>

        <!-- Upload development plan drawer -->
        <Drawer
            :show="uploadOpen"
            :title="t.idp.upload.title"
            max-width="max-w-lg"
            @close="uploadOpen = false"
        >
            <form id="idp-upload-form" class="space-y-5" @submit.prevent="submitUpload">
                <!-- Step 1: download template + master data -->
                <div class="space-y-2">
                    <p class="text-sm font-semibold text-slate-700">{{ t.idp.upload.step1 }}</p>
                    <div class="flex flex-wrap gap-2">
                        <a
                            :href="`/idp/${emp.employee_id}/template`"
                            class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-emerald-700 shadow-sm transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-excel" />
                            {{ t.idp.upload.downloadTemplate }}
                        </a>
                        <a
                            href="/idp/master-pdf"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-semibold text-primary shadow-sm transition hover:bg-slate-50"
                        >
                            <i class="fa-solid fa-file-pdf" />
                            {{ t.idp.upload.downloadMaster }}
                        </a>
                    </div>
                </div>

                <!-- Instructions -->
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

                <!-- Step 2: choose file -->
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
                    @click="uploadOpen = false"
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

        <!-- Approve / reject drawer (note required) -->
        <Drawer
            :show="actOpen"
            max-width="max-w-lg"
            @close="actOpen = false"
        >
            <template #header>
                <div class="min-w-0">
                    <h3 class="font-bold text-slate-800">
                        {{ actDecision === 'approve' ? t.approvalFlow.approveTitle : t.approvalFlow.rejectTitle }}
                    </h3>
                    <p v-if="actPlan" class="mt-0.5 truncate text-sm text-slate-500">
                        {{ localize(competencyLabels, actPlan.competency_name) }} ·
                        {{ localize(programLabels, actPlan.development_program) }}
                    </p>
                </div>
            </template>

            <form id="idp-act-form" class="space-y-4" @submit.prevent="submitAct">
                <div
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                    :class="actDecision === 'approve'
                        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        : 'border-red-200 bg-red-50 text-red-800'"
                >
                    <i :class="actDecision === 'approve' ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'" />
                    <span v-if="actPlan?.approval">
                        {{ t.approvalFlow.layer }} {{ actPlan.approval.current_level }} / {{ actPlan.approval.total_levels }}
                    </span>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.approvalFlow.note }} <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        v-model="actForm.note"
                        rows="4"
                        :placeholder="t.approvalFlow.notePlaceholder"
                        class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="actForm.errors.note ? 'border-red-500' : 'border-border'"
                    />
                    <p v-if="actForm.errors.note" class="mt-1 text-xs text-red-600">{{ actForm.errors.note }}</p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="actOpen = false"
                >
                    {{ t.approvalFlow.cancel }}
                </button>
                <button
                    type="submit"
                    form="idp-act-form"
                    :disabled="actForm.processing"
                    class="inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold text-white transition disabled:opacity-60"
                    :class="actDecision === 'approve' ? 'bg-emerald-500 hover:bg-emerald-600' : 'bg-red-500 hover:bg-red-600'"
                >
                    <i v-if="actForm.processing" class="fa-solid fa-spinner fa-spin" />
                    {{ actDecision === 'approve' ? t.approvalFlow.confirmApprove : t.approvalFlow.confirmReject }}
                </button>
            </template>
        </Drawer>

        <!-- Approval-chain detail drawer -->
        <Drawer
            :show="chainOpen"
            :title="t.approvalFlow.chainTitle"
            max-width="max-w-lg"
            @close="chainOpen = false"
        >
            <div v-if="chainPlan?.approval" class="space-y-3">
                <p class="truncate text-sm font-medium text-slate-700">
                    {{ localize(competencyLabels, chainPlan.competency_name) }}
                </p>

                <!-- When it was submitted for approval -->
                <p
                    v-if="chainPlan.approval.submitted_at"
                    class="flex items-center gap-1.5 text-xs text-slate-500"
                >
                    <i class="fa-solid fa-paper-plane text-slate-300" />
                    <span>{{ t.approvalFlow.submittedAt }}: {{ fmtDateTime(chainPlan.approval.submitted_at) }}</span>
                </p>

                <ol class="space-y-3">
                    <li
                        v-for="step in chainPlan.approval.steps"
                        :key="step.level"
                        class="flex gap-3"
                    >
                        <div class="flex flex-col items-center">
                            <span
                                class="flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold ring-1 ring-inset"
                                :class="step.status === 'approved'
                                    ? 'bg-emerald-50 text-emerald-600 ring-emerald-200'
                                    : step.status === 'rejected'
                                        ? 'bg-red-50 text-red-600 ring-red-200'
                                        : chainPlan.approval.current_level === step.level && chainPlan.approval.status === 'pending'
                                            ? 'bg-amber-50 text-amber-600 ring-amber-200'
                                            : 'bg-slate-50 text-slate-400 ring-slate-200'"
                            >
                                {{ t.approvalFlow.layerShort }}{{ step.level }}
                            </span>
                            <span
                                v-if="step.level < chainPlan.approval.steps.length"
                                class="mt-1 w-px flex-1 bg-border"
                            />
                        </div>
                        <div class="min-w-0 flex-1 pb-1">
                            <div class="flex items-center gap-2">
                                <i :class="[stepIcon(step.status).icon, stepIcon(step.status).color]" class="text-xs" />
                                <span class="truncate text-sm font-semibold text-slate-800">
                                    {{ step.approver_name ?? step.approver_id }}
                                </span>
                            </div>
                            <p class="text-xs text-slate-400">
                                <template v-if="step.status === 'approved'">{{ t.approvalFlow.approvedBy }}</template>
                                <template v-else-if="step.status === 'rejected'">{{ t.approvalFlow.rejectedBy }}</template>
                                <template v-else>{{ t.approvalFlow.pending }}</template>
                                <span v-if="step.acted_at"> · {{ fmtDateTime(step.acted_at) }}</span>
                            </p>
                            <p
                                v-if="step.note"
                                class="mt-1 rounded-md bg-slate-50 px-2.5 py-1.5 text-xs text-slate-600"
                            >
                                <i class="fa-solid fa-quote-left mr-1 text-[10px] text-slate-300" />
                                {{ step.note }}
                            </p>
                        </div>
                    </li>
                </ol>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="chainOpen = false"
                >
                    {{ t.approvalFlow.cancel }}
                </button>
            </template>
        </Drawer>
    </div>
</template>
