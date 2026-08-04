<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Modal from '@/Components/Domain/Modal.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

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
}

interface Model {
    id: number
    name: string
    percentage: number
    plans: Plan[]
}

const props = defineProps<{
    employee: { data: { employee_id: string; fullname: string; designation_name: string | null } }
    developmentModels: Model[]
    options: {
        competencyNames: string[]
        developmentPrograms: string[]
        reviewTools: string[]
    }
    competencyMap: Record<string, Array<{ value: string; model_id: number | null }>>
}>()

const emp = props.employee.data

const modalOpen = ref(false)
const editingId = ref<number | null>(null)

const form = useForm({
    employee_id: emp.employee_id,
    development_model_id: null as number | null,
    competency_type: 'Soft Competency',
    competency_name: '',
    development_program: '',
    review_tools: '',
    expected_outcome: '',
    time_frame_start: '',
    time_frame_end: '',
    realization_date: '',
    result_evidence: '',
})

// For a Soft Competency, restrict programs to those linked to the competency.
const programOptions = computed(() => {
    if (form.competency_type === 'Soft Competency' && form.competency_name) {
        const linked = props.competencyMap[form.competency_name.toLowerCase().trim()]
        if (linked?.length) return linked.map((p) => p.value)
    }
    return props.options.developmentPrograms
})

function openCreate(modelId: number) {
    editingId.value = null
    form.reset()
    form.clearErrors()
    form.employee_id = emp.employee_id
    form.development_model_id = modelId
    form.competency_type = 'Soft Competency'
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
    form.time_frame_start = plan.time_frame_start ?? ''
    form.time_frame_end = plan.time_frame_end ?? ''
    form.realization_date = plan.realization_date ?? ''
    form.result_evidence = plan.result_evidence ?? ''
    modalOpen.value = true
}

function submit() {
    const opts = {
        preserveScroll: true,
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

const confirmingDelete = reactive({ open: false, plan: null as Plan | null })

function askDelete(plan: Plan) {
    confirmingDelete.plan = plan
    confirmingDelete.open = true
}

function doDelete() {
    if (!confirmingDelete.plan) return
    router.delete(`/idp/${confirmingDelete.plan.id}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmingDelete.open = false
            confirmingDelete.plan = null
        },
    })
}

function fmt(value: string | null): string {
    if (!value) return '—'
    const d = new Date(value)
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
    <Head :title="`${t.idp.manage} — ${emp.fullname}`" />

    <AppLayout>
        <PageHeader :title="t.idp.manageTitle">
            <template #actions>
                <Link
                    href="/idp"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-arrow-left text-xs" />
                    {{ t.idp.backToList }}
                </Link>
            </template>
        </PageHeader>

        <!-- Employee banner -->
        <div class="mb-6 rounded-xl border border-border bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-slate-800">{{ emp.fullname }}</h2>
            <p class="text-sm text-slate-500">{{ emp.employee_id }} · {{ emp.designation_name ?? '—' }}</p>
        </div>

        <!-- Plans grouped by development model -->
        <div class="space-y-6">
            <section
                v-for="model in developmentModels"
                :key="model.id"
                class="rounded-xl border border-border bg-white shadow-sm"
            >
                <div class="flex items-center justify-between border-b border-border px-5 py-3">
                    <h3 class="font-semibold text-slate-700">
                        {{ model.name }}
                        <span class="ml-1 text-xs font-normal text-slate-400">{{ model.percentage }}%</span>
                    </h3>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                        @click="openCreate(model.id)"
                    >
                        <i class="fa-solid fa-plus" />
                        {{ t.idp.addPlan }}
                    </button>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[820px] text-left text-sm">
                        <thead>
                            <tr class="border-b border-border text-xs uppercase tracking-wider text-slate-400">
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.form.competencyName }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.form.program }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.form.timeframe }}</th>
                                <th class="px-5 py-2.5 font-semibold">{{ t.idp.form.realization }}</th>
                                <th class="px-5 py-2.5 text-right font-semibold" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="plan in model.plans"
                                :key="plan.id"
                                class="border-b border-border/60 last:border-0 hover:bg-slate-50"
                            >
                                <td class="px-5 py-3">
                                    <div class="font-medium text-slate-700">{{ plan.competency_name }}</div>
                                    <div class="text-xs text-slate-400">{{ plan.competency_type }}</div>
                                </td>
                                <td class="px-5 py-3 text-slate-600">{{ plan.development_program }}</td>
                                <td class="px-5 py-3 text-slate-500">
                                    {{ fmt(plan.time_frame_start) }} – {{ fmt(plan.time_frame_end) }}
                                </td>
                                <td class="px-5 py-3 text-slate-500">{{ fmt(plan.realization_date) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button
                                        type="button"
                                        class="mr-1 h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                                        @click="openEdit(plan)"
                                    >
                                        <i class="fa-solid fa-pen" />
                                    </button>
                                    <button
                                        type="button"
                                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                        @click="askDelete(plan)"
                                    >
                                        <i class="fa-solid fa-trash" />
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="model.plans.length === 0">
                                <td colspan="5" class="px-5 py-8 text-center text-sm text-slate-400">
                                    {{ t.idp.noPlans }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Add / edit modal -->
        <Modal
            :show="modalOpen"
            :title="editingId ? t.idp.editPlan : t.idp.addPlan"
            max-width="max-w-2xl"
            @close="modalOpen = false"
        >
            <form
                id="idp-form"
                class="grid grid-cols-1 gap-4 sm:grid-cols-2"
                @submit.prevent="submit"
            >
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.type }}</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input v-model="form.competency_type" type="radio" value="Soft Competency" class="text-primary focus:ring-primary">
                            {{ t.idp.form.soft }}
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input v-model="form.competency_type" type="radio" value="Technical Competency" class="text-primary focus:ring-primary">
                            {{ t.idp.form.technical }}
                        </label>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.competencyName }}</label>
                    <input
                        v-model="form.competency_name"
                        list="competency-options"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.competency_name ? 'border-red-500' : 'border-border'"
                    >
                    <datalist id="competency-options">
                        <option v-for="c in options.competencyNames" :key="c" :value="c" />
                    </datalist>
                    <p v-if="form.errors.competency_name" class="mt-1 text-xs text-red-600">{{ form.errors.competency_name }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.program }}</label>
                    <input
                        v-model="form.development_program"
                        list="program-options"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.development_program ? 'border-red-500' : 'border-border'"
                    >
                    <datalist id="program-options">
                        <option v-for="p in programOptions" :key="p" :value="p" />
                    </datalist>
                    <p v-if="form.errors.development_program" class="mt-1 text-xs text-red-600">{{ form.errors.development_program }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.reviewTools }}</label>
                    <input
                        v-model="form.review_tools"
                        list="review-options"
                        class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                    <datalist id="review-options">
                        <option v-for="r in options.reviewTools" :key="r" :value="r" />
                    </datalist>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.start }}</label>
                    <input
                        v-model="form.time_frame_start"
                        type="date"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.time_frame_start ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.time_frame_start" class="mt-1 text-xs text-red-600">{{ form.errors.time_frame_start }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.end }}</label>
                    <input
                        v-model="form.time_frame_end"
                        type="date"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.time_frame_end ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.time_frame_end" class="mt-1 text-xs text-red-600">{{ form.errors.time_frame_end }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.realization }}</label>
                    <input
                        v-model="form.realization_date"
                        type="date"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.realization_date ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.realization_date" class="mt-1 text-xs text-red-600">{{ form.errors.realization_date }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.expectedOutcome }}</label>
                    <textarea
                        v-model="form.expected_outcome"
                        rows="2"
                        class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                    <p v-if="form.errors.expected_outcome" class="mt-1 text-xs text-red-600">{{ form.errors.expected_outcome }}</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.idp.form.resultEvidence }}</label>
                    <textarea
                        v-model="form.result_evidence"
                        rows="2"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.result_evidence ? 'border-red-500' : 'border-border'"
                    />
                    <p v-if="form.errors.result_evidence" class="mt-1 text-xs text-red-600">{{ form.errors.result_evidence }}</p>
                </div>
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
        </Modal>

        <!-- Delete confirmation -->
        <Modal
            :show="confirmingDelete.open"
            :title="t.idp.deleteTitle"
            max-width="max-w-md"
            @close="confirmingDelete.open = false"
        >
            <p class="text-sm text-slate-600">{{ t.idp.deleteConfirm }}</p>
            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                    @click="confirmingDelete.open = false"
                >
                    {{ t.idp.form.cancel }}
                </button>
                <button
                    type="button"
                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700"
                    @click="doDelete"
                >
                    {{ t.idp.form.delete }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
