<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { useLocale } from '@/Composables/useLocale'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'

const { t, locale } = useLocale()

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    // A competency may pin several proficiency levels + key behaviors.
    proficiency_level_ids: number[]
    key_behavior_ids: number[]
}

interface CompetencyType {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competencies_count: number
}

interface ProficiencyLevel {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface KeyBehavior {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    proficiency_level_id: number
}

const props = defineProps<{
    competencies: Competency[]
    competencyTypes: CompetencyType[]
    proficiencyLevels: ProficiencyLevel[]
    keyBehaviors: KeyBehavior[]
}>()

/**
 * After a create/update/delete the server redirects back here. Restricting the
 * reload to this page's own data (+ flash) turns every save into an Inertia
 * partial reload, so the expensive shared props (corporate employee lookup,
 * approval counts, notification feed) are not re-evaluated on each mutation.
 */
const reloadOnly = ['competencies', 'competencyTypes', 'flash']

// Localized name for a competency / competency type, falling back to the
// canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized competency description (falls back to the other language).
function competencyDescription(c: Competency): string {
    const preferred = locale.value === 'id' ? c.description_id : c.description_en
    const fallback = locale.value === 'id' ? c.description_en : c.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

/**
 * --------------------------------------------------------------------------
 * Master form (competency + competency type)
 * --------------------------------------------------------------------------
 */

type MasterType = 'competency_name' | 'competency_type'

const masterModal = ref(false)
const masterType = ref<MasterType>('competency_name')
const editingMasterId = ref<number | null>(null)

const masterForm = useForm({
    type: 'competency_name' as MasterType,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
    // Competency → competency type + proficiency levels + key behaviors.
    competency_type_id: null as number | null,
    proficiency_level_ids: [] as number[],
    key_behavior_ids: [] as number[],
})

/**
 * How a competency relates to a proficiency level:
 *  - 'none'     → no proficiency level at all
 *  - 'level'    → a proficiency level, without a key behavior
 *  - 'behavior' → a proficiency level plus one of its key behaviors
 * The mode is derived from the stored ids on edit and drives which selects show.
 */
type ProficiencyMode = 'none' | 'level' | 'behavior'
const proficiencyMode = ref<ProficiencyMode>('none')

function openMaster(type: MasterType, item?: Competency | CompetencyType) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    masterForm.type = type
    masterForm.value_en = item?.value_en ?? item?.value ?? ''
    masterForm.value_id = item?.value_id ?? ''
    masterForm.description_en = item?.description_en ?? ''
    masterForm.description_id = item?.description_id ?? ''
    masterForm.competency_type_id =
        (item as Competency)?.competency_type_id ?? null
    masterForm.proficiency_level_ids = [
        ...((item as Competency)?.proficiency_level_ids ?? []),
    ]
    masterForm.key_behavior_ids = [
        ...((item as Competency)?.key_behavior_ids ?? []),
    ]

    // Derive the proficiency mode from the stored ids.
    proficiencyMode.value =
        masterForm.key_behavior_ids.length > 0
            ? 'behavior'
            : masterForm.proficiency_level_ids.length > 0
              ? 'level'
              : 'none'

    // One editable row per stored level, each holding that level's behaviors.
    levelRows.value = rowsFromIds(
        masterForm.proficiency_level_ids,
        masterForm.key_behavior_ids,
    )

    masterModal.value = true
}

/**
 * Proficiency rows — the editing model behind the two flat id arrays. One row
 * is one proficiency level plus the key behaviors chosen *under that level*,
 * so picking behaviors never means hunting through a pooled list. The form's
 * proficiency_level_ids / key_behavior_ids stay the wire format and are kept
 * in sync from the rows.
 */
interface LevelRow {
    // Stable key so Vue keeps a row's inputs when siblings are added/removed.
    uid: number
    levelId: number | null
    keyBehaviorIds: number[]
}

let rowUid = 0
const levelRows = ref<LevelRow[]>([])

function newRow(
    levelId: number | null = null,
    keyBehaviorIds: number[] = [],
): LevelRow {
    return { uid: ++rowUid, levelId, keyBehaviorIds }
}

// Rebuild the rows from the stored ids (on edit), grouping each key behavior
// under the level it belongs to.
function rowsFromIds(levelIds: number[], keyBehaviorIds: number[]): LevelRow[] {
    const behaviorsByLevel = new Map<number, number[]>()

    for (const id of keyBehaviorIds) {
        const kb = props.keyBehaviors.find((k) => k.id === id)
        if (!kb) continue
        const bucket = behaviorsByLevel.get(kb.proficiency_level_id) ?? []
        bucket.push(id)
        behaviorsByLevel.set(kb.proficiency_level_id, bucket)
    }

    // A level reachable only through one of its key behaviors still gets a row.
    const ordered = [...levelIds]
    for (const levelId of behaviorsByLevel.keys()) {
        if (!ordered.includes(levelId)) ordered.push(levelId)
    }

    return ordered.map((id) => newRow(id, behaviorsByLevel.get(id) ?? []))
}

// Flatten the rows back into the arrays the controller validates. Key
// behaviors only travel in 'behavior' mode.
function syncFormIds() {
    const rows = levelRows.value

    masterForm.proficiency_level_ids = rows
        .map((r) => r.levelId)
        .filter((id): id is number => id != null)

    masterForm.key_behavior_ids =
        proficiencyMode.value === 'behavior'
            ? rows.flatMap((r) => (r.levelId == null ? [] : r.keyBehaviorIds))
            : []
}

watch(levelRows, syncFormIds, { deep: true })

// Keep the rows consistent with the chosen mode: 'none' clears them, the other
// two always leave one row on screen to fill in.
watch(proficiencyMode, (mode) => {
    if (mode === 'none') {
        levelRows.value = []
    } else if (levelRows.value.length === 0) {
        levelRows.value = [newRow()]
    }

    syncFormIds()
})

function addLevelRow() {
    levelRows.value.push(newRow())
}

function removeLevelRow(uid: number) {
    levelRows.value = levelRows.value.filter((r) => r.uid !== uid)
}

// Swapping a row's level invalidates the behaviors picked under the old one.
function setRowLevel(row: LevelRow, value: string) {
    row.levelId = value === '' ? null : Number(value)
    row.keyBehaviorIds = []
}

function setRowBehaviors(row: LevelRow, values: string[]) {
    row.keyBehaviorIds = values.map(Number)
}

// Levels not already claimed by another row (this row's own stays selectable).
function levelOptionsFor(row: LevelRow): Option[] {
    const taken = new Set(
        levelRows.value
            .filter((r) => r.uid !== row.uid && r.levelId != null)
            .map((r) => r.levelId as number),
    )

    return proficiencyLevelOptions.value.filter(
        (o) => !taken.has(Number(o.value)),
    )
}

// Key behaviors belonging to this row's level — scoped, so no level prefix.
function keyBehaviorOptionsFor(row: LevelRow): Option[] {
    if (row.levelId == null) return []

    return props.keyBehaviors
        .filter((kb) => kb.proficiency_level_id === row.levelId)
        .map((kb) => ({ value: String(kb.id), label: masterName(kb) }))
}

// Once every level has a row there is nothing left to add.
const canAddLevelRow = computed(
    () => levelRows.value.length < props.proficiencyLevels.length,
)

function submitMaster() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (masterModal.value = false),
    }

    if (editingMasterId.value) {
        masterForm.put(`/idp-setting/masters/${editingMasterId.value}`, opts)
    } else {
        masterForm.post('/idp-setting/masters', opts)
    }
}

function deleteMaster(id: number, name?: string) {
    pendingDelete.value = {
        url: `/idp-setting/masters/${id}`,
        name,
    }
}

const masterTitle = () => {
    const type =
        masterType.value === 'competency_name'
            ? t.value.idp.settings.competency
            : t.value.idp.settings.competencyType

    const prefix = editingMasterId.value
        ? t.value.idp.settings.edit
        : t.value.idp.settings.add

    return `${prefix} ${type}`
}

// Both competency and competency type carry a bilingual description.
const masterHasDescription = computed(
    () =>
        masterType.value === 'competency_name' ||
        masterType.value === 'competency_type',
)

/**
 * --------------------------------------------------------------------------
 * Delete confirmation (shared dialog)
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function confirmDelete() {
    if (!pendingDelete.value) return

    router.delete(pendingDelete.value.url, {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onStart: () => (deleting.value = true),
        onFinish: () => (deleting.value = false),
        onSuccess: () => (pendingDelete.value = null),
    })
}

/**
 * --------------------------------------------------------------------------
 * Competency types
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const ct of props.competencyTypes) m.set(ct.id, ct)
    return m
})

// Localized name for the competency type linked to a competency (or '').
function competencyTypeName(id: number | null): string {
    if (id == null) return ''
    const ct = competencyTypeById.value.get(id)
    return ct ? masterName(ct) : ''
}

// Localized description of a competency type (falls back to the other language).
function typeDescription(ct: CompetencyType): string {
    const preferred = locale.value === 'id' ? ct.description_id : ct.description_en
    const fallback = locale.value === 'id' ? ct.description_en : ct.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

// Competency types as dropdown options for the competency form.
const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((ct) => ({
        value: String(ct.id),
        label: masterName(ct),
    })),
)

// Proficiency levels as dropdown options for the competency form.
const proficiencyLevelOptions = computed<Option[]>(() =>
    props.proficiencyLevels.map((pl) => ({
        value: String(pl.id),
        label: masterName(pl),
    })),
)

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const pl of props.proficiencyLevels) m.set(pl.id, pl)
    return m
})

// Localized name for the proficiency level linked to a competency (or '').
function proficiencyLevelName(id: number | null): string {
    if (id == null) return ''
    const pl = proficiencyLevelById.value.get(id)
    return pl ? masterName(pl) : ''
}

const keyBehaviorById = computed(() => {
    const m = new Map<number, KeyBehavior>()
    for (const kb of props.keyBehaviors) m.set(kb.id, kb)
    return m
})

// Localized name for the key behavior pinned on a competency (or '').
function keyBehaviorName(id: number | null): string {
    if (id == null) return ''
    const kb = keyBehaviorById.value.get(id)
    return kb ? masterName(kb) : ''
}

// Localized names for a competency's selected proficiency levels / key behaviors.
function proficiencyLevelNames(ids: number[] | null): string[] {
    return (ids ?? [])
        .map((id) => proficiencyLevelName(id))
        .filter((n) => n !== '')
}

function keyBehaviorNames(ids: number[] | null): string[] {
    return (ids ?? []).map((id) => keyBehaviorName(id)).filter((n) => n !== '')
}

// Selected competency-type filter for the competencies table (null = all;
// 0 = the "untyped" bucket).
const selectedTypeFilter = ref<number | null>(null)

// Keep the filter valid as types are added/removed.
watch(
    () => props.competencyTypes,
    (list) => {
        if (
            selectedTypeFilter.value !== null &&
            selectedTypeFilter.value !== 0 &&
            !list.some((ct) => ct.id === selectedTypeFilter.value)
        ) {
            selectedTypeFilter.value = null
        }
    },
)

// Competencies with no type assigned (drives the "Untyped" filter option).
const untypedCompetencyCount = computed(
    () => props.competencies.filter((c) => c.competency_type_id == null).length,
)

// Competency types as ClientTable rows (localized name + description derived).
const typeRows = computed(() =>
    props.competencyTypes.map((ct) => ({
        ...ct,
        _name: masterName(ct),
        _description: typeDescription(ct),
    })),
)

const typeColumns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.competencyType, sortable: true, sortKey: '_name', thClass: 'w-64' },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'competencies_count', label: t.value.idp.settings.competencies, sortable: true, align: 'center', thClass: 'w-32' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

// Bridge the numeric/null type filter to the string-valued <select> in the
// competency toolbar ('' = all, '0' = untyped bucket).
const typeFilterValue = computed<string>({
    get: () => (selectedTypeFilter.value === null ? '' : String(selectedTypeFilter.value)),
    set: (v) => (selectedTypeFilter.value = v === '' ? null : Number(v)),
})

/**
 * --------------------------------------------------------------------------
 * Competency table — search + type filter (external) → ClientTable (sort + pages)
 * --------------------------------------------------------------------------
 */

const competencySearch = ref('')

// Rows carry the derived localized name + type name ClientTable sorts on; the
// original fields remain (spread) so the cell slots' helpers keep working.
const competencyRows = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()
    const typeFilter = selectedTypeFilter.value

    return props.competencies
        .filter((c) => {
            // Competency-type filter (0 = untyped bucket).
            if (typeFilter !== null) {
                if (typeFilter === 0) {
                    if (c.competency_type_id != null) return false
                } else if (c.competency_type_id !== typeFilter) {
                    return false
                }
            }

            if (!q) return true
            return (
                masterName(c).toLowerCase().includes(q) ||
                c.value.toLowerCase().includes(q)
            )
        })
        .map((c) => ({
            ...c,
            name: masterName(c),
            type_name: competencyTypeName(c.competency_type_id),
        }))
})

const competencyColumns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.competency, sortable: true, thClass: 'w-72' },
    { key: 'type_name', label: t.value.idp.settings.competencyType, sortable: true, thClass: 'w-48' },
    { key: 'proficiency', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-40' },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])
</script>

<template>
    <Head :title="t.idp.settings.competencyTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.competencyTitle"
            :subtitle="t.idp.settings.competencySubtitle"
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Competency types · manage + filter chips
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.competencyTypes }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ competencyTypes.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.competencyTypesHint }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                        @click="openMaster('competency_type')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competencyType }}
                    </button>
                </div>

                <!-- Competency types table -->
                <ClientTable
                    :columns="typeColumns"
                    :rows="typeRows"
                    row-key="id"
                    :per-page="5"
                    numbered
                >
                        <template #cell-name="{ row }">
                            <span class="inline-flex items-center gap-1.5 font-semibold text-slate-800">
                                <i class="fa-solid fa-tag text-[10px] text-indigo-400" />
                                {{ row._name }}
                            </span>
                        </template>

                        <template #cell-description="{ row }">
                            <span
                                v-if="row._description"
                                class="whitespace-pre-wrap break-words text-slate-500"
                            >
                                {{ row._description }}
                            </span>
                            <span v-else class="text-xs italic text-slate-300">
                                {{ t.idp.settings.noDescription }}
                            </span>
                        </template>

                        <template #cell-competencies_count="{ row }">
                            <span
                                class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-600"
                            >
                                {{ row.competencies_count }}
                            </span>
                        </template>

                        <template #cell-actions="{ row }">
                            <div class="flex items-center justify-end gap-1">
                                <IconButton
                                    icon="fa-solid fa-pen"
                                    variant="edit"
                                    :title="t.idp.settings.editCompetencyType"
                                    @click="openMaster('competency_type', row as unknown as CompetencyType)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteCompetencyType"
                                    @click="deleteMaster(row.id, row._name)"
                                />
                            </div>
                        </template>

                        <template #empty>
                            {{ t.idp.settings.noTypesYet }}
                        </template>
                    </ClientTable>
            </section>

            <!-- ------------------------------------------------------------
                 Competencies card: header + toolbar + table
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.competencies }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ competencies.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.competenciesHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <!-- Competency-type filter (replaces the old filter chips) -->
                    <select
                        v-model="typeFilterValue"
                        class="rounded-md border border-border bg-white px-3 py-2 text-sm text-slate-600 focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option value="">{{ t.idp.settings.allTypes }}</option>
                        <option
                            v-for="ct in competencyTypes"
                            :key="ct.id"
                            :value="String(ct.id)"
                        >
                            {{ masterName(ct) }}
                        </option>
                        <option v-if="untypedCompetencyCount" value="0">
                            {{ t.idp.settings.untyped }}
                        </option>
                    </select>

                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="competencySearch"
                            type="search"
                            :placeholder="t.idp.settings.searchCompetency"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openMaster('competency_name')"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competency }}
                    </button>
                </div>
            </div>

                <!-- Competency table -->
                <ClientTable
                    :columns="competencyColumns"
                    :rows="competencyRows"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
                    </template>

                    <template #cell-type_name="{ row }">
                        <span
                            v-if="row.type_name"
                            class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                        >
                            <i class="fa-solid fa-tag text-[9px]" />
                            {{ row.type_name }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">
                            {{ t.idp.settings.untyped }}
                        </span>
                    </template>

                    <template #cell-proficiency="{ row }">
                        <div
                            v-if="proficiencyLevelNames(row.proficiency_level_ids).length"
                            class="flex flex-col items-start gap-1"
                        >
                            <div class="flex flex-wrap gap-1">
                                <span
                                    v-for="name in proficiencyLevelNames(row.proficiency_level_ids)"
                                    :key="name"
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                                >
                                    <i class="fa-solid fa-signal text-[9px]" />
                                    {{ name }}
                                </span>
                            </div>
                            <div
                                v-if="keyBehaviorNames(row.key_behavior_ids).length"
                                class="flex flex-wrap gap-1"
                            >
                                <span
                                    v-for="name in keyBehaviorNames(row.key_behavior_ids)"
                                    :key="name"
                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600"
                                    :title="t.idp.settings.keyBehavior"
                                >
                                    <i class="fa-solid fa-list-check text-[9px]" />
                                    {{ name }}
                                </span>
                            </div>
                        </div>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-description="{ row }">
                        <span
                            v-if="competencyDescription(row as unknown as Competency)"
                            class="whitespace-pre-wrap break-words text-slate-500"
                        >
                            {{ competencyDescription(row as unknown as Competency) }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">
                            {{ t.idp.settings.noDescription }}
                        </span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <IconButton
                                icon="fa-solid fa-pen"
                                variant="edit"
                                :title="t.idp.settings.editCompetency"
                                @click="openMaster('competency_name', row as unknown as Competency)"
                            />
                            <IconButton
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :title="t.idp.settings.deleteCompetency"
                                @click="deleteMaster(row.id, row.name)"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{
                            competencySearch || selectedTypeFilter !== null
                                ? t.idp.settings.noMatch
                                : t.idp.settings.none
                        }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             MASTER DATA MODAL (competency + competency type)
        ================================================================= -->

        <Drawer
            :show="masterModal"
            :title="masterTitle()"
            @close="masterModal = false"
        >
            <form
                id="master-form"
                class="space-y-4"
                @submit.prevent="submitMaster"
            >
                <!-- English section -->
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
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.name }}
                            </label>
                            <input
                                v-model="masterForm.value_en"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.value_en
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="masterForm.errors.value_en"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.value_en }}
                            </p>
                        </div>

                        <div v-if="masterHasDescription">
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="masterForm.description_en"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>

                <!-- Bahasa Indonesia section -->
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
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.name }}
                            </label>
                            <input
                                v-model="masterForm.value_id"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    masterForm.errors.value_id
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p
                                v-if="masterForm.errors.value_id"
                                class="mt-1 text-xs text-red-600"
                            >
                                {{ masterForm.errors.value_id }}
                            </p>
                        </div>

                        <div v-if="masterHasDescription">
                            <label
                                class="mb-1 block text-xs font-medium text-slate-500"
                            >
                                {{ t.idp.settings.description }}
                                <span class="font-normal text-slate-400">
                                    ({{ t.idp.settings.optional }})
                                </span>
                            </label>
                            <textarea
                                v-model="masterForm.description_id"
                                rows="4"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>

                <!-- Competency -> Competency type -->
                <div v-if="masterType === 'competency_name'">
                    <label
                        class="mb-1.5 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.competencyType }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>

                    <SearchableSelect
                        :model-value="
                            masterForm.competency_type_id == null
                                ? ''
                                : String(masterForm.competency_type_id)
                        "
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        @update:model-value="
                            masterForm.competency_type_id =
                                $event === '' ? null : Number($event)
                        "
                    />
                </div>

                <!-- Competency -> Proficiency level & key behavior -->
                <div v-if="masterType === 'competency_name'" class="space-y-3">
                    <label class="block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.proficiencyLevel }}
                    </label>

                    <!-- Mode selector -->
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <label
                            v-for="opt in [
                                { key: 'behavior', label: t.idp.settings.withKeyBehavior },
                                { key: 'level', label: t.idp.settings.withoutKeyBehavior },
                                { key: 'none', label: t.idp.settings.noProficiencyLevel },
                            ]"
                            :key="opt.key"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                            :class="
                                proficiencyMode === opt.key
                                    ? 'border-primary bg-primary/5 text-primary'
                                    : 'border-border text-slate-600 hover:bg-slate-50'
                            "
                        >
                            <input
                                v-model="proficiencyMode"
                                type="radio"
                                :value="opt.key"
                                class="text-primary focus:ring-primary"
                            >
                            {{ opt.label }}
                        </label>
                    </div>

                    <!-- One card per proficiency level, each carrying its own
                         key behaviors; levels are added/removed a row at a time. -->
                    <div v-if="proficiencyMode !== 'none'" class="space-y-2">
                        <p
                            v-if="proficiencyMode === 'behavior'"
                            class="text-xs text-slate-400"
                        >
                            {{ t.idp.settings.levelRowsHint }}
                        </p>

                        <div
                            v-for="(row, i) in levelRows"
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
                                    :title="t.idp.settings.removeLevelRow"
                                    @click="removeLevelRow(row.uid)"
                                >
                                    <i class="fa-solid fa-xmark text-xs" />
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div>
                                    <label
                                        class="mb-1 block text-xs font-medium text-slate-500"
                                    >
                                        {{ t.idp.settings.proficiencyLevel }}
                                    </label>

                                    <SearchableSelect
                                        :model-value="
                                            row.levelId == null
                                                ? ''
                                                : String(row.levelId)
                                        "
                                        :options="levelOptionsFor(row)"
                                        :placeholder="t.idp.settings.proficiencyLevelPickHint"
                                        :invalid="!!masterForm.errors.proficiency_level_ids"
                                        @update:model-value="setRowLevel(row, $event)"
                                    />
                                </div>

                                <div v-if="proficiencyMode === 'behavior'">
                                    <label
                                        class="mb-1 block text-xs font-medium text-slate-500"
                                    >
                                        {{ t.idp.settings.keyBehavior }}
                                    </label>

                                    <MultiSelect
                                        v-if="keyBehaviorOptionsFor(row).length > 0"
                                        :model-value="row.keyBehaviorIds.map(String)"
                                        :options="keyBehaviorOptionsFor(row)"
                                        :placeholder="t.idp.settings.keyBehaviorPickHint"
                                        :invalid="!!masterForm.errors.key_behavior_ids"
                                        @update:model-value="setRowBehaviors(row, $event)"
                                    />
                                    <p
                                        v-else
                                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                                    >
                                        {{
                                            row.levelId == null
                                                ? t.idp.settings.pickLevelFirst
                                                : t.idp.settings.noKeyBehaviorsForLevel
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <button
                            v-if="canAddLevelRow"
                            type="button"
                            class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-2 text-sm font-medium text-slate-500 transition hover:border-primary hover:bg-primary/5 hover:text-primary"
                            @click="addLevelRow"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addLevelRow }}
                        </button>

                        <p
                            v-if="masterForm.errors.proficiency_level_ids"
                            class="text-xs text-red-600"
                        >
                            {{ masterForm.errors.proficiency_level_ids }}
                        </p>
                        <p
                            v-if="masterForm.errors.key_behavior_ids"
                            class="text-xs text-red-600"
                        >
                            {{ masterForm.errors.key_behavior_ids }}
                        </p>
                    </div>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="masterModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="master-form"
                    :disabled="masterForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             DELETE CONFIRMATION
        ================================================================= -->

        <ConfirmDialog
            :show="pendingDelete !== null"
            :title="t.idp.settings.deleteTitle"
            :message="t.idp.settings.confirmDelete"
            :confirm-label="t.idp.settings.delete"
            :cancel-label="t.idp.form.cancel"
            variant="danger"
            :processing="deleting"
            @confirm="confirmDelete"
            @close="pendingDelete = null"
        >
            <p
                v-if="pendingDelete?.name"
                class="mt-3 truncate rounded-md bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700"
            >
                {{ pendingDelete.name }}
            </p>
        </ConfirmDialog>
    </AppLayout>
</template>
