<script setup lang="ts">
/**
 * The signed-in user's own development plan — the same panel the manage
 * screen uses, opened straight on their own record. Their team's plans live on
 * the list (`idp.list`), so this page carries no "back to list".
 */
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
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

// Everything past `employee` is absent when the user has no plan of their own
// to show (no employee record, or not allowed to see it).
const props = defineProps<{
    employee: { data: { employee_id: string; fullname: string; designation_name: string | null } } | null
    developmentModels?: DevelopmentModelView[]
    options?: {
        competencyTypes: MasterOption[]
        competencyNames: MasterOption[]
        developmentPrograms: ProgramOption[]
        reviewTools: MasterOption[]
    }
    competencyMap?: Record<string, ProgramOption[]>
    planning?: PlanningState
    progress?: StageProgress
    packages?: PackageOption[]
    selectedPackageId?: number | null
    viewingActive?: boolean
}>()

const emp = computed(() => props.employee?.data ?? null)
</script>

<template>
    <Head :title="t.idp.mineTitle" />

    <AppLayout>
        <PageHeader :title="t.idp.mineTitle" :subtitle="t.idp.mineSubtitle">
            <template v-if="emp" #actions>
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
            </template>
        </PageHeader>

        <IdpPanel
            v-if="emp && developmentModels && options && competencyMap && planning && progress && packages"
            ref="panel"
            sticky-header
            :employee="emp"
            :development-models="developmentModels"
            :options="options"
            :competency-map="competencyMap"
            :planning="planning"
            :progress="progress"
            :packages="packages"
            :selected-package-id="selectedPackageId ?? null"
            :viewing-active="viewingActive ?? false"
            :reload-url="route('idp.mine')"
        />

        <div
            v-else
            class="flex flex-col items-center gap-3 rounded-xl border border-border bg-white px-5 py-16 text-center shadow-sm"
        >
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-slate-50 text-slate-300">
                <i class="fa-solid fa-user-slash text-2xl" />
            </div>
            <p class="font-semibold text-slate-700">{{ t.idp.mineUnavailableTitle }}</p>
            <p class="max-w-md text-sm text-slate-400">{{ t.idp.mineUnavailable }}</p>
        </div>
    </AppLayout>
</template>
