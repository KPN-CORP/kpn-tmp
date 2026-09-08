<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import MasterStatusHistory from '@/Components/Domain/MasterStatusHistory.vue'
import { type Option } from '@/Components/UI/MultiSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'

const { t, locale } = useLocale()

/**
 * The nested rows a competency owns: the parts it breaks down into, and the
 * rungs of its proficiency ladder with the behaviors observed at each.
 *
 * All of them are the competency's own, so the wire shape is the DB's own field
 * names rather than the masters' value_en / value_id. `id` is present on a
 * stored row and absent on one just added, which is how the server tells an
 * update from an insert.
 */
interface SubCompetency {
    id?: number
    name_en: string
    name_id: string | null
    description_en: string | null
    description_id: string | null
}

/**
 * Both `sequence` fields are read-only here: the server assigns them from the
 * row's position in the submitted list, so the form orders by position and
 * never posts a number.
 */
interface KeyBehavior {
    id?: number
    name_en: string
    name_id: string | null
    sequence: number
}

interface ProficiencyLevel {
    id?: number
    name_en: string
    name_id: string | null
    description_en: string | null
    description_id: string | null
    sequence: number
    is_active: boolean
    key_behaviors: KeyBehavior[]
}

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    is_active: boolean
    sub_competencies: SubCompetency[]
    proficiency_levels: ProficiencyLevel[]
}

interface CompetencyType {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

const props = defineProps<{
    // null when adding.
    competency: Competency | null
    competencyTypes: CompetencyType[]
}>()

const listUrl = route('master_data.competency')

const editing = computed(() => props.competency !== null)

// Localized name for a master row, falling back to the canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

/**
 * --------------------------------------------------------------------------
 * The form
 * --------------------------------------------------------------------------
 * Seeded straight from the `competency` prop — this page IS the form, so there
 * is no open/close step. It posts to the competency's own page endpoints, which
 * run the same validation and the same writes as the shared master endpoints
 * and then land back on the list.
 */

const form = useForm({
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: props.competency?.value_en ?? props.competency?.value ?? '',
    value_id: props.competency?.value_id ?? '',
    description_en: props.competency?.description_en ?? '',
    description_id: props.competency?.description_id ?? '',
    competency_type_id: props.competency?.competency_type_id ?? null,
    // Both kept in sync from their row lists below.
    sub_competencies: [] as SubCompetency[],
    proficiency_levels: [] as ProficiencyLevel[],
    // New competencies are usable straight away.
    is_active: props.competency?.is_active ?? true,
})

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((ct) => ({
        value: String(ct.id),
        label: masterName(ct),
    })),
)

// Nested rules report against their wire path (proficiency_levels.0.name_en),
// which is not a property on the form object — so those errors are read by
// string rather than by key.
function flatError(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key]
}

/**
 * --------------------------------------------------------------------------
 * Sub-competencies
 * --------------------------------------------------------------------------
 * A dynamic list: one row per part the competency breaks down into, added and
 * removed a row at a time. `uid` is a stable local key so Vue keeps a row's
 * inputs when siblings come and go; `id` (when set) is the stored row the
 * server should update rather than replace.
 */

interface SubRow extends SubCompetency {
    uid: number
}

let subUid = 0

function newSubRow(sub?: SubCompetency): SubRow {
    return {
        uid: ++subUid,
        id: sub?.id,
        name_en: sub?.name_en ?? '',
        name_id: sub?.name_id ?? '',
        description_en: sub?.description_en ?? '',
        description_id: sub?.description_id ?? '',
    }
}

const subRows = ref<SubRow[]>(
    (props.competency?.sub_competencies ?? []).map((sub) => newSubRow(sub)),
)

// The rows minus their local key — what actually goes over the wire.
function syncSubCompetencies() {
    form.sub_competencies = subRows.value.map(({ uid: _uid, ...row }) => row)
}

watch(subRows, syncSubCompetencies, { deep: true, immediate: true })

function addSubRow() {
    subRows.value.push(newSubRow())
}

function removeSubRow(uid: number) {
    subRows.value = subRows.value.filter((r) => r.uid !== uid)
}

function subError(index: number, field: keyof SubCompetency): string | undefined {
    return flatError(`sub_competencies.${index}.${field}`)
}

// How many rows are filled in — shown on the step header, since an empty row
// is dropped server-side rather than saved.
const namedSubCount = computed(
    () => subRows.value.filter((r) => r.name_en.trim() !== '').length,
)

/**
 * --------------------------------------------------------------------------
 * The proficiency ladder
 * --------------------------------------------------------------------------
 * Free-typed rungs the competency owns: a bilingual name and description, an
 * active flag (audited, so a stored rung has a history to read back), and under
 * each one any number of free-typed key behaviors. Both lists are dynamic — the
 * same uid/id contract as the sub-competencies, one level deeper.
 *
 * Both are ordered by row position, moved with the up/down buttons; the stored
 * `sequence` is dropped on the way in and assigned server-side on the way out,
 * so the list order is the only thing that says where a row sits.
 */

interface BehaviorRow extends Omit<KeyBehavior, 'sequence'> {
    uid: number
}

interface LadderRow extends Omit<ProficiencyLevel, 'key_behaviors' | 'sequence'> {
    uid: number
    key_behaviors: BehaviorRow[]
}

let ladderUid = 0

function newBehaviorRow(behavior?: KeyBehavior): BehaviorRow {
    return {
        uid: ++ladderUid,
        id: behavior?.id,
        name_en: behavior?.name_en ?? '',
        name_id: behavior?.name_id ?? '',
    }
}

const ladderRows = ref<LadderRow[]>([])

function newLadderRow(level?: ProficiencyLevel): LadderRow {
    return {
        uid: ++ladderUid,
        id: level?.id,
        name_en: level?.name_en ?? '',
        name_id: level?.name_id ?? '',
        description_en: level?.description_en ?? '',
        description_id: level?.description_id ?? '',
        is_active: level?.is_active ?? true,
        key_behaviors: (level?.key_behaviors ?? []).map((b) => newBehaviorRow(b)),
    }
}

/**
 * Move a row one place up or down in its list. Returns silently at either end,
 * so the buttons can stay rendered (disabled) rather than jumping about.
 */
function moveRow<T>(rows: T[], index: number, delta: number): void {
    const target = index + delta
    if (target < 0 || target >= rows.length) return

    const [row] = rows.splice(index, 1)
    rows.splice(target, 0, row)
}

ladderRows.value = (props.competency?.proficiency_levels ?? []).map((level) =>
    newLadderRow(level),
)

// Strip the local keys; the nested behaviors lose theirs too.
function syncLadder() {
    form.proficiency_levels = ladderRows.value.map(
        ({ uid: _uid, key_behaviors, ...level }) => ({
            ...level,
            key_behaviors: key_behaviors.map(({ uid: _bUid, ...behavior }) => behavior),
        }),
    )
}

watch(ladderRows, syncLadder, { deep: true, immediate: true })

function addLadderRow() {
    ladderRows.value.push(newLadderRow())
}

function removeLadderRow(uid: number) {
    ladderRows.value = ladderRows.value.filter((r) => r.uid !== uid)
}

function moveLadderRow(index: number, delta: number) {
    moveRow(ladderRows.value, index, delta)
}

function addBehaviorRow(row: LadderRow) {
    row.key_behaviors.push(newBehaviorRow())
}

function removeBehaviorRow(row: LadderRow, uid: number) {
    row.key_behaviors = row.key_behaviors.filter((b) => b.uid !== uid)
}

function moveBehaviorRow(row: LadderRow, index: number, delta: number) {
    moveRow(row.key_behaviors, index, delta)
}

function levelError(
    index: number,
    field: 'name_en' | 'name_id',
): string | undefined {
    return flatError(`proficiency_levels.${index}.${field}`)
}

function behaviorError(
    levelIndex: number,
    behaviorIndex: number,
    field: 'name_en' | 'name_id',
): string | undefined {
    return flatError(
        `proficiency_levels.${levelIndex}.key_behaviors.${behaviorIndex}.${field}`,
    )
}

const namedLevelCount = computed(
    () => ladderRows.value.filter((r) => r.name_en.trim() !== '').length,
)

// The activation trail of one stored rung, in the shared history drawer. A rung
// that has never been saved has nothing to show, so it gets no button.
const historyLevel = ref<LadderRow | null>(null)

const historyUrl = computed(() =>
    historyLevel.value?.id
        ? route('master_data.competency.levels.statusHistory', historyLevel.value.id)
        : null,
)

/**
 * --------------------------------------------------------------------------
 * Submit
 * --------------------------------------------------------------------------
 */

const title = computed(
    () =>
        `${editing.value ? t.value.idp.settings.edit : t.value.idp.settings.add} ${t.value.idp.settings.competency}`,
)

function submit() {
    if (editing.value) {
        form.put(route('master_data.competency.update', props.competency?.id))
    } else {
        form.post(route('master_data.competency.store'))
    }
}
</script>

<template>
    <Head :title="title" />

    <AppLayout>
        <PageHeader :title="title" :subtitle="t.idp.settings.competencySubtitle">
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
            <!-- 1 · Classification -->
            <FormSection
                step="1"
                :title="t.idp.settings.competencyType"
                icon="fa-solid fa-tag"
                :complete="form.competency_type_id != null"
            >
                <div class="sm:max-w-md">
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competencyType }}
                        <span class="text-red-500">*</span>
                    </label>

                    <SearchableSelect
                        :model-value="
                            form.competency_type_id == null
                                ? ''
                                : String(form.competency_type_id)
                        "
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        :invalid="!!form.errors.competency_type_id"
                        @update:model-value="
                            form.competency_type_id =
                                $event === '' ? null : Number($event)
                        "
                    />

                    <p
                        v-if="form.errors.competency_type_id"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ form.errors.competency_type_id }}
                    </p>
                </div>
            </FormSection>

            <!-- 2 · Name + description, in both languages side by side. -->
            <FormSection
                step="2"
                :title="t.idp.settings.competencyName"
                icon="fa-solid fa-pen"
                :complete="form.value_en.trim() !== ''"
            >
                <div class="grid gap-4 lg:grid-cols-2">
                    <!-- English -->
                    <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                            >
                                EN
                            </span>
                            <span class="text-sm font-semibold text-slate-700">
                                {{ t.idp.settings.english }}
                            </span>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    {{ t.idp.settings.competencyName }}
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="form.value_en"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        form.errors.value_en
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="form.errors.value_en"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.value_en }}
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
                                    v-model="form.description_en"
                                    rows="5"
                                    :placeholder="t.idp.settings.descriptionHint"
                                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Bahasa Indonesia -->
                    <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                        <div class="mb-3 flex items-center gap-2">
                            <span
                                class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                            >
                                ID
                            </span>
                            <span class="text-sm font-semibold text-slate-700">
                                {{ t.idp.settings.bahasa }}
                            </span>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    {{ t.idp.settings.competencyName }}
                                </label>
                                <input
                                    v-model="form.value_id"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        form.errors.value_id
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="form.errors.value_id"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ form.errors.value_id }}
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
                                    v-model="form.description_id"
                                    rows="5"
                                    :placeholder="t.idp.settings.descriptionHint"
                                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </FormSection>

            <!-- 3 · Sub-competencies: a dynamic list of parts, each with a
                 bilingual name and description. -->
            <FormSection
                step="3"
                :title="t.idp.settings.subCompetencies"
                icon="fa-solid fa-diagram-project"
                :complete="namedSubCount > 0"
            >
                <template #aside>
                    <span
                        v-if="namedSubCount > 0"
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500"
                    >
                        {{ namedSubCount }}
                    </span>
                </template>

                <p
                    v-if="subRows.length === 0"
                    class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                >
                    {{ t.idp.settings.noSubCompetenciesYet }}
                </p>

                <div
                    v-for="(row, i) in subRows"
                    :key="row.uid"
                    class="rounded-lg border border-border bg-slate-50/60 p-3"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <span
                            class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-slate-200 text-[11px] font-semibold text-slate-600"
                        >
                            {{ i + 1 }}
                        </span>

                        <button
                            type="button"
                            class="rounded p-1 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                            :title="t.idp.settings.removeSubCompetency"
                            @click="removeSubRow(row.uid)"
                        >
                            <i class="fa-solid fa-xmark text-xs" />
                        </button>
                    </div>

                    <div class="grid gap-3 lg:grid-cols-2">
                        <!-- English -->
                        <div class="space-y-2 rounded-md border border-border bg-white p-3">
                            <span
                                class="inline-flex items-center rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700"
                            >
                                EN
                            </span>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    {{ t.idp.settings.subCompetencyName }}
                                    <span class="text-red-500">*</span>
                                </label>
                                <input
                                    v-model="row.name_en"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        subError(i, 'name_en')
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="subError(i, 'name_en')"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ subError(i, 'name_en') }}
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
                        <div class="space-y-2 rounded-md border border-border bg-white p-3">
                            <span
                                class="inline-flex items-center rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700"
                            >
                                ID
                            </span>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-slate-500">
                                    {{ t.idp.settings.subCompetencyName }}
                                </label>
                                <input
                                    v-model="row.name_id"
                                    class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                    :class="
                                        subError(i, 'name_id')
                                            ? 'border-red-500'
                                            : 'border-border'
                                    "
                                >
                                <p
                                    v-if="subError(i, 'name_id')"
                                    class="mt-1 text-xs text-red-600"
                                >
                                    {{ subError(i, 'name_id') }}
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
                                    v-model="row.description_id"
                                    rows="3"
                                    :placeholder="t.idp.settings.descriptionHint"
                                    class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm font-medium text-slate-500 transition hover:border-primary hover:bg-primary/5 hover:text-primary"
                    @click="addSubRow"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addSubCompetency }}
                </button>

                <!-- Two rows sharing a name: the table would refuse it. -->
                <p
                    v-if="form.errors.sub_competencies"
                    class="text-xs text-red-600"
                >
                    {{ form.errors.sub_competencies }}
                </p>
            </FormSection>

            <!-- 4 · The proficiency ladder: typed-in rungs, each with its own
                 key behaviors. -->
            <FormSection
                step="4"
                :title="t.idp.settings.proficiencyLevel"
                icon="fa-solid fa-signal"
                :complete="namedLevelCount > 0"
            >
                <template #aside>
                    <span
                        v-if="namedLevelCount > 0"
                        class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500"
                    >
                        {{ namedLevelCount }}
                    </span>
                </template>

                <p
                    v-if="ladderRows.length === 0"
                    class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                >
                    {{ t.idp.settings.noProficiencyLevelsYet }}
                </p>

                <div
                    v-for="(row, i) in ladderRows"
                    :key="row.uid"
                    class="rounded-lg border border-border bg-slate-50/60 p-3"
                    :class="row.is_active ? '' : 'opacity-75'"
                >
                    <!-- Rung header: position, reorder, status, history, remove.
                         The sequence is the row's place in this list, so it is
                         shown rather than typed and moved with the arrows. -->
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-slate-200 text-[11px] font-semibold text-slate-600"
                                :title="t.idp.settings.sequence"
                            >
                                {{ i + 1 }}
                            </span>

                            <div class="flex items-center rounded-md border border-border bg-white">
                                <button
                                    type="button"
                                    class="rounded-l-md px-2 py-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                    :disabled="i === 0"
                                    :title="t.idp.settings.moveUp"
                                    @click="moveLadderRow(i, -1)"
                                >
                                    <i class="fa-solid fa-chevron-up text-[10px]" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-r-md border-l border-border px-2 py-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                    :disabled="i === ladderRows.length - 1"
                                    :title="t.idp.settings.moveDown"
                                    @click="moveLadderRow(i, 1)"
                                >
                                    <i class="fa-solid fa-chevron-down text-[10px]" />
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <!-- Active/inactive. Switching a stored rung off is
                                 recorded, which is what the history button
                                 reads back. -->
                            <label
                                class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1.5 text-xs font-medium transition"
                                :class="
                                    row.is_active
                                        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                        : 'border-border bg-white text-slate-500'
                                "
                            >
                                <input
                                    v-model="row.is_active"
                                    type="checkbox"
                                    class="h-3.5 w-3.5 rounded border-border text-primary focus:ring-primary"
                                >
                                {{
                                    row.is_active
                                        ? t.idp.settings.activeLabel
                                        : t.idp.settings.inactiveBadge
                                }}
                            </label>

                            <button
                                v-if="row.id"
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                                :title="t.idp.settings.statusHistory"
                                @click="historyLevel = row"
                            >
                                <i class="fa-solid fa-clock-rotate-left text-xs" />
                            </button>

                            <button
                                type="button"
                                class="rounded p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                :title="t.idp.settings.removeProficiencyLevel"
                                @click="removeLadderRow(row.uid)"
                            >
                                <i class="fa-solid fa-xmark text-xs" />
                            </button>
                        </div>
                    </div>

                    <!-- Rung name, both languages. -->
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                <span
                                    class="mr-1 inline-flex items-center rounded bg-sky-100 px-1 py-0.5 text-[9px] font-bold uppercase text-sky-700"
                                >
                                    EN
                                </span>
                                {{ t.idp.settings.proficiencyLevel }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input
                                v-model="row.name_en"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    levelError(i, 'name_en')
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="levelError(i, 'name_en')"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ levelError(i, 'name_en') }}
                            </p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                <span
                                    class="mr-1 inline-flex items-center rounded bg-rose-100 px-1 py-0.5 text-[9px] font-bold uppercase text-rose-700"
                                >
                                    ID
                                </span>
                                {{ t.idp.settings.proficiencyLevel }}
                            </label>
                            <input
                                v-model="row.name_id"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    levelError(i, 'name_id')
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="levelError(i, 'name_id')"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ levelError(i, 'name_id') }}
                            </p>
                        </div>
                    </div>

                    <!-- What the rung means, both languages. -->
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                <span
                                    class="mr-1 inline-flex items-center rounded bg-sky-100 px-1 py-0.5 text-[9px] font-bold uppercase text-sky-700"
                                >
                                    EN
                                </span>
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="row.description_en"
                                rows="2"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
                                <span
                                    class="mr-1 inline-flex items-center rounded bg-rose-100 px-1 py-0.5 text-[9px] font-bold uppercase text-rose-700"
                                >
                                    ID
                                </span>
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="row.description_id"
                                rows="2"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>

                    <!-- Key behaviors observed at this rung. -->
                    <div class="mt-3 rounded-md border border-border bg-white p-3">
                        <div class="mb-2 flex items-center gap-2">
                            <i class="fa-solid fa-list-check text-[10px] text-amber-500" />
                            <span class="text-xs font-semibold text-slate-700">
                                {{ t.idp.settings.keyBehaviors }}
                            </span>
                            <span
                                v-if="row.key_behaviors.length"
                                class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold text-slate-500"
                            >
                                {{ row.key_behaviors.length }}
                            </span>
                        </div>

                        <p
                            v-if="row.key_behaviors.length === 0"
                            class="mb-2 rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                        >
                            {{ t.idp.settings.noKeyBehaviorsYet }}
                        </p>

                        <div
                            v-for="(behavior, b) in row.key_behaviors"
                            :key="behavior.uid"
                            class="mb-2 flex items-start gap-2"
                        >
                            <span
                                class="mt-2 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-100 text-[10px] font-semibold text-amber-700"
                            >
                                {{ b + 1 }}
                            </span>

                            <div class="grid flex-1 gap-2 sm:grid-cols-2">
                                <div>
                                    <input
                                        v-model="behavior.name_en"
                                        :placeholder="t.idp.settings.keyBehavior + ' (EN)'"
                                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :class="
                                            behaviorError(i, b, 'name_en')
                                                ? 'border-red-500'
                                                : 'border-border'
                                        "
                                    >
                                    <p
                                        v-if="behaviorError(i, b, 'name_en')"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ behaviorError(i, b, 'name_en') }}
                                    </p>
                                </div>

                                <div>
                                    <input
                                        v-model="behavior.name_id"
                                        :placeholder="t.idp.settings.keyBehavior + ' (ID)'"
                                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :class="
                                            behaviorError(i, b, 'name_id')
                                                ? 'border-red-500'
                                                : 'border-border'
                                        "
                                    >
                                    <p
                                        v-if="behaviorError(i, b, 'name_id')"
                                        class="mt-1 text-xs text-red-600"
                                    >
                                        {{ behaviorError(i, b, 'name_id') }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-1 flex shrink-0 items-center">
                                <div class="flex items-center rounded-md border border-border bg-white">
                                    <button
                                        type="button"
                                        class="rounded-l-md px-1.5 py-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                        :disabled="b === 0"
                                        :title="t.idp.settings.moveUp"
                                        @click="moveBehaviorRow(row, b, -1)"
                                    >
                                        <i class="fa-solid fa-chevron-up text-[10px]" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-r-md border-l border-border px-1.5 py-1.5 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-slate-400"
                                        :disabled="b === row.key_behaviors.length - 1"
                                        :title="t.idp.settings.moveDown"
                                        @click="moveBehaviorRow(row, b, 1)"
                                    >
                                        <i class="fa-solid fa-chevron-down text-[10px]" />
                                    </button>
                                </div>

                                <button
                                    type="button"
                                    class="rounded p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                    :title="t.idp.settings.removeKeyBehavior"
                                    @click="removeBehaviorRow(row, behavior.uid)"
                                >
                                    <i class="fa-solid fa-xmark text-xs" />
                                </button>
                            </div>
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-border px-2.5 py-1.5 text-xs font-medium text-slate-500 transition hover:border-primary hover:bg-primary/5 hover:text-primary"
                            @click="addBehaviorRow(row)"
                        >
                            <i class="fa-solid fa-plus text-[10px]" />
                            {{ t.idp.settings.addKeyBehavior }}
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm font-medium text-slate-500 transition hover:border-primary hover:bg-primary/5 hover:text-primary"
                    @click="addLadderRow"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addProficiencyLevel }}
                </button>

                <!-- A duplicate name across rungs, or inside one rung's
                     behaviors. -->
                <p
                    v-if="form.errors.proficiency_levels"
                    class="text-xs text-red-600"
                >
                    {{ form.errors.proficiency_levels }}
                </p>
            </FormSection>

            <!-- 5 · Active / inactive (the competency itself) -->
            <FormSection
                step="5"
                :title="t.idp.settings.status"
                icon="fa-solid fa-toggle-on"
            >
                <ActiveStateField
                    v-model="form.is_active"
                    :error="form.errors.is_active"
                />
            </FormSection>

            <!-- Actions: pinned to the bottom of the viewport, so Save is
                 reachable without scrolling back down a long form. -->
            <div
                class="fixed inset-x-0 bottom-0 z-20 border-t border-border bg-white/95 py-3 backdrop-blur lg:pl-[var(--sidebar-width)]"
            >
                <div
                    class="flex items-center justify-end gap-2 px-4 sm:px-6 lg:px-8"
                >
                    <Link
                        :href="listUrl"
                        class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    >
                        {{ t.idp.form.cancel }}
                    </Link>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                    >
                        {{ t.idp.form.save }}
                    </button>
                </div>
            </div>
        </form>

        <!-- One rung's activation trail, read from the audit log on open. -->
        <MasterStatusHistory
            :show="historyLevel !== null"
            :url="historyUrl"
            :name="historyLevel?.name_en ?? ''"
            @close="historyLevel = null"
        />
    </AppLayout>
</template>
