<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import NineBoxSection from '@/Components/Domain/NineBoxSection.vue'
import CompetencySection from '@/Components/Domain/CompetencySection.vue'
import InternalMovementSection from '@/Components/Domain/InternalMovementSection.vue'
import IdpPanel from '@/Components/Domain/IdpPanel.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import { formatDate as fmtDate, formatDateTime } from '@/Composables/useDate'

const { t } = useLocale()

interface Employee {
    employee_id: string
    fullname: string
    email: string | null
    personal_email: string | null
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
    date_of_birth: string | null
    marital_status: string | null
    nationality: string | null
    homebase: string | null
    language_ability: string[] | null
}

interface Appraisal {
    id: number
    appraisal_year: number
    grade: string | null
    potential: string | null
    talent_box: string | null
}

interface ResultSummary {
    critical_position: string | null
    successor_type: string | null
    successor_to_position: string | null
}

interface Movement {
    effective_from: string | null
    effective_to: string | null
    type: string | null
    detail: string | null
    from: string | null
    to: string | null
    status: string | null
}

interface MasterOption {
    value: string
    value_en: string | null
    value_id: string | null
}

const props = defineProps<{
    employee: { data: Employee }
    photoUrl: string | null
    formalEducations: Array<Record<string, any>>
    workExperiences: Array<Record<string, any>>
    trainings: Array<Record<string, any>>
    appraisals: Appraisal[]
    competencyAssessments: Array<Record<string, any>>
    resultSummary: ResultSummary | null
    successorLabel: string | null
    movements: Movement[]
    movementAttributes: string[]
    matrixGradeConfigs: Array<Record<string, any>>
    canInputNineBox: boolean
    canInputCompetency: boolean
    canInputSuccession: boolean
    // Data Access flags for this employee (self/team, IC vs PM)
    canDownloadFacecard: boolean
    canViewIdp: boolean
    canDownloadIdp: boolean
    // IDP tab (rendered inline via IdpPanel)
    developmentModels: any[]
    options: {
        competencyNames: MasterOption[]
        developmentPrograms: MasterOption[]
        reviewTools: MasterOption[]
    }
    competencyMap: Record<string, Array<MasterOption & { model_id: number | null }>>
}>()

const emp = props.employee.data

// Face Card / IDP tab switch (rendered on the same page, like the legacy).
const tab = ref<'facecard' | 'idp'>('facecard')

// Download PDF follows the active tab (facecard vs IDP).
const pdfHref = computed(() =>
    tab.value === 'idp' ? `/idp/${emp.employee_id}/pdf` : `/employee/${emp.employee_id}/pdf`,
)
// Whether the Download PDF button is allowed for the currently active tab.
const canDownloadCurrent = computed(() =>
    tab.value === 'idp' ? props.canDownloadIdp : props.canDownloadFacecard,
)
const na = computed(() => t.value.facecard.profile.na)

// --- Detail table columns (sortable + paginated via ClientTable) ---
const educationColumns = computed<Column[]>(() => [
    { key: 'from_date', label: t.value.facecard.profile.from, sortable: true },
    { key: 'to_date', label: t.value.facecard.profile.to, sortable: true },
    { key: 'degree', label: t.value.facecard.profile.degree, sortable: true },
    { key: 'institution', label: t.value.facecard.profile.institution, sortable: true },
    { key: 'major', label: t.value.facecard.profile.major, sortable: true },
    { key: 'gpa_percentage', label: t.value.facecard.profile.gpa, sortable: true },
])

const trainingColumns = computed<Column[]>(() => [
    { key: 'issue_date', label: t.value.facecard.profile.from, sortable: true },
    { key: 'completion_date', label: t.value.facecard.profile.to, sortable: true },
    { key: 'name', label: t.value.facecard.profile.trainingName, sortable: true },
    { key: 'organizer', label: t.value.facecard.profile.organizer, sortable: true },
])

const workColumns = computed<Column[]>(() => [
    { key: 'from_date', label: t.value.facecard.profile.from, sortable: true },
    { key: 'to_date', label: t.value.facecard.profile.to, sortable: true },
    { key: 'company', label: t.value.facecard.profile.company, sortable: true },
    { key: 'deptDivision', label: t.value.facecard.profile.deptDivision },
    { key: 'position', label: t.value.facecard.profile.position, sortable: true },
])

const initials = emp.fullname
    ? emp.fullname.split(' ').slice(0, 2).map((w) => w[0]).join('').toUpperCase()
    : '?'

const publishDate = computed(() => formatDateTime(new Date().toISOString()))

const age = computed(() => {
    if (!emp.date_of_birth) return null
    const dob = new Date(emp.date_of_birth)
    const diff = Date.now() - dob.getTime()
    return Math.abs(new Date(diff).getUTCFullYear() - 1970)
})

const languages = computed(() =>
    Array.isArray(emp.language_ability) && emp.language_ability.length
        ? emp.language_ability.join(', ')
        : null,
)

const latestPa = computed(() => {
    const withGrade = props.appraisals.filter((a) => a.grade)
    return withGrade.length ? withGrade[0] : null
})

// --- Individual summary rows ---
const individual = computed(() => [
    { label: t.value.facecard.profile.fullName, value: emp.fullname },
    { label: t.value.facecard.profile.dateOfBirth, value: fmtDate(emp.date_of_birth) },
    { label: t.value.facecard.profile.age, value: age.value ? `${age.value} ${t.value.facecard.profile.yearsOld}` : null },
    { label: t.value.facecard.profile.maritalStatus, value: emp.marital_status },
    { label: t.value.facecard.profile.gender, value: emp.gender },
    { label: t.value.facecard.profile.nationality, value: emp.nationality },
    { label: t.value.facecard.profile.homebase, value: emp.homebase },
    { label: t.value.facecard.profile.languageAbility, value: languages.value },
])

const employmentLeft = computed(() => [
    { label: t.value.facecard.profile.employeeId, value: emp.employee_id },
    { label: t.value.facecard.profile.businessUnit, value: emp.group_company },
    { label: t.value.facecard.profile.position, value: emp.designation_name },
    { label: t.value.facecard.profile.department, value: emp.unit },
    { label: t.value.facecard.profile.company, value: emp.company_name },
])

const employmentRight = computed(() => [
    { label: t.value.facecard.profile.workLocation, value: emp.office_area },
    { label: t.value.facecard.profile.joinDateKpn, value: fmtDate(emp.date_of_joining) },
    { label: t.value.facecard.profile.currentGrade, value: emp.job_level },
])

// --- Succession drawer ---
const successionOpen = ref(false)
const successionForm = useForm({
    employee_id: emp.employee_id,
    critical_position: props.resultSummary?.critical_position ?? '',
    successor_type: props.resultSummary?.successor_type ?? '',
    successor_to_position: props.resultSummary?.successor_to_position ?? '',
})

function openSuccession() {
    successionForm.clearErrors()
    successionOpen.value = true
}

function submitSuccession() {
    successionForm.post('/result-summary', {
        preserveScroll: true,
        onSuccess: () => (successionOpen.value = false),
    })
}

function gpa(value: any): string {
    const n = Number(value)
    return Number.isFinite(n) ? n.toFixed(2) : na.value
}

// --- Photo upload ---
const fileInput = ref<HTMLInputElement | null>(null)
const photoForm = useForm<{ photo: File | null }>({ photo: null })

function pickPhoto() {
    fileInput.value?.click()
}

function onPhotoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0]
    if (!file) return
    photoForm.photo = file
    photoForm.post(`/employee/${emp.employee_id}/photo`, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            photoForm.reset()
            if (fileInput.value) fileInput.value.value = ''
        },
    })
}

function deletePhoto() {
    router.delete(`/employee/${emp.employee_id}/photo`, { preserveScroll: true })
}
</script>

<template>
    <Head :title="emp.fullname" />

    <AppLayout>
        <!-- Back -->
        <div class="mb-4">
            <Link href="/facecard" class="text-sm font-medium text-primary hover:underline">
                &laquo; {{ t.facecard.profile.back }}
            </Link>
        </div>

        <!-- Tabs + contextual Download PDF -->
        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex gap-2">
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    :class="tab === 'facecard' ? 'bg-primary text-white' : 'border border-border bg-white text-slate-600 hover:bg-slate-50'"
                    @click="tab = 'facecard'"
                >
                    {{ t.facecard.profile.faceCardTab }}
                </button>
                <button
                    v-if="canViewIdp"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-semibold transition"
                    :class="tab === 'idp' ? 'bg-primary text-white' : 'border border-border bg-white text-slate-600 hover:bg-slate-50'"
                    @click="tab = 'idp'"
                >
                    {{ t.facecard.profile.idpTab }}
                </button>
            </div>

            <a
                v-if="canDownloadCurrent"
                :href="pdfHref"
                class="inline-flex items-center justify-center gap-2 rounded-lg border border-emerald-500 px-4 py-2 text-sm font-semibold text-emerald-600 transition hover:bg-emerald-500 hover:text-white"
            >
                <i class="fa-solid fa-file-pdf text-xs" />
                {{ t.facecard.profile.downloadPdf }}
            </a>
        </div>

        <!-- ===== Face Card tab ===== -->
        <div v-show="tab === 'facecard'">
        <!-- Publish date -->
        <div class="mb-4 rounded-xl border border-border bg-white p-4 text-sm shadow-sm">
            <span class="text-slate-500">{{ t.facecard.profile.publishDate }} :</span>
            <span class="ml-1 font-semibold text-slate-700">{{ publishDate }}</span>
        </div>

        <!-- Summary row -->
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-12">
            <!-- Individual Summary -->
            <div class="overflow-hidden rounded-xl border border-border bg-white shadow-sm lg:col-span-4">
                <div class="flex items-center gap-2.5 border-b border-border px-5 py-3.5">
                    <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                    <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.individualSummary }}</h3>
                </div>
                <div class="px-5 py-4">
                    <table class="w-full text-sm">
                        <tbody>
                            <tr v-for="row in individual" :key="row.label" class="align-top">
                                <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ row.label }}</td>
                                <td class="py-1 pr-1 text-slate-300">:</td>
                                <td class="py-1 text-slate-700">{{ row.value || na }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Employment Summary -->
            <div class="overflow-hidden rounded-xl border border-border bg-white shadow-sm lg:col-span-6">
                <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3.5">
                    <div class="flex items-center gap-2.5">
                        <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                        <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.employmentSummary }}</h3>
                    </div>
                    <button
                        v-if="canInputSuccession"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                        @click="openSuccession"
                    >
                        <i class="fa-solid fa-plus" />
                        {{ t.facecard.profile.input }}
                    </button>
                </div>

                <div class="px-5 py-4">
                    <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="row in employmentLeft" :key="row.label" class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ row.label }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">{{ row.value || na }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="row in employmentRight" :key="row.label" class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ row.label }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">{{ row.value || na }}</td>
                                </tr>
                                <tr class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ t.facecard.profile.latestPa }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">
                                        <template v-if="latestPa">{{ latestPa.appraisal_year }} (<strong>{{ latestPa.grade }}</strong>)</template>
                                        <template v-else>{{ na }}</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="my-3 border-t border-border" />

                    <div class="grid grid-cols-1 gap-x-6 sm:grid-cols-2">
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ t.facecard.profile.criticalPosition }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">{{ resultSummary?.critical_position || na }}</td>
                                </tr>
                                <tr class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ t.facecard.profile.successorType }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">{{ resultSummary?.successor_type || na }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr class="align-top">
                                    <td class="py-1 pr-2 font-medium text-slate-500 whitespace-nowrap">{{ t.facecard.profile.successorTo }}</td>
                                    <td class="py-1 pr-1 text-slate-300">:</td>
                                    <td class="py-1 text-slate-700">{{ successorLabel || na }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Photo -->
            <div class="overflow-hidden rounded-xl border border-border bg-white shadow-sm lg:col-span-2">
                <div class="flex items-center gap-2.5 border-b border-border px-5 py-3.5">
                    <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                    <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.photo }}</h3>
                </div>
                <div class="flex flex-col items-center justify-center gap-2 px-5 py-4">
                    <div
                        class="flex items-center justify-center overflow-hidden rounded-lg border border-border bg-slate-50 text-2xl font-bold text-primary/60"
                        style="width: 100px; height: 133px;"
                    >
                        <img
                            v-if="photoUrl"
                            :src="photoUrl"
                            :alt="emp.fullname"
                            class="h-full w-full object-cover"
                        >
                        <template v-else>{{ initials }}</template>
                    </div>
                    <span v-if="!photoUrl" class="text-xs text-slate-400">{{ t.facecard.profile.noPhoto }}</span>

                    <input
                        ref="fileInput"
                        type="file"
                        accept="image/jpeg,image/png"
                        class="hidden"
                        @change="onPhotoChange"
                    >

                    <div class="flex flex-wrap justify-center gap-1.5">
                        <button
                            type="button"
                            :disabled="photoForm.processing"
                            class="inline-flex items-center gap-1.5 rounded-md bg-primary px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-primary-hover disabled:opacity-60"
                            @click="pickPhoto"
                        >
                            <i v-if="photoForm.processing" class="fa-solid fa-spinner fa-spin" />
                            <i v-else class="fa-solid fa-upload" />
                            {{ photoUrl ? t.facecard.profile.photoChange : t.facecard.profile.photoAdd }}
                        </button>
                        <button
                            v-if="photoUrl"
                            type="button"
                            :disabled="photoForm.processing"
                            class="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-slate-600 transition hover:bg-red-50 hover:text-red-600 disabled:opacity-60"
                            @click="deletePhoto"
                        >
                            <i class="fa-solid fa-trash" />
                            {{ t.facecard.profile.photoDelete }}
                        </button>
                    </div>

                    <p v-if="photoForm.errors.photo" class="text-center text-xs text-red-600">{{ photoForm.errors.photo }}</p>
                    <p v-else class="text-center text-[11px] text-slate-400">{{ t.facecard.profile.photoHint }}</p>
                </div>
            </div>
        </div>

        <!-- Education + Training -->
        <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <!-- Formal Education -->
            <div class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex items-center gap-2.5 border-b border-border px-5 py-3.5">
                    <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                    <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.education }}</h3>
                </div>
                <ClientTable
                    :columns="educationColumns"
                    :rows="formalEducations"
                    :initial-sort="{ key: 'from_date', dir: 'desc' }"
                    :empty-text="t.facecard.profile.noEducation"
                >
                    <template #cell-from_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                    <template #cell-to_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                    <template #cell-degree="{ value }">{{ value || na }}</template>
                    <template #cell-institution="{ value }">{{ value || na }}</template>
                    <template #cell-major="{ value }">{{ value || na }}</template>
                    <template #cell-gpa_percentage="{ value }">{{ gpa(value) }}</template>
                </ClientTable>
            </div>

            <!-- Training / Certification -->
            <div class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex items-center gap-2.5 border-b border-border px-5 py-3.5">
                    <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                    <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.training }}</h3>
                </div>
                <ClientTable
                    :columns="trainingColumns"
                    :rows="trainings"
                    :initial-sort="{ key: 'completion_date', dir: 'desc' }"
                    :empty-text="t.facecard.profile.noTraining"
                >
                    <template #cell-issue_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                    <template #cell-completion_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                    <template #cell-name="{ value }">{{ value || na }}</template>
                    <template #cell-organizer="{ value }">{{ value || na }}</template>
                </ClientTable>
            </div>
        </div>

        <!-- Work Experience -->
        <div class="mb-6 overflow-hidden rounded-xl border border-border bg-white shadow-sm">
            <div class="flex items-center gap-2.5 border-b border-border px-5 py-3.5">
                <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                <h3 class="font-semibold text-slate-800">{{ t.facecard.profile.workExperience }}</h3>
            </div>
            <ClientTable
                :columns="workColumns"
                :rows="workExperiences"
                :initial-sort="{ key: 'from_date', dir: 'desc' }"
                :empty-text="t.facecard.profile.noWorkExperience"
            >
                <template #cell-from_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                <template #cell-to_date="{ value }"><span class="whitespace-nowrap text-slate-500">{{ fmtDate(value) }}</span></template>
                <template #cell-company="{ value }">{{ value || na }}</template>
                <template #cell-deptDivision>{{ na }}</template>
                <template #cell-position="{ value }">{{ value || na }}</template>
            </ClientTable>
        </div>

        <!-- Internal Movement -->
        <div class="mb-6">
            <InternalMovementSection :movements="movements" :attributes="movementAttributes" />
        </div>

        <!-- Year-on-Year 9-Box -->
        <div class="mb-6">
            <NineBoxSection
                :employee-id="emp.employee_id"
                :appraisals="appraisals"
                :can-input="canInputNineBox"
            />
        </div>

        <!-- Competency Assessment -->
        <div class="mb-6">
            <CompetencySection
                :employee-id="emp.employee_id"
                :assessments="competencyAssessments"
                :matrix-configs="matrixGradeConfigs"
                :can-input="canInputCompetency"
            />
        </div>
        </div>
        <!-- ===== End Face Card tab ===== -->

        <!-- ===== Individual Development Plan tab ===== -->
        <div v-show="tab === 'idp'">
            <IdpPanel
                :employee="{ employee_id: emp.employee_id, fullname: emp.fullname, designation_name: emp.designation_name }"
                :development-models="developmentModels"
                :options="options"
                :competency-map="competencyMap"
                :can-edit="false"
            />
        </div>

        <!-- Succession drawer -->
        <Drawer :show="successionOpen" :title="t.facecard.profile.employmentSummary" @close="successionOpen = false">
            <form id="succession-form" class="space-y-4" @submit.prevent="submitSuccession">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.facecard.profile.criticalPosition }}</label>
                    <input v-model="successionForm.critical_position" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.facecard.profile.successorType }}</label>
                    <input v-model="successionForm.successor_type" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.facecard.profile.successorTo }}</label>
                    <input v-model="successionForm.successor_to_position" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="successionOpen = false">
                    {{ t.result.cancel }}
                </button>
                <button type="submit" form="succession-form" :disabled="successionForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.result.save }}
                </button>
            </template>
        </Drawer>
    </AppLayout>
</template>
