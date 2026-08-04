<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Domain/Modal.vue'
import DataTable, { type Column } from '@/Components/Domain/DataTable.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Assessment {
    id: number
    period: number
    assessment_date: string | null
    matrix_grade: string | null
    proposed_grade: string | null
    priority_for_development: string | null
    [key: string]: any
}

interface MatrixConfig {
    period: number
    grade_level: string
    [key: string]: any
}

const props = defineProps<{
    employeeId: string
    assessments: Assessment[]
    matrixConfigs: MatrixConfig[]
    canInput: boolean
}>()

const columns: Column[] = [
    { key: 'period', label: t.value.competency.period, tdClass: 'font-medium text-slate-700' },
    { key: 'matrix_grade', label: t.value.competency.matrixGrade },
    { key: 'proposed_grade', label: t.value.competency.proposedGrade },
    { key: 'priority_for_development', label: t.value.competency.priority },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

const COMPETENCIES = [
    { label: 'Synergized Team', score: 'synergized_team_score', min: 'synergized_team_min' },
    { label: 'Integrity', score: 'integrity_score', min: 'integrity_min' },
    { label: 'Growth', score: 'growth_score', min: 'growth_min' },
    { label: 'Adaptive', score: 'adaptive_score', min: 'adaptive_min' },
    { label: 'Passion', score: 'passion_score', min: 'passion_min' },
    { label: 'Manage & Planning', score: 'manage_planning_score', min: 'manage_planning_min' },
    { label: 'Decision Making', score: 'decision_making_score', min: 'decision_making_min' },
    { label: 'Relationship Building', score: 'relationship_building_score', min: 'relationship_building_min' },
    { label: 'Developing Others', score: 'developing_others_score', min: 'developing_others_min' },
]

const modalOpen = ref(false)

const form = useForm<Record<string, any>>({
    employee_id: props.employeeId,
    assessment_date: new Date().toISOString().slice(0, 10),
    proposed_grade: '',
    priority_for_development: 'No',
    synergized_team_score: 0,
    integrity_score: 0,
    growth_score: 0,
    adaptive_score: 0,
    passion_score: 0,
    manage_planning_score: 0,
    decision_making_score: 0,
    relationship_building_score: 0,
    developing_others_score: 0,
})

// Live matrix (target) grade: highest grade_level for the period whose minimums
// are all satisfied. Mirrors MatrixGradeService server-side.
const computedGrade = computed<string | null>(() => {
    const period = new Date(form.assessment_date).getFullYear()
    let best: string | null = null
    for (const cfg of props.matrixConfigs) {
        if (Number(cfg.period) !== period) continue
        const ok = COMPETENCIES.every((c) => Number(form[c.score] ?? 0) >= Number(cfg[c.min] ?? 0))
        if (ok && (best === null || cfg.grade_level > best)) best = cfg.grade_level
    }
    return best
})

function openCreate() {
    form.reset()
    form.clearErrors()
    form.employee_id = props.employeeId
    modalOpen.value = true
}

function openEdit(a: Assessment) {
    form.clearErrors()
    form.employee_id = props.employeeId
    form.assessment_date = a.assessment_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10)
    form.proposed_grade = a.proposed_grade ?? ''
    form.priority_for_development = a.priority_for_development ?? 'No'
    for (const c of COMPETENCIES) form[c.score] = a[c.score] ?? 0
    modalOpen.value = true
}

function submit() {
    form.post('/competency-assessment', {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    })
}
</script>

<template>
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.competency.title }}
            </h3>
            <button
                v-if="canInput"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                @click="openCreate"
            >
                <i class="fa-solid fa-plus" /> {{ t.competency.add }}
            </button>
        </div>

        <DataTable :columns="columns" :rows="assessments" row-key="id">
            <template #cell-matrix_grade="{ value }">
                <span class="rounded bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">{{ value ?? '—' }}</span>
            </template>
            <template #cell-action="{ row }">
                <button v-if="canInput" class="h-8 w-8 rounded-md text-slate-400 hover:bg-slate-100 hover:text-primary" @click="openEdit(row)">
                    <i class="fa-solid fa-pen" />
                </button>
            </template>
            <template #empty>{{ t.competency.empty }}</template>
        </DataTable>

        <!-- Add / edit modal -->
        <Modal :show="modalOpen" :title="t.competency.formTitle" max-width="max-w-2xl" @close="modalOpen = false">
            <form id="competency-form" class="space-y-4" @submit.prevent="submit">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.competency.assessmentDate }}</label>
                        <DateInput v-model="form.assessment_date" :invalid="!!form.errors.assessment_date" />
                        <p v-if="form.errors.assessment_date" class="mt-1 text-xs text-red-600">{{ form.errors.assessment_date }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.competency.matrixTarget }}</label>
                        <div class="rounded-md border border-border bg-slate-50 px-3 py-2 text-sm font-semibold text-primary">
                            {{ computedGrade ?? '—' }}
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.competency.priority }}</label>
                        <SearchableSelect
                            v-model="form.priority_for_development"
                            :options="[{ value: 'No', label: 'No' }, { value: 'Yes', label: 'Yes' }]"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div v-for="c in COMPETENCIES" :key="c.score">
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ c.label }}</label>
                        <select v-model.number="form[c.score]" class="w-full rounded-md border border-border px-2 py-1.5 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                            <option v-for="n in [0, 1, 2, 3, 4]" :key="n" :value="n">{{ n }}</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.competency.proposedGrade }}</label>
                    <input v-model="form.proposed_grade" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="modalOpen = false">
                    {{ t.competency.cancel }}
                </button>
                <button type="submit" form="competency-form" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.competency.save }}
                </button>
            </template>
        </Modal>
    </section>
</template>
