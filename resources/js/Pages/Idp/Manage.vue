<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import IdpPanel from '@/Components/Domain/IdpPanel.vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'
import type {
    DevelopmentModelView,
    MasterOption,
    PackageOption,
    PlanningState,
    ProgramOption,
    StageProgress,
} from '@/types/idp'

const { t } = useLocale()

const panel = ref<InstanceType<typeof IdpPanel> | null>(null)

const props = defineProps<{
    employee: { data: { employee_id: string; fullname: string; designation_name: string | null } }
    developmentModels: DevelopmentModelView[]
    options: {
        competencyTypes: MasterOption[]
        competencyNames: MasterOption[]
        developmentPrograms: ProgramOption[]
        reviewTools: MasterOption[]
    }
    competencyMap: Record<string, ProgramOption[]>
    planning: PlanningState
    progress: StageProgress
    packages: PackageOption[]
    selectedPackageId: number | null
    viewingActive: boolean
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
                    :href="route('idp.download_pdf', { employeeId: emp.employee_id, package: selectedPackageId })"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                >
                    <i class="fa-solid fa-file-pdf text-xs" />
                    {{ t.idp.downloadPdf }}
                </a>
                <a
                    :href="route('idp.export', { employeeId: emp.employee_id, package: selectedPackageId })"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm transition hover:bg-slate-50"
                >
                    <i class="fa-solid fa-file-excel text-xs" />
                    {{ t.idp.exportExcel }}
                </a>
                <Link
                    :href="route('idp.list')"
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
            :planning="planning"
            :progress="progress"
            :packages="packages"
            :selected-package-id="selectedPackageId"
            :viewing-active="viewingActive"
            :reload-url="route('idp.show', emp.employee_id)"
        />
    </AppLayout>
</template>
