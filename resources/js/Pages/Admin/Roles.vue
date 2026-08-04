<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Modal from '@/Components/Domain/Modal.vue'
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
}>()

const modalOpen = ref(false)
const editingId = ref<number | null>(null)

const form = useForm<{
    name: string
    business_unit: string[]
    company: string[]
    location: string[]
    permissions: string[]
    members: string[]
}>({
    name: '',
    business_unit: [],
    company: [],
    location: [],
    permissions: [],
    members: [],
})

// Comma-separated text mirrors for the array fields.
const buText = ref('')
const coText = ref('')
const locText = ref('')
const membersText = ref('')

const split = (s: string) => s.split(',').map((v) => v.trim()).filter(Boolean)
const join = (a: string[]) => (a ?? []).join(', ')

function openCreate() {
    editingId.value = null
    form.reset()
    form.clearErrors()
    buText.value = coText.value = locText.value = membersText.value = ''
    modalOpen.value = true
}

function openEdit(role: Role) {
    editingId.value = role.id
    form.clearErrors()
    form.name = role.name
    form.permissions = [...role.permissions]
    buText.value = join(role.business_unit)
    coText.value = join(role.company)
    locText.value = join(role.location)
    membersText.value = join(role.members)
    modalOpen.value = true
}

function submit() {
    form.business_unit = split(buText.value)
    form.company = split(coText.value)
    form.location = split(locText.value)
    form.members = split(membersText.value)

    const opts = { preserveScroll: true, onSuccess: () => (modalOpen.value = false) }
    if (editingId.value) form.put(`/admin/roles/${editingId.value}`, opts)
    else form.post('/admin/roles', opts)
}

function remove(role: Role) {
    if (confirm(t.value.roles.confirmDelete)) {
        router.delete(`/admin/roles/${role.id}`, { preserveScroll: true })
    }
}

function toggle(name: string) {
    const i = form.permissions.indexOf(name)
    if (i === -1) form.permissions.push(name)
    else form.permissions.splice(i, 1)
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

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="role in roles"
                :key="role.id"
                class="rounded-xl border border-border bg-white p-5 shadow-sm"
            >
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-bold text-slate-800">{{ role.name }}</h3>
                        <p class="mt-0.5 text-xs text-slate-400">
                            {{ role.permissions.length }} {{ t.roles.permissions }} ·
                            {{ role.members.length }} {{ t.roles.members }}
                        </p>
                    </div>
                    <div class="flex gap-1">
                        <button class="h-8 w-8 rounded-md text-slate-400 hover:bg-slate-100 hover:text-primary" @click="openEdit(role)">
                            <i class="fa-solid fa-pen text-xs" />
                        </button>
                        <button
                            v-if="!role.protected"
                            class="h-8 w-8 rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600"
                            @click="remove(role)"
                        >
                            <i class="fa-solid fa-trash text-xs" />
                        </button>
                    </div>
                </div>

                <div v-if="role.business_unit.length || role.company.length || role.location.length" class="mt-3 flex flex-wrap gap-1">
                    <span v-for="s in [...role.business_unit, ...role.company, ...role.location]" :key="s" class="rounded bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">
                        {{ s }}
                    </span>
                </div>
                <p v-else class="mt-3 text-xs italic text-slate-300">{{ t.roles.unrestricted }}</p>
            </div>
        </div>

        <!-- Create / edit modal -->
        <Modal :show="modalOpen" :title="editingId ? t.roles.edit : t.roles.add" max-width="max-w-2xl" @close="modalOpen = false">
            <form id="role-form" class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.roles.name }}</label>
                    <input
                        v-model="form.name"
                        class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.name ? 'border-red-500' : 'border-border'"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.businessUnit }}</label>
                        <input v-model="buText" :placeholder="t.roles.commaSep" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.company }}</label>
                        <input v-model="coText" :placeholder="t.roles.commaSep" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.location }}</label>
                        <input v-model="locText" :placeholder="t.roles.commaSep" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">{{ t.roles.membersLabel }}</label>
                    <textarea v-model="membersText" rows="2" :placeholder="t.roles.membersHint" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">{{ t.roles.permissions }}</label>
                    <div class="max-h-64 space-y-4 overflow-y-auto rounded-md border border-border p-3">
                        <div v-for="(perms, group) in permissionGroups" :key="group">
                            <div class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-400">{{ group }}</div>
                            <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                                <label v-for="p in perms" :key="p.name" class="flex items-center gap-2 text-sm text-slate-600">
                                    <input type="checkbox" :checked="form.permissions.includes(p.name)" class="rounded border-slate-300 text-primary focus:ring-primary" @change="toggle(p.name)">
                                    {{ p.label }}
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="modalOpen = false">
                    {{ t.roles.cancel }}
                </button>
                <button type="submit" form="role-form" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.roles.save }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
