<script setup lang="ts">
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import Drawer from '@/Components/Domain/Drawer.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

interface ResultSummary {
    critical_position: string | null
    successor_type: string | null
    successor_to_position: string | null
}

const props = defineProps<{
    employeeId: string
    resultSummary: ResultSummary | null
    canInput: boolean
}>()

const modalOpen = ref(false)

const form = useForm({
    employee_id: props.employeeId,
    critical_position: props.resultSummary?.critical_position ?? '',
    successor_type: props.resultSummary?.successor_type ?? '',
    successor_to_position: props.resultSummary?.successor_to_position ?? '',
})

function open() {
    form.clearErrors()
    modalOpen.value = true
}

function submit() {
    form.post('/result-summary', {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    })
}

const items = () => [
    { label: t.value.result.criticalPosition, value: props.resultSummary?.critical_position },
    { label: t.value.result.successorType, value: props.resultSummary?.successor_type },
    { label: t.value.result.successorTo, value: props.resultSummary?.successor_to_position },
]
</script>

<template>
    <section>
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500">
                {{ t.result.title }}
            </h3>
            <button
                v-if="canInput"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                @click="open"
            >
                <i class="fa-solid fa-pen" /> {{ t.result.edit }}
            </button>
        </div>

        <dl class="grid grid-cols-1 gap-x-8 gap-y-4 rounded-xl border border-border bg-white p-5 shadow-sm sm:grid-cols-3">
            <div v-for="item in items()" :key="item.label">
                <dt class="text-xs uppercase tracking-wider text-slate-400">{{ item.label }}</dt>
                <dd class="mt-0.5 text-sm font-medium text-slate-700">{{ item.value || '—' }}</dd>
            </div>
        </dl>

        <Drawer :show="modalOpen" :title="t.result.title" @close="modalOpen = false">
            <form id="result-form" class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.result.criticalPosition }}</label>
                    <input v-model="form.critical_position" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.result.successorType }}</label>
                    <input v-model="form.successor_type" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-slate-700">{{ t.result.successorTo }}</label>
                    <input v-model="form.successor_to_position" class="w-full rounded-md border border-border px-3 py-2 text-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary">
                </div>
            </form>
            <template #footer>
                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50" @click="modalOpen = false">
                    {{ t.result.cancel }}
                </button>
                <button type="submit" form="result-form" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:opacity-60">
                    {{ t.result.save }}
                </button>
            </template>
        </Drawer>
    </section>
</template>
