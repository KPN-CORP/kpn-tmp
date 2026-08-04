<script setup lang="ts">
import { ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Modal from '@/Components/Domain/Modal.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface Guide {
    id: number
    title: string
    description: string | null
    file_name: string
    file_size: string | null
    target_role: string
    uploader?: { name: string } | null
    created_at: string
}

const props = defineProps<{
    guides: Guide[]
    canManage: boolean
}>()

const modalOpen = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ title: string; description: string; target_role: string; file: File | null }>({
    title: '',
    description: '',
    target_role: 'all',
    file: null,
})

function open() {
    form.reset()
    form.clearErrors()
    if (fileInput.value) fileInput.value.value = ''
    modalOpen.value = true
}

function onFile(e: Event) {
    form.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

function submit() {
    form.post('/user-guide', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (modalOpen.value = false),
    })
}

function remove(guide: Guide) {
    if (confirm(t.value.guide.confirmDelete)) {
        router.delete(`/user-guide/${guide.id}`, { preserveScroll: true })
    }
}
</script>

<template>
    <Head :title="t.guide.title" />

    <AppLayout>
        <PageHeader :title="t.guide.title" :subtitle="t.guide.subtitle">
            <template #actions>
                <button
                    v-if="canManage"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover"
                    @click="open"
                >
                    <i class="fa-solid fa-plus text-xs" /> {{ t.guide.add }}
                </button>
            </template>
        </PageHeader>

        <div v-if="guides.length" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="guide in guides" :key="guide.id" class="flex flex-col rounded-xl border border-border bg-white p-5 shadow-sm">
                <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-lg bg-red-50 text-primary">
                    <i class="fa-solid fa-file-lines" />
                </div>
                <h3 class="font-bold text-slate-800">{{ guide.title }}</h3>
                <p class="mt-1 flex-1 text-sm text-slate-500">{{ guide.description || '—' }}</p>
                <div class="mt-3 flex items-center justify-between text-xs text-slate-400">
                    <span>{{ guide.file_size }} · {{ guide.target_role }}</span>
                </div>
                <div class="mt-4 flex items-center gap-2 border-t border-border pt-3">
                    <a
                        :href="`/user-guide/${guide.id}/download`"
                        class="inline-flex flex-1 items-center justify-center gap-2 rounded-md border border-primary/30 px-3 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                    >
                        <i class="fa-solid fa-download" /> {{ t.guide.download }}
                    </a>
                    <button v-if="canManage" class="h-8 w-8 rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600" @click="remove(guide)">
                        <i class="fa-solid fa-trash text-xs" />
                    </button>
                </div>
            </div>
        </div>

        <div v-else class="rounded-xl border border-dashed border-border bg-white py-16 text-center text-sm text-slate-400">
            {{ t.guide.empty }}
        </div>

        <!-- Upload modal -->
        <Modal :show="modalOpen" :title="t.guide.add" @close="modalOpen = false">
            <form id="guide-form" class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.guide.guideTitle }}</label>
                    <input v-model="form.title" class="w-full rounded-md border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" :class="form.errors.title ? 'border-red-500' : 'border-border'">
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.guide.description }}</label>
                    <textarea v-model="form.description" rows="2" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.guide.targetRole }}</label>
                    <select v-model="form.target_role" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                        <option value="all">all</option>
                        <option value="manager">manager</option>
                        <option value="admin">admin</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.guide.file }}</label>
                    <input ref="fileInput" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx" class="w-full rounded-md border border-border px-3 py-1.5 text-sm" @change="onFile">
                    <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="modalOpen = false">
                    {{ t.guide.cancel }}
                </button>
                <button type="submit" form="guide-form" :disabled="form.processing || !form.file" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.guide.save }}
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>
