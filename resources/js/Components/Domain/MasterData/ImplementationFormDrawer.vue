<script setup lang="ts">
/**
 * The add/edit drawer of the Master Implementation screen
 * (`Pages/MasterData/MasterImplementation.vue`). The page owns the data and
 * decides what a save reloads (`reloadOnly`); this owns the form, its cascade
 * watchers and the `loadingForm` guard, so their timing relative to the
 * synchronous seeding in `open()` is exactly what it was on the page.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'

import Drawer from '@/Components/Domain/Drawer.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import FormSection from '@/Components/UI/FormSection.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import MultiSelect from '@/Components/UI/MultiSelect.vue'
import ActiveStateField from '@/Components/Domain/ActiveStateField.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'
import { useMasterLabels } from '@/Composables/useMasterLabels'
import type {
    ImplCompetency as Competency,
    ImplCompetencyType as CompetencyType,
    ImplProficiencyLevel as ProficiencyLevel,
    Implementation,
} from '@/types/masterImplementation'

const { t } = useLocale()
const { masterName, rowDescription } = useMasterLabels()

const props = defineProps<{
    competencyTypes: CompetencyType[]
    competencies: Competency[]
    proficiencyLevels: ProficiencyLevel[]
    grades: string[]
    businessUnits: string[]
    // What a save reloads — the page's call, not the drawer's.
    reloadOnly: string[]
}>()

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

/**
 * An implementation maps masters that must be usable now, so only active ones
 * are offered: a deactivated competency or proficiency level would produce a
 * mapping that applies to nobody. The server enforces the same rule on save.
 */

// Competencies filtered by the chosen competency type, minus the inactive ones.
const competencyOptions = computed<Option[]>(() => {
    const typeId = implForm.competency_type_id
    if (typeId == null) return []

    const options = props.competencies
        .filter((c) => c.competency_type_id === typeId && c.is_active)
        .map((c) => ({ value: String(c.id), label: masterName(c) }))

    // A competency saved earlier that has since been switched off keeps its
    // place, so an edit to some other field doesn't blank the select and lose
    // the mapping.
    const current = selectedCompetency.value
    if (current && !current.is_active) {
        options.unshift({ value: String(current.id), label: masterName(current) })
    }

    return options
})

const competencyInactive = computed(
    () => !!selectedCompetency.value && !selectedCompetency.value.is_active,
)

const gradeOptions = computed<Option[]>(() => toStringOptions(props.grades))

// --- Cascading org hierarchy ---

const businessUnitOptions = computed<Option[]>(() => toStringOptions(props.businessUnits))

/**
 * --------------------------------------------------------------------------
 * Implementation form (create / edit)
 * --------------------------------------------------------------------------
 */

const implModal = ref(false)
const editingImplId = ref<number | null>(null)
// Suppresses the cascade watchers while a row is being loaded into the form.
const loadingForm = ref(false)

function blankImpl() {
    return {
        competency_type_id: null as number | null,
        competency_id: null as number | null,
        // MultiSelect binds string[]; converted to ints server-side.
        proficiency_level_ids: [] as string[],
        grades: [] as string[],
        business_units: [] as string[],
        // A new mapping applies straight away.
        is_active: true,
        job_family: '',
        function_name: '',
        position: '',
    }
}

const implForm = useForm(blankImpl())

// The competency currently chosen in the form (scopes the proficiency options).
const selectedCompetency = computed<Competency | null>(() =>
    implForm.competency_id == null
        ? null
        : competencyById.value.get(implForm.competency_id) ?? null,
)

// The active levels the chosen competency offers.
const proficiencyOptions = computed<Option[]>(() => {
    const c = selectedCompetency.value
    if (!c) return []

    const pinned = new Set(implForm.proficiency_level_ids)

    return c.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(id))
        .filter((p): p is ProficiencyLevel => !!p)
        // Levels already stored on this implementation stay listed even once
        // switched off; dropping them would silently unpin them on the next
        // save. They are flagged below instead.
        .filter((p) => p.is_active || pinned.has(String(p.id)))
        // The description says what the rung means, which is the only thing
        // telling PL1 from PL2 apart on a form.
        .map((p) => ({
            value: String(p.id),
            label: masterName(p),
            description: rowDescription(p) || undefined,
        }))
})

// Pinned levels that have since been switched off.
const inactivePinnedLevelNames = computed(() =>
    implForm.proficiency_level_ids
        .map((id) => proficiencyLevelById.value.get(Number(id)))
        .filter((p): p is ProficiencyLevel => !!p && !p.is_active)
        .map((p) => masterName(p)),
)

// Drop any pinned proficiency level the newly chosen competency doesn't offer.
// Suppressed while a row is being loaded — the options list deliberately keeps
// what the row already stores, so the load must not unpin it either.
watch(selectedCompetency, (c) => {
    if (loadingForm.value) return

    const valid = new Set((c?.proficiency_level_ids ?? []).map(String))
    implForm.proficiency_level_ids = implForm.proficiency_level_ids.filter((id) =>
        valid.has(id),
    )
})

// Changing the competency type drops a competency that no longer belongs to it.
// Suppressed while loading, so a stored pair that has since drifted apart is
// neither blanked nor left marking the form dirty.
watch(
    () => implForm.competency_type_id,
    (typeId) => {
        if (loadingForm.value) return

        const c = selectedCompetency.value
        if (c && c.competency_type_id !== typeId) {
            implForm.competency_id = null
        }
    },
)

// Changing the business units invalidates every child in the hierarchy — but
// not while a row is being loaded into the form, which would wipe the very
// values being restored (watchers flush after the form is seeded in openImpl).
watch(
    () => implForm.business_units,
    () => {
        if (loadingForm.value) return

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

    // Suppress the cascade watchers for the duration of the load, so restoring
    // a row never clears its own children.
    loadingForm.value = true

    // Seeded as both data and defaults, so `isDirty` — which drives the
    // discard prompt — measures this sitting's edits (see `seedForm`).
    seedForm(implForm, {
        ...blankImpl(),
        competency_type_id: item?.competency_type_id ?? null,
        competency_id: item?.competency_id ?? null,
        proficiency_level_ids: (item?.proficiency_level_ids ?? []).map(String),
        grades: [...(item?.grades ?? [])],
        business_units: [...(item?.business_units ?? [])],
        is_active: item?.is_active ?? true,
        job_family: item?.job_family ?? '',
        function_name: item?.function_name ?? '',
        position: item?.position ?? '',
    })

    implModal.value = true

    // Release once the watchers the seeding queued have flushed.
    nextTick(() => (loadingForm.value = false))
}

function closeImpl() {
    implModal.value = false
    seedForm(implForm, blankImpl())
}

// Closing the drawer throws the draft away, so confirm first when there is
// something to lose. Backdrop click, Escape and Cancel all route through here.
const { confirming, requestClose, discard } = useUnsavedGuard(implForm, closeImpl)

function submitImpl() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: props.reloadOnly,
        onSuccess: () => closeImpl(),
    }

    if (editingImplId.value) {
        implForm.put(route('idp.setting.implementations.update', editingImplId.value), opts)
    } else {
        implForm.post(route('idp.setting.implementations.store'), opts)
    }
}

const implTitle = computed(() =>
    editingImplId.value
        ? t.value.idp.settings.editImplementation
        : t.value.idp.settings.addImplementation,
)

/**
 * The form is a cascade (competency type -> competency -> level/grades), so the
 * scope section reports whether its two required fields are settled — the step
 * badge turns into a check. Org scope and status are always satisfiable, so
 * they carry a plain step number.
 */
const scopeComplete = computed(
    () => implForm.competency_type_id != null && implForm.competency_id != null,
)

defineExpose({ open: openImpl })
</script>

<template>
    <Drawer
        :show="implModal"
        :title="implTitle"
        max-width="max-w-3xl"
        @close="requestClose"
    >
        <form id="impl-form" class="space-y-4" @submit.prevent="submitImpl">
            <!-- ========================================================
                 1. Scope — which competency, at which proficiency
            ========================================================= -->
            <FormSection
                :step="1"
                :title="t.idp.settings.scope"
                icon="fa-solid fa-bullseye"
                :complete="scopeComplete"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <!-- Competency type (scopes everything below it) -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.competencyType }}
                            <span class="text-red-500">*</span>
                        </label>

                        <SearchableSelect
                            :model-value="
                                implForm.competency_type_id == null
                                    ? ''
                                    : String(implForm.competency_type_id)
                            "
                            :options="competencyTypeOptions"
                            :placeholder="t.idp.settings.selectCompetencyType"
                            :invalid="!!implForm.errors.competency_type_id"
                            @update:model-value="
                                implForm.competency_type_id =
                                    $event === '' ? null : Number($event)
                            "
                        />
                        <p
                            v-if="implForm.errors.competency_type_id"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ implForm.errors.competency_type_id }}
                        </p>
                    </div>

                    <!-- Competency (of that type, active only) -->
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.competency }}
                            <span class="text-red-500">*</span>
                        </label>

                        <SearchableSelect
                            v-if="implForm.competency_type_id != null && competencyOptions.length"
                            :model-value="
                                implForm.competency_id == null
                                    ? ''
                                    : String(implForm.competency_id)
                            "
                            :options="competencyOptions"
                            :placeholder="t.idp.settings.competencyPickHint"
                            :invalid="!!implForm.errors.competency_id"
                            @update:model-value="
                                implForm.competency_id = $event === '' ? null : Number($event)
                            "
                        />
                        <p
                            v-else
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i
                                class="mt-0.5 text-[10px] text-slate-300"
                                :class="
                                    implForm.competency_type_id == null
                                        ? 'fa-solid fa-lock'
                                        : 'fa-solid fa-circle-info'
                                "
                            />
                            <span>
                                {{
                                    implForm.competency_type_id == null
                                        ? t.idp.settings.pickTypeFirst
                                        : t.idp.settings.noCompetenciesForType
                                }}
                            </span>
                        </p>

                        <p v-if="implForm.errors.competency_id" class="mt-1 text-xs text-red-600">
                            {{ implForm.errors.competency_id }}
                        </p>

                        <!-- A competency saved before it was switched off. Kept so
                             the mapping isn't lost, but it can't stay as it is. -->
                        <p
                            v-if="competencyInactive"
                            class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                            <span>{{ t.idp.settings.competencyInactiveForImplementation }}</span>
                        </p>
                    </div>

                    <!-- Proficiency levels the competency offers -->
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.proficiencyLevel }}
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>

                        <MultiSelect
                            v-if="selectedCompetency && proficiencyOptions.length"
                            :model-value="implForm.proficiency_level_ids"
                            :options="proficiencyOptions"
                            :placeholder="t.idp.settings.proficiencyLevelPickHint"
                            :invalid="!!implForm.errors.proficiency_level_ids"
                            select-all
                            :select-all-label="t.idp.settings.selectAllLevels"
                            :clear-all-label="t.idp.settings.clearAllLevels"
                            @update:model-value="implForm.proficiency_level_ids = $event"
                        />
                        <p
                            v-else
                            class="flex items-start gap-2 rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                        >
                            <i
                                class="mt-0.5 text-[10px] text-slate-300"
                                :class="
                                    selectedCompetency
                                        ? 'fa-solid fa-circle-info'
                                        : 'fa-solid fa-lock'
                                "
                            />
                            <span>
                                {{
                                    !selectedCompetency
                                        ? t.idp.settings.pickCompetencyFirst
                                        : selectedCompetency.proficiency_level_ids.length > 0
                                            ? t.idp.settings.noActiveProficiencyForCompetency
                                            : t.idp.settings.noProficiencyForCompetency
                                }}
                            </span>
                        </p>

                        <p
                            v-if="implForm.errors.proficiency_level_ids"
                            class="mt-1 text-xs text-red-600"
                        >
                            {{ implForm.errors.proficiency_level_ids }}
                        </p>

                        <!-- Levels pinned earlier that have since been switched off. -->
                        <p
                            v-if="inactivePinnedLevelNames.length"
                            class="mt-1.5 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-700"
                        >
                            <i class="fa-solid fa-triangle-exclamation mt-0.5 text-[10px]" />
                            <span>
                                {{ t.idp.settings.inactiveLevelsPinned }}
                                {{ inactivePinnedLevelNames.join(', ') }}
                            </span>
                        </p>
                    </div>

                    <!-- Grades (empty means every grade) -->
                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-slate-700">
                            {{ t.idp.settings.grade }}
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>

                        <MultiSelect
                            :model-value="implForm.grades"
                            :options="gradeOptions"
                            :placeholder="t.idp.settings.gradePickHint"
                            :invalid="!!implForm.errors.grades"
                            select-all
                            :select-all-label="t.idp.settings.selectAllGrades"
                            :clear-all-label="t.idp.settings.clearAllGrades"
                            @update:model-value="implForm.grades = $event"
                        />
                        <p v-if="implForm.errors.grades" class="mt-1 text-xs text-red-600">
                            {{ implForm.errors.grades }}
                        </p>
                    </div>
                </div>
            </FormSection>

            <!-- ========================================================
                 2. Organization scope — who the mapping applies to
            ========================================================= -->
            <FormSection
                :step="2"
                :title="t.idp.settings.orgScope"
                icon="fa-solid fa-building"
            >
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.businessUnit }}
                        <span class="font-normal text-slate-400">
                            ({{ t.idp.settings.optional }})
                        </span>
                    </label>

                    <MultiSelect
                        :model-value="implForm.business_units"
                        :options="businessUnitOptions"
                        :placeholder="t.idp.settings.businessUnitsPickHint"
                        :invalid="!!implForm.errors.business_units"
                        select-all
                        :select-all-label="t.idp.settings.selectAllBusinessUnits"
                        :clear-all-label="t.idp.settings.clearAllBusinessUnits"
                        @update:model-value="implForm.business_units = $event"
                    />
                    <p v-if="implForm.errors.business_units" class="mt-1 text-xs text-red-600">
                        {{ implForm.errors.business_units }}
                    </p>
                </div>
            </FormSection>

            <!-- ========================================================
                 3. Status — applies from now on, or retired
            ========================================================= -->
            <FormSection
                :step="3"
                :title="t.idp.settings.status"
                icon="fa-solid fa-toggle-on"
            >
                <ActiveStateField
                    v-model="implForm.is_active"
                    :error="implForm.errors.is_active"
                />
            </FormSection>
        </form>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                @click="requestClose"
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
         UNSAVED-CHANGES CONFIRMATION
    ================================================================= -->

    <UnsavedChangesDialog
        :show="confirming"
        @confirm="discard"
        @close="confirming = false"
    />
</template>
