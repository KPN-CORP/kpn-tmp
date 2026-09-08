<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import UnsavedChangesDialog from '@/Components/Domain/UnsavedChangesDialog.vue'
import ClientTable, { type Column } from '@/Components/Domain/ClientTable.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'
import { seedForm, useUnsavedGuard } from '@/Composables/useUnsavedGuard'
import { route } from '@/Config/route'

const { t } = useLocale()

interface Appraisal {
    id: number
    appraisal_year: number
    grade: string | null
    potential: string | null
    talent_box: string | null
}

const props = defineProps<{
    employeeId: string
    appraisals: Appraisal[]
    canInput: boolean
}>()

// Options + display labels are locale-driven; the stored values stay in English.
const potentialOptions = computed<Option[]>(() =>
    (['High', 'Medium', 'Low'] as const).map((v) => ({
        value: v,
        label: t.value.appraisal.potentialLabels[v],
    })),
)
const potentialOptionsClearable = computed<Option[]>(() => [
    { value: '', label: '—' },
    ...potentialOptions.value,
])
const talentBoxOptions = computed<Option[]>(() => [
    { value: '', label: '—' },
    ...Object.entries(t.value.appraisal.talentBoxLabels).map(([value, label]) => ({ value, label })),
])

function potentialLabel(value: string | null): string {
    if (!value) return '—'
    return (t.value.appraisal.potentialLabels as Record<string, string>)[value] ?? value
}
function talentBoxLabel(value: string | null): string {
    if (!value) return '—'
    return (t.value.appraisal.talentBoxLabels as Record<string, string>)[value] ?? value
}

const columns = computed<Column[]>(() => [
    { key: 'appraisal_year', label: t.value.appraisal.year, sortable: true, tdClass: 'font-medium text-slate-800' },
    { key: 'grade', label: t.value.appraisal.performanceAppraisal, sortable: true },
    { key: 'potential', label: t.value.appraisal.potential, sortable: true },
    { key: 'talent_box', label: t.value.appraisal.talentBox, sortable: true },
    ...(props.canInput ? [{ key: 'action', label: t.value.appraisal.action, align: 'right' as const }] : []),
])

// --- Add ---
const addModal = ref(false)

function blankAdd() {
    return {
        employee_id: props.employeeId,
        appraisal_year: new Date().getFullYear(),
        potential: 'Medium',
        talent_box: '',
    }
}

const addForm = useForm(blankAdd())

// Both drawers seed their values as data AND defaults, so `isDirty` — which
// drives the discard prompt — measures this sitting's edits (see `seedForm`).
function openAdd() {
    seedForm(addForm, blankAdd())
    addModal.value = true
}
function closeAdd() {
    addModal.value = false
    seedForm(addForm, blankAdd())
}
const {
    confirming: confirmingAdd,
    requestClose: requestCloseAdd,
    discard: discardAdd,
} = useUnsavedGuard(addForm, closeAdd)

function submitAdd() {
    addForm.post(route('ninebox.store'), {
        preserveScroll: true,
        onSuccess: () => closeAdd(),
    })
}

// --- Edit ---
const editModal = ref(false)
const editForm = useForm({ potential: '', talent_box: '' })
const editingId = ref<number | null>(null)
function openEdit(a: Appraisal) {
    editingId.value = a.id
    seedForm(editForm, {
        potential: a.potential ?? '',
        talent_box: a.talent_box ?? '',
    })
    editModal.value = true
}
function closeEdit() {
    editModal.value = false
    seedForm(editForm, { potential: '', talent_box: '' })
}
const {
    confirming: confirmingEdit,
    requestClose: requestCloseEdit,
    discard: discardEdit,
} = useUnsavedGuard(editForm, closeEdit)

function submitEdit() {
    editForm.put(route('ninebox.update', editingId.value), {
        preserveScroll: true,
        onSuccess: () => closeEdit(),
    })
}

// --- Delete ---
function remove(a: Appraisal) {
    if (!confirm(t.value.appraisal.deleteConfirm)) return
    router.delete(route('ninebox.destroy'), {
        data: { employee_id: props.employeeId, appraisal_year: a.appraisal_year },
        preserveScroll: true,
    })
}
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-border bg-white shadow-sm">
        <div class="flex items-center justify-between gap-3 border-b border-border px-5 py-3.5">
            <div class="flex items-center gap-2.5">
                <span class="h-5 w-1.5 shrink-0 rounded-full bg-primary" />
                <h3 class="font-semibold text-slate-800">{{ t.appraisal.title }}</h3>
            </div>
            <button
                v-if="canInput"
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                @click="openAdd"
            >
                <i class="fa-solid fa-plus" /> {{ t.appraisal.add }}
            </button>
        </div>

        <ClientTable
            :columns="columns"
            :rows="appraisals"
            row-key="id"
            :initial-sort="{ key: 'appraisal_year', dir: 'desc' }"
            :empty-text="t.appraisal.empty"
        >
            <template #cell-potential="{ value }">{{ potentialLabel(value) }}</template>
            <template #cell-talent_box="{ value }">{{ talentBoxLabel(value) }}</template>
            <template #cell-action="{ row }">
                <div class="inline-flex gap-1 opacity-60 transition group-hover:opacity-100">
                    <button
                        type="button"
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                        :title="t.appraisal.editBtn"
                        @click="openEdit(row)"
                    >
                        <i class="fa-solid fa-pen text-xs" />
                    </button>
                    <button
                        type="button"
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                        :title="t.appraisal.deleteBtn"
                        @click="remove(row)"
                    >
                        <i class="fa-solid fa-trash text-xs" />
                    </button>
                </div>
            </template>
        </ClientTable>

        <div class="border-t border-border px-5 py-3 text-xs italic leading-relaxed text-slate-500">
            <strong>{{ t.appraisal.noteTitle }}</strong><br>
            {{ t.appraisal.noteBody }}<br>
            {{ t.appraisal.note2023after }}<br>
            {{ t.appraisal.note2023 }}<br>
            {{ t.appraisal.note2023before }}
        </div>

        <!-- Add drawer -->
        <Drawer :show="addModal" :title="t.appraisal.add" @close="requestCloseAdd">
            <form id="ninebox-add" class="space-y-4" @submit.prevent="submitAdd">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.year }}</label>
                    <input
                        v-model.number="addForm.appraisal_year"
                        type="number" min="2000" max="2100"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="addForm.errors.appraisal_year ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="addForm.errors.appraisal_year" class="mt-1 text-xs text-red-600">{{ addForm.errors.appraisal_year }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.potential }}</label>
                    <SearchableSelect v-model="addForm.potential" :options="potentialOptions" :invalid="!!addForm.errors.potential" />
                    <p v-if="addForm.errors.potential" class="mt-1 text-xs text-red-600">{{ addForm.errors.potential }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.talentBox }}</label>
                    <SearchableSelect v-model="addForm.talent_box" :options="talentBoxOptions" />
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="requestCloseAdd">
                    {{ t.appraisal.cancel }}
                </button>
                <button type="submit" form="ninebox-add" :disabled="addForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.appraisal.save }}
                </button>
            </template>
        </Drawer>

        <!-- Edit drawer -->
        <Drawer :show="editModal" :title="t.appraisal.edit" @close="requestCloseEdit">
            <form id="ninebox-edit" class="space-y-4" @submit.prevent="submitEdit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.potential }}</label>
                    <SearchableSelect v-model="editForm.potential" :options="potentialOptionsClearable" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.talentBox }}</label>
                    <SearchableSelect v-model="editForm.talent_box" :options="talentBoxOptions" />
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="requestCloseEdit">
                    {{ t.appraisal.cancel }}
                </button>
                <button type="submit" form="ninebox-edit" :disabled="editForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.appraisal.save }}
                </button>
            </template>
        </Drawer>

        <!-- Unsaved-changes prompts, one per drawer. -->
        <UnsavedChangesDialog
            :show="confirmingAdd"
            @confirm="discardAdd"
            @close="confirmingAdd = false"
        />

        <UnsavedChangesDialog
            :show="confirmingEdit"
            @confirm="discardEdit"
            @close="confirmingEdit = false"
        />
    </section>
</template>
