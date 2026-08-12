<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import DataTable, { type Column } from '@/Components/Domain/DataTable.vue'
import MultiSelect, { type Option } from '@/Components/UI/MultiSelect.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Role {
    id: number
    name: string
    business_unit: string[]
    company: string[]
    location: string[]
    permissions: string[]
    members: string[]
    protected: boolean
    default: boolean
    is_data_access: boolean
}

interface Perm {
    name: string
    label: string
    section?: string
}

const props = defineProps<{
    roles: Role[]
    permissionGroups: Record<string, Perm[]>
    dataAccessPermissions: Perm[]
    scopeOptions: { businessUnits: string[]; companies: string[]; locations: string[] }
    users: Option[]
}>()

// --- Page tabs: basic permissions vs data access ---
const pageTab = ref<'permissions' | 'data'>('permissions')
const basicRoles = computed(() => props.roles.filter((r) => !r.is_data_access))
const dataRoles = computed(() => props.roles.filter((r) => r.is_data_access))

const toOptions = (values: string[]): Option[] => values.map((v) => ({ value: v, label: v }))
const businessUnitOptions = computed(() => toOptions(props.scopeOptions.businessUnits))
const companyOptions = computed(() => toOptions(props.scopeOptions.companies))
const locationOptions = computed(() => toOptions(props.scopeOptions.locations))

const scopeCount = computed(
    () => form.business_unit.length + form.company.length + form.location.length,
)
const isUnrestricted = computed(() => scopeCount.value === 0)

function clearScope() {
    form.business_unit = []
    form.company = []
    form.location = []
}

const basicColumns: Column[] = [
    { key: 'name', label: t.value.roles.name, tdClass: 'font-medium text-slate-700' },
    { key: 'scope', label: t.value.roles.scope, sortable: false },
    { key: 'permissions', label: t.value.roles.permissions, sortable: false, thClass: 'text-center', tdClass: 'text-center' },
    { key: 'members', label: t.value.roles.members, sortable: false, thClass: 'text-center', tdClass: 'text-center' },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

const dataColumns: Column[] = [
    { key: 'name', label: t.value.roles.name, tdClass: 'font-medium text-slate-700' },
    { key: 'scope', label: t.value.roles.appliesTo, sortable: false },
    { key: 'permissions', label: t.value.roles.dataAccessCol, sortable: false, thClass: 'text-center', tdClass: 'text-center' },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

// --- Create / edit role ---
const roleModal = ref(false)
const editingId = ref<number | null>(null)
const editingDefault = ref(false)

const form = useForm<{
    name: string
    business_unit: string[]
    company: string[]
    location: string[]
    is_data_access: boolean
    permissions: string[]
    members: string[]
}>({
    name: '',
    business_unit: [],
    company: [],
    location: [],
    is_data_access: false,
    permissions: [],
    members: [],
})

function openCreate() {
    editingId.value = null
    editingDefault.value = false
    form.reset()
    form.is_data_access = pageTab.value === 'data'
    form.clearErrors()
    permissionSearch.value = ''
    roleModal.value = true
}

function openEdit(role: Role) {
    editingId.value = role.id
    editingDefault.value = role.default
    form.clearErrors()
    form.name = role.name
    form.business_unit = [...role.business_unit]
    form.company = [...role.company]
    form.location = [...role.location]
    form.is_data_access = role.is_data_access
    form.permissions = [...role.permissions]
    form.members = [...role.members]
    permissionSearch.value = ''
    roleModal.value = true
}

function submitRole() {
    const opts = { preserveScroll: true, onSuccess: () => (roleModal.value = false) }
    if (editingId.value) form.put(`/admin/roles/${editingId.value}`, opts)
    else form.post('/admin/roles', opts)
}

function togglePermission(name: string) {
    const i = form.permissions.indexOf(name)
    if (i === -1) form.permissions.push(name)
    else form.permissions.splice(i, 1)
}

// --- Functional permission picker (Permissions tab) ---
const permissionSearch = ref('')

const allPermissions = computed(() => Object.values(props.permissionGroups).flat())

/** Split permissions into ordered { section, items } blocks for sub-headers. */
function sectionsOf(perms: Perm[]): { section: string; items: Perm[] }[] {
    const map = new Map<string, Perm[]>()
    for (const p of perms) {
        const key = p.section ?? ''
        if (!map.has(key)) map.set(key, [])
        map.get(key)!.push(p)
    }
    return Array.from(map, ([section, items]) => ({ section, items }))
}

const filteredGroups = computed(() => {
    const q = permissionSearch.value.trim().toLowerCase()
    const out: Record<string, Perm[]> = {}
    for (const [group, perms] of Object.entries(props.permissionGroups)) {
        const matched = q
            ? perms.filter(
                  (p) =>
                      p.label.toLowerCase().includes(q) ||
                      p.name.toLowerCase().includes(q) ||
                      group.toLowerCase().includes(q),
              )
            : perms
        if (matched.length) out[group] = matched
    }
    return out
})

const hasFilteredGroups = computed(() => Object.keys(filteredGroups.value).length > 0)

function selectedInGroup(perms: { name: string }[]): number {
    return perms.reduce((n, p) => n + (form.permissions.includes(p.name) ? 1 : 0), 0)
}

function groupAllSelected(perms: { name: string }[]): boolean {
    return perms.length > 0 && selectedInGroup(perms) === perms.length
}

function groupIndeterminate(perms: { name: string }[]): boolean {
    const n = selectedInGroup(perms)
    return n > 0 && n < perms.length
}

function toggleGroup(perms: { name: string }[]) {
    if (groupAllSelected(perms)) {
        const names = new Set(perms.map((p) => p.name))
        form.permissions = form.permissions.filter((p) => !names.has(p))
    } else {
        for (const p of perms) {
            if (!form.permissions.includes(p.name)) form.permissions.push(p.name)
        }
    }
}

function selectAllPermissions() {
    form.permissions = allPermissions.value.map((p) => p.name)
}

function clearAllPermissions() {
    form.permissions = []
}

// --- Delete ---
function remove(role: Role) {
    if (confirm(t.value.roles.confirmDelete)) {
        router.delete(`/admin/roles/${role.id}`, { preserveScroll: true })
    }
}

function scopeChips(role: Role): string[] {
    return [...role.business_unit, ...role.company, ...role.location]
}
</script>

<template>
    <Head :title="t.roles.title" />

    <AppLayout>
        <PageHeader :title="t.roles.title" :subtitle="t.roles.subtitle">
            <template #actions>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    @click="openCreate"
                >
                    <i class="fa-solid fa-plus text-xs" />
                    {{ pageTab === 'data' ? t.roles.addDataRole : t.roles.add }}
                </button>
            </template>
        </PageHeader>

        <!-- Page tabs -->
        <div class="mb-4 flex gap-1 border-b border-border">
            <button
                type="button"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="pageTab === 'permissions' ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-700'"
                @click="pageTab = 'permissions'"
            >
                {{ t.roles.permissionsTab }}
            </button>
            <button
                type="button"
                class="-mb-px border-b-2 px-4 py-2 text-sm font-semibold transition"
                :class="pageTab === 'data' ? 'border-primary text-primary' : 'border-transparent text-slate-500 hover:text-slate-700'"
                @click="pageTab = 'data'"
            >
                {{ t.roles.dataAccessTab }}
            </button>
        </div>

        <!-- ===== Permissions tab ===== -->
        <DataTable v-if="pageTab === 'permissions'" :columns="basicColumns" :rows="basicRoles" row-key="id" min-width="860px">
            <template #cell-scope="{ row }">
                <div v-if="scopeChips(row).length" class="flex flex-wrap gap-1">
                    <span
                        v-for="s in scopeChips(row)"
                        :key="s"
                        class="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                    >
                        {{ s }}
                    </span>
                </div>
                <span v-else class="text-xs italic text-slate-300">{{ t.roles.unrestricted }}</span>
            </template>

            <template #cell-permissions="{ row }">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ row.permissions.length }}</span>
            </template>

            <template #cell-members="{ row }">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ row.members.length }}</span>
            </template>

            <template #cell-action="{ row }">
                <div class="inline-flex items-center gap-1">
                    <button
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                        :title="t.roles.edit"
                        @click="openEdit(row)"
                    >
                        <i class="fa-solid fa-pen text-xs" />
                    </button>
                    <button
                        v-if="!row.protected"
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                        :title="t.roles.delete"
                        @click="remove(row)"
                    >
                        <i class="fa-solid fa-trash text-xs" />
                    </button>
                </div>
            </template>

            <template #empty>{{ t.roles.empty }}</template>
        </DataTable>

        <!-- ===== Data Access tab ===== -->
        <DataTable v-else :columns="dataColumns" :rows="dataRoles" row-key="id" min-width="720px">
            <template #cell-scope="{ row }">
                <div v-if="scopeChips(row).length" class="flex flex-wrap gap-1">
                    <span
                        v-for="s in scopeChips(row)"
                        :key="s"
                        class="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500"
                    >
                        {{ s }}
                    </span>
                </div>
                <span v-else class="text-xs italic text-slate-400">{{ t.roles.allUsers }}</span>
            </template>

            <template #cell-permissions="{ row }">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ row.permissions.length }}</span>
            </template>

            <template #cell-action="{ row }">
                <div class="inline-flex items-center gap-1">
                    <button
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-slate-100 hover:text-primary"
                        :title="t.roles.edit"
                        @click="openEdit(row)"
                    >
                        <i class="fa-solid fa-pen text-xs" />
                    </button>
                    <button
                        v-if="!row.protected"
                        class="h-8 w-8 rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                        :title="t.roles.delete"
                        @click="remove(row)"
                    >
                        <i class="fa-solid fa-trash text-xs" />
                    </button>
                </div>
            </template>

            <template #empty>{{ t.roles.emptyData }}</template>
        </DataTable>

        <!-- Create / edit role -->
        <Drawer :show="roleModal" :title="editingId ? t.roles.edit : (form.is_data_access ? t.roles.addDataRole : t.roles.add)" max-width="max-w-2xl" @close="roleModal = false">
            <form id="role-form" class="space-y-4" @submit.prevent="submitRole">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.roles.name }}</label>
                    <input
                        v-model="form.name"
                        :disabled="editingDefault"
                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary disabled:bg-slate-100 disabled:text-slate-400"
                        :class="form.errors.name ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <!-- Access Scope -->
                <div class="rounded-lg border border-border bg-slate-50/60">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-white px-3 py-2">
                        <div class="flex items-center gap-2">
                            <i class="fa-solid fa-shield-halved text-sm text-primary" />
                            <span class="text-sm font-medium text-slate-700">{{ t.roles.accessScope }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="isUnrestricted"
                                class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-600"
                            >
                                <i class="fa-solid fa-earth-asia text-[10px]" /> {{ form.is_data_access ? t.roles.allUsers : t.roles.unrestrictedBadge }}
                            </span>
                            <template v-else>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-600">
                                    <i class="fa-solid fa-filter text-[10px]" /> {{ t.roles.restrictedBadge }} · {{ scopeCount }}
                                </span>
                                <button type="button" class="text-xs font-medium text-slate-500 hover:text-primary hover:underline" @click="clearScope">
                                    {{ t.roles.clearScope }}
                                </button>
                            </template>
                        </div>
                    </div>

                    <div class="space-y-3 p-3">
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                                <i class="fa-solid fa-sitemap text-[11px] text-slate-400" /> {{ t.roles.businessUnit }}
                                <span v-if="form.business_unit.length" class="ml-auto rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold text-primary">{{ form.business_unit.length }}</span>
                            </label>
                            <MultiSelect v-model="form.business_unit" :options="businessUnitOptions" :placeholder="t.roles.any" />
                        </div>
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                                <i class="fa-solid fa-building text-[11px] text-slate-400" /> {{ t.roles.company }}
                                <span v-if="form.company.length" class="ml-auto rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold text-primary">{{ form.company.length }}</span>
                            </label>
                            <MultiSelect v-model="form.company" :options="companyOptions" :placeholder="t.roles.any" />
                        </div>
                        <div>
                            <label class="mb-1 flex items-center gap-1.5 text-xs font-medium text-slate-600">
                                <i class="fa-solid fa-location-dot text-[11px] text-slate-400" /> {{ t.roles.location }}
                                <span v-if="form.location.length" class="ml-auto rounded-full bg-primary/10 px-1.5 text-[10px] font-semibold text-primary">{{ form.location.length }}</span>
                            </label>
                            <MultiSelect v-model="form.location" :options="locationOptions" :placeholder="t.roles.any" />
                        </div>
                    </div>
                    <p class="px-3 pb-3 text-xs text-slate-400">{{ form.is_data_access ? t.roles.dataScopeHint : t.roles.scopeHint }}</p>
                </div>

                <!-- ===== Basic role: assign users + functional permissions ===== -->
                <template v-if="!form.is_data_access">
                    <div class="rounded-lg border border-border bg-slate-50/60">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border bg-white px-3 py-2">
                            <div class="flex items-center gap-2">
                                <i class="fa-solid fa-users text-sm text-primary" />
                                <span class="text-sm font-medium text-slate-700">{{ t.roles.assignUsers }}</span>
                            </div>
                            <span
                                v-if="form.members.length"
                                class="rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-semibold text-primary"
                            >
                                {{ form.members.length }}
                            </span>
                        </div>
                        <div class="p-3">
                            <MultiSelect v-model="form.members" :options="users" :placeholder="t.roles.searchUsers" />
                            <p class="mt-2 text-xs text-slate-400">{{ t.roles.membersHint }}</p>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <label class="text-sm font-medium text-slate-700">
                                {{ t.roles.permissions }}
                                <span class="ml-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                                    {{ form.permissions.length }} / {{ allPermissions.length }} {{ t.roles.selectedCount }}
                                </span>
                            </label>
                            <div class="flex items-center gap-3 text-xs font-medium">
                                <button type="button" class="text-primary hover:underline" @click="selectAllPermissions">
                                    {{ t.roles.selectAll }}
                                </button>
                                <span class="text-slate-300">|</span>
                                <button type="button" class="text-slate-500 hover:underline" @click="clearAllPermissions">
                                    {{ t.roles.clearAll }}
                                </button>
                            </div>
                        </div>

                        <div class="relative mb-3">
                            <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400" />
                            <input
                                v-model="permissionSearch"
                                type="search"
                                :placeholder="t.roles.searchPermissions"
                                class="w-full rounded-md border border-border bg-white py-2 pl-9 pr-3 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            >
                        </div>

                        <div v-if="hasFilteredGroups" class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div
                                v-for="(perms, group) in filteredGroups"
                                :key="group"
                                class="flex flex-col rounded-lg border border-border bg-slate-50/60"
                            >
                                <label class="flex cursor-pointer items-center gap-2 rounded-t-lg border-b border-border bg-white px-3 py-2">
                                    <input
                                        type="checkbox"
                                        class="rounded border-slate-300 text-primary focus:ring-primary"
                                        :checked="groupAllSelected(perms)"
                                        :indeterminate="groupIndeterminate(perms)"
                                        @change="toggleGroup(perms)"
                                    >
                                    <span class="flex-1 text-xs font-bold uppercase tracking-wider text-slate-500">{{ group }}</span>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">
                                        {{ selectedInGroup(perms) }}/{{ perms.length }}
                                    </span>
                                </label>
                                <div class="flex flex-col gap-0.5 p-1.5">
                                    <template v-for="sec in sectionsOf(perms)" :key="sec.section">
                                        <div
                                            v-if="sectionsOf(perms).length > 1 && sec.section"
                                            class="px-2 pb-0.5 pt-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-400"
                                        >
                                            {{ sec.section }}
                                        </div>
                                        <label
                                            v-for="p in sec.items"
                                            :key="p.name"
                                            class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-600 transition hover:bg-white"
                                            :class="form.permissions.includes(p.name) ? 'bg-white font-medium text-slate-800' : ''"
                                        >
                                            <input
                                                type="checkbox"
                                                :checked="form.permissions.includes(p.name)"
                                                class="rounded border-slate-300 text-primary focus:ring-primary"
                                                @change="togglePermission(p.name)"
                                            >
                                            {{ p.label }}
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <p v-else class="rounded-md border border-dashed border-border py-6 text-center text-sm text-slate-400">
                            {{ t.roles.noPermissionsMatch }}
                        </p>
                    </div>
                </template>

                <!-- ===== Data-access role: data capabilities ===== -->
                <div v-else class="rounded-lg border border-border bg-slate-50/60">
                    <div class="flex items-center gap-2 border-b border-border bg-white px-3 py-2">
                        <i class="fa-solid fa-database text-sm text-primary" />
                        <span class="text-sm font-medium text-slate-700">{{ t.roles.dataAccessPermission }}</span>
                    </div>
                    <div class="flex flex-col gap-0.5 p-1.5">
                        <template v-for="sec in sectionsOf(props.dataAccessPermissions)" :key="sec.section">
                            <div
                                v-if="sec.section"
                                class="px-2 pb-0.5 pt-1.5 text-[10px] font-semibold uppercase tracking-wider text-slate-400"
                            >
                                {{ sec.section }}
                            </div>
                            <label
                                v-for="p in sec.items"
                                :key="p.name"
                                class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-600 transition hover:bg-white"
                                :class="form.permissions.includes(p.name) ? 'bg-white font-medium text-slate-800' : ''"
                            >
                                <input
                                    type="checkbox"
                                    :checked="form.permissions.includes(p.name)"
                                    class="rounded border-slate-300 text-primary focus:ring-primary"
                                    @change="togglePermission(p.name)"
                                >
                                {{ p.label }}
                            </label>
                        </template>
                    </div>
                    <p class="px-3 pb-3 pt-1 text-xs text-slate-400">{{ t.roles.dataAccessHint }}</p>
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="roleModal = false">
                    {{ t.roles.cancel }}
                </button>
                <button type="submit" form="role-form" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.roles.save }}
                </button>
            </template>
        </Drawer>
    </AppLayout>
</template>
