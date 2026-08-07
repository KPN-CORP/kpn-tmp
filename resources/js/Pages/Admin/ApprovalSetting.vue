<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

type ApproverType = 'manager_l1' | 'manager_l2' | 'specific_employee'

interface Layer {
    id: number
    sequence: number
    name: string
    approver_type: ApproverType
    approver_employee_id: string | null
    approver_name: string | null
    is_active: boolean
}

interface Flow {
    id: number
    module: 'idp' | 'appraisal'
    name: string
    description: string | null
    is_active: boolean
    layers: Layer[]
}

const props = defineProps<{ flows: Flow[] }>()

/**
 * --------------------------------------------------------------------------
 * Module tabs
 * --------------------------------------------------------------------------
 */

const activeModule = ref<Flow['module']>(props.flows[0]?.module ?? 'idp')

const activeFlow = computed<Flow | undefined>(() =>
    props.flows.find((f) => f.module === activeModule.value),
)

function moduleLabel(module: Flow['module']): string {
    return module === 'idp'
        ? t.value.approval.moduleIdp
        : t.value.approval.moduleAppraisal
}

/**
 * --------------------------------------------------------------------------
 * Approver type helpers
 * --------------------------------------------------------------------------
 */

function approverLabel(layer: Layer): string {
    if (layer.approver_type === 'manager_l1') return t.value.approval.approverManagerL1
    if (layer.approver_type === 'manager_l2') return t.value.approval.approverManagerL2
    return layer.approver_name ?? layer.approver_employee_id ?? '—'
}

const approverTypeOptions = computed(() => [
    { value: 'manager_l1' as ApproverType, label: t.value.approval.approverManagerL1 },
    { value: 'manager_l2' as ApproverType, label: t.value.approval.approverManagerL2 },
    { value: 'specific_employee' as ApproverType, label: t.value.approval.approverSpecific },
])

/**
 * --------------------------------------------------------------------------
 * Flow: toggle active + edit
 * --------------------------------------------------------------------------
 */

const flowModal = ref(false)
const flowForm = useForm({ name: '', description: '', is_active: true })

function openFlow() {
    if (!activeFlow.value) return
    flowForm.clearErrors()
    flowForm.name = activeFlow.value.name
    flowForm.description = activeFlow.value.description ?? ''
    flowForm.is_active = activeFlow.value.is_active
    flowModal.value = true
}

function submitFlow() {
    if (!activeFlow.value) return
    flowForm.put(`/approval-setting/flows/${activeFlow.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (flowModal.value = false),
    })
}

function toggleFlowActive() {
    if (!activeFlow.value) return
    router.put(
        `/approval-setting/flows/${activeFlow.value.id}`,
        {
            name: activeFlow.value.name,
            description: activeFlow.value.description,
            is_active: !activeFlow.value.is_active,
        },
        { preserveScroll: true },
    )
}

/**
 * --------------------------------------------------------------------------
 * Layer: add / edit
 * --------------------------------------------------------------------------
 */

const layerModal = ref(false)
const editingLayerId = ref<number | null>(null)

const layerForm = useForm({
    approval_flow_id: 0,
    name: '',
    approver_type: 'manager_l1' as ApproverType,
    approver_employee_id: null as string | null,
    is_active: true,
})

// The label shown in the employee picker for the currently-selected approver.
const selectedApproverName = ref<string | null>(null)

function openLayer(layer?: Layer) {
    if (!activeFlow.value) return
    editingLayerId.value = layer?.id ?? null
    layerForm.clearErrors()

    layerForm.approval_flow_id = activeFlow.value.id
    layerForm.name = layer?.name ?? ''
    layerForm.approver_type = layer?.approver_type ?? 'manager_l1'
    layerForm.approver_employee_id = layer?.approver_employee_id ?? null
    layerForm.is_active = layer?.is_active ?? true

    selectedApproverName.value = layer?.approver_name ?? null
    layerModal.value = true
}

// Clear the specific employee when switching back to a dynamic source.
watch(
    () => layerForm.approver_type,
    (type) => {
        if (type !== 'specific_employee') {
            layerForm.approver_employee_id = null
            selectedApproverName.value = null
        }
    },
)

function submitLayer() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (layerModal.value = false),
    }

    if (editingLayerId.value) {
        layerForm.put(`/approval-setting/layers/${editingLayerId.value}`, opts)
    } else {
        layerForm.post('/approval-setting/layers', opts)
    }
}

/**
 * --------------------------------------------------------------------------
 * Employee picker (specific approver) — live search
 * --------------------------------------------------------------------------
 */

interface EmployeeResult {
    employee_id: string
    fullname: string
    designation_name: string | null
    group_company: string | null
}

const empQuery = ref('')
const empResults = ref<EmployeeResult[]>([])
const empSearching = ref(false)
const empOpen = ref(false)
let empDebounce: ReturnType<typeof setTimeout> | undefined

watch(empQuery, () => {
    clearTimeout(empDebounce)
    empDebounce = setTimeout(searchEmployees, 300)
})

async function searchEmployees() {
    empSearching.value = true
    try {
        const res = await fetch(
            `/approval-setting/employees?q=${encodeURIComponent(empQuery.value)}`,
            { headers: { Accept: 'application/json' } },
        )
        empResults.value = res.ok ? await res.json() : []
    } catch {
        empResults.value = []
    } finally {
        empSearching.value = false
    }
}

function openEmployeePicker() {
    empOpen.value = true
    empQuery.value = ''
    if (empResults.value.length === 0) searchEmployees()
}

function pickEmployee(emp: EmployeeResult) {
    layerForm.approver_employee_id = emp.employee_id
    selectedApproverName.value = emp.fullname
    empOpen.value = false
}

/**
 * --------------------------------------------------------------------------
 * Reorder (move up / down) — persists the whole ordered list
 * --------------------------------------------------------------------------
 */

const reordering = ref(false)

function move(index: number, direction: -1 | 1) {
    if (!activeFlow.value) return
    const layers = [...activeFlow.value.layers]
    const target = index + direction
    if (target < 0 || target >= layers.length) return

    ;[layers[index], layers[target]] = [layers[target], layers[index]]

    router.post(
        `/approval-setting/flows/${activeFlow.value.id}/reorder`,
        { layer_ids: layers.map((l) => l.id) },
        {
            preserveScroll: true,
            onStart: () => (reordering.value = true),
            onFinish: () => (reordering.value = false),
        },
    )
}

/**
 * --------------------------------------------------------------------------
 * Delete layer
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<Layer | null>(null)
const deleting = ref(false)

function confirmDelete() {
    if (!pendingDelete.value) return
    router.delete(`/approval-setting/layers/${pendingDelete.value.id}`, {
        preserveScroll: true,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}

const modules = reactive(props.flows.map((f) => f.module))
</script>

<template>
    <Head :title="t.approval.title" />

    <AppLayout>
        <PageHeader
            :title="t.approval.title"
            :subtitle="t.approval.subtitle"
        />

        <!-- ================================================================
             Module tabs
        ================================================================= -->
        <div class="mb-6 rounded-xl border border-border bg-white p-1.5 shadow-sm">
            <nav
                class="grid grid-cols-1 gap-1 sm:grid-cols-2"
                aria-label="Approval modules"
            >
                <button
                    v-for="m in modules"
                    :key="m"
                    type="button"
                    class="flex items-center justify-center gap-2 rounded-lg px-4 py-3 text-sm font-semibold transition-all duration-200"
                    :class="
                        activeModule === m
                            ? 'bg-primary text-white shadow-sm'
                            : 'text-slate-500 hover:bg-slate-50 hover:text-slate-700'
                    "
                    @click="activeModule = m"
                >
                    <i
                        :class="
                            m === 'idp'
                                ? 'fa-solid fa-seedling'
                                : 'fa-solid fa-chart-line'
                        "
                    />
                    <span>{{ moduleLabel(m) }}</span>
                </button>
            </nav>
        </div>

        <template v-if="activeFlow">
            <!-- ============================================================
                 Flow header
            ============================================================= -->
            <section
                class="mb-6 rounded-xl border border-border bg-white p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-semibold text-slate-800">
                                {{ activeFlow.name }}
                            </h3>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                :class="
                                    activeFlow.is_active
                                        ? 'bg-emerald-50 text-emerald-600'
                                        : 'bg-slate-100 text-slate-500'
                                "
                            >
                                <span
                                    class="h-1.5 w-1.5 rounded-full"
                                    :class="
                                        activeFlow.is_active
                                            ? 'bg-emerald-500'
                                            : 'bg-slate-400'
                                    "
                                />
                                {{
                                    activeFlow.is_active
                                        ? t.approval.flowActive
                                        : t.approval.flowInactive
                                }}
                            </span>
                        </div>
                        <p class="mt-1 max-w-xl text-sm text-slate-400">
                            {{ activeFlow.description || t.approval.subtitle }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <!-- Active toggle -->
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="activeFlow.is_active"
                            :title="t.approval.toggleFlow"
                            class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors"
                            :class="
                                activeFlow.is_active
                                    ? 'bg-emerald-500'
                                    : 'bg-slate-300'
                            "
                            @click="toggleFlowActive"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                :class="
                                    activeFlow.is_active
                                        ? 'translate-x-6'
                                        : 'translate-x-1'
                                "
                            />
                        </button>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                            @click="openFlow"
                        >
                            <i class="fa-solid fa-pen text-xs" />
                            {{ t.approval.editFlow }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- ============================================================
                 Layers
            ============================================================= -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.approval.layers }}
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.approval.layersHint }}
                    </p>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    @click="openLayer()"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.approval.addLayer }}
                </button>
            </div>

            <!-- Layer chain -->
            <ol v-if="activeFlow.layers.length" class="mt-5 space-y-3">
                <li
                    v-for="(layer, i) in activeFlow.layers"
                    :key="layer.id"
                    class="relative flex items-center gap-4 rounded-xl border border-border bg-white p-4 shadow-sm"
                    :class="{ 'opacity-60': !layer.is_active }"
                >
                    <!-- Step badge -->
                    <div
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary"
                    >
                        {{ i + 1 }}
                    </div>

                    <!-- Details -->
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-slate-800">
                                {{ layer.name }}
                            </span>
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                :class="
                                    layer.approver_type === 'specific_employee'
                                        ? 'bg-sky-50 text-sky-600'
                                        : 'bg-indigo-50 text-indigo-600'
                                "
                            >
                                <i
                                    class="fa-solid text-[9px]"
                                    :class="
                                        layer.approver_type === 'specific_employee'
                                            ? 'fa-user'
                                            : 'fa-sitemap'
                                    "
                                />
                                {{
                                    layer.approver_type === 'specific_employee'
                                        ? t.approval.specificBadge
                                        : t.approval.dynamicBadge
                                }}
                            </span>
                            <span
                                v-if="!layer.is_active"
                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500"
                            >
                                {{ t.approval.layerInactive }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-sm text-slate-500">
                            <i class="fa-solid fa-arrow-right-long mr-1 text-[10px] text-slate-300" />
                            {{ approverLabel(layer) }}
                        </p>
                    </div>

                    <!-- Reorder -->
                    <div class="flex flex-col">
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center rounded text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 disabled:pointer-events-none disabled:opacity-30"
                            :disabled="i === 0 || reordering"
                            :title="t.approval.moveUp"
                            @click="move(i, -1)"
                        >
                            <i class="fa-solid fa-chevron-up text-xs" />
                        </button>
                        <button
                            type="button"
                            class="flex h-6 w-6 items-center justify-center rounded text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 disabled:pointer-events-none disabled:opacity-30"
                            :disabled="i === activeFlow.layers.length - 1 || reordering"
                            :title="t.approval.moveDown"
                            @click="move(i, 1)"
                        >
                            <i class="fa-solid fa-chevron-down text-xs" />
                        </button>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-1 border-l border-border/60 pl-3">
                        <IconButton
                            icon="fa-solid fa-pen"
                            variant="edit"
                            :title="t.approval.editLayer"
                            @click="openLayer(layer)"
                        />
                        <IconButton
                            icon="fa-solid fa-trash"
                            variant="delete"
                            :title="t.approval.deleteLayer"
                            @click="pendingDelete = layer"
                        />
                    </div>
                </li>
            </ol>

            <!-- Empty state -->
            <div
                v-else
                class="mt-5 rounded-xl border border-dashed border-border bg-white px-6 py-14 text-center"
            >
                <div
                    class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <i class="fa-solid fa-list-check text-xl" />
                </div>
                <h4 class="mt-4 font-semibold text-slate-700">
                    {{ t.approval.emptyLayers }}
                </h4>
                <p class="mx-auto mt-1 max-w-md text-sm text-slate-400">
                    {{ t.approval.emptyLayersHint }}
                </p>
                <button
                    type="button"
                    class="mt-5 inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    @click="openLayer()"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.approval.addLayer }}
                </button>
            </div>
        </template>

        <!-- ================================================================
             FLOW MODAL
        ================================================================= -->
        <Drawer
            :show="flowModal"
            :title="t.approval.editFlow"
            @close="flowModal = false"
        >
            <form id="flow-form" class="space-y-4" @submit.prevent="submitFlow">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        {{ t.approval.flowName }}
                    </label>
                    <input
                        v-model="flowForm.name"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="flowForm.errors.name ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="flowForm.errors.name" class="mt-1 text-xs text-red-600">
                        {{ flowForm.errors.name }}
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        {{ t.approval.flowDescription }}
                    </label>
                    <textarea
                        v-model="flowForm.description"
                        rows="3"
                        class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>

                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input
                        v-model="flowForm.is_active"
                        type="checkbox"
                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    >
                    {{ t.approval.flowEnabled }}
                </label>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="flowModal = false"
                >
                    {{ t.approval.cancel }}
                </button>
                <button
                    type="submit"
                    form="flow-form"
                    :disabled="flowForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.approval.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             LAYER MODAL
        ================================================================= -->
        <Drawer
            :show="layerModal"
            :title="editingLayerId ? t.approval.editLayer : t.approval.addLayer"
            @close="layerModal = false"
        >
            <form id="layer-form" class="space-y-4" @submit.prevent="submitLayer">
                <!-- Layer name -->
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">
                        {{ t.approval.layerName }}
                    </label>
                    <input
                        v-model="layerForm.name"
                        :placeholder="t.approval.layerNameHint"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="layerForm.errors.name ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="layerForm.errors.name" class="mt-1 text-xs text-red-600">
                        {{ layerForm.errors.name }}
                    </p>
                </div>

                <!-- Approver type -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.approval.approverType }}
                    </label>
                    <div class="space-y-2">
                        <label
                            v-for="opt in approverTypeOptions"
                            :key="opt.value"
                            class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm transition"
                            :class="
                                layerForm.approver_type === opt.value
                                    ? 'border-primary bg-primary/5 text-slate-800'
                                    : 'border-border text-slate-600 hover:bg-slate-50'
                            "
                        >
                            <input
                                v-model="layerForm.approver_type"
                                type="radio"
                                :value="opt.value"
                                class="h-4 w-4 text-primary focus:ring-primary"
                            >
                            {{ opt.label }}
                        </label>
                    </div>
                    <p class="mt-1.5 text-xs text-slate-400">
                        {{ t.approval.approverTypeHint }}
                    </p>
                </div>

                <!-- Specific employee picker -->
                <div v-if="layerForm.approver_type === 'specific_employee'">
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.approval.pickEmployee }}
                    </label>

                    <button
                        v-if="!empOpen"
                        type="button"
                        class="flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition hover:bg-slate-50"
                        :class="layerForm.errors.approver_employee_id ? 'border-red-500' : 'border-border'"
                        @click="openEmployeePicker"
                    >
                        <span
                            v-if="layerForm.approver_employee_id"
                            class="min-w-0 truncate text-slate-700"
                        >
                            {{ selectedApproverName ?? layerForm.approver_employee_id }}
                            <span class="text-slate-400">
                                · {{ layerForm.approver_employee_id }}
                            </span>
                        </span>
                        <span v-else class="text-slate-400">
                            {{ t.approval.pickEmployee }}
                        </span>
                        <i class="fa-solid fa-chevron-down shrink-0 text-xs text-slate-400" />
                    </button>

                    <div v-else class="rounded-md border border-border">
                        <div class="relative border-b border-border">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="empQuery"
                                type="search"
                                autofocus
                                :placeholder="t.approval.searchEmployee"
                                class="w-full rounded-t-md border-0 py-2 pl-9 pr-9 text-sm focus:outline-none focus:ring-0"
                            >
                            <i
                                v-if="empSearching"
                                class="fa-solid fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                        </div>
                        <ul class="max-h-56 overflow-y-auto py-1">
                            <li v-for="emp in empResults" :key="emp.employee_id">
                                <button
                                    type="button"
                                    class="flex w-full flex-col items-start px-3 py-2 text-left transition hover:bg-slate-50"
                                    @click="pickEmployee(emp)"
                                >
                                    <span class="text-sm font-medium text-slate-700">
                                        {{ emp.fullname }}
                                    </span>
                                    <span class="text-xs text-slate-400">
                                        {{ emp.employee_id }} ·
                                        {{ emp.designation_name ?? 'N.A' }}
                                    </span>
                                </button>
                            </li>
                            <li
                                v-if="!empSearching && empResults.length === 0"
                                class="px-3 py-6 text-center text-sm text-slate-400"
                            >
                                {{ t.approval.noEmployeeResults }}
                            </li>
                        </ul>
                    </div>

                    <p
                        v-if="layerForm.errors.approver_employee_id"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ layerForm.errors.approver_employee_id }}
                    </p>
                </div>

                <!-- Active -->
                <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                    <input
                        v-model="layerForm.is_active"
                        type="checkbox"
                        class="h-4 w-4 rounded border-border text-primary focus:ring-primary"
                    >
                    {{ t.approval.layerActive }}
                </label>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="layerModal = false"
                >
                    {{ t.approval.cancel }}
                </button>
                <button
                    type="submit"
                    form="layer-form"
                    :disabled="layerForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.approval.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->
        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.approval.deleteTitle"
            :message="t.approval.confirmDeleteLayer"
            :confirm-label="t.approval.delete"
            :cancel-label="t.approval.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ pendingDelete.name }}
            </p>
        </ConfirmDialog>
    </AppLayout>
</template>
