<script setup lang="ts">
/**
 * The plan table's filter bar. Every value it filters on is already in the
 * page's props, so it works entirely in the browser — no reload, no server
 * round trip, and the stage tracker's totals stay unfiltered (they describe the
 * plan, not the current view of it).
 *
 * `planFilters.ts` owns the state shape and the predicate; this component owns
 * the dropdowns and the cascade between the first three of them.
 */
import { computed } from 'vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import { useLocale } from '@/Composables/useLocale'
import {
    blankPlanFilters,
    cascadeValue,
    countPlanFilters,
    hasPlanFilters,
    matchesPlanFilters,
    rangeInvalid,
    upstreamOf,
    type CascadeField,
    type PlanFilterState,
} from '@/Components/Domain/Idp/planFilters'
import type { Plan } from '@/types/idp'

const { t } = useLocale()

const props = defineProps<{
    modelValue: PlanFilterState
    /** Every plan in the cycle — what the options are built from. */
    plans: Plan[]
    /** The cycle's development models, in their own order (70-20-10, not A-Z). */
    models: { id: number; name: string }[]
    /** Canonical value → localized label, as the table already resolves them. */
    competencyLabels: Record<string, string>
    typeLabels: Record<string, string>
    reviewToolLabels: Record<string, string>
    /** How many rows survive. */
    shown: number
}>()

const emit = defineEmits<{ (e: 'update:modelValue', value: PlanFilterState): void }>()

const f = computed(() => t.value.idp.filters)

const active = computed(() => hasPlanFilters(props.modelValue))
const activeCount = computed(() => countPlanFilters(props.modelValue))

// Typing can still produce an inverted range — the bounds below only reach the
// native calendar — so it is said out loud rather than silently emptying the
// table. `matchesPlanFilters` leaves such a range out until it is fixed.
const invalidRange = computed(() => rangeInvalid(props.modelValue))

const showing = computed(() =>
    f.value.showing.replace('{shown}', String(props.shown)).replace('{total}', String(props.plans.length)),
)

// --- The cascade -----------------------------------------------------------

/**
 * What `field` may still offer, given only the filters standing to its left.
 * The dropdown and the drop-what-no-longer-fits check below both read this, so
 * an offered value is by construction one the table will keep.
 */
function offered(state: PlanFilterState, field: CascadeField): string[] {
    const upstream = upstreamOf(state, field)

    return distinct(props.plans.filter((plan) => matchesPlanFilters(plan, upstream)), cascadeValue[field])
}

/** Drops `field`'s pick when the narrowing above no longer offers it. */
function settle(state: PlanFilterState, field: CascadeField) {
    if (state[field] && !offered(state, field).includes(state[field])) {
        state[field] = ''
    }
}

function set<K extends keyof PlanFilterState>(key: K, value: PlanFilterState[K]) {
    const next = { ...props.modelValue, [key]: value }

    // Narrowing can strip a choice further down the row of any meaning: it would
    // match nothing, and the table would empty for a reason nothing on screen
    // explains — the select shows its "All …" placeholder for a value it no
    // longer lists. So such a pick is dropped. The type settles first, since the
    // competency is then judged against it.
    if (key === 'developmentModel') settle(next, 'competencyType')
    if (key === 'developmentModel' || key === 'competencyType') settle(next, 'competency')

    emit('update:modelValue', next)
}

function clearAll() {
    emit('update:modelValue', blankPlanFilters())
}

// --- Option lists ----------------------------------------------------------

function distinct(plans: Plan[], pick: (plan: Plan) => string | null): string[] {
    return [...new Set(plans.map(pick).filter((v): v is string => !!v))]
}

/** Those values as a dropdown: labelled, sorted, with an "all" entry in front. */
function optionsFrom(values: string[], labels: Record<string, string>, allLabel: string): Option[] {
    return [
        { value: '', label: allLabel },
        ...values
            .map((value) => ({ value, label: labels[value] ?? value }))
            .sort((a, b) => a.label.localeCompare(b.label)),
    ]
}

// Kept in the package's own order — a 70-20-10 split reads in that order, not
// alphabetically — and narrowed to the models this employee actually has plans
// under, like every other value-derived list here.
const modelOptions = computed<Option[]>(() => {
    const used = new Set(props.plans.map((p) => p.development_model_id))

    return [
        { value: '', label: f.value.allModels },
        ...props.models.filter((m) => used.has(m.id)).map((m) => ({ value: String(m.id), label: m.name })),
    ]
})

const typeOptions = computed(() =>
    optionsFrom(offered(props.modelValue, 'competencyType'), props.typeLabels, f.value.allTypes),
)

// Narrowed by the type above it — and by the model above that — but still a
// filter in its own right: with neither of them set it offers every competency,
// so it can be used on its own.
const competencyOptions = computed(() =>
    optionsFrom(offered(props.modelValue, 'competency'), props.competencyLabels, f.value.allCompetencies),
)

const reviewToolOptions = computed(() =>
    optionsFrom(distinct(props.plans, (p) => p.review_tools), props.reviewToolLabels, f.value.allReviewTools),
)

// The status lists offer every state rather than only the ones in use: "show me
// the overdue ones" is a fair question even when the answer is none.
const timelineOptions = computed<Option[]>(() => [
    { value: '', label: f.value.allTimelineStatuses },
    ...(['completed', 'inProgress', 'upcoming', 'overdue', 'planned'] as const).map((key) => ({
        value: key,
        label: t.value.idp.status[key],
    })),
])

const planningOptions = computed<Option[]>(() => [
    { value: '', label: f.value.allPlanningStatuses },
    { value: 'approved', label: t.value.idp.stage.planApproved },
    { value: 'inReview', label: t.value.idp.stage.inReview },
    { value: 'notApproved', label: t.value.idp.stage.notApprovedYet },
])

const resultOptions = computed<Option[]>(() => [
    { value: '', label: f.value.allResultStatuses },
    { value: 'locked', label: t.value.idp.result.locked },
    { value: 'notFiled', label: t.value.idp.result.notFiled },
    { value: 'ready', label: t.value.idp.result.readyToSubmit },
    { value: 'pending', label: f.value.awaitingApproval },
    { value: 'approved', label: t.value.approvalFlow.statusApproved },
    { value: 'rejected', label: t.value.approvalFlow.statusRejected },
])
</script>

<template>
    <div class="mb-6 rounded-xl border border-border bg-white p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                <i class="fa-solid fa-filter text-[10px]" />
                {{ f.title }}
                <span
                    v-if="activeCount"
                    class="rounded-full bg-primary/10 px-1.5 py-0.5 text-[10px] font-bold normal-case text-primary"
                >
                    {{ activeCount }}
                </span>
            </p>

            <div v-if="active" class="flex items-center gap-3">
                <p class="text-xs text-slate-500">{{ showing }}</p>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-md border border-border px-2.5 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-50"
                    @click="clearAll"
                >
                    <i class="fa-solid fa-xmark text-[10px]" />
                    {{ f.clear }}
                </button>
            </div>
        </div>

        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1.5fr)]"
        >
            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">
                    {{ t.idp.form.developmentModel }}
                </label>
                <SearchableSelect
                    :model-value="modelValue.developmentModel"
                    :options="modelOptions"
                    :placeholder="f.allModels"
                    @update:model-value="set('developmentModel', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">{{ t.idp.form.type }}</label>
                <SearchableSelect
                    :model-value="modelValue.competencyType"
                    :options="typeOptions"
                    :placeholder="f.allTypes"
                    @update:model-value="set('competencyType', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">
                    {{ t.idp.form.competencyName }}
                </label>
                <SearchableSelect
                    :model-value="modelValue.competency"
                    :options="competencyOptions"
                    :placeholder="f.allCompetencies"
                    @update:model-value="set('competency', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">
                    {{ t.idp.form.reviewTools }}
                </label>
                <SearchableSelect
                    :model-value="modelValue.reviewTool"
                    :options="reviewToolOptions"
                    :placeholder="f.allReviewTools"
                    @update:model-value="set('reviewTool', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">{{ f.timelineStatus }}</label>
                <SearchableSelect
                    :model-value="modelValue.timeline"
                    :options="timelineOptions"
                    :placeholder="f.allTimelineStatuses"
                    @update:model-value="set('timeline', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">{{ f.planningStatus }}</label>
                <SearchableSelect
                    :model-value="modelValue.planning"
                    :options="planningOptions"
                    :placeholder="f.allPlanningStatuses"
                    @update:model-value="set('planning', $event)"
                />
            </div>

            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500">{{ f.resultStatus }}</label>
                <SearchableSelect
                    :model-value="modelValue.result"
                    :options="resultOptions"
                    :placeholder="f.allResultStatuses"
                    @update:model-value="set('result', $event)"
                />
            </div>

            <!-- One filter with two ends: a plan is kept when its own dates
                 overlap the range, so a long program is found by any month it
                 runs in. -->
            <div>
                <label class="mb-1 block text-[11px] font-medium text-slate-500" :title="f.rangeHint">
                    {{ t.idp.table.timeframe }}
                    <i class="fa-solid fa-circle-info ml-0.5 text-[9px] text-slate-300" />
                </label>
                <div class="flex items-center gap-1.5">
                    <DateInput
                        class="min-w-0 flex-1"
                        :model-value="modelValue.from"
                        :max="modelValue.to"
                        :invalid="invalidRange"
                        @update:model-value="set('from', $event)"
                    />
                    <i class="fa-solid fa-arrow-right-long shrink-0 text-[10px] text-slate-300" />
                    <DateInput
                        class="min-w-0 flex-1"
                        :model-value="modelValue.to"
                        :min="modelValue.from"
                        :invalid="invalidRange"
                        @update:model-value="set('to', $event)"
                    />
                </div>
                <p v-if="invalidRange" class="mt-1 flex items-start gap-1 text-[11px] text-red-600">
                    <i class="fa-solid fa-circle-exclamation mt-0.5 text-[9px]" />
                    {{ f.rangeInvalid }}
                </p>
            </div>
        </div>
    </div>
</template>
