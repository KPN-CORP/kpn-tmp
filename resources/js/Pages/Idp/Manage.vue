<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import IdpPanel from '@/Components/Domain/IdpPanel.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

const panel = ref<InstanceType<typeof IdpPanel> | null>(null)

interface MasterOption {
    value: string
    value_en: string | null
    value_id: string | null
}

interface Plan {
    id: number
    realization_date: string | null
    [key: string]: any
}

interface Model {
    id: number
    name: string
    percentage: number
    description_en: string | null
    description_id: string | null
    plans: Plan[]
}

const props = defineProps<{
    employee: { data: { employee_id: string; fullname: string; designation_name: string | null } }
    developmentModels: Model[]
    options: {
        competencyNames: MasterOption[]
        developmentPrograms: MasterOption[]
        reviewTools: MasterOption[]
    }
    competencyMap: Record<string, Array<MasterOption & { model_id: number | null }>>
}>()

const emp = props.employee.data
</script>

<template>
    <Head :title="`${t.idp.manage} — ${emp.fullname}`" />

    <AppLayout>
        <PageHeader :title="t.idp.manageTitle">
            <template #actions>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                    @click="panel?.openUpload()"
                >
                    <i class="fa-solid fa-file-arrow-up text-xs" />
                    {{ t.idp.upload.button }}
                </button>
                <a
                    :href="`/idp/${emp.employee_id}/pdf`"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-file-pdf text-xs" />
                    {{ t.idp.downloadPdf }}
                </a>
                <a
                    :href="`/idp/${emp.employee_id}/export`"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-file-excel text-xs" />
                    {{ t.idp.exportExcel }}
                </a>
                <Link
                    href="/idp"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-arrow-left text-xs" />
                    {{ t.idp.backToList }}
                </Link>
            </template>
        </PageHeader>

        <IdpPanel
            ref="panel"
            :employee="emp"
            :development-models="developmentModels"
            :options="options"
            :competency-map="competencyMap"
        />
    </AppLayout>
</template>
