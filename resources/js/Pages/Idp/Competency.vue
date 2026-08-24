<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
import Pagination from '@/Components/UI/Pagination.vue'
import { useLocale } from '@/Composables/useLocale'
import type { Option } from '@/Components/UI/MultiSelect.vue'

const { t, locale } = useLocale()

interface Competency {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    competency_type_id: number | null
    proficiency_level_id: number | null
    key_behavior_id: number | null
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
    // Competency → competency type + proficiency level + key behavior.
    competency_type_id: null as number | null,
    proficiency_level_id: null as number | null,
    key_behavior_id: null as number | null,
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
    masterForm.proficiency_level_id =
        (item as Competency)?.proficiency_level_id ?? null
    masterForm.key_behavior_id = (item as Competency)?.key_behavior_id ?? null

    // Derive the proficiency mode from the stored ids.
    proficiencyMode.value =
        masterForm.key_behavior_id != null
            ? 'behavior'
            : masterForm.proficiency_level_id != null
              ? 'level'
              : 'none'

    masterModal.value = true
}

// Keep the form fields consistent with the chosen mode.
watch(proficiencyMode, (mode) => {
    if (mode === 'none') {
        masterForm.proficiency_level_id = null
        masterForm.key_behavior_id = null
    } else if (mode === 'level') {
        masterForm.key_behavior_id = null
    }
})

// Changing the level (in behavior mode) drops a key behavior that no longer
// belongs to it.
watch(
    () => masterForm.proficiency_level_id,
    (level) => {
        if (proficiencyMode.value !== 'behavior') return
        const kb = keyBehaviorById.value.get(masterForm.key_behavior_id ?? -1)
        if (!kb || kb.proficiency_level_id !== level) {
            masterForm.key_behavior_id = null
        }
    },
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

// Key behaviors of the currently chosen level, as dropdown options.
const keyBehaviorOptions = computed<Option[]>(() => {
    const level = masterForm.proficiency_level_id
    if (level == null) return []
    return props.keyBehaviors
        .filter((kb) => kb.proficiency_level_id === level)
        .map((kb) => ({ value: String(kb.id), label: masterName(kb) }))
})

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

// Competencies with no type assigned (drives the "Untyped" filter chip).
const untypedCompetencyCount = computed(
    () => props.competencies.filter((c) => c.competency_type_id == null).length,
)

/**
 * --------------------------------------------------------------------------
 * Competency table — search, sort, pagination (client-side)
 * --------------------------------------------------------------------------
 */

const competencySearch = ref('')

const competencyRows = computed(() => {
    const q = competencySearch.value.trim().toLowerCase()
    const typeFilter = selectedTypeFilter.value

    return props.competencies.filter((c) => {
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
})

type SortDir = 'asc' | 'desc'
interface SortState {
    key: string
    dir: SortDir
}
function nextSort(current: SortState | null, key: string): SortState | null {
    if (current?.key !== key) return { key, dir: 'asc' }
    if (current.dir === 'asc') return { key, dir: 'desc' }
    return null
}
function sortIcon(state: SortState | null, key: string): string {
    if (state?.key !== key) return 'fa-solid fa-sort text-slate-300'
    return state.dir === 'asc'
        ? 'fa-solid fa-sort-up text-primary'
        : 'fa-solid fa-sort-down text-primary'
}

const competencySort = ref<SortState | null>(null)
function toggleCompetencySort(key: string) {
    competencySort.value = nextSort(competencySort.value, key)
}

const sortedCompetencies = computed(() => {
    const s = competencySort.value
    if (!s) return competencyRows.value
    const dir = s.dir === 'asc' ? 1 : -1
    const val = (c: Competency) =>
        s.key === 'type'
            ? competencyTypeName(c.competency_type_id)
            : masterName(c)
    return [...competencyRows.value].sort(
        (a, b) => val(a).localeCompare(val(b)) * dir,
    )
})

const competencyPage = ref(1)
const competencyPerPage = ref(10)

const competencyTotalPages = computed(() =>
    Math.max(1, Math.ceil(competencyRows.value.length / competencyPerPage.value)),
)

const pagedCompetencies = computed(() => {
    const start = (competencyPage.value - 1) * competencyPerPage.value
    return sortedCompetencies.value.slice(start, start + competencyPerPage.value)
})

const competencyFrom = computed(() =>
    competencyRows.value.length === 0
        ? 0
        : (competencyPage.value - 1) * competencyPerPage.value + 1,
)

watch([competencySearch, selectedTypeFilter], () => (competencyPage.value = 1))
watch(competencyTotalPages, (total) => {
    if (competencyPage.value > total) competencyPage.value = total
})
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
            <section class="rounded-xl border border-border bg-white p-5 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="text-base font-semibold text-slate-800">
                            {{ t.idp.settings.competencyTypes }}
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

                <!-- Filter chips -->
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <!-- All -->
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            selectedTypeFilter === null
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                        @click="selectedTypeFilter = null"
                    >
                        {{ t.idp.settings.allTypes }}
                        <span
                            class="rounded-full px-1.5 text-xs"
                            :class="
                                selectedTypeFilter === null
                                    ? 'bg-white/20'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{ competencies.length }}
                        </span>
                    </button>

                    <!-- Each type (clickable to filter; edit/delete on hover) -->
                    <div
                        v-for="ct in competencyTypes"
                        :key="ct.id"
                        class="group inline-flex items-center rounded-full border transition"
                        :class="
                            selectedTypeFilter === ct.id
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-600 hover:bg-slate-50'
                        "
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 py-1.5 pl-3 pr-1 text-sm font-medium"
                            :title="typeDescription(ct) || masterName(ct)"
                            @click="
                                selectedTypeFilter =
                                    selectedTypeFilter === ct.id ? null : ct.id
                            "
                        >
                            <i class="fa-solid fa-tag text-[10px] opacity-70" />
                            {{ masterName(ct) }}
                            <span
                                class="rounded-full px-1.5 text-xs"
                                :class="
                                    selectedTypeFilter === ct.id
                                        ? 'bg-white/20'
                                        : 'bg-slate-100 text-slate-500'
                                "
                            >
                                {{ ct.competencies_count }}
                            </span>
                        </button>
                        <span
                            class="flex items-center gap-0.5 pr-1.5 opacity-60 transition group-hover:opacity-100"
                        >
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs transition hover:bg-black/10"
                                :title="t.idp.settings.editCompetencyType"
                                @click.stop="openMaster('competency_type', ct)"
                            >
                                <i class="fa-solid fa-pen" />
                            </button>
                            <button
                                type="button"
                                class="flex h-6 w-6 items-center justify-center rounded-full text-xs transition hover:bg-black/10"
                                :title="t.idp.settings.deleteCompetencyType"
                                @click.stop="deleteMaster(ct.id, masterName(ct))"
                            >
                                <i class="fa-solid fa-trash" />
                            </button>
                        </span>
                    </div>

                    <!-- Untyped bucket -->
                    <button
                        v-if="untypedCompetencyCount"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-full border border-dashed px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            selectedTypeFilter === 0
                                ? 'border-primary bg-primary text-white'
                                : 'border-border bg-white text-slate-500 hover:bg-slate-50'
                        "
                        @click="selectedTypeFilter = selectedTypeFilter === 0 ? null : 0"
                    >
                        {{ t.idp.settings.untyped }}
                        <span
                            class="rounded-full px-1.5 text-xs"
                            :class="
                                selectedTypeFilter === 0
                                    ? 'bg-white/20'
                                    : 'bg-slate-100 text-slate-500'
                            "
                        >
                            {{ untypedCompetencyCount }}
                        </span>
                    </button>

                    <span
                        v-if="!competencyTypes.length"
                        class="text-sm text-slate-400"
                    >
                        {{ t.idp.settings.noTypesYet }}
                    </span>
                </div>
            </section>

            <!-- ------------------------------------------------------------
                 Header: title · search · add competency
            ------------------------------------------------------------- -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-slate-800">
                        {{ t.idp.settings.competencies }}
                    </h3>
                    <p class="mt-0.5 text-sm text-slate-400">
                        {{ t.idp.settings.competenciesHint }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
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

            <!-- ------------------------------------------------------------
                 Competency table
            ------------------------------------------------------------- -->
            <div
                class="overflow-x-auto rounded-xl border border-border bg-white shadow-sm"
            >
                <table class="w-full min-w-[640px] border-collapse text-sm">
                    <thead>
                        <tr
                            class="border-b border-border bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500"
                        >
                            <th class="w-14 px-5 py-3 text-center">#</th>
                            <th class="w-72 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleCompetencySort('name')"
                                >
                                    {{ t.idp.settings.competency }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(competencySort, 'name')"
                                    />
                                </button>
                            </th>
                            <th class="w-48 px-5 py-3">
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1.5 uppercase tracking-wide transition hover:text-slate-700"
                                    @click="toggleCompetencySort('type')"
                                >
                                    {{ t.idp.settings.competencyType }}
                                    <i
                                        class="text-[10px]"
                                        :class="sortIcon(competencySort, 'type')"
                                    />
                                </button>
                            </th>
                            <th class="w-40 px-5 py-3">
                                {{ t.idp.settings.proficiencyLevel }}
                            </th>
                            <th class="px-5 py-3">
                                {{ t.idp.settings.description }}
                            </th>
                            <th
                                class="w-24 border-l border-border/60 px-5 py-3 text-center"
                            >
                                {{ t.idp.settings.action }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr
                            v-for="(c, i) in pagedCompetencies"
                            :key="c.id"
                            class="border-b border-border/60 align-top transition last:border-0 hover:bg-slate-50/60"
                        >
                            <td class="px-5 py-4 text-center text-slate-400">
                                {{ competencyFrom + i }}
                            </td>
                            <td class="px-5 py-4 font-semibold text-slate-800">
                                {{ masterName(c) }}
                            </td>
                            <td class="px-5 py-4">
                                <span
                                    v-if="competencyTypeName(c.competency_type_id)"
                                    class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                                >
                                    <i class="fa-solid fa-tag text-[9px]" />
                                    {{ competencyTypeName(c.competency_type_id) }}
                                </span>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.untyped }}
                                </span>
                            </td>
                            <td class="px-5 py-4">
                                <div
                                    v-if="proficiencyLevelName(c.proficiency_level_id)"
                                    class="flex flex-col items-start gap-1"
                                >
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                                    >
                                        <i class="fa-solid fa-signal text-[9px]" />
                                        {{ proficiencyLevelName(c.proficiency_level_id) }}
                                    </span>
                                    <span
                                        v-if="keyBehaviorName(c.key_behavior_id)"
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-600"
                                        :title="t.idp.settings.keyBehavior"
                                    >
                                        <i class="fa-solid fa-list-check text-[9px]" />
                                        {{ keyBehaviorName(c.key_behavior_id) }}
                                    </span>
                                </div>
                                <span v-else class="text-xs italic text-slate-300">
                                    —
                                </span>
                            </td>
                            <td class="px-5 py-4 text-slate-500">
                                <span
                                    v-if="competencyDescription(c)"
                                    class="whitespace-pre-wrap break-words"
                                >
                                    {{ competencyDescription(c) }}
                                </span>
                                <span v-else class="text-xs italic text-slate-300">
                                    {{ t.idp.settings.noDescription }}
                                </span>
                            </td>
                            <td
                                class="border-l border-border/60 px-5 py-4 text-center align-middle"
                            >
                                <div class="inline-flex items-center gap-1">
                                    <IconButton
                                        icon="fa-solid fa-pen"
                                        variant="edit"
                                        :title="t.idp.settings.editCompetency"
                                        @click="openMaster('competency_name', c)"
                                    />
                                    <IconButton
                                        icon="fa-solid fa-trash"
                                        variant="delete"
                                        :title="t.idp.settings.deleteCompetency"
                                        @click="deleteMaster(c.id, masterName(c))"
                                    />
                                </div>
                            </td>
                        </tr>

                        <tr v-if="competencyRows.length === 0">
                            <td
                                colspan="6"
                                class="px-5 py-10 text-center text-sm text-slate-400"
                            >
                                {{
                                    competencySearch || selectedTypeFilter !== null
                                        ? t.idp.settings.noMatch
                                        : t.idp.settings.none
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <Pagination
                :page="competencyPage"
                :per-page="competencyPerPage"
                :total="competencyRows.length"
                @update:page="competencyPage = $event"
                @update:per-page="competencyPerPage = $event; competencyPage = 1"
            />
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

                    <!-- Proficiency level select (level or behavior mode) -->
                    <div v-if="proficiencyMode !== 'none'">
                        <label
                            class="mb-1.5 block text-xs font-medium text-slate-500"
                        >
                            {{ t.idp.settings.proficiencyLevel }}
                        </label>

                        <SearchableSelect
                            :model-value="
                                masterForm.proficiency_level_id == null
                                    ? ''
                                    : String(masterForm.proficiency_level_id)
                            "
                            :options="proficiencyLevelOptions"
                            :placeholder="t.idp.settings.proficiencyLevelPickHint"
                            @update:model-value="
                                masterForm.proficiency_level_id =
                                    $event === '' ? null : Number($event)
                            "
                        />
                        <p
                            v-if="masterForm.errors.proficiency_level_id"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ masterForm.errors.proficiency_level_id }}
                        </p>
                    </div>

                    <!-- Key behavior select (behavior mode) -->
                    <div v-if="proficiencyMode === 'behavior'">
                        <label
                            class="mb-1.5 block text-xs font-medium text-slate-500"
                        >
                            {{ t.idp.settings.keyBehavior }}
                        </label>

                        <SearchableSelect
                            v-if="
                                masterForm.proficiency_level_id != null &&
                                keyBehaviorOptions.length > 0
                            "
                            :model-value="
                                masterForm.key_behavior_id == null
                                    ? ''
                                    : String(masterForm.key_behavior_id)
                            "
                            :options="keyBehaviorOptions"
                            :placeholder="t.idp.settings.keyBehaviorPickHint"
                            @update:model-value="
                                masterForm.key_behavior_id =
                                    $event === '' ? null : Number($event)
                            "
                        />
                        <p
                            v-else
                            class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                        >
                            {{
                                masterForm.proficiency_level_id == null
                                    ? t.idp.settings.pickLevelFirst
                                    : t.idp.settings.noKeyBehaviorsForLevel
                            }}
                        </p>
                        <p
                            v-if="masterForm.errors.key_behavior_id"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ masterForm.errors.key_behavior_id }}
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
