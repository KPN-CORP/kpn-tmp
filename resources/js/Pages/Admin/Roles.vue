<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Modal from '@/Components/Domain/Modal.vue'
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
}

const props = defineProps<{
    roles: Role[]
    permissionGroups: Record<string, { name: string; label: string }[]>
    scopeOptions: { businessUnits: string[]; companies: string[]; locations: string[] }
    users: Option[]
}>()

const toOptions = (values: string[]): Option[] => values.map((v) => ({ value: v, label: v }))
const businessUnitOptions = computed(() => toOptions(props.scopeOptions.businessUnits))
const companyOptions = computed(() => toOptions(props.scopeOptions.companies))
const locationOptions = computed(() => toOptions(props.scopeOptions.locations))

const columns: Column[] = [
    { key: 'name', label: t.value.roles.name, tdClass: 'font-medium text-slate-700' },
    { key: 'scope', label: t.value.roles.scope, sortable: false },
    { key: 'permissions', label: t.value.roles.permissions, sortable: false, thClass: 'text-center', tdClass: 'text-center' },
    { key: 'members', label: t.value.roles.members, sortable: false, thClass: 'text-center', tdClass: 'text-center' },
    { key: 'action', label: '', thClass: 'text-right', tdClass: 'text-right' },
]

// --- Create / edit role ---
const roleModal = ref(false)
const editingId = ref<number | null>(null)

const form = useForm<{
    name: string
    business_unit: string[]
    company: string[]
    location: string[]
    permissions: string[]
}>({ name: '', business_unit: [], company: [], location: [], permissions: [] })

function openCreate() {
    editingId.value = null
    form.reset()
    form.clearErrors()
    roleModal.value = true
}

function openEdit(role: Role) {
    editingId.value = role.id
    form.clearErrors()
    form.name = role.name
    form.business_unit = [...role.business_unit]
    form.company = [...role.company]
    form.location = [...role.location]
    form.permissions = [...role.permissions]
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

// --- Assign users ---
const membersModal = ref(false)
const membersRole = ref<Role | null>(null)
const membersForm = useForm<{ members: string[] }>({ members: [] })

function openMembers(role: Role) {
    membersRole.value = role
    membersForm.clearErrors()
    membersForm.members = [...role.members]
    membersModal.value = true
}

function submitMembers() {
    if (!membersRole.value) return
    membersForm.put(`/admin/roles/${membersRole.value.id}/members`, {
        preserveScroll: true,
        onSuccess: () => (membersModal.value = false),
    })
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
                    <i class="fa-solid fa-plus text-xs" /> {{ t.roles.add }}
                </button>
            </template>
        </PageHeader>

        <DataTable :columns="columns" :rows="roles" row-key="id" min-width="860px">
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
                        class="inline-flex h-8 items-center gap-1.5 rounded-md border border-border bg-white px-2.5 text-xs font-medium text-slate-600 transition hover:border-primary/40 hover:text-primary"
                        :title="t.roles.assignUsers"
                        @click="openMembers(row)"
                    >
                        <i class="fa-solid fa-user-plus" /> {{ t.roles.assign }}
                    </button>
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

        <!-- Create / edit role -->
        <Modal :show="roleModal" :title="editingId ? t.roles.edit : t.roles.add" max-width="max-w-2xl" @close="roleModal = false">
            <form id="role-form" class="space-y-4" @submit.prevent="submitRole">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.roles.name }}</label>
                    <input
                        v-model="form.name"
                        class="w-full rounded-md border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.name ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.businessUnit }}</label>
                        <MultiSelect v-model="form.business_unit" :options="businessUnitOptions" :placeholder="t.roles.any" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.company }}</label>
                        <MultiSelect v-model="form.company" :options="companyOptions" :placeholder="t.roles.any" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.location }}</label>
                        <MultiSelect v-model="form.location" :options="locationOptions" :placeholder="t.roles.any" />
                    </div>
                </div>
                <p class="text-xs text-slate-400">{{ t.roles.scopeHint }}</p>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">{{ t.roles.permissions }}</label>
                    <div class="max-h-64 space-y-4 overflow-y-auto rounded-md border border-border p-3">
                        <div v-for="(perms, group) in permissionGroups" :key="group">
                            <div class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">{{ group }}</div>
                            <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" :checked="form.permissions.includes(p.name)" class="rounded border-slate-300 text-primary focus:ring-primary" @change="togglePermission(p.name)">
                                    {{ p.label }}
                                </label>
                            </div>
                        </div>
                    </div>
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
        </Modal>

        <!-- Assign users -->
        <Modal :show="membersModal" :title="`${t.roles.assignUsers} — ${membersRole?.name ?? ''}`" @close="membersModal = false">
            <form id="members-form" @submit.prevent="submitMembers">
                <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.roles.membersLabel }}</label>
                <MultiSelect v-model="membersForm.members" :options="users" :placeholder="t.roles.searchUsers" />
                <p class="mt-2 text-xs text-slate-400">{{ t.roles.membersHint }}</p>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="membersModal = false">
                    {{ t.roles.cancel }}
                </button>
                <button type="submit" form="members-form" :disabled="membersForm.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.roles.save }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
