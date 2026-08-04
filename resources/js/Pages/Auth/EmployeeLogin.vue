<script setup lang="ts">
import { ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AuthBrand from '@/Components/UI/AuthBrand.vue'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface EmployeeResult {
    employee_id: string
    fullname: string
    designation_name: string | null
    group_company: string | null
}

const query = ref('')
const results = ref<EmployeeResult[]>([])
const searching = ref(false)
const submittingId = ref<string | null>(null)

let debounce: ReturnType<typeof setTimeout> | undefined

watch(query, () => {
    clearTimeout(debounce)
    debounce = setTimeout(runSearch, 300)
})

async function runSearch() {
    searching.value = true
    try {
        const res = await fetch(
            `/dev-login/employees/search?q=${encodeURIComponent(query.value)}`,
            { headers: { Accept: 'application/json' } },
        )
        results.value = res.ok ? await res.json() : []
    } catch {
        results.value = []
    } finally {
        searching.value = false
    }
}

function loginAs(employee: EmployeeResult) {
    submittingId.value = employee.employee_id
    router.post(
        '/dev-login/employees',
        { employee_id: employee.employee_id },
        { onFinish: () => (submittingId.value = null) },
    )
}

// Show an initial page of employees on load.
runSearch()
</script>

<template>
    <Head :title="t.auth.employeeLoginTitle" />

    <AuthLayout>
        <div class="mb-6 flex justify-end">
            <LanguageSwitcher align="right" />
        </div>

        <AuthBrand
            :title="t.auth.employeeLoginTitle"
            :subtitle="t.auth.employeeLoginSubtitle"
        />

        <!-- Search -->
        <div class="relative">
            <i
                class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
            />
            <input
                v-model="query"
                type="text"
                :placeholder="t.auth.searchPlaceholder"
                class="w-full rounded-md border border-border py-2.5 pl-9 pr-9 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
            <i
                v-if="searching"
                class="fa-solid fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
            />
        </div>

        <!-- Results -->
        <ul class="mt-4 max-h-80 space-y-2 overflow-y-auto">
            <li
                v-for="emp in results"
                :key="emp.employee_id"
            >
                <button
                    type="button"
                    :disabled="submittingId !== null"
                    class="flex w-full items-center justify-between gap-3 rounded-md border border-border p-3 text-left transition-colors hover:border-primary hover:bg-red-50/40 disabled:cursor-not-allowed disabled:opacity-60"
                    @click="loginAs(emp)"
                >
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-slate-700">
                            {{ emp.fullname }}
                        </span>
                        <span class="block truncate text-xs text-slate-400">
                            {{ emp.employee_id }} ·
                            {{ emp.designation_name ?? 'N.A' }} ·
                            {{ emp.group_company ?? 'N.A' }}
                        </span>
                    </span>

                    <i
                        class="fa-solid shrink-0 text-sm text-primary"
                        :class="submittingId === emp.employee_id ? 'fa-spinner fa-spin' : 'fa-arrow-right-to-bracket'"
                    />
                </button>
            </li>

            <li
                v-if="!searching && results.length === 0"
                class="rounded-md border border-dashed border-border py-8 text-center text-sm text-slate-400"
            >
                {{ t.auth.noResults }}
            </li>
        </ul>

        <div class="mt-6 border-t border-border pt-4 text-center">
            <Link
                href="/login"
                class="text-sm font-medium text-slate-500 hover:text-primary hover:underline"
            >
                {{ t.auth.backToLogin }}
            </Link>
        </div>
    </AuthLayout>
</template>
