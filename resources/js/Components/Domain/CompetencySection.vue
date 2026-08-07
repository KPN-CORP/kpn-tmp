<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

function priorityLabel(value: string | null | undefined): string {
    if (!value) return t.value.facecard.profile.na
    return (t.value.competency.priorityLabels as Record<string, string>)[value] ?? value
}

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

// key drives both `${key}_score` (assessment) and `${key}_min` (matrix config);
// the human label is locale-driven.
const COMP_KEYS = [
    'synergized_team',
    'integrity',
    'growth',
    'adaptive',
    'passion',
    'manage_planning',
    'decision_making',
    'relationship_building',
    'developing_others',
] as const

const competencies = computed(() =>
    COMP_KEYS.map((key) => ({ key, label: t.value.competency.names[key] })),
)

const latest = computed<Assessment | null>(() => props.assessments[0] ?? null)

const selectedDate = ref<string>(
    latest.value?.assessment_date?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
)
const selectedGrade = ref<string>('')

const selectedYear = computed(() =>
    selectedDate.value ? new Date(selectedDate.value).getFullYear() : null,
)

const assessmentForYear = computed<Assessment | null>(
    () => props.assessments.find((a) => Number(a.period) === selectedYear.value) ?? null,
)

const gradeOptionsForYear = computed(() =>
    props.matrixConfigs
        .filter((c) => Number(c.period) === selectedYear.value)
        .map((c) => ({ value: c.grade_level, label: c.grade_level })),
)

const gradeSelectOptions = computed(() => [
    {
        value: '',
        label: gradeOptionsForYear.value.length ? t.value.competency.selectGrade : t.value.competency.noGradesForYear,
    },
    ...gradeOptionsForYear.value,
])

const targetConfig = computed<MatrixConfig | null>(
    () =>
        props.matrixConfigs.find(
            (c) => Number(c.period) === selectedYear.value && c.grade_level === selectedGrade.value,
        ) ?? null,
)

// Re-sync grade + selection whenever the assessment date (year) changes.
watch(
    selectedYear,
    () => {
        selectedGrade.value = assessmentForYear.value?.matrix_grade ?? ''
    },
    { immediate: true },
)

const actualScores = computed<number[]>(() =>
    assessmentForYear.value
        ? COMP_KEYS.map((key) => Number(assessmentForYear.value![`${key}_score`] ?? 0))
        : [],
)

const targetScores = computed<number[]>(() =>
    targetConfig.value
        ? COMP_KEYS.map((key) => Number(targetConfig.value![`${key}_min`] ?? 0))
        : [],
)

interface FitResult {
    strengths: string[]
    areas: string[]
    fitCount: number
    overallStatus: string
}

const fit = computed<FitResult>(() => {
    const strengths: string[] = []
    const areas: string[] = []
    const actual = actualScores.value
    const target = targetScores.value

    if (actual.length && actual.some((s) => s >= 1)) {
        competencies.value.forEach((c, i) => {
            if ((actual[i] ?? 0) >= (target[i] ?? 0) && (actual[i] ?? 0) >= 1) strengths.push(c.label)
            else areas.push(c.label)
        })
    }

    const fitCount = strengths.length
    let overallStatus = t.value.facecard.profile.na
    if (fitCount >= 1) {
        if (fitCount >= 7) overallStatus = t.value.competency.recommended
        else if (fitCount >= 5) overallStatus = t.value.competency.needDevelopment
        else overallStatus = t.value.competency.notRecommended
    }

    return { strengths, areas, fitCount, overallStatus }
})

// Assessment recency badge.
const dateBadge = computed(() => {
    if (!assessmentForYear.value) {
        return { text: t.value.competency.noAssessmentForYear, tone: 'bg-red-100 text-red-700' }
    }
    const d = new Date(assessmentForYear.value.assessment_date ?? '')
    const label = `${d.toLocaleString(locale.value === 'id' ? 'id-ID' : 'en-US', { month: 'long' })} ${d.getFullYear()}`
    const expired = new Date().getFullYear() - d.getFullYear() >= 2
    return expired
        ? { text: `(${t.value.competency.expiredFrom} ${label})`, tone: 'bg-red-100 text-red-700' }
        : { text: `(${t.value.competency.upToDateFrom} ${label})`, tone: 'bg-emerald-100 text-emerald-700' }
})

// --- Radar geometry (9 axes, scale 0..4) ---
const RADAR = { cx: 130, cy: 130, r: 100, axes: COMP_KEYS.length, max: 4 }

function radarPoint(index: number, value: number) {
    const angle = -Math.PI / 2 + (index * 2 * Math.PI) / RADAR.axes
    const radius = (Math.min(value, RADAR.max) / RADAR.max) * RADAR.r
    return {
        x: RADAR.cx + radius * Math.cos(angle),
        y: RADAR.cy + radius * Math.sin(angle),
    }
}

function polygon(values: number[]): string {
    return values.map((v, i) => { const p = radarPoint(i, v); return `${p.x},${p.y}` }).join(' ')
}

const rings = [1, 2, 3, 4]
const axisEndpoints = computed(() => COMP_KEYS.map((_, i) => radarPoint(i, RADAR.max)))
const axisLabels = computed(() =>
    competencies.value.map((c, i) => {
        const p = radarPoint(i, RADAR.max + 0.7)
        const anchor = p.x < RADAR.cx - 5 ? 'end' : p.x > RADAR.cx + 5 ? 'start' : 'middle'
        return { label: c.label, x: p.x, y: p.y, anchor }
    }),
)
const hasRadar = computed(() => targetScores.value.length > 0 || actualScores.value.length > 0)

const fitLevels = [
    { n: 9, group: t.value.competency.recommended, rowspan: 3 },
    { n: 8 },
    { n: 7 },
    { n: 6, group: t.value.competency.needDevelopment, rowspan: 2 },
    { n: 5 },
    { n: 4, group: t.value.competency.notRecommended, rowspan: 4 },
    { n: 3 },
    { n: 2 },
    { n: 1 },
] as { n: number; group?: string; rowspan?: number }[]

// --- Input drawer ---
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

const formGrade = computed<string | null>(() => {
    const period = new Date(form.assessment_date).getFullYear()
    let best: string | null = null
    for (const cfg of props.matrixConfigs) {
        if (Number(cfg.period) !== period) continue
        const ok = COMP_KEYS.every((key) => Number(form[`${key}_score`] ?? 0) >= Number(cfg[`${key}_min`] ?? 0))
        if (ok && (best === null || cfg.grade_level > best)) best = cfg.grade_level
    }
    return best
})

function openInput() {
    form.clearErrors()
    form.employee_id = props.employeeId
    const existing = assessmentForYear.value
    if (existing) {
        form.assessment_date = existing.assessment_date?.slice(0, 10) ?? selectedDate.value
        form.proposed_grade = existing.proposed_grade ?? ''
        form.priority_for_development = existing.priority_for_development ?? 'No'
        for (const key of COMP_KEYS) form[`${key}_score`] = existing[`${key}_score`] ?? 0
    } else {
        form.assessment_date = selectedDate.value
        form.proposed_grade = ''
        form.priority_for_development = 'No'
        for (const key of COMP_KEYS) form[`${key}_score`] = 0
    }
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
    <section class="rounded-xl border border-border bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 rounded-t-xl border-b border-border px-5 py-3.5">
            <div class="flex items-center gap-2.5">
                <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                <h3 class="font-semibold text-slate-800">{{ t.competency.title }}</h3>
            </div>
            <button
                v-if="canInput"
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                @click="openInput"
            >
                <i class="fa-solid fa-plus" />
                {{ t.competency.add }}
            </button>
        </div>

        <div class="px-5 py-4">
        <!-- Selectors -->
        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            <div>
                <label class="mb-1 block text-xs text-slate-500">
                    {{ t.competency.assessmentDate }}
                    <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-medium" :class="dateBadge.tone">{{ dateBadge.text }}</span>
                </label>
                <DateInput v-model="selectedDate" />
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">{{ t.competency.proposedGrade }}</label>
                <div class="rounded-md border border-border bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    {{ assessmentForYear?.proposed_grade || t.facecard.profile.na }}
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">{{ t.competency.priority }}</label>
                <div class="rounded-md border border-border bg-slate-50 px-3 py-2 text-sm text-slate-600">
                    {{ priorityLabel(assessmentForYear?.priority_for_development) }}
                </div>
            </div>
            <div>
                <label class="mb-1 block text-xs text-slate-500">
                    {{ t.competency.matrixGrade }}
                    <span v-if="assessmentForYear?.matrix_grade" class="text-slate-400">({{ t.competency.latest }}: {{ assessmentForYear.matrix_grade }})</span>
                </label>
                <SearchableSelect v-model="selectedGrade" :options="gradeSelectOptions" :placeholder="t.competency.selectGrade" />
            </div>
        </div>
        <hr class="mb-4 border-border">

        <!-- Radar + fit table + lists -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Radar -->
            <div class="flex flex-col items-center">
                <svg viewBox="0 0 260 260" class="w-full max-w-[320px]">
                    <g v-if="hasRadar">
                        <!-- rings -->
                        <polygon
                            v-for="ring in rings"
                            :key="`ring-${ring}`"
                            :points="polygon(competencies.map(() => ring))"
                            fill="none"
                            stroke="#e2e8f0"
                            stroke-width="1"
                        />
                        <!-- axes -->
                        <line
                            v-for="(p, i) in axisEndpoints"
                            :key="`axis-${i}`"
                            :x1="RADAR.cx" :y1="RADAR.cy" :x2="p.x" :y2="p.y"
                            stroke="#e2e8f0" stroke-width="1"
                        />
                        <!-- target -->
                        <polygon
                            v-if="targetScores.length"
                            :points="polygon(targetScores)"
                            fill="rgba(239,68,68,0.15)" stroke="rgb(239,68,68)" stroke-width="1.5"
                        />
                        <!-- actual -->
                        <polygon
                            v-if="actualScores.length"
                            :points="polygon(actualScores)"
                            fill="rgba(59,130,246,0.15)" stroke="rgb(59,130,246)" stroke-width="1.5"
                        />
                        <!-- labels -->
                        <text
                            v-for="(l, i) in axisLabels"
                            :key="`lbl-${i}`"
                            :x="l.x" :y="l.y" :text-anchor="l.anchor"
                            class="fill-slate-400"
                            style="font-size: 6.5px"
                            dominant-baseline="middle"
                        >{{ l.label }}</text>
                    </g>
                    <text v-else x="130" y="130" text-anchor="middle" class="fill-slate-400" style="font-size: 9px">
                        {{ t.competency.selectGradeToCompare }}
                    </text>
                </svg>
                <div v-if="hasRadar" class="mt-2 flex gap-4 text-xs">
                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-red-500" /> {{ t.competency.target }}</span>
                    <span class="flex items-center gap-1"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-blue-500" /> {{ t.competency.actual }}</span>
                </div>
                <div class="mt-3 flex text-center text-xs">
                    <div class="border border-border bg-slate-50 px-3 py-2 text-slate-600">{{ fit.overallStatus }}</div>
                    <div class="border border-l-0 border-border bg-slate-50 px-3 py-2 text-slate-600">{{ fit.fitCount }} {{ t.competency.competencyFit }}</div>
                </div>
            </div>

            <!-- Fit-level table -->
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-center text-xs">
                    <tbody>
                        <tr v-for="lvl in fitLevels" :key="lvl.n">
                            <td
                                v-if="lvl.group"
                                :rowspan="lvl.rowspan"
                                class="w-2/5 border border-border bg-slate-50 px-2 py-1.5 align-middle font-medium text-slate-600"
                            >
                                {{ lvl.group }}
                            </td>
                            <td
                                class="border border-border px-2 py-1.5"
                                :class="fit.fitCount === lvl.n ? 'bg-emerald-100 font-semibold text-emerald-800' : ''"
                            >
                                {{ lvl.n }} {{ t.competency.competencyFit }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Strength / Area lists -->
            <div class="grid grid-cols-2 gap-0">
                <table class="w-full border-collapse text-xs">
                    <thead class="bg-slate-50"><tr><th class="border border-border px-2 py-1.5 text-center text-slate-500">{{ t.competency.strength }}</th></tr></thead>
                    <tbody>
                        <tr v-for="i in 8" :key="`s-${i}`">
                            <td class="h-7 border border-border px-2 text-center text-slate-600">{{ fit.strengths[i - 1] ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
                <table class="w-full border-collapse text-xs">
                    <thead class="bg-slate-50"><tr><th class="border border-l-0 border-border px-2 py-1.5 text-center text-slate-500">{{ t.competency.areaOfDevelopment }}</th></tr></thead>
                    <tbody>
                        <tr v-for="i in 8" :key="`a-${i}`">
                            <td class="h-7 border border-l-0 border-border px-2 text-center text-slate-600">{{ fit.areas[i - 1] ?? '' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        </div>

        <!-- Input drawer -->
        <Drawer :show="modalOpen" :title="t.competency.formTitle" max-width="max-w-2xl" @close="modalOpen = false">
            <form id="competency-form" class="space-y-6" @submit.prevent="submit">
                <!-- Section: Assessment -->
                <section class="space-y-4">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-regular fa-calendar-check text-slate-300" />
                        {{ t.competency.sectionAssessment }}
                    </h4>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.competency.assessmentDate }}</label>
                            <DateInput v-model="form.assessment_date" :invalid="!!form.errors.assessment_date" />
                            <p v-if="form.errors.assessment_date" class="mt-1 text-xs text-red-600">{{ form.errors.assessment_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.competency.priority }}</label>
                            <SearchableSelect
                                v-model="form.priority_for_development"
                                :options="[{ value: 'No', label: t.competency.priorityLabels.No }, { value: 'Yes', label: t.competency.priorityLabels.Yes }]"
                            />
                        </div>
                    </div>

                    <!-- Live matrix target grade -->
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3">
                        <div class="min-w-0">
                            <div class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ t.competency.matrixTarget }}</div>
                            <div class="text-xs text-slate-400">{{ t.competency.matrixAuto }}</div>
                        </div>
                        <div class="text-2xl font-bold leading-none text-primary">{{ formGrade ?? '—' }}</div>
                    </div>
                </section>

                <!-- Section: Competency scores -->
                <section class="space-y-3 border-t border-border pt-5">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-solid fa-sliders text-slate-300" />
                        {{ t.competency.sectionScores }}
                        <span class="ml-auto font-normal normal-case tracking-normal text-slate-400">{{ t.competency.scaleHint }}</span>
                    </h4>

                    <div class="divide-y divide-border/60 overflow-hidden rounded-lg border border-border">
                        <div
                            v-for="c in competencies"
                            :key="c.key"
                            class="flex items-center justify-between gap-3 px-3.5 py-2.5 transition hover:bg-slate-50/60"
                        >
                            <span class="text-sm text-slate-700">{{ c.label }}</span>
                            <div class="inline-flex shrink-0 overflow-hidden rounded-lg border border-border">
                                <button
                                    v-for="n in [0, 1, 2, 3, 4]"
                                    :key="n"
                                    type="button"
                                    class="h-8 w-9 border-l border-border text-sm font-semibold transition first:border-l-0"
                                    :class="form[`${c.key}_score`] === n
                                        ? 'bg-primary text-white'
                                        : 'bg-white text-slate-500 hover:bg-slate-100'"
                                    @click="form[`${c.key}_score`] = n"
                                >
                                    {{ n }}
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Section: Result -->
                <section class="space-y-4 border-t border-border pt-5">
                    <h4 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-slate-400">
                        <i class="fa-solid fa-award text-slate-300" />
                        {{ t.competency.sectionResult }}
                    </h4>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.competency.proposedGrade }}</label>
                        <input
                            v-model="form.proposed_grade"
                            class="w-full rounded-lg border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>
                </section>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="modalOpen = false">
                    {{ t.competency.cancel }}
                </button>
                <button type="submit" form="competency-form" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.competency.save }}
                </button>
            </template>
        </Drawer>
    </section>
</template>
