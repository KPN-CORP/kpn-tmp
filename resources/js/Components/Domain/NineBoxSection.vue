<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

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

// --- Add ---
const addModal = ref(false)
const addForm = useForm({
    employee_id: props.employeeId,
    appraisal_year: new Date().getFullYear(),
    potential: 'Medium',
    talent_box: '',
})
function openAdd() {
    addForm.clearErrors()
    addForm.reset()
    addForm.employee_id = props.employeeId
    addModal.value = true
}
function submitAdd() {
    addForm.post('/ninebox', {
        preserveScroll: true,
        onSuccess: () => (addModal.value = false),
    })
}

// --- Edit ---
const editModal = ref(false)
const editForm = useForm({ potential: '', talent_box: '' })
const editingId = ref<number | null>(null)
function openEdit(a: Appraisal) {
    editingId.value = a.id
    editForm.clearErrors()
    editForm.potential = a.potential ?? ''
    editForm.talent_box = a.talent_box ?? ''
    editModal.value = true
}
function submitEdit() {
    editForm.put(`/ninebox/${editingId.value}`, {
        preserveScroll: true,
        onSuccess: () => (editModal.value = false),
    })
}

// --- Delete ---
function remove(a: Appraisal) {
    if (!confirm(t.value.appraisal.deleteConfirm)) return
    router.delete('/ninebox', {
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

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-border bg-slate-50/60 text-[11px] uppercase tracking-wider text-slate-400">
                        <th class="px-5 py-2.5 font-semibold">{{ t.appraisal.year }}</th>
                        <th class="px-5 py-2.5 font-semibold">{{ t.appraisal.performanceAppraisal }}</th>
                        <th class="px-5 py-2.5 font-semibold">{{ t.appraisal.potential }}</th>
                        <th class="px-5 py-2.5 font-semibold">{{ t.appraisal.talentBox }}</th>
                        <th v-if="canInput" class="px-5 py-2.5 text-right font-semibold">{{ t.appraisal.action }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="a in appraisals" :key="a.id" class="group border-b border-border/60 transition last:border-0 hover:bg-slate-50/70">
                        <td class="px-5 py-3.5 font-medium text-slate-800">{{ a.appraisal_year }}</td>
                        <td class="px-5 py-3.5 text-slate-700">{{ a.grade ?? '—' }}</td>
                        <td class="px-5 py-3.5 text-slate-700">{{ potentialLabel(a.potential) }}</td>
                        <td class="px-5 py-3.5 text-slate-700">{{ talentBoxLabel(a.talent_box) }}</td>
                        <td v-if="canInput" class="px-5 py-3.5 text-right">
                            <div class="inline-flex gap-1 opacity-60 transition group-hover:opacity-100">
                                <button
                                    type="button"
                                    class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                                    :title="t.appraisal.editBtn"
                                    @click="openEdit(a)"
                                >
                                    <i class="fa-solid fa-pen text-xs" />
                                </button>
                                <button
                                    type="button"
                                    class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                                    :title="t.appraisal.deleteBtn"
                                    @click="remove(a)"
                                >
                                    <i class="fa-solid fa-trash text-xs" />
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="appraisals.length === 0">
                        <td :colspan="canInput ? 5 : 4" class="px-5 py-8 text-center text-slate-400">
                            {{ t.appraisal.empty }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="border-t border-border px-5 py-3 text-xs italic leading-relaxed text-slate-500">
            <strong>{{ t.appraisal.noteTitle }}</strong><br>
            {{ t.appraisal.noteBody }}<br>
            {{ t.appraisal.note2023after }}<br>
            {{ t.appraisal.note2023 }}<br>
            {{ t.appraisal.note2023before }}
        </div>

        <!-- Add drawer -->
        <Drawer :show="addModal" :title="t.appraisal.add" @close="addModal = false">
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
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="addModal = false">
                    {{ t.appraisal.cancel }}
                </button>
                <button type="submit" form="ninebox-add" :disabled="addForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.appraisal.save }}
                </button>
            </template>
        </Drawer>

        <!-- Edit drawer -->
        <Drawer :show="editModal" :title="t.appraisal.edit" @close="editModal = false">
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
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="editModal = false">
                    {{ t.appraisal.cancel }}
                </button>
                <button type="submit" form="ninebox-edit" :disabled="editForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.appraisal.save }}
                </button>
            </template>
        </Drawer>
    </section>
</template>
