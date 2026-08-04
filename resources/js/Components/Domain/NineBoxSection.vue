<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Modal from '@/Components/Domain/Modal.vue'
import NineBoxGrid from '@/Components/Domain/NineBoxGrid.vue'
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

const TALENT_BOXES = [
    'Stars (1)',
    'High Potentials (2)',
    'High Impact Performers (3)',
    'Trusted Professional (4)',
    'Potential Gems (5)',
    'Core Players (6)',
    'Effective Employee (7)',
    'Inconsistent Performers (8)',
    'Deadwood (9)',
]

const selectedYear = ref<number | null>(props.appraisals[0]?.appraisal_year ?? null)

const selected = computed(
    () => props.appraisals.find((a) => a.appraisal_year === selectedYear.value) ?? null,
)

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
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.appraisal.title }}
            </h3>
            <button
                v-if="canInput"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                @click="openAdd"
            >
                <i class="fa-solid fa-plus" /> {{ t.appraisal.add }}
            </button>
        </div>

        <div
            v-if="appraisals.length === 0"
            class="rounded-xl border border-dashed border-border bg-white py-10 text-center text-sm text-slate-400"
        >
            {{ t.appraisal.empty }}
        </div>

        <div v-else class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <!-- Year list -->
            <div class="rounded-xl border border-border bg-white shadow-sm">
                <div class="divide-y divide-border/60">
                    <button
                        v-for="a in appraisals"
                        :key="a.id"
                        type="button"
                        class="flex w-full items-center justify-between px-4 py-3 text-left transition hover:bg-slate-50"
                        :class="a.appraisal_year === selectedYear ? 'bg-red-50/40' : ''"
                        @click="selectedYear = a.appraisal_year"
                    >
                        <div>
                            <div class="text-sm font-semibold text-slate-700">{{ a.appraisal_year }}</div>
                            <div class="text-xs text-slate-400">
                                {{ a.talent_box ?? '—' }}
                                <span v-if="a.potential"> · {{ t.appraisal.potential }}: {{ a.potential }}</span>
                                <span v-if="a.grade"> · {{ t.appraisal.grade }}: {{ a.grade }}</span>
                            </div>
                        </div>
                        <div v-if="canInput" class="flex gap-1">
                            <span
                                role="button"
                                class="flex h-7 w-7 items-center justify-center rounded text-slate-400 hover:text-primary"
                                @click.stop="openEdit(a)"
                            >
                                <i class="fa-solid fa-pen text-xs" />
                            </span>
                            <span
                                role="button"
                                class="flex h-7 w-7 items-center justify-center rounded text-slate-400 hover:text-red-600"
                                @click.stop="remove(a)"
                            >
                                <i class="fa-solid fa-trash text-xs" />
                            </span>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Grid for the selected year -->
            <div class="rounded-xl border border-border bg-white p-4 shadow-sm">
                <NineBoxGrid :talent-box="selected?.talent_box" />
            </div>
        </div>

        <!-- Add modal -->
        <Modal :show="addModal" :title="t.appraisal.add" @close="addModal = false">
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
                    <select v-model="addForm.potential" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="High">High</option>
                        <option value="Medium">Medium</option>
                        <option value="Low">Low</option>
                    </select>
                    <p v-if="addForm.errors.potential" class="mt-1 text-xs text-red-600">{{ addForm.errors.potential }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.talentBox }}</label>
                    <select v-model="addForm.talent_box" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="">—</option>
                        <option v-for="b in TALENT_BOXES" :key="b" :value="b">{{ b }}</option>
                    </select>
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
        </Modal>

        <!-- Edit modal -->
        <Modal :show="editModal" :title="t.appraisal.edit" @close="editModal = false">
            <form id="ninebox-edit" class="space-y-4" @submit.prevent="submitEdit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.potential }}</label>
                    <select v-model="editForm.potential" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="">—</option>
                        <option value="High">High</option>
                        <option value="Medium">Medium</option>
                        <option value="Low">Low</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.appraisal.talentBox }}</label>
                    <select v-model="editForm.talent_box" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="">—</option>
                        <option v-for="b in TALENT_BOXES" :key="b" :value="b">{{ b }}</option>
                    </select>
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
        </Modal>
    </section>
</template>
