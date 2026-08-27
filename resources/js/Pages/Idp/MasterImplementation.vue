<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface Localized {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
}

interface CompetencyType extends Localized {
    competencies_count: number
}

interface Competency extends Localized {
    competency_type_id: number | null
    proficiency_level_id: number | null
}

type KeyBehavior = Localized

interface ProficiencyLevel extends Localized {
    key_behaviors: KeyBehavior[]
}

interface Implementation {
    id: number
    competency_type_id: number | null
    competency_name_id: number | null
    proficiency_level_id: number | null
    grade: string | null
    business_unit: string | null
    job_family: string | null
    function_name: string | null
    position: string | null
}

const props = defineProps<{
    implementations: Implementation[]
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    grades: string[]
    // Dynamic org-scope hierarchy: business unit → job family / function → position.
    businessUnits: string[]
    jobFamiliesByBu: Record<string, string[]>
    functionsByBu: Record<string, string[]>
    positionsByBuFunction: Record<string, Record<string, string[]>>
}>()

/**
 * After a mutation the server redirects back here; restrict the reload to this
 * page's own data (+ flash) so every save is a lightweight partial reload.
 */
const reloadOnly = ['implementations', 'flash']

// Localized name for a master row, falling back to the canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
} | null | undefined): string {
    if (!item) return ''
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

/**
 * --------------------------------------------------------------------------
 * Lookup maps (id → row) for resolving labels in the table
 * --------------------------------------------------------------------------
 */

const competencyTypeById = computed(() => {
    const m = new Map<number, CompetencyType>()
    for (const c of props.competencyTypes) m.set(c.id, c)
    return m
})

const competencyById = computed(() => {
    const m = new Map<number, Competency>()
    for (const c of props.competencies) m.set(c.id, c)
    return m
})

const proficiencyLevelById = computed(() => {
    const m = new Map<number, ProficiencyLevel>()
    for (const p of props.proficiencyLevels) m.set(p.id, p)
    return m
})

/**
 * --------------------------------------------------------------------------
 * Dropdown options (string lists → { value, label })
 * --------------------------------------------------------------------------
 */

const toStringOptions = (list: string[]): Option[] =>
    (list ?? []).map((v) => ({ value: v, label: v }))

const competencyTypeOptions = computed<Option[]>(() =>
    props.competencyTypes.map((c) => ({ value: String(c.id), label: masterName(c) })),
)

// Competencies filtered by the chosen competency type.
const competencyOptions = computed<Option[]>(() => {
    const typeId = implForm.competency_type_id
    if (typeId == null) return []
    return props.competencies
        .filter((c) => c.competency_type_id === typeId)
        .map((c) => ({ value: String(c.id), label: masterName(c) }))
})

const gradeOptions = computed<Option[]>(() => toStringOptions(props.grades))

// --- Cascading org hierarchy ---

const businessUnitOptions = computed<Option[]>(() => toStringOptions(props.businessUnits))

// Job families of the chosen business unit.
const jobFamilyOptions = computed<Option[]>(() => {
    const bu = implForm.business_unit
    if (!bu) return []
    return toStringOptions(props.jobFamiliesByBu[bu] ?? [])
})

// Functions (departments) of the chosen business unit.
const functionOptions = computed<Option[]>(() => {
    const bu = implForm.business_unit
    if (!bu) return []
    return toStringOptions(props.functionsByBu[bu] ?? [])
})

// Positions (designations) of the chosen business unit + function.
const positionOptions = computed<Option[]>(() => {
    const bu = implForm.business_unit
    const fn = implForm.function_name
    if (!bu || !fn) return []
    return toStringOptions(props.positionsByBuFunction[bu]?.[fn] ?? [])
})

/**
 * --------------------------------------------------------------------------
 * Implementation form (create / edit)
 * --------------------------------------------------------------------------
 */

const implModal = ref(false)
const editingImplId = ref<number | null>(null)

const implForm = useForm({
    competency_type_id: null as number | null,
    competency_name_id: null as number | null,
    proficiency_level_id: null as number | null,
    grade: '',
    business_unit: '',
    job_family: '',
    function_name: '',
    position: '',
})

// The competency currently chosen in the form (drives the proficiency panel).
const selectedCompetency = computed<Competency | null>(() =>
    implForm.competency_name_id == null
        ? null
        : competencyById.value.get(implForm.competency_name_id) ?? null,
)

// The proficiency level derived from the chosen competency (with key behaviors).
const derivedProficiency = computed<ProficiencyLevel | null>(() => {
    const c = selectedCompetency.value
    if (!c || c.proficiency_level_id == null) return null
    return proficiencyLevelById.value.get(c.proficiency_level_id) ?? null
})

// Keep proficiency_level_id in step with the chosen competency (it owns one).
watch(selectedCompetency, (c) => {
    implForm.proficiency_level_id = c?.proficiency_level_id ?? null
})

// Changing the competency type drops a competency that no longer belongs to it.
watch(
    () => implForm.competency_type_id,
    (typeId) => {
        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            implForm.competency_name_id = null
        }
    },
)

// Changing the business unit invalidates every child in the hierarchy.
watch(
    () => implForm.business_unit,
    () => {
        implForm.job_family = ''
        implForm.function_name = ''
        implForm.position = ''
    },
)

// Changing the function invalidates the position.
watch(
    () => implForm.function_name,
    () => {
        implForm.position = ''
    },
)

function openImpl(item?: Implementation) {
    editingImplId.value = item?.id ?? null
    implForm.clearErrors()

    // Assign the parents before the children so the cascade watchers don't wipe
    // the child values we're restoring on edit. Vue flushes watchers after this
    // synchronous block, so the final child assignments below win.
    implForm.competency_type_id = item?.competency_type_id ?? null
    implForm.competency_name_id = item?.competency_name_id ?? null
    implForm.proficiency_level_id = item?.proficiency_level_id ?? null
    implForm.grade = item?.grade ?? ''
    implForm.business_unit = item?.business_unit ?? ''
    implForm.job_family = item?.job_family ?? ''
    implForm.function_name = item?.function_name ?? ''
    implForm.position = item?.position ?? ''

    implModal.value = true
}

function submitImpl() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (implModal.value = false),
    }

    if (editingImplId.value) {
        implForm.put(`/idp-setting/implementations/${editingImplId.value}`, opts)
    } else {
        implForm.post('/idp-setting/implementations', opts)
    }
}

const implTitle = computed(() =>
    editingImplId.value
        ? t.value.idp.settings.editImplementation
        : t.value.idp.settings.addImplementation,
)

/**
 * --------------------------------------------------------------------------
 * Implementation table — search → ClientTable (sort + pagination)
 * --------------------------------------------------------------------------
 */

const implSearch = ref('')

const implRows = computed(() => {
    const q = implSearch.value.trim().toLowerCase()

    return props.implementations
        .map((row) => {
            const competency = row.competency_name_id != null
                ? competencyById.value.get(row.competency_name_id)
                : null
            const type = row.competency_type_id != null
                ? competencyTypeById.value.get(row.competency_type_id)
                : null
            const level = row.proficiency_level_id != null
                ? proficiencyLevelById.value.get(row.proficiency_level_id)
                : null

            return {
                ...row,
                competency_name: masterName(competency),
                type_name: masterName(type),
                proficiency_name: masterName(level),
            }
        })
        .filter((row) => {
            if (!q) return true
            return [
                row.competency_name,
                row.type_name,
                row.proficiency_name,
                row.grade ?? '',
                row.business_unit ?? '',
                row.job_family ?? '',
                row.function_name ?? '',
                row.position ?? '',
            ].some((v) => v.toLowerCase().includes(q))
        })
})

const implColumns = computed<Column[]>(() => [
    { key: 'competency_name', label: t.value.idp.settings.competency, sortable: true, thClass: 'w-56' },
    { key: 'proficiency_name', label: t.value.idp.settings.proficiencyLevel, thClass: 'w-40' },
    { key: 'grade', label: t.value.idp.settings.grade, sortable: true, thClass: 'w-28' },
    { key: 'business_unit', label: t.value.idp.settings.businessUnit, sortable: true, thClass: 'w-40' },
    { key: 'job_family', label: t.value.idp.settings.jobFamily, sortable: true, thClass: 'w-40' },
    { key: 'function_name', label: t.value.idp.settings.functionLabel, sortable: true, thClass: 'w-40' },
    { key: 'position', label: t.value.idp.settings.position, sortable: true, thClass: 'w-40' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteImpl(row: { id: number; competency_name: string }) {
    pendingDelete.value = {
        url: `/idp-setting/implementations/${row.id}`,
        name: row.competency_name,
    }
}

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
</script>

<template>
    <Head :title="t.idp.settings.implementationTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.implementationTitle"
            :subtitle="t.idp.settings.implementationSubtitle"
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Implementations · header + toolbar + table
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.implementations }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ implementations.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.implementationsHint }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <div class="relative">
                            <i
                                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                            />
                            <input
                                v-model="implSearch"
                                type="search"
                                :placeholder="t.idp.settings.searchImplementation"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openImpl()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.addImplementation }}
                        </button>
                    </div>
                </div>

                <ClientTable
                    :columns="implColumns"
                    :rows="implRows"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-competency_name="{ row }">
                        <div class="flex flex-col items-start gap-1">
                            <span class="font-semibold text-slate-800">{{ row.competency_name || '—' }}</span>
                            <span
                                v-if="row.type_name"
                                class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-600"
                            >
                                <i class="fa-solid fa-tag text-[9px]" />
                                {{ row.type_name }}
                            </span>
                        </div>
                    </template>

                    <template #cell-proficiency_name="{ row }">
                        <span
                            v-if="row.proficiency_name"
                            class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                        >
                            <i class="fa-solid fa-signal text-[9px]" />
                            {{ row.proficiency_name }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-grade="{ row }">
                        <span v-if="row.grade" class="text-slate-600">{{ row.grade }}</span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-business_unit="{ row }">
                        <span v-if="row.business_unit" class="text-slate-600">{{ row.business_unit }}</span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-job_family="{ row }">
                        <span v-if="row.job_family" class="text-slate-600">{{ row.job_family }}</span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-function_name="{ row }">
                        <span v-if="row.function_name" class="text-slate-600">{{ row.function_name }}</span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-position="{ row }">
                        <span v-if="row.position" class="text-slate-600">{{ row.position }}</span>
                        <span v-else class="text-xs italic text-slate-300">—</span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <IconButton
                                icon="fa-solid fa-pen"
                                variant="edit"
                                :title="t.idp.settings.editImplementation"
                                @click="openImpl(row as unknown as Implementation)"
                            />
                            <IconButton
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :title="t.idp.settings.deleteImplementation"
                                @click="deleteImpl(row as unknown as { id: number; competency_name: string })"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{ implSearch ? t.idp.settings.noImplementationsMatch : t.idp.settings.noImplementations }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             IMPLEMENTATION MODAL
        ================================================================= -->

        <Drawer :show="implModal" :title="implTitle" @close="implModal = false">
            <form id="impl-form" class="space-y-4" @submit.prevent="submitImpl">
                <!-- Competency type -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competencyType }}
                    </label>
                    <SearchableSelect
                        :model-value="implForm.competency_type_id == null ? '' : String(implForm.competency_type_id)"
                        :options="competencyTypeOptions"
                        :placeholder="t.idp.settings.competencyTypePickHint"
                        :invalid="!!implForm.errors.competency_type_id"
                        @update:model-value="implForm.competency_type_id = $event === '' ? null : Number($event)"
                    />
                    <p v-if="implForm.errors.competency_type_id" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.competency_type_id }}
                    </p>
                </div>

                <!-- Competency name (filtered by type) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.competency }}
                    </label>
                    <SearchableSelect
                        v-if="implForm.competency_type_id != null && competencyOptions.length > 0"
                        :model-value="implForm.competency_name_id == null ? '' : String(implForm.competency_name_id)"
                        :options="competencyOptions"
                        :placeholder="t.idp.settings.competencyPickHint"
                        :invalid="!!implForm.errors.competency_name_id"
                        @update:model-value="implForm.competency_name_id = $event === '' ? null : Number($event)"
                    />
                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            implForm.competency_type_id == null
                                ? t.idp.settings.pickTypeFirst
                                : t.idp.settings.noCompetenciesForType
                        }}
                    </p>
                    <p v-if="implForm.errors.competency_name_id" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.competency_name_id }}
                    </p>
                </div>

                <!-- Proficiency level (derived from the chosen competency) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.proficiencyLevel }}
                    </label>

                    <div
                        v-if="derivedProficiency"
                        class="rounded-lg border border-border bg-slate-50/60 p-3"
                    >
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-600"
                            >
                                <i class="fa-solid fa-signal text-[9px]" />
                                {{ masterName(derivedProficiency) }}
                            </span>
                        </div>

                        <div v-if="derivedProficiency.key_behaviors.length" class="mt-2">
                            <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                {{ t.idp.settings.keyBehaviorsOfLevel }}
                            </p>
                            <ul class="space-y-1">
                                <li
                                    v-for="kb in derivedProficiency.key_behaviors"
                                    :key="kb.id"
                                    class="flex items-start gap-1.5 text-xs text-slate-600"
                                >
                                    <i class="fa-solid fa-list-check mt-0.5 text-[9px] text-amber-500" />
                                    {{ masterName(kb) }}
                                </li>
                            </ul>
                        </div>
                    </div>

                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{
                            selectedCompetency
                                ? t.idp.settings.noProficiencyForCompetency
                                : t.idp.settings.proficiencyFromCompetency
                        }}
                    </p>
                </div>

                <!-- Grade -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.grade }}
                    </label>
                    <SearchableSelect
                        :model-value="implForm.grade"
                        :options="gradeOptions"
                        :placeholder="t.idp.settings.gradePickHint"
                        @update:model-value="implForm.grade = $event"
                    />
                </div>

                <hr class="border-border/60">

                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                    {{ t.idp.settings.orgScope }}
                </p>

                <!-- Business unit (top of the hierarchy) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.businessUnit }}
                    </label>
                    <SearchableSelect
                        :model-value="implForm.business_unit"
                        :options="businessUnitOptions"
                        :placeholder="t.idp.settings.businessUnitPickHint"
                        @update:model-value="implForm.business_unit = $event"
                    />
                </div>

                <!-- Job family (scoped to business unit) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.jobFamily }}
                    </label>
                    <SearchableSelect
                        v-if="implForm.business_unit && jobFamilyOptions.length"
                        :model-value="implForm.job_family"
                        :options="jobFamilyOptions"
                        :placeholder="t.idp.settings.jobFamilyPickHint"
                        @update:model-value="implForm.job_family = $event"
                    />
                    <p v-else class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400">
                        {{ implForm.business_unit ? t.idp.settings.noneForBu : t.idp.settings.pickBusinessUnitFirst }}
                    </p>
                </div>

                <!-- Function / department (scoped to business unit) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.functionLabel }}
                    </label>
                    <SearchableSelect
                        v-if="implForm.business_unit && functionOptions.length"
                        :model-value="implForm.function_name"
                        :options="functionOptions"
                        :placeholder="t.idp.settings.functionPickHint"
                        @update:model-value="implForm.function_name = $event"
                    />
                    <p v-else class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400">
                        {{ implForm.business_unit ? t.idp.settings.noneForBu : t.idp.settings.pickBusinessUnitFirst }}
                    </p>
                </div>

                <!-- Position / designation (scoped to business unit + function) -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.position }}
                    </label>
                    <SearchableSelect
                        v-if="implForm.function_name && positionOptions.length"
                        :model-value="implForm.position"
                        :options="positionOptions"
                        :placeholder="t.idp.settings.positionPickHint"
                        @update:model-value="implForm.position = $event"
                    />
                    <p v-else class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400">
                        {{ implForm.function_name ? t.idp.settings.noneForFunction : t.idp.settings.pickFunctionFirst }}
                    </p>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="implModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="impl-form"
                    :disabled="implForm.processing"
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
