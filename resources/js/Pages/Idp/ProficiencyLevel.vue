<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import ConfirmDialog from '@/Components/Domain/ConfirmDialog.vue'
import IconButton from '@/Components/UI/IconButton.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import { useLocale } from '@/Composables/useLocale'

const { t, locale } = useLocale()

interface KeyBehavior {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    proficiency_level_id: number
}

interface ProficiencyLevel {
    id: number
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
    key_behaviors: KeyBehavior[]
}

// Shared shape for the localized name/description helpers below.
type LocalizedItem = {
    value: string
    value_en: string | null
    value_id: string | null
    description_en: string | null
    description_id: string | null
}

const props = defineProps<{
    proficiencyLevels: ProficiencyLevel[]
}>()

/**
 * After a create/update/delete the server redirects back here. Restricting the
 * reload to this page's own data (+ flash) turns every save into an Inertia
 * partial reload, so the expensive shared props (corporate employee lookup,
 * approval counts, notification feed) are not re-evaluated on each mutation.
 */
const reloadOnly = ['proficiencyLevels', 'flash']

// Localized name, falling back to the canonical `value`.
function levelName(item: LocalizedItem): string {
    const preferred = locale.value === 'id' ? item.value_id : item.value_en
    return (preferred ?? '').trim() !== '' ? (preferred as string) : item.value
}

// Localized description (falls back to the other language).
function levelDescription(item: LocalizedItem): string {
    const preferred = locale.value === 'id' ? item.description_id : item.description_en
    const fallback = locale.value === 'id' ? item.description_en : item.description_id
    return (preferred ?? '').trim() !== ''
        ? (preferred as string)
        : (fallback ?? '')
}

/**
 * --------------------------------------------------------------------------
 * Add / edit form
 * --------------------------------------------------------------------------
 */

const modal = ref(false)
const editingId = ref<number | null>(null)

const form = useForm({
    type: 'proficiency_level',
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
})

function openForm(item?: ProficiencyLevel) {
    editingId.value = item?.id ?? null

    form.clearErrors()
    form.value_en = item?.value_en ?? item?.value ?? ''
    form.value_id = item?.value_id ?? ''
    form.description_en = item?.description_en ?? ''
    form.description_id = item?.description_id ?? ''

    modal.value = true
}

function submitForm() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => (modal.value = false),
    }

    if (editingId.value) {
        form.put(`/idp-setting/masters/proficiency_level/${editingId.value}`, opts)
    } else {
        form.post('/idp-setting/masters', opts)
    }
}

function deleteLevel(item: ProficiencyLevel) {
    pendingDelete.value = {
        url: `/idp-setting/masters/proficiency_level/${item.id}`,
        name: levelName(item),
    }
}

/**
 * --------------------------------------------------------------------------
 * Key behaviors (nested under a proficiency level)
 * --------------------------------------------------------------------------
 * Each proficiency level owns a list of key behaviors (name + bilingual
 * description). They are `key_behavior` master rows keyed back to the level via
 * proficiency_level_id, so they save through the same master-data endpoints.
 */

const kbDrawer = ref(false)
const kbLevelId = ref<number | null>(null)

// The currently managed level, resolved from props so it stays fresh after an
// Inertia partial reload updates the nested key_behaviors.
const kbLevel = computed(
    () => props.proficiencyLevels.find((l) => l.id === kbLevelId.value) ?? null,
)

function openKeyBehaviors(level: ProficiencyLevel) {
    kbLevelId.value = level.id
    kbFormOpen.value = false
    kbEditingId.value = null
    kbDrawer.value = true
}

const kbFormOpen = ref(false)
const kbEditingId = ref<number | null>(null)

const kbForm = useForm({
    type: 'key_behavior',
    proficiency_level_id: 0,
    // Canonical `value` tracks the English name (value_en) server-side.
    value_en: '',
    value_id: '',
    description_en: '',
    description_id: '',
})

function openKbForm(item?: KeyBehavior) {
    kbEditingId.value = item?.id ?? null

    kbForm.clearErrors()
    kbForm.proficiency_level_id = kbLevelId.value ?? 0
    kbForm.value_en = item?.value_en ?? item?.value ?? ''
    kbForm.value_id = item?.value_id ?? ''
    kbForm.description_en = item?.description_en ?? ''
    kbForm.description_id = item?.description_id ?? ''

    kbFormOpen.value = true
}

function submitKbForm() {
    const opts = {
        preserveScroll: true,
        preserveState: true,
        only: reloadOnly,
        onSuccess: () => {
            kbFormOpen.value = false
            kbEditingId.value = null
        },
    }

    if (kbEditingId.value) {
        kbForm.put(`/idp-setting/masters/key_behavior/${kbEditingId.value}`, opts)
    } else {
        kbForm.post('/idp-setting/masters', opts)
    }
}

function deleteKeyBehavior(item: KeyBehavior) {
    pendingDelete.value = {
        url: `/idp-setting/masters/key_behavior/${item.id}`,
        name: levelName(item),
    }
}

/**
 * --------------------------------------------------------------------------
 * Delete confirmation
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
 * Table — search (external) + ClientTable (sort + pagination)
 * --------------------------------------------------------------------------
 */

const search = ref('')

// Rows carry the derived localized name/description ClientTable sorts + renders.
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase()
    const rows = props.proficiencyLevels.map((r) => ({
        ...r,
        name: levelName(r),
        description: levelDescription(r),
    }))
    return q
        ? rows.filter(
              (r) =>
                  r.name.toLowerCase().includes(q) ||
                  r.value.toLowerCase().includes(q),
          )
        : rows
})

const columns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.proficiencyLevel, sortable: true, thClass: 'w-72' },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'key_behaviors', label: t.value.idp.settings.keyBehaviors, align: 'center', thClass: 'w-52' },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])

// Key behaviors of the managed level as ClientTable rows (in the drawer).
const kbRows = computed(() =>
    (kbLevel.value?.key_behaviors ?? []).map((kb) => ({
        ...kb,
        name: levelName(kb),
        description: levelDescription(kb),
    })),
)

const kbColumns = computed<Column[]>(() => [
    { key: 'name', label: t.value.idp.settings.name, sortable: true },
    { key: 'description', label: t.value.idp.settings.description },
    { key: 'actions', label: t.value.idp.settings.action, align: 'right' },
])
</script>

<template>
    <Head :title="t.idp.settings.proficiencyLevelTitle" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.proficiencyLevelTitle"
            :subtitle="t.idp.settings.proficiencyLevelSubtitle"
        />

        <div class="space-y-6">
            <!-- ------------------------------------------------------------
                 Proficiency levels card: header + toolbar + table
            ------------------------------------------------------------- -->
            <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 p-5">
                    <div>
                        <h3 class="flex items-center gap-2 text-base font-semibold text-slate-800">
                            {{ t.idp.settings.proficiencyLevels }}
                            <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-500">
                                {{ proficiencyLevels.length }}
                            </span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-400">
                            {{ t.idp.settings.proficiencyLevelsHint }}
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
                                :placeholder="t.idp.settings.searchProficiencyLevel"
                                class="w-56 rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                            @click="openForm()"
                        >
                            <i class="fa-solid fa-plus text-xs" />
                            {{ t.idp.settings.proficiencyLevel }}
                        </button>
                    </div>
                </div>

                <ClientTable
                    :columns="columns"
                    :rows="filtered"
                    row-key="id"
                    :per-page="10"
                    numbered
                >
                    <template #cell-name="{ row }">
                        <span class="font-semibold text-slate-800">{{ row.name }}</span>
                    </template>

                    <template #cell-description="{ row }">
                        <span
                            v-if="row.description"
                            class="whitespace-pre-wrap break-words text-slate-500"
                        >
                            {{ row.description }}
                        </span>
                        <span v-else class="text-xs italic text-slate-300">
                            {{ t.idp.settings.noDescription }}
                        </span>
                    </template>

                    <template #cell-key_behaviors="{ row }">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-white px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:border-primary hover:text-primary"
                            @click="openKeyBehaviors(row as unknown as ProficiencyLevel)"
                        >
                            <i class="fa-solid fa-list-check text-[11px]" />
                            {{ t.idp.settings.manageKeyBehaviors }}
                            <span
                                class="ml-0.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-slate-100 px-1.5 text-[11px] font-semibold text-slate-600"
                            >
                                {{ row.key_behaviors.length }}
                            </span>
                        </button>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex items-center justify-end gap-1">
                            <IconButton
                                icon="fa-solid fa-pen"
                                variant="edit"
                                :title="t.idp.settings.editProficiencyLevel"
                                @click="openForm(row as unknown as ProficiencyLevel)"
                            />
                            <IconButton
                                icon="fa-solid fa-trash"
                                variant="delete"
                                :title="t.idp.settings.deleteProficiencyLevel"
                                @click="deleteLevel(row as unknown as ProficiencyLevel)"
                            />
                        </div>
                    </template>

                    <template #empty>
                        {{ search ? t.idp.settings.noMatch : t.idp.settings.none }}
                    </template>
                </ClientTable>
            </section>
        </div>

        <!-- ================================================================
             ADD / EDIT MODAL
        ================================================================= -->

        <Drawer
            :show="modal"
            :title="
                (editingId ? t.idp.settings.edit : t.idp.settings.add) +
                ' ' +
                t.idp.settings.proficiencyLevel
            "
            @close="modal = false"
        >
            <form id="level-form" class="space-y-4" @submit.prevent="submitForm">
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
                                v-model="form.value_en"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    form.errors.value_en
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p v-if="form.errors.value_en" class="mt-1 text-xs text-red-600">
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
                                v-model="form.value_id"
                                class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                :class="
                                    form.errors.value_id
                                        ? 'border-red-500'
                                        : 'border-border'
                                "
                            >
                            <p v-if="form.errors.value_id" class="mt-1 text-xs text-red-600">
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
                                rows="4"
                                :placeholder="t.idp.settings.descriptionHint"
                                class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            />
                        </div>
                    </div>
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="modal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="level-form"
                    :disabled="form.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Drawer>

        <!-- ================================================================
             MANAGE KEY BEHAVIORS
        ================================================================= -->

        <Drawer
            :show="kbDrawer"
            :title="
                t.idp.settings.keyBehaviors +
                (kbLevel ? ' — ' + levelName(kbLevel) : '')
            "
            @close="kbDrawer = false"
        >
            <div class="space-y-4">
                <!-- Add / edit form (toggled) -->
                <form
                    v-if="kbFormOpen"
                    id="kb-form"
                    class="space-y-4 rounded-lg border border-primary/30 bg-primary/5 p-4"
                    @submit.prevent="submitKbForm"
                >
                    <p class="text-sm font-semibold text-slate-700">
                        {{
                            (kbEditingId
                                ? t.idp.settings.edit
                                : t.idp.settings.add) +
                            ' ' +
                            t.idp.settings.keyBehavior
                        }}
                    </p>

                    <!-- English -->
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            {{ t.idp.settings.name }} (EN)
                        </label>
                        <input
                            v-model="kbForm.value_en"
                            class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :class="
                                kbForm.errors.value_en
                                    ? 'border-red-500'
                                    : 'border-border'
                            "
                        >
                        <p v-if="kbForm.errors.value_en" class="mt-1 text-xs text-red-600">
                            {{ kbForm.errors.value_en }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            {{ t.idp.settings.description }} (EN)
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>
                        <textarea
                            v-model="kbForm.description_en"
                            rows="3"
                            :placeholder="t.idp.settings.descriptionHint"
                            class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                    </div>

                    <!-- Bahasa Indonesia -->
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            {{ t.idp.settings.name }} (ID)
                        </label>
                        <input
                            v-model="kbForm.value_id"
                            class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            {{ t.idp.settings.description }} (ID)
                            <span class="font-normal text-slate-400">
                                ({{ t.idp.settings.optional }})
                            </span>
                        </label>
                        <textarea
                            v-model="kbForm.description_id"
                            rows="3"
                            :placeholder="t.idp.settings.descriptionHint"
                            class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-border px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-white"
                            @click="kbFormOpen = false"
                        >
                            {{ t.idp.form.cancel }}
                        </button>
                        <button
                            type="submit"
                            :disabled="kbForm.processing"
                            class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                        >
                            {{ t.idp.form.save }}
                        </button>
                    </div>
                </form>

                <!-- Add trigger -->
                <button
                    v-else
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    @click="openKbForm()"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ t.idp.settings.addKeyBehavior }}
                </button>

                <!-- List -->
                <div class="overflow-hidden rounded-lg border border-border">
                    <ClientTable
                        :columns="kbColumns"
                        :rows="kbRows"
                        row-key="id"
                        :per-page="8"
                        numbered
                    >
                        <template #cell-name="{ row }">
                            <span class="font-semibold text-slate-800">{{ row.name }}</span>
                        </template>

                        <template #cell-description="{ row }">
                            <span
                                v-if="row.description"
                                class="whitespace-pre-wrap break-words text-slate-500"
                            >
                                {{ row.description }}
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
                                    :title="t.idp.settings.editKeyBehavior"
                                    @click="openKbForm(row as unknown as KeyBehavior)"
                                />
                                <IconButton
                                    icon="fa-solid fa-trash"
                                    variant="delete"
                                    :title="t.idp.settings.deleteKeyBehavior"
                                    @click="deleteKeyBehavior(row as unknown as KeyBehavior)"
                                />
                            </div>
                        </template>

                        <template #empty>
                            {{ t.idp.settings.noKeyBehaviors }}
                        </template>
                    </ClientTable>
                </div>
            </div>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="kbDrawer = false"
                >
                    {{ t.idp.form.close }}
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
