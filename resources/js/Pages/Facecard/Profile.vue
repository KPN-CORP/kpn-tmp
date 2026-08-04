<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import DataTable, { type Column } from '@/Components/Domain/DataTable.vue'
import NineBoxSection from '@/Components/Domain/NineBoxSection.vue'
import CompetencySection from '@/Components/Domain/CompetencySection.vue'
import ResultSummarySection from '@/Components/Domain/ResultSummarySection.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Employee {
    employee_id: string
    fullname: string
    email: string | null
    gender: string | null
    group_company: string | null
    company_name: string | null
    designation_code: string | null
    designation_name: string | null
    job_level: string | null
    employee_type: string | null
    unit: string | null
    office_area: string | null
    date_of_joining: string | null
}

interface Appraisal {
    id: number
    appraisal_year: number
    grade: string | null
    potential: string | null
    talent_box: string | null
}

const props = defineProps<{
    employee: { data: Employee }
    formalEducations: Array<Record<string, any>>
    workExperiences: Array<Record<string, any>>
    trainings: Array<Record<string, any>>
    appraisals: Appraisal[]
    competencyAssessments: Array<Record<string, any>>
    resultSummary: Record<string, any> | null
    matrixGradeConfigs: Array<Record<string, any>>
    canInputNineBox: boolean
    canInputCompetency: boolean
    canInputSuccession: boolean
}>()

const emp = props.employee.data

function fmtDate(value: string | null): string {
    if (!value) return '—'
    const d = new Date(value)
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' })
}

const details = [
    { label: t.value.facecard.profile.employeeId, value: emp.employee_id },
    { label: t.value.facecard.profile.email, value: emp.email },
    { label: t.value.facecard.profile.businessUnit, value: emp.group_company },
    { label: t.value.facecard.profile.company, value: emp.company_name },
    { label: t.value.facecard.profile.designation, value: emp.designation_name },
    { label: t.value.facecard.profile.jobLevel, value: emp.job_level },
    { label: t.value.facecard.profile.unit, value: emp.unit },
    { label: t.value.facecard.profile.employeeType, value: emp.employee_type },
    { label: t.value.facecard.profile.officeArea, value: emp.office_area },
    { label: t.value.facecard.profile.joinDate, value: fmtDate(emp.date_of_joining) },
]

const educationColumns: Column[] = [
    { key: 'from_date', label: t.value.facecard.profile.from },
    { key: 'to_date', label: t.value.facecard.profile.to },
    { key: 'degree', label: t.value.facecard.profile.degree },
    { key: 'institution', label: t.value.facecard.profile.institution },
    { key: 'major', label: t.value.facecard.profile.major },
    { key: 'gpa_percentage', label: t.value.facecard.profile.gpa },
]

const workColumns: Column[] = [
    { key: 'from_date', label: t.value.facecard.profile.from },
    { key: 'to_date', label: t.value.facecard.profile.to },
    { key: 'company', label: t.value.facecard.profile.company },
    { key: 'position', label: t.value.facecard.profile.position },
]

const trainingColumns: Column[] = [
    { key: 'issue_date', label: t.value.facecard.profile.issued },
    { key: 'completion_date', label: t.value.facecard.profile.completed },
    { key: 'name', label: t.value.facecard.profile.certification },
    { key: 'organizer', label: t.value.facecard.profile.organizer },
]

const initials = emp.fullname
    ? emp.fullname.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()
    : '?'
</script>

<template>
    <Head :title="emp.fullname" />

    <AppLayout>
        <PageHeader :title="t.facecard.profile.title">
            <template #actions>
                <Link
                    href="/facecard"
                    class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-arrow-left text-xs" />
                    {{ t.facecard.profile.back }}
                </Link>
            </template>
        </PageHeader>

        <!-- Header card -->
        <div class="rounded-xl border border-border bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                <div
                    class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-primary/10 text-2xl font-bold text-primary"
                >
                    {{ initials }}
                </div>

                <div class="min-w-0">
                    <h2 class="text-xl font-bold text-slate-800">{{ emp.fullname }}</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        {{ emp.designation_name ?? '—' }}
                        <span v-if="emp.designation_code" class="text-slate-400">
                            ({{ emp.designation_code }})
                        </span>
                    </p>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ emp.group_company ?? '—' }} · {{ emp.job_level ?? '—' }}
                    </p>
                </div>
            </div>

            <!-- Details grid -->
            <dl class="mt-6 grid grid-cols-1 gap-x-8 gap-y-4 border-t border-border pt-6 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="item in details" :key="item.label">
                    <dt class="text-xs uppercase tracking-wider text-slate-400">{{ item.label }}</dt>
                    <dd class="mt-0.5 text-sm font-medium text-slate-700">{{ item.value || '—' }}</dd>
                </div>
            </dl>
        </div>

        <!-- Succession summary -->
        <div class="mt-8">
            <ResultSummarySection
                :employee-id="emp.employee_id"
                :result-summary="resultSummary"
                :can-input="canInputSuccession"
            />
        </div>

        <!-- Competency assessment -->
        <div class="mt-8">
            <CompetencySection
                :employee-id="emp.employee_id"
                :assessments="competencyAssessments"
                :matrix-configs="matrixGradeConfigs"
                :can-input="canInputCompetency"
            />
        </div>

        <!-- Nine-box (year-on-year) -->
        <div class="mt-8">
            <NineBoxSection
                :employee-id="emp.employee_id"
                :appraisals="appraisals"
                :can-input="canInputNineBox"
            />
        </div>

        <!-- Formal education -->
        <section class="mt-8">
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.facecard.profile.education }}
            </h3>
            <DataTable :columns="educationColumns" :rows="formalEducations" row-key="institution">
                <template #cell-from_date="{ value }">{{ fmtDate(value) }}</template>
                <template #cell-to_date="{ value }">{{ fmtDate(value) }}</template>
                <template #empty>{{ t.facecard.profile.noEducation }}</template>
            </DataTable>
        </section>

        <!-- Work experience -->
        <section class="mt-8">
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.facecard.profile.workExperience }}
            </h3>
            <DataTable :columns="workColumns" :rows="workExperiences" row-key="company">
                <template #cell-from_date="{ value }">{{ fmtDate(value) }}</template>
                <template #cell-to_date="{ value }">{{ fmtDate(value) }}</template>
                <template #empty>{{ t.facecard.profile.noWorkExperience }}</template>
            </DataTable>
        </section>

        <!-- Training & certification -->
        <section class="mt-8">
            <h3 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.facecard.profile.training }}
            </h3>
            <DataTable :columns="trainingColumns" :rows="trainings" row-key="name">
                <template #cell-issue_date="{ value }">{{ fmtDate(value) }}</template>
                <template #cell-completion_date="{ value }">{{ fmtDate(value) }}</template>
                <template #empty>{{ t.facecard.profile.noTraining }}</template>
            </DataTable>
        </section>
    </AppLayout>
</template>
