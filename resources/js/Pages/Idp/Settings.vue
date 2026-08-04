```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'

import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Modal from '@/Components/Domain/Modal.vue'
import SearchableSelect, { type Option } from '@/Components/UI/SearchableSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Model {
    id: number
    name: string
    percentage: number
    description_en: string | null
    description_id: string | null
    development_programs_count: number
    individual_development_plans_count: number
}

interface Competency {
    id: number
    value: string
    related_program: number[]
    linked_programs: string[]
}

interface Program {
    id: number
    value: string
    development_model_id: number | null
    model_name: string | null
}

interface ReviewTool {
    id: number
    value: string
}

const props = defineProps<{
    developmentModels: Model[]
    totalPercentage: number
    competencies: Competency[]
    developmentPrograms: Program[]
    reviewTools: ReviewTool[]
}>()

/**
 * --------------------------------------------------------------------------
 * Tabs
 * --------------------------------------------------------------------------
 */

type Tab =
    | 'development-model'
    | 'competencies-programs'
    | 'review-tools'

const activeTab = ref<Tab>('development-model')

/**
 * --------------------------------------------------------------------------
 * Development model
 * --------------------------------------------------------------------------
 */

const modelOptions = computed<Option[]>(() => [
    { value: '', label: '—' },
    ...props.developmentModels.map((m) => ({
        value: String(m.id),
        label: m.name,
    })),
])

const modelModal = ref(false)
const editingModelId = ref<number | null>(null)

const modelForm = useForm({
    name: '',
    percentage: 10,
    description_en: '',
    description_id: '',
})

function openModel(model?: Model) {
    editingModelId.value = model?.id ?? null

    modelForm.clearErrors()

    modelForm.name = model?.name ?? ''
    modelForm.percentage = model?.percentage ?? 10
    modelForm.description_en = model?.description_en ?? ''
    modelForm.description_id = model?.description_id ?? ''

    modelModal.value = true
}

function submitModel() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (modelModal.value = false),
    }

    if (editingModelId.value) {
        modelForm.put(
            `/idp-setting/models/${editingModelId.value}`,
            opts,
        )
    } else {
        modelForm.post('/idp-setting/models', opts)
    }
}

function deleteModel(model: Model) {
    if (confirm(t.value.idp.settings.confirmDelete)) {
        router.delete(`/idp-setting/models/${model.id}`, {
            preserveScroll: true,
        })
    }
}

/**
 * --------------------------------------------------------------------------
 * Master data
 * --------------------------------------------------------------------------
 */

type MasterType =
    | 'competency_name'
    | 'development_program'
    | 'review_tools'

const masterModal = ref(false)
const masterType = ref<MasterType>('competency_name')
const editingMasterId = ref<number | null>(null)

const masterForm = useForm({
    type: 'competency_name' as MasterType,
    value: '',
    development_model_id: null as number | null,
    related_programs: [] as number[],
})

function openMaster(
    type: MasterType,
    item?: Competency | Program | ReviewTool,
) {
    masterType.value = type
    editingMasterId.value = item?.id ?? null

    masterForm.clearErrors()

    masterForm.type = type
    masterForm.value = item?.value ?? ''

    masterForm.development_model_id =
        (item as Program)?.development_model_id ?? null

    masterForm.related_programs =
        (item as Competency)?.related_program ?? []

    masterModal.value = true
}

function submitMaster() {
    const opts = {
        preserveScroll: true,
        onSuccess: () => (masterModal.value = false),
    }

    if (editingMasterId.value) {
        masterForm.put(
            `/idp-setting/masters/${editingMasterId.value}`,
            opts,
        )
    } else {
        masterForm.post('/idp-setting/masters', opts)
    }
}

function deleteMaster(id: number) {
    if (confirm(t.value.idp.settings.confirmDelete)) {
        router.delete(`/idp-setting/masters/${id}`, {
            preserveScroll: true,
        })
    }
}

const masterTitle = () => {
    if (masterType.value === 'competency_name') {
        return t.value.idp.settings.competency
    }

    if (masterType.value === 'development_program') {
        return t.value.idp.settings.program
    }

    return t.value.idp.settings.reviewTool
}
</script>

<template>
    <Head :title="t.idp.settings.title" />

    <AppLayout>
        <PageHeader
            :title="t.idp.settings.title"
            :subtitle="t.idp.settings.subtitle"
        />

        <!-- ================================================================
             Tabs
        ================================================================= -->

        <div class="mb-6 border-b border-border">
            <nav
                class="-mb-px flex flex-wrap gap-6"
                aria-label="Settings tabs"
            >
                <!-- Development Model -->
                <button
                    type="button"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'development-model'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="activeTab = 'development-model'"
                >
                    Development Model
                </button>

                <!-- Competencies & Development Programs -->
                <button
                    type="button"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'competencies-programs'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="activeTab = 'competencies-programs'"
                >
                    Competencies & Development Programs
                </button>

                <!-- Review Tools -->
                <button
                    type="button"
                    class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors"
                    :class="
                        activeTab === 'review-tools'
                            ? 'border-primary text-primary'
                            : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700'
                    "
                    @click="activeTab = 'review-tools'"
                >
                    Review Tools
                </button>
            </nav>
        </div>

        <!-- ================================================================
             TAB 1: DEVELOPMENT MODEL
        ================================================================= -->

        <div v-if="activeTab === 'development-model'">
            <section
                class="mb-8 rounded-xl border border-border bg-white shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-5 py-3"
                >
                    <div>
                        <h3 class="font-semibold text-slate-700">
                            {{ t.idp.settings.models }}
                        </h3>

                        <p
                            class="text-xs"
                            :class="
                                totalPercentage === 100
                                    ? 'text-green-600'
                                    : 'text-amber-600'
                            "
                        >
                            {{ t.idp.settings.total }}:
                            {{ totalPercentage }}%
                        </p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-primary-hover"
                        @click="openModel()"
                    >
                        <i class="fa-solid fa-plus" />
                        {{ t.idp.settings.addModel }}
                    </button>
                </div>

                <div class="divide-y divide-border/60">
                    <div
                        v-for="model in developmentModels"
                        :key="model.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <div>
                            <span class="font-medium text-slate-700">
                                {{ model.name }}
                            </span>

                            <span
                                class="ml-2 text-sm text-slate-400"
                            >
                                {{ model.percentage }}%
                            </span>
                        </div>

                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="h-8 w-8 rounded-md text-slate-400 hover:bg-slate-100 hover:text-primary"
                                @click="openModel(model)"
                            >
                                <i class="fa-solid fa-pen" />
                            </button>

                            <button
                                type="button"
                                class="h-8 w-8 rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600"
                                @click="deleteModel(model)"
                            >
                                <i class="fa-solid fa-trash" />
                            </button>
                        </div>
                    </div>

                    <div
                        v-if="developmentModels.length === 0"
                        class="px-5 py-8 text-center text-sm text-slate-400"
                    >
                        {{ t.idp.settings.none }}
                    </div>
                </div>
            </section>
        </div>

        <!-- ================================================================
             TAB 2: COMPETENCIES & DEVELOPMENT PROGRAMS
        ================================================================= -->

        <div v-if="activeTab === 'competencies-programs'">
            <div
                class="grid grid-cols-1 gap-6 lg:grid-cols-2"
            >
                <!-- ========================================================
                     Competencies
                ========================================================= -->

                <section
                    class="rounded-xl border border-border bg-white shadow-sm"
                >
                    <div
                        class="flex items-center justify-between border-b border-border px-5 py-3"
                    >
                        <div>
                            <h3 class="font-semibold text-slate-700">
                                {{ t.idp.settings.competencies }}
                            </h3>

                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ competencies.length }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="text-primary hover:text-primary-hover"
                            @click="openMaster('competency_name')"
                        >
                            <i class="fa-solid fa-plus" />
                        </button>
                    </div>

                    <ul class="divide-y divide-border/60">
                        <li
                            v-for="c in competencies"
                            :key="c.id"
                            class="flex items-start justify-between gap-2 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-medium text-slate-700"
                                >
                                    {{ c.value }}
                                </div>

                                <div
                                    v-if="c.linked_programs.length"
                                    class="mt-0.5 truncate text-xs text-slate-400"
                                >
                                    {{ c.linked_programs.join(', ') }}
                                </div>
                            </div>

                            <div class="flex shrink-0 gap-1">
                                <button
                                    type="button"
                                    class="h-7 w-7 rounded text-slate-400 hover:text-primary"
                                    @click="
                                        openMaster(
                                            'competency_name',
                                            c,
                                        )
                                    "
                                >
                                    <i
                                        class="fa-solid fa-pen text-xs"
                                    />
                                </button>

                                <button
                                    type="button"
                                    class="h-7 w-7 rounded text-slate-400 hover:text-red-600"
                                    @click="deleteMaster(c.id)"
                                >
                                    <i
                                        class="fa-solid fa-trash text-xs"
                                    />
                                </button>
                            </div>
                        </li>

                        <li
                            v-if="competencies.length === 0"
                            class="px-5 py-6 text-center text-sm text-slate-400"
                        >
                            {{ t.idp.settings.none }}
                        </li>
                    </ul>
                </section>

                <!-- ========================================================
                     Development Programs
                ========================================================= -->

                <section
                    class="rounded-xl border border-border bg-white shadow-sm"
                >
                    <div
                        class="flex items-center justify-between border-b border-border px-5 py-3"
                    >
                        <div>
                            <h3 class="font-semibold text-slate-700">
                                {{ t.idp.settings.programs }}
                            </h3>

                            <p class="mt-0.5 text-xs text-slate-400">
                                {{ developmentPrograms.length }}
                            </p>
                        </div>

                        <button
                            type="button"
                            class="text-primary hover:text-primary-hover"
                            @click="
                                openMaster(
                                    'development_program',
                                )
                            "
                        >
                            <i class="fa-solid fa-plus" />
                        </button>
                    </div>

                    <ul class="divide-y divide-border/60">
                        <li
                            v-for="p in developmentPrograms"
                            :key="p.id"
                            class="flex items-start justify-between gap-2 px-5 py-3"
                        >
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-medium text-slate-700"
                                >
                                    {{ p.value }}
                                </div>

                                <div
                                    v-if="p.model_name"
                                    class="mt-0.5 text-xs text-slate-400"
                                >
                                    {{ p.model_name }}
                                </div>
                            </div>

                            <div class="flex shrink-0 gap-1">
                                <button
                                    type="button"
                                    class="h-7 w-7 rounded text-slate-400 hover:text-primary"
                                    @click="
                                        openMaster(
                                            'development_program',
                                            p,
                                        )
                                    "
                                >
                                    <i
                                        class="fa-solid fa-pen text-xs"
                                    />
                                </button>

                                <button
                                    type="button"
                                    class="h-7 w-7 rounded text-slate-400 hover:text-red-600"
                                    @click="deleteMaster(p.id)"
                                >
                                    <i
                                        class="fa-solid fa-trash text-xs"
                                    />
                                </button>
                            </div>
                        </li>

                        <li
                            v-if="developmentPrograms.length === 0"
                            class="px-5 py-6 text-center text-sm text-slate-400"
                        >
                            {{ t.idp.settings.none }}
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <!-- ================================================================
             TAB 3: REVIEW TOOLS
        ================================================================= -->

        <div v-if="activeTab === 'review-tools'">
            <section
                class="rounded-xl border border-border bg-white shadow-sm"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-5 py-3"
                >
                    <div>
                        <h3 class="font-semibold text-slate-700">
                            {{ t.idp.settings.reviewTools }}
                        </h3>

                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ reviewTools.length }}
                        </p>
                    </div>

                    <button
                        type="button"
                        class="text-primary hover:text-primary-hover"
                        @click="openMaster('review_tools')"
                    >
                        <i class="fa-solid fa-plus" />
                    </button>
                </div>

                <ul class="divide-y divide-border/60">
                    <li
                        v-for="r in reviewTools"
                        :key="r.id"
                        class="flex items-center justify-between px-5 py-3"
                    >
                        <span
                            class="text-sm font-medium text-slate-700"
                        >
                            {{ r.value }}
                        </span>

                        <div class="flex gap-1">
                            <button
                                type="button"
                                class="h-7 w-7 rounded text-slate-400 hover:text-primary"
                                @click="
                                    openMaster(
                                        'review_tools',
                                        r,
                                    )
                                "
                            >
                                <i
                                    class="fa-solid fa-pen text-xs"
                                />
                            </button>

                            <button
                                type="button"
                                class="h-7 w-7 rounded text-slate-400 hover:text-red-600"
                                @click="deleteMaster(r.id)"
                            >
                                <i
                                    class="fa-solid fa-trash text-xs"
                                />
                            </button>
                        </div>
                    </li>

                    <li
                        v-if="reviewTools.length === 0"
                        class="px-5 py-6 text-center text-sm text-slate-400"
                    >
                        {{ t.idp.settings.none }}
                    </li>
                </ul>
            </section>
        </div>

        <!-- ================================================================
             DEVELOPMENT MODEL MODAL
        ================================================================= -->

        <Modal
            :show="modelModal"
            :title="
                editingModelId
                    ? t.idp.settings.editModel
                    : t.idp.settings.addModel
            "
            @close="modelModal = false"
        >
            <form
                id="model-form"
                class="space-y-4"
                @submit.prevent="submitModel"
            >
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.name }}
                    </label>

                    <input
                        v-model="modelForm.name"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="
                            modelForm.errors.name
                                ? 'border-red-500'
                                : 'border-border'
                        "
                    >

                    <p
                        v-if="modelForm.errors.name"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ modelForm.errors.name }}
                    </p>
                </div>

                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.percentage }}
                    </label>

                    <input
                        v-model.number="modelForm.percentage"
                        type="number"
                        min="1"
                        max="100"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="
                            modelForm.errors.percentage
                                ? 'border-red-500'
                                : 'border-border'
                        "
                    >

                    <p
                        v-if="modelForm.errors.percentage"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ modelForm.errors.percentage }}
                    </p>
                </div>

                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.descriptionEn }}
                    </label>

                    <textarea
                        v-model="modelForm.description_en"
                        rows="4"
                        :placeholder="
                            t.idp.settings.descriptionHint
                        "
                        class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>

                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.descriptionId }}
                    </label>

                    <textarea
                        v-model="modelForm.description_id"
                        rows="4"
                        :placeholder="
                            t.idp.settings.descriptionHint
                        "
                        class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    />
                </div>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                    @click="modelModal = false"
                >
                    {{ t.idp.form.cancel }}
                </button>

                <button
                    type="submit"
                    form="model-form"
                    :disabled="modelForm.processing"
                    class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60"
                >
                    {{ t.idp.form.save }}
                </button>
            </template>
        </Modal>

        <!-- ================================================================
             MASTER DATA MODAL
        ================================================================= -->

        <Modal
            :show="masterModal"
            :title="masterTitle()"
            @close="masterModal = false"
        >
            <form
                id="master-form"
                class="space-y-4"
                @submit.prevent="submitMaster"
            >
                <div>
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.name }}
                    </label>

                    <input
                        v-model="masterForm.value"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="
                            masterForm.errors.value
                                ? 'border-red-500'
                                : 'border-border'
                        "
                    >

                    <p
                        v-if="masterForm.errors.value"
                        class="mt-1 text-xs text-red-600"
                    >
                        {{ masterForm.errors.value }}
                    </p>
                </div>

                <!-- Development program -> Development model -->
                <div
                    v-if="masterType === 'development_program'"
                >
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.model }}
                    </label>

                    <SearchableSelect
                        :model-value="
                            masterForm.development_model_id == null
                                ? ''
                                : String(
                                      masterForm.development_model_id,
                                  )
                        "
                        :options="modelOptions"
                        @update:model-value="
                            masterForm.development_model_id =
                                $event === ''
                                    ? null
                                    : Number($event)
                        "
                    />
                </div>

                <!-- Competency -> Linked programs -->
                <div
                    v-if="masterType === 'competency_name'"
                >
                    <label
                        class="mb-1 block text-sm font-medium text-slate-700"
                    >
                        {{ t.idp.settings.linkedPrograms }}
                    </label>

                    <select
                        v-model="masterForm.related_programs"
                        multiple
                        class="h-32 w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                    >
                        <option
                            v-for="p in developmentPrograms"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.value }}
                        </option>
                    </select>

                    <p class="mt-1 text-xs text-slate-400">
                        {{ t.idp.settings.multiHint }}
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
        </Modal>
    </AppLayout>
</template>
```
