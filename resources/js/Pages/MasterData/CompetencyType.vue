<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface CompetencyType {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    // How many competencies are filed under this type — the server refuses to
    // delete a type that still has any, so the count explains why.
    competencies_count: number
    // The corporate business units this type applies to, as raw kpncorp names.
    business_units: string[]
}

const props = defineProps<{
    competencyTypes: CompetencyType[]
    // Every business unit the corporate master knows about, whether or not
    // anyone is filed under it yet (kpncorp `master_bisnisunits`).
    businessUnits: string[]
}>()

/**
 * After a create/update/delete the server redirects back here. Restricting the
 * reload to this page's own data (+ flash) turns every save into an Inertia
 * partial reload, so the expensive shared props (corporate employee lookup,
 * approval counts, notification feed) are not re-evaluated on each mutation.
 */
const reloadOnly = ['competencyTypes', 'flash']

// Localized name for a competency type, falling back to the canonical `value`.
function masterName(item: {
    value: string
    value_en?: string | null
    value_id?: string | null
}): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized description (falls back to the other language).
function typeDescription(ct: CompetencyType): string {
    const preferred = locale.value === 'id' ? ct.description_id : ct.description_en
    const fallback = locale.value === 'id' ? ct.description_en : ct.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

/**
 * --------------------------------------------------------------------------
 * Form
 * --------------------------------------------------------------------------
 * Competency types are one kind of IDP master, so they write through the
 * shared /idp-setting/masters endpoints with `type=competency_type`. A type is
 * never switched off (only the competencies under it are), so there is no
 * active-state field here.
 */

const MASTER_TYPE = 'competency_type'

const masterModal = ref(false)
const editingMasterId = ref<number | null>(null)

const masterForm = useForm({
    type: MASTER_TYPE,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
    // The business units the type applies to. Raw corporate names, replaced
    // wholesale on save — the same shape a training's units have.
    business_units: [] as string[],
})

const businessUnitOptions = computed<Option[]>(() =>
    (props.businessUnits ?? []).map((unit) => ({ value: unit, label: unit })),
)

/**
 * A unit the type already stores that the corporate master no longer lists.
 * Keeping it selectable means an unrelated edit never silently drops it; it is
 * flagged instead, the way an off-list master is elsewhere.
 */
const unknownUnits = computed<string[]>(() => {
    const known = new Set(props.businessUnits ?? [])
    return masterForm.business_units.filter((unit) => !known.has(unit))
})

function openMaster(item?: CompetencyType) {
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    masterForm.type = MASTER_TYPE
    masterForm.value_en = item?.value_en ?? item?.value ?? ''
    masterForm.value_id = item?.value_id ?? ''
    masterForm.description_en = item?.description_en ?? ''
    masterForm.description_id = item?.description_id ?? ''
    masterForm.business_units = [...(item?.business_units ?? [])]

    masterModal.value = true
}

function submitMaster() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (masterModal.value = false),
    }

    if (editingMasterId.value) {
        masterForm.put(
            `/idp-setting/masters/${MASTER_TYPE}/${editingMasterId.value}`,
            opts,
        )
    } else {
        masterForm.post('/idp-setting/masters', opts)
    }
}

const masterTitle = () => {
    const prefix = editingMasterId.value
        ? t.value.idp.settings.edit
        : t.value.idp.settings.add

    return `${prefix} ${t.value.idp.settings.competencyType}`
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
 * --------------------------------------------------------------------------
 */

const pendingDelete = ref<{ url: string; name?: string } | null>(null)
const deleting = ref(false)

function deleteMaster(id: number, name?: string) {
    pendingDelete.value = { url: `/idp-setting/masters/${MASTER_TYPE}/${id}`, name }
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

/**
 * --------------------------------------------------------------------------
 * Table — search (external) → ClientTable (sort + pages)
 * --------------------------------------------------------------------------
 */

const search = ref('')

// Rows carry the derived localized name + description ClientTable sorts on;
// the original fields remain (spread) so the cell slots keep working.
const typeRows = computed(() => {
    const q = search.value.trim().toLowerCase()

    return props.competencyTypes
        .filter((ct) => {
            if (!q) return true
            return (
                masterName(ct).toLowerCase().includes(q) ||
                ct.value.toLowerCase().includes(q) ||
                typeDescription(ct).toLowerCase().includes(q) ||
                (ct.business_units ?? []).some((unit) =>
                    unit.toLowerCase().includes(q),
                )
            )
        })
        .map((ct) => ({
            ...ct,
            _name: masterName(ct),
            _description: typeDescription(ct),
        }))
})

const typeColumns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.competencyType, sortable: true, sortKey: '_name', thClass: 'w-64' },
    { key: 'business_units', label: t.value.idp.settings.businessUnit, thClass: 'w-48' },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'competencies_count', label: t.value.idp.settings.competencies, sortable: true, align: 'center', thClass: 'w-32' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])
</script>

<template>
    <Head :title="t.idp.settings.competencyTypeTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.competencyTypeTitle"
            :subtitle="t.idp.settings.competencyTypeSubtitle"
        />

        <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
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

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative">
                        <i
                            class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"
                        />
                        <input
                            v-model="search"
                            type="search"
                            :placeholder="t.idp.settings.searchCompetencyType"
                            class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                        @click="openMaster()"
                    >
                        <i class="fa-solid fa-plus text-xs" />
                        {{ t.idp.settings.competencyType }}
                    </button>
                </div>
            </div>

            <ClientTable
                :columns="typeColumns"
                :rows="typeRows"
                row-key="id"
                :per-page="10"
                numbered
            >
                <template #cell-name="{ row }">
                    <span class="inline-flex items-center gap-1.5 font-semibold text-slate-800">
                        <i class="fa-solid fa-tag text-[10px] text-indigo-400" />
                        {{ row._name }}
                    </span>
                </template>

                <template #cell-business_units="{ row }">
                    <div v-if="row.business_units?.length" class="flex flex-wrap gap-1">
                        <span
                            v-for="(unit, i) in row.business_units"
                            :key="i"
                            class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        >
                            {{ unit }}
                        </span>
                    </div>
                    <span v-else class="text-xs italic text-slate-300">—</span>
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
                            @click="openMaster(row as unknown as CompetencyType)"
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
                    {{ search ? t.idp.settings.noMatch : t.idp.settings.noTypesYet }}
                </template>
            </ClientTable>
        </section>

        <!-- ================================================================
             FORM
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
                            <label class="mb-1 block text-xs font-medium text-slate-500">
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

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
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
                            <label class="mb-1 block text-xs font-medium text-slate-500">
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

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">
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

                <!-- Business units the type applies to. Required: a type with
                     no unit would apply nowhere. Options come from the
                     corporate master, so a unit is offered whether or not
                     anyone is filed under it yet. -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">
                        {{ t.idp.settings.businessUnit }}
                        <span class="text-red-500">*</span>
                    </label>

                    <MultiSelect
                        v-if="businessUnitOptions.length"
                        :model-value="masterForm.business_units"
                        :options="businessUnitOptions"
                        :placeholder="t.idp.settings.businessUnitsPickHint"
                        :invalid="!!masterForm.errors.business_units"
                        select-all
                        :select-all-label="t.idp.settings.selectAllBusinessUnits"
                        :clear-all-label="t.idp.settings.clearAllBusinessUnits"
                        @update:model-value="masterForm.business_units = $event"
                    />
                    <p
                        v-else
                        class="rounded-md border border-dashed border-border px-3 py-2 text-xs text-slate-400"
                    >
                        {{ t.idp.settings.noBusinessUnits }}
                    </p>

                    <!-- Units stored here that the corporate master no longer
                         lists. Kept so the pick isn't lost, but flagged. -->
                    <p
                        v-if="unknownUnits.length"
                        class="mt-1 text-xs font-medium text-amber-600"
                    >
                        <i class="fa-solid fa-triangle-exclamation mr-1" />
                        {{ t.idp.settings.unknownBusinessUnits }}: {{ unknownUnits.join(', ') }}
                    </p>

                    <p
                        v-if="masterForm.errors.business_units"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ masterForm.errors.business_units }}
                    </p>
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
