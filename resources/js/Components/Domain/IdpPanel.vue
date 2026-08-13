<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmt, formatDateTime as fmtDateTime } from '@/Composables/useDate'

const { t, locale } = useLocale()

interface MasterOption {
    value: string
    value_en: string | null
    value_id: string | null
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
            competencyNames: MasterOption[]
            developmentPrograms: MasterOption[]
            reviewTools: MasterOption[]
        }
        competencyMap: Record<string, Array<MasterOption & { model_id: number | null }>>
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

const form = useForm({
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
})

const currentModel = computed(() =>
    props.developmentModels.find((m) => m.id === form.development_model_id) ?? null,
)

// Localized display label for a master option; the canonical `value` stays the
// stored/matched key while value_en / value_id drive what the user sees.
function masterLabel(item: MasterOption): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// For a Soft Competency, restrict programs to those linked to the competency.
const programOptions = computed<MasterOption[]>(() => {
    if (form.competency_type === 'Soft Competency' && form.competency_name) {
        const linked = props.competencyMap[form.competency_name.toLowerCase().trim()]
        if (linked?.length) return linked
    }
    return props.options.developmentPrograms
})

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

const competencyNameOptions = computed(() => toSelectOptions(props.options.competencyNames, form.competency_name))
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

const competencyTypeOptions = computed<Option[]>(() => [
    { value: 'Soft Competency', label: t.value.idp.form.soft },
    { value: 'Technical Competency', label: t.value.idp.form.technical },
])

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

function openCreate(modelId: number) {
    editingId.value = null
    form.reset()
    form.clearErrors()
    form.employee_id = emp.employee_id
    form.development_model_id = modelId
    modalOpen.value = true
}

function openEdit(plan: Plan) {
    editingId.value = plan.id
    form.clearErrors()
    form.employee_id = emp.employee_id
    form.development_model_id = plan.development_model_id
    form.competency_type = plan.competency_type
    form.competency_name = plan.competency_name
    form.development_program = plan.development_program
    form.review_tools = plan.review_tools ?? ''
    form.expected_outcome = plan.expected_outcome ?? ''
    form.time_frame_start = plan.time_frame_start?.slice(0, 10) ?? ''
    form.time_frame_end = plan.time_frame_end?.slice(0, 10) ?? ''
    form.realization_date = plan.realization_date?.slice(0, 10) ?? ''
    form.result_evidence = plan.result_evidence ?? ''
    modalOpen.value = true
}

function submit() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            modalOpen.value = false
            form.reset()
        },
    }
    if (editingId.value) {
        form.put(`/idp/${editingId.value}`, opts)
    } else {
        form.post('/idp', opts)
    }
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
                                            {{ plan.competency_type }}
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
                                    <div v-if="plan.expected_outcome" class="mt-0.5 max-w-xs text-xs text-slate-400">
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
            max-width="max-w-2xl"
            @close="modalOpen = false"
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
                class="space-y-6"
                @submit.prevent="submit"
            >
                <!-- Section: development area -->
                <section class="space-y-4">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-solid fa-layer-group text-slate-300" />
                        {{ t.idp.form.sectionArea }}
                    </h4>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.idp.form.developmentModel }}</label>
                        <div
                            class="flex items-center gap-2 rounded-lg border border-border bg-slate-50 px-3 py-2 text-sm text-slate-600"
                        >
                            <i class="fa-solid fa-layer-group text-xs text-slate-400" />
                            <span class="font-medium text-slate-700">{{ currentModel?.name ?? '—' }}</span>
                            <span v-if="currentModel" class="text-slate-400">· {{ currentModel.percentage }}%</span>
                        </div>
                        <p v-if="form.errors.development_model_id" class="mt-1 text-xs text-red-600">{{ form.errors.development_model_id }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.form.type }} <span class="text-red-500">*</span>
                            </label>
                            <SearchableSelect
                                :model-value="form.competency_type"
                                :options="competencyTypeOptions"
                                :placeholder="t.idp.form.selectPlaceholder"
                                :invalid="!!form.errors.competency_type"
                                @update:model-value="form.competency_type = $event"
                            />
                            <p v-if="form.errors.competency_type" class="mt-1 text-xs text-red-600">{{ form.errors.competency_type }}</p>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">
                                {{ t.idp.form.competencyName }} <span class="text-red-500">*</span>
                            </label>
                            <SearchableSelect
                                :model-value="form.competency_name"
                                :options="competencyNameOptions"
                                :placeholder="t.idp.form.selectPlaceholder"
                                :invalid="!!form.errors.competency_name"
                                @update:model-value="form.competency_name = $event"
                            />
                            <p v-if="form.errors.competency_name" class="mt-1 text-xs text-red-600">{{ form.errors.competency_name }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.idp.form.reviewTools }}</label>
                        <SearchableSelect
                            :model-value="form.review_tools"
                            :options="reviewToolsOptions"
                            :placeholder="t.idp.form.selectPlaceholder"
                            @update:model-value="form.review_tools = $event"
                        />
                    </div>
                </section>

                <!-- Section: development program -->
                <section class="space-y-4 border-t border-border pt-5">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-solid fa-book-open text-slate-300" />
                        {{ t.idp.form.sectionProgram }}
                    </h4>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.form.program }} <span class="text-red-500">*</span>
                        </label>
                        <SearchableSelect
                            :model-value="form.development_program"
                            :options="programSelectOptions"
                            :placeholder="t.idp.form.selectPlaceholder"
                            :invalid="!!form.errors.development_program"
                            @update:model-value="form.development_program = $event"
                        />
                        <p v-if="form.errors.development_program" class="mt-1 text-xs text-red-600">{{ form.errors.development_program }}</p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.idp.form.expectedOutcome }}</label>
                        <input
                            v-model="form.expected_outcome"
                            type="text"
                            maxlength="500"
                            :placeholder="t.idp.form.expectedOutcomePlaceholder"
                            class="w-full rounded-lg border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                        <p v-if="form.errors.expected_outcome" class="mt-1 text-xs text-red-600">{{ form.errors.expected_outcome }}</p>
                    </div>
                </section>

                <!-- Section: timeline & result -->
                <section class="space-y-4 border-t border-border pt-5">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-solid fa-calendar-days text-slate-300" />
                        {{ t.idp.form.sectionTimeline }}
                    </h4>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.form.timeframe }} <span class="text-red-500">*</span>
                        </label>
                        <div class="flex items-center gap-2">
                            <div class="flex-1">
                                <DateInput v-model="form.time_frame_start" :invalid="!!form.errors.time_frame_start" />
                            </div>
                            <i class="fa-solid fa-arrow-right-long shrink-0 text-slate-300" />
                            <div class="flex-1">
                                <DateInput v-model="form.time_frame_end" :invalid="!!form.errors.time_frame_end" />
                            </div>
                        </div>
                        <p v-if="form.errors.time_frame_start" class="mt-1 text-xs text-red-600">{{ form.errors.time_frame_start }}</p>
                        <p v-else-if="form.errors.time_frame_end" class="mt-1 text-xs text-red-600">{{ form.errors.time_frame_end }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.idp.form.realization }}</label>
                            <DateInput v-model="form.realization_date" :invalid="!!form.errors.realization_date" />
                            <p v-if="form.errors.realization_date" class="mt-1 text-xs text-red-600">{{ form.errors.realization_date }}</p>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.idp.form.resultEvidence }}</label>
                            <input
                                v-model="form.result_evidence"
                                type="text"
                                :placeholder="t.idp.form.resultEvidencePlaceholder"
                                class="w-full rounded-lg border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="form.errors.result_evidence ? 'border-red-500' : 'border-border'"
                            >
                            <p v-if="form.errors.result_evidence" class="mt-1 text-xs text-red-600">{{ form.errors.result_evidence }}</p>
                        </div>
                    </div>
                </section>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="modalOpen = false"
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
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

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
