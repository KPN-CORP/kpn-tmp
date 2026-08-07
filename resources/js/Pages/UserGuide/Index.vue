<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/Components/UI/PageHeader.vue'
import Drawer from '@/Components/Domain/Drawer.vue'
import SearchableSelect from '@/Components/UI/SearchableSelect.vue'
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

const drawerOpen = ref(false)
const dragOver = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

const form = useForm<{ title: string; description: string; target_role: string; file: File | null }>({
    title: '',
    description: '',
    target_role: 'all',
    file: null,
})

const fileSizeLabel = computed(() => {
    if (!form.file) return ''
    const kb = form.file.size / 1024
    return kb < 1024 ? `${kb.toFixed(0)} KB` : `${(kb / 1024).toFixed(1)} MB`
})

function open() {
    form.reset()
    form.clearErrors()
    if (fileInput.value) fileInput.value.value = ''
    drawerOpen.value = true
}

function onFile(e: Event) {
    form.file = (e.target as HTMLInputElement).files?.[0] ?? null
}

function onDrop(e: DragEvent) {
    dragOver.value = false
    const file = e.dataTransfer?.files?.[0]
    if (file) form.file = file
}

function clearFile() {
    form.file = null
    if (fileInput.value) fileInput.value.value = ''
}

function submit() {
    form.post('/user-guide', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (drawerOpen.value = false),
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

        <!-- Upload drawer (slides in from the right) -->
        <Drawer :show="drawerOpen" :title="t.guide.add" @close="drawerOpen = false">
            <form id="guide-form" class="space-y-6" @submit.prevent="submit">
                <!-- File dropzone -->
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.guide.file }}</label>

                    <label
                        v-if="!form.file"
                        class="group relative flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition"
                        :class="[
                            dragOver ? 'border-primary bg-red-50/60' : 'border-border bg-slate-50/60 hover:border-primary/50 hover:bg-red-50/30',
                            form.errors.file ? 'border-red-400' : '',
                        ]"
                        @dragover.prevent="dragOver = true"
                        @dragleave.prevent="dragOver = false"
                        @drop.prevent="onDrop"
                    >
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-white text-primary shadow-sm ring-1 ring-black/5 transition group-hover:scale-105">
                            <i class="fa-solid fa-cloud-arrow-up text-lg" />
                        </span>
                        <span class="text-sm font-semibold text-slate-700">
                            {{ t.guide.dropHint }}
                        </span>
                        <span class="text-xs text-slate-400">{{ t.guide.acceptedFormats }}</span>
                        <input ref="fileInput" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx" class="absolute inset-0 cursor-pointer opacity-0" @change="onFile">
                    </label>

                    <!-- Selected file preview -->
                    <div v-else class="flex items-center gap-3 rounded-xl border border-border bg-white p-3 shadow-sm">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-red-50 text-primary">
                            <i class="fa-solid fa-file-lines" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-slate-800">{{ form.file.name }}</p>
                            <p class="text-xs text-slate-400">{{ fileSizeLabel }}</p>
                        </div>
                        <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md text-slate-400 transition hover:bg-red-50 hover:text-red-600" @click="clearFile">
                            <i class="fa-solid fa-xmark" />
                        </button>
                    </div>

                    <p v-if="form.errors.file" class="mt-1.5 text-xs text-red-600">{{ form.errors.file }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.guide.guideTitle }}</label>
                    <input v-model="form.title" class="w-full rounded-lg border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" :class="form.errors.title ? 'border-red-500' : 'border-border'">
                    <p v-if="form.errors.title" class="mt-1 text-xs text-red-600">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.guide.description }}</label>
                    <textarea v-model="form.description" rows="3" class="w-full rounded-lg border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary" />
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700">{{ t.guide.targetRole }}</label>
                    <SearchableSelect
                        v-model="form.target_role"
                        :options="[{ value: 'all', label: 'all' }, { value: 'manager', label: 'manager' }, { value: 'admin', label: 'admin' }]"
                    />
                </div>
            </form>

            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="drawerOpen = false">
                    {{ t.guide.cancel }}
                </button>
                <button type="submit" form="guide-form" :disabled="form.processing || !form.file" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    <i v-if="form.processing" class="fa-solid fa-spinner fa-spin text-xs" />
                    {{ t.guide.save }}
                </button>
            </template>
        </Drawer>
    </AppLayout>
</template>
