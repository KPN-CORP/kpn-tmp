<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import DateInput from '@/Components/UI/DateInput.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'

const { t } = useLocale()

/**
 * A package's weighted models are edited here, not on a screen of their own:
 * the percentages only mean anything as a set (they have to total 100%), so the
 * whole set is saved with the package in one submit.
 *
 * A row carries its own `id` when stored and none when just added, which is how
 * the server tells an update from an insert. The usage counts come along so a
 * row that cannot be removed says so before the save is attempted.
 */
interface ModelRow {
    id?: number
    name_en: string
    name_id: string | null
    percentage: number
    // When set, what this model develops is drawn from the Master Training
    // catalogue rather than written out by hand.
    uses_master_training: boolean
    description_en: string | null
    description_id: string | null
    development_programs_count: number
    individual_development_plans_count: number
}

interface Package {
    id: number
    name: string
    start_date: string | null
    end_date: string | null
    is_current: boolean
    models: ModelRow[]
}

const props = defineProps<{
    // null when adding.
    package: Package | null
}>()

const listUrl = route('idp.setting.development_model')

const editing = computed(() => props.package !== null)

// Accent palette cycled across the model rows / the weighting bar so each
// weighting (e.g. 70-20-10) is visually distinct. Class strings are kept
// literal so Tailwind can see them.
const modelPalette = [
    { bar: 'bg-indigo-500', text: 'text-indigo-600' },
    { bar: 'bg-sky-500', text: 'text-sky-600' },
    { bar: 'bg-amber-500', text: 'text-amber-600' },
    { bar: 'bg-emerald-500', text: 'text-emerald-600' },
    { bar: 'bg-rose-500', text: 'text-rose-600' },
]

const colorFor = (i: number) => modelPalette[i % modelPalette.length]

/**
 * --------------------------------------------------------------------------
 * The form
 * --------------------------------------------------------------------------
 * `rows` is the editable model list, each row carrying a local `uid` so Vue can
 * key it while it has no database id yet. The form's `models` field is derived
 * from it at submit time.
 */

let nextUid = 1

type Row = ModelRow & { uid: number }

function toRow(model?: Partial<ModelRow>): Row {
    return {
        uid: nextUid++,
        id: model?.id,
        name_en: model?.name_en ?? '',
        name_id: model?.name_id ?? '',
        percentage: model?.percentage ?? 0,
        uses_master_training: model?.uses_master_training ?? false,
        description_en: model?.description_en ?? '',
        description_id: model?.description_id ?? '',
        development_programs_count: model?.development_programs_count ?? 0,
        individual_development_plans_count:
            model?.individual_development_plans_count ?? 0,
    }
}

// A brand-new package opens with the 70-20-10 split blocked out, so the common
// case is a matter of naming three rows rather than building the shape.
const seedRows: Row[] = props.package
    ? props.package.models.map((m) => toRow(m))
    : [70, 20, 10].map((percentage) => toRow({ percentage }))

const rows = ref<Row[]>(seedRows.length ? seedRows : [toRow()])

const form = useForm({
    name: props.package?.name ?? '',
    start_date: props.package?.start_date ?? '',
    end_date: props.package?.end_date ?? '',
    is_current: props.package?.is_current ?? false,
    // Replaced from `rows` on submit.
    models: [] as Omit<
        ModelRow,
        'development_programs_count' | 'individual_development_plans_count'
    >[],
})

function addRow() {
    rows.value.push(toRow())
}

function removeRow(index: number) {
    rows.value.splice(index, 1)

    if (rows.value.length === 0) rows.value.push(toRow())
}

// A stored model an IDP plan or a development program still points at cannot be
// dropped — the server rejects it, so the button explains rather than fails.
function removalBlocker(row: Row): string | null {
    if (!row.id) return null

    if (row.individual_development_plans_count > 0) {
        return t.value.idp.settings.modelInUsePlans
    }

    if (row.development_programs_count > 0) {
        return t.value.idp.settings.modelInUsePrograms
    }

    return null
}

// Percentage accepts whole numbers only — block the characters a `number`
// input would otherwise allow (exponent, sign, decimal point).
function blockNonNumeric(e: KeyboardEvent) {
    if (['e', 'E', '+', '-', '.', ','].includes(e.key)) e.preventDefault()
}

const totalPercentage = computed(() =>
    rows.value.reduce((sum, r) => sum + (Number(r.percentage) || 0), 0),
)

const balanced = computed(() => totalPercentage.value === 100)

// How far off 100% the split is, so the bar can say "12% left" / "12% over".
const remaining = computed(() => 100 - totalPercentage.value)

const namedRowCount = computed(
    () => rows.value.filter((r) => r.name_en.trim() !== '').length,
)

/**
 * A package can only be pinned active while its period covers today. Mirror the
 * server rule so the checkbox is disabled (and any stale tick cleared) the
 * moment the chosen dates no longer include today.
 */
const validToday = computed(() => {
    if (!form.start_date) return false

    const today = new Date()
    today.setHours(0, 0, 0, 0)

    const start = new Date(form.start_date)
    if (Number.isNaN(start.getTime()) || start.getTime() > today.getTime()) {
        return false
    }

    if (form.end_date) {
        const end = new Date(form.end_date)
        if (Number.isNaN(end.getTime()) || end.getTime() < today.getTime()) {
            return false
        }
    }

    return true
})

/**
 * --------------------------------------------------------------------------
 * Errors
 * --------------------------------------------------------------------------
 * Nested rows come back keyed by position (models.2.name_en), which Inertia
 * hands over as flat keys on `form.errors`.
 */

const flatErrors = computed(() => form.errors as Record<string, string>)

function rowError(index: number, field: 'name_en' | 'percentage') {
    return flatErrors.value[`models.${index}.${field}`]
}

// The set-level errors (total ≠ 100%, a removed model still in use) all land on
// `models`; Laravel keeps only the first, so it is shown once above the list.
const modelsError = computed(() => flatErrors.value.models)

/**
 * --------------------------------------------------------------------------
 * Submit
 * --------------------------------------------------------------------------
 */

const title = computed(() =>
    editing.value
        ? t.value.idp.settings.editPackage
        : t.value.idp.settings.addPackage,
)

function submit() {
    form.models = rows.value.map((row) => ({
        id: row.id,
        name_en: row.name_en,
        name_id: row.name_id,
        percentage: Number(row.percentage) || 0,
        uses_master_training: row.uses_master_training,
        description_en: row.description_en,
        description_id: row.description_id,
    }))

    if (!validToday.value) form.is_current = false

    if (editing.value) {
        form.put(route('idp.setting.packages.update', props.package?.id))
    } else {
        form.post(route('idp.setting.packages.store'))
    }
}
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <PageHeader
            :title="title"
            :subtitle="t.idp.settings.packagesHint"
        >
            <template #actions>
                <Link
                    :href="listUrl"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-white px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-arrow-left text-xs" />
                    {{ t.idp.settings.backToList }}
                </Link>
            </template>
        </PageHeader>

        <form class="space-y-4 pb-24" @submit.prevent="submit">
            <!-- 1 · The package itself: name + period + the active pin. -->
            <FormSection
                step="1"
                :title="t.idp.settings.packageDetails"
                icon="fa-solid fa-box-open"
                :complete="!!form.name && !!form.start_date"
            >
                <!-- Name and the two period dates share one row, so the
                     section fills its width instead of hugging the left edge.
                     The name gets the wider half — it is free text, the dates
                     are fixed-width. -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="lg:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.packageName }}
                            <span class="text-red-500">*</span>
                        </label>
                        <input
                            v-model="form.name"
                            :placeholder="t.idp.settings.packageNamePlaceholder"
                            class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :class="form.errors.name ? 'border-red-500' : 'border-border'"
                        >
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.startDate }}
                            <span class="text-red-500">*</span>
                        </label>
                        <DateInput
                            v-model="form.start_date"
                            :invalid="!!form.errors.start_date"
                        />
                        <p v-if="form.errors.start_date" class="mt-1 text-xs text-red-600">
                            {{ form.errors.start_date }}
                        </p>
                    </div>

                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.endDate }}
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>
                        <DateInput
                            v-model="form.end_date"
                            :invalid="!!form.errors.end_date"
                        />
                        <p v-if="form.errors.end_date" class="mt-1 text-xs text-red-600">
                            {{ form.errors.end_date }}
                        </p>
                    </div>
                </div>

                <label
                    class="flex items-start gap-2.5 rounded-lg border p-3"
                    :class="
                        validToday
                            ? 'border-border bg-slate-50/60'
                            : 'cursor-not-allowed border-border bg-slate-100/60 opacity-70'
                    "
                >
                    <input
                        v-model="form.is_current"
                        type="checkbox"
                        :disabled="!validToday"
                        class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary disabled:cursor-not-allowed"
                    >
                    <span class="text-sm">
                        <span class="font-medium text-slate-700">
                            {{ t.idp.settings.setCurrent }}
                        </span>
                        <span class="mt-0.5 block text-xs text-slate-400">
                            {{ t.idp.settings.setCurrentHint }}
                        </span>
                        <!-- Why the pin is unavailable for these dates. -->
                        <span
                            v-if="!validToday"
                            class="mt-1 block text-xs font-medium text-amber-600"
                        >
                            <i class="fa-solid fa-triangle-exclamation mr-1" />
                            {{ t.idp.settings.setCurrentUnavailable }}
                        </span>
                    </span>
                </label>
                <p v-if="form.errors.is_current" class="text-xs text-red-600">
                    {{ form.errors.is_current }}
                </p>
            </FormSection>

            <!-- 2 · The weighted models, saved with the package. -->
            <FormSection
                step="2"
                :title="t.idp.settings.models"
                icon="fa-solid fa-diagram-project"
                :hint="t.idp.settings.modelsHint"
                :complete="balanced && namedRowCount === rows.length"
            >
                <template #aside>
                    <span
                        class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500"
                    >
                        {{ namedRowCount }} / {{ rows.length }}
                    </span>
                </template>

                <!-- Live weighting bar: the 100% rule, visible while typing. -->
                <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                    <div class="flex h-3 w-full overflow-hidden rounded-full bg-slate-200">
                        <div
                            v-for="(row, i) in rows"
                            :key="row.uid"
                            class="h-full transition-all duration-300"
                            :class="colorFor(i).bar"
                            :style="{
                                width: Math.min(Number(row.percentage) || 0, 100) + '%',
                            }"
                            :title="`${row.name_en || '—'} · ${row.percentage}%`"
                        />
                    </div>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span
                                v-for="(row, i) in rows"
                                :key="row.uid"
                                class="flex items-center gap-1.5 text-xs text-slate-500"
                            >
                                <span class="h-2.5 w-2.5 rounded-full" :class="colorFor(i).bar" />
                                {{ row.name_en || t.idp.settings.untitledModel }}
                                <span class="font-semibold text-slate-700">
                                    {{ row.percentage }}%
                                </span>
                            </span>
                        </div>

                        <span
                            class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold"
                            :class="
                                balanced
                                    ? 'bg-emerald-50 text-emerald-600'
                                    : 'bg-amber-50 text-amber-600'
                            "
                        >
                            <i
                                :class="
                                    balanced
                                        ? 'fa-solid fa-circle-check'
                                        : 'fa-solid fa-triangle-exclamation'
                                "
                            />
                            {{ t.idp.settings.total }} {{ totalPercentage }}% ·
                            <template v-if="balanced">
                                {{ t.idp.settings.balanced }}
                            </template>
                            <template v-else-if="remaining > 0">
                                {{ remaining }}% {{ t.idp.settings.remaining }}
                            </template>
                            <template v-else>
                                {{ -remaining }}% {{ t.idp.settings.over }}
                            </template>
                        </span>
                    </div>
                </div>

                <!-- Set-level failure: the total, or a removed model still in use. -->
                <p
                    v-if="modelsError"
                    class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-600"
                >
                    <i class="fa-solid fa-circle-exclamation mr-1" />
                    {{ modelsError }}
                </p>

                <!-- One card per model. -->
                <div class="space-y-3">
                    <div
                        v-for="(row, i) in rows"
                        :key="row.uid"
                        class="overflow-hidden rounded-lg border border-border bg-white"
                    >
                        <!-- Row header: what this model IS. The weighting
                             belongs here rather than in a third column beside
                             the two language blocks — it is the model's primary
                             attribute, and a narrow column next to two tall
                             ones left it stranded at the top. -->
                        <div
                            class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border/60 bg-slate-50/70 px-4 py-3"
                        >
                            <span
                                class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                                :class="colorFor(i).bar"
                            >
                                {{ i + 1 }}
                            </span>

                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-700">
                                {{ row.name_en || t.idp.settings.untitledModel }}
                            </span>

                            <span
                                v-if="removalBlocker(row)"
                                class="shrink-0 rounded-full bg-slate-200/70 px-2 py-0.5 text-[11px] font-medium text-slate-500"
                            >
                                {{ t.idp.settings.inUse }}
                            </span>

                            <!-- Weighting -->
                            <div class="flex shrink-0 items-center gap-2">
                                <label
                                    :for="`pct-${row.uid}`"
                                    class="text-xs font-medium text-slate-500"
                                >
                                    {{ t.idp.settings.percentage }}
                                    <span class="text-red-500">*</span>
                                </label>
                                <div class="relative w-24">
                                    <input
                                        :id="`pct-${row.uid}`"
                                        v-model.number="row.percentage"
                                        type="number"
                                        min="1"
                                        max="100"
                                        step="1"
                                        inputmode="numeric"
                                        class="w-full rounded-md border bg-white px-3 py-1.5 pr-7 text-right text-sm font-semibold focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :class="
                                            rowError(i, 'percentage')
                                                ? 'border-red-500'
                                                : 'border-border'
                                        "
                                        @keydown="blockNonNumeric"
                                    >
                                    <span
                                        class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                                    >
                                        %
                                    </span>
                                </div>
                            </div>

                            <IconButton
                                class="shrink-0"
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :disabled="!!removalBlocker(row)"
                                :title="removalBlocker(row) ?? t.idp.settings.removeModel"
                                @click="removeRow(i)"
                            />

                            <!-- Full-width so it never squeezes the header. -->
                            <p
                                v-if="rowError(i, 'percentage')"
                                class="w-full text-right text-xs text-red-600"
                            >
                                {{ rowError(i, 'percentage') }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                            <!-- English -->
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                                    >
                                        EN
                                    </span>
                                    <span class="text-xs font-semibold text-slate-500">
                                        {{ t.idp.settings.english }}
                                    </span>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">
                                        {{ t.idp.settings.modelName }}
                                        <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        v-model="row.name_en"
                                        :placeholder="t.idp.settings.namePlaceholderEn"
                                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :class="
                                            rowError(i, 'name_en')
                                                ? 'border-red-500'
                                                : 'border-border'
                                        "
                                    >
                                    <p
                                        v-if="rowError(i, 'name_en')"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ rowError(i, 'name_en') }}
                                    </p>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">
                                        {{ t.idp.settings.description }}
                                        <span class="font-normal text-slate-400">
                                            ({{ t.idp.settings.optional }})
                                        </span>
                                    </label>
                                    <textarea
                                        v-model="row.description_en"
                                        rows="3"
                                        :placeholder="t.idp.settings.descriptionHint"
                                        class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                            </div>

                            <!-- Bahasa Indonesia -->
                            <div class="space-y-3">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                                    >
                                        ID
                                    </span>
                                    <span class="text-xs font-semibold text-slate-500">
                                        {{ t.idp.settings.bahasa }}
                                    </span>
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">
                                        {{ t.idp.settings.modelName }}
                                        <span class="font-normal text-slate-400">
                                            ({{ t.idp.settings.optional }})
                                        </span>
                                    </label>
                                    <input
                                        v-model="row.name_id"
                                        :placeholder="t.idp.settings.namePlaceholderId"
                                        class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    >
                                </div>

                                <div>
                                    <label class="mb-1 block text-xs font-medium text-slate-500">
                                        {{ t.idp.settings.description }}
                                        <span class="font-normal text-slate-400">
                                            ({{ t.idp.settings.optional }})
                                        </span>
                                    </label>
                                    <textarea
                                        v-model="row.description_id"
                                        rows="3"
                                        :placeholder="t.idp.settings.descriptionHint"
                                        class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    />
                                </div>
                            </div>

                            <!-- Source of what this model develops. Spans both
                                 language columns: it is a property of the model,
                                 not of either language. -->
                            <label
                                class="flex cursor-pointer items-start gap-2.5 rounded-lg border p-3 transition lg:col-span-2"
                                :class="
                                    row.uses_master_training
                                        ? 'border-primary/30 bg-primary/5'
                                        : 'border-border bg-slate-50/60 hover:bg-slate-50'
                                "
                            >
                                <input
                                    v-model="row.uses_master_training"
                                    type="checkbox"
                                    class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-primary"
                                >
                                <span class="text-sm">
                                    <span class="flex items-center gap-1.5 font-medium text-slate-700">
                                        <i class="fa-solid fa-graduation-cap text-xs text-slate-400" />
                                        {{ t.idp.settings.useMasterTraining }}
                                    </span>
                                    <span class="mt-0.5 block text-xs text-slate-400">
                                        {{ t.idp.settings.useMasterTrainingHint }}
                                    </span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-dashed border-border px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                    @click="addRow"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addModel }}
                </button>
            </FormSection>

            <!-- Actions: pinned to the bottom of the viewport, so Save is
                 reachable without scrolling back down a long form. -->
            <div
                class="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-white/95 py-3 backdrop-blur lg:pl-[var(--sidebar-width)]"
            >
                <div class="flex items-center justify-end gap-3 px-4 sm:px-6 lg:px-8">
                    <!-- Why Save is unavailable, rather than a dead button. -->
                    <span
                        v-if="!balanced"
                        class="mr-auto inline-flex items-center gap-1.5 text-xs font-medium text-amber-600"
                    >
                        <i class="fa-solid fa-triangle-exclamation" />
                        {{ t.idp.settings.mustTotal100 }}
                    </span>

                    <Link
                        :href="listUrl"
                        class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    >
                        {{ t.idp.form.cancel }}
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing || !balanced"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {{ t.idp.form.save }}
                    </button>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
