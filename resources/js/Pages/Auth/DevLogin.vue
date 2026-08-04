<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AuthBrand from '@/Components/UI/AuthBrand.vue'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'

const { t } = useLocale()

const form = useForm({
    access_key: '',
})

function submit() {
    form.post('/dev-login', {
        onFinish: () => form.reset('access_key'),
    })
}
</script>

<template>
    <Head :title="t.auth.devLoginTitle" />

    <AuthLayout>
        <div class="mb-6 flex justify-end">
            <LanguageSwitcher align="right" />
        </div>

        <AuthBrand
            :title="t.auth.devLoginTitle"
            :subtitle="t.auth.devLoginSubtitle"
        />

        <form
            class="space-y-4"
            @submit.prevent="submit"
        >
            <div>
                <label
                    for="access_key"
                    class="mb-1.5 block text-sm font-medium text-slate-700"
                >
                    {{ t.auth.accessKey }}
                </label>

                <div class="relative">
                    <i
                        class="fa-solid fa-key pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                    />

                    <input
                        id="access_key"
                        v-model="form.access_key"
                        type="password"
                        autocomplete="off"
                        required
                        :placeholder="t.auth.accessKeyPlaceholder"
                        class="w-full rounded-md border py-2.5 pl-9 pr-3 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.access_key ? 'border-red-500 bg-red-50' : 'border-border'"
                    >
                </div>

                <p
                    v-if="form.errors.access_key"
                    class="mt-1.5 text-xs text-red-600"
                >
                    {{ form.errors.access_key }}
                </p>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="flex w-full items-center justify-center gap-2 rounded-md bg-primary py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-60"
            >
                <i
                    v-if="form.processing"
                    class="fa-solid fa-spinner fa-spin"
                />
                {{ t.auth.continue }}
            </button>
        </form>

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
