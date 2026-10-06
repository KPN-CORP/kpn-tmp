<script setup lang="ts">
/**
 * The read-only detail drawer of the Master Implementation screen: the whole
 * mapping, which a row can only ever show the head of. It holds the row's own
 * value (the page passes it), so it keeps rendering while the drawer closes.
 * Read-only, so — like the activation history — it closes without asking.
 */
import Drawer from '@/Components/Domain/Drawer.vue'
import ProficiencyLevelCell from '@/Components/Domain/ProficiencyLevelCell.vue'
import { formatDateTime } from '@/Composables/useDate'
import { useLocale } from '@/Composables/useLocale'
import type { ImplementationDetail } from '@/types/masterImplementation'

const { t } = useLocale()

defineProps<{
    impl: ImplementationDetail | null
}>()

const emit = defineEmits<{
    close: []
    // Straight from reading a mapping to changing it.
    edit: []
}>()
</script>

<template>
    <Drawer
        :show="impl !== null"
        :title="t.idp.settings.implementationDetail"
        max-width="max-w-2xl"
        @close="emit('close')"
    >
        <div v-if="impl" class="space-y-6">
            <!-- What it maps -->
            <div class="rounded-lg border border-border bg-slate-50/60 p-4">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    {{ t.idp.settings.competency }}
                </p>
                <div class="mt-1.5 flex flex-wrap items-center gap-2">
                    <span
                        v-if="impl.competency_code"
                        class="inline-flex items-center rounded bg-indigo-50 px-1.5 py-0.5 font-mono text-xs font-semibold text-indigo-700"
                    >
                        {{ impl.competency_code }}
                    </span>
                    <span class="text-base font-semibold text-slate-800">
                        {{ impl.competency_name || '—' }}
                    </span>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-border/60 pt-3">
                    <span class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.competencyType }}
                    </span>
                    <span
                        v-if="impl.type_code"
                        class="inline-flex items-center rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-indigo-700"
                    >
                        {{ impl.type_code }}
                    </span>
                    <span v-if="impl.type_name" class="text-sm font-medium text-slate-700">
                        {{ impl.type_name }}
                    </span>
                    <span v-else class="text-xs italic text-slate-300">
                        {{ t.idp.settings.untyped }}
                    </span>

                    <span
                        class="ml-auto inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="
                            impl.is_active
                                ? 'bg-emerald-50 text-emerald-600'
                                : 'bg-slate-100 text-slate-500'
                        "
                    >
                        {{
                            impl.is_active
                                ? t.idp.settings.activeBadge
                                : t.idp.settings.inactiveBadge
                        }}
                    </span>
                </div>
            </div>

            <!-- The rungs, in full — the part a row cannot carry -->
            <div>
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                    {{ t.idp.settings.proficiencyLevel }}
                </p>
                <div v-if="impl.lines.length" class="space-y-3">
                    <div
                        v-for="line in impl.lines"
                        :key="line.id"
                        class="rounded-md border border-border/60 px-3 py-2.5"
                    >
                        <ProficiencyLevelCell
                            :name="line.name"
                            :sequence="line.sequence"
                            :code="line.code"
                            :description="line.description"
                            :active="line.active"
                        />
                    </div>
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed border-border bg-slate-50/60 px-3 py-2 text-xs text-slate-500"
                >
                    {{ t.idp.settings.noProficiencyLevel }}
                </p>
            </div>

            <!-- Who it applies to -->
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.businessUnit }}
                    </p>
                    <div v-if="impl.business_units?.length" class="flex flex-wrap gap-1">
                        <span
                            v-for="bu in impl.business_units"
                            :key="bu"
                            class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600"
                        >
                            {{ bu }}
                        </span>
                    </div>
                    <span v-else class="text-xs italic text-slate-300">—</span>
                </div>

                <div>
                    <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.grade }}
                    </p>
                    <div v-if="impl.grades?.length" class="flex flex-wrap gap-1">
                        <span
                            v-for="grade in impl.grades"
                            :key="grade"
                            class="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600"
                        >
                            {{ grade }}
                        </span>
                    </div>
                    <span v-else class="text-xs italic text-slate-300">—</span>
                </div>
            </div>

            <!-- When -->
            <div class="grid gap-5 border-t border-border/60 pt-4 sm:grid-cols-2">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.createdAt }}
                    </p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ formatDateTime(impl.created_at) }}
                    </p>
                </div>
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                        {{ t.idp.settings.updatedAt }}
                    </p>
                    <p class="mt-1 text-sm text-slate-600">
                        {{ formatDateTime(impl.updated_at) }}
                    </p>
                </div>
            </div>
        </div>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-border px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
                @click="emit('close')"
            >
                {{ t.idp.form.close }}
            </button>

            <button
                type="button"
                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover"
                @click="emit('edit')"
            >
                {{ t.idp.settings.editImplementation }}
            </button>
        </template>
    </Drawer>
</template>
