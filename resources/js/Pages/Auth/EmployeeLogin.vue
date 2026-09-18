<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AuthBrand from '@/Components/UI/AuthBrand.vue'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'
import { route } from '@/Config/route'

const { t } = useLocale()

interface EmployeeResult {
    employee_id: string
    fullname: string
    designation_name: string | null
    group_company: string | null
}

// The server refuses to search on a shorter term, so it decides the threshold.
const props = withDefaults(defineProps<{ minSearchLength?: number }>(), {
    minSearchLength: 2,
})

const query = ref('')
const results = ref<EmployeeResult[]>([])
const searching = ref(false)
const submittingId = ref<string | null>(null)

const term = computed(() => query.value.trim())
const longEnough = computed(() => term.value.length >= props.minSearchLength)

let debounce: ReturnType<typeof setTimeout> | undefined
// Every search carries a sequence number; a reply that is not the latest one
// is dropped, so a slow early request can never overwrite a newer result set.
let sequence = 0

watch(query, () => {
    clearTimeout(debounce)

    if (!longEnough.value) {
        sequence += 1
        results.value = []
        searching.value = false

        return
    }

    searching.value = true
    debounce = setTimeout(runSearch, 300)
})

async function runSearch() {
    const current = ++sequence
    const q = term.value

    searching.value = true

    try {
        const res = await fetch(
            `${route('dev.login.search')}?q=${encodeURIComponent(q)}`,
            { headers: { Accept: 'application/json' } },
        )
        const found = res.ok ? await res.json() : []

        if (current === sequence) {
            results.value = found
        }
    } catch {
        if (current === sequence) {
            results.value = []
        }
    } finally {
        if (current === sequence) {
            searching.value = false
        }
    }
}

function clearSearch() {
    query.value = ''
}

function loginAs(employee: EmployeeResult) {
    submittingId.value = employee.employee_id
    router.post(
        route('dev.login.impersonate'),
        { employee_id: employee.employee_id },
        { onFinish: () => (submittingId.value = null) },
    )
}
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
                autofocus
                autocomplete="off"
                :placeholder="t.auth.searchPlaceholder"
                class="w-full rounded-md border border-border py-2.5 pl-9 pr-9 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
            >
            <i
                v-if="searching"
                class="fa-solid fa-spinner fa-spin absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
            />
            <button
                v-else-if="query !== ''"
                type="button"
                :aria-label="t.auth.clearSearch"
                :title="t.auth.clearSearch"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400 transition-colors hover:text-slate-600"
                @click="clearSearch"
            >
                <i class="fa-solid fa-xmark" />
            </button>
        </div>

        <!-- Nothing is searched until the term is long enough. -->
        <p
            v-if="!longEnough"
            class="mt-4 rounded-md border border-dashed border-border py-8 text-center text-sm text-slate-400"
        >
            {{ t.auth.typeToSearch.replace(':count', String(props.minSearchLength)) }}
        </p>

        <!-- Results -->
        <ul
            v-else
            class="mt-4 max-h-80 space-y-2 overflow-y-auto"
        >
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
                v-if="searching && results.length === 0"
                class="rounded-md border border-dashed border-border py-8 text-center text-sm text-slate-400"
            >
                {{ t.auth.searching }}
            </li>

            <li
                v-else-if="!searching && results.length === 0"
                class="rounded-md border border-dashed border-border py-8 text-center text-sm text-slate-400"
            >
                {{ t.auth.noResults }}
            </li>
        </ul>

        <div class="mt-6 border-t border-border pt-4 text-center">
            <Link
                :href="route('login')"
                class="text-sm font-medium text-slate-500 hover:text-primary hover:underline"
            >
                {{ t.auth.backToLogin }}
            </Link>
        </div>
    </AuthLayout>
</template>
