<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AuthBrand from '@/Components/UI/AuthBrand.vue'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'

defineProps<{
    status?: string
}>()

const { t } = useLocale()

const form = useForm({
    email: '',
})

function submit() {
    form.post('/forgot-password')
}
</script>

<template>
    <Head :title="t.auth.forgotTitle" />

    <AuthLayout>
        <!-- Language, matching the login screen's switcher -->
        <div class="mb-6 flex justify-end">
            <LanguageSwitcher align="right" />
        </div>

        <!-- Brand -->
        <AuthBrand
            :title="t.auth.forgotTitle"
            :subtitle="t.auth.forgotSubtitle"
        />

        <!-- Success message once a link has been sent -->
        <div
            v-if="status"
            class="mb-4 rounded-md border border-green-200 bg-green-50 px-3 py-2.5 text-sm text-green-700"
        >
            {{ status }}
        </div>

        <form
            class="space-y-4"
            @submit.prevent="submit"
        >
            <!-- Email -->
            <div>
                <label
                    for="email"
                    class="mb-1.5 block text-sm font-medium text-slate-700"
                >
                    {{ t.auth.email }}
                </label>

                <div class="relative">
                    <i
                        class="fa-regular fa-envelope pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                    />

                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        required
                        :placeholder="t.auth.emailPlaceholder"
                        class="w-full rounded-md border py-2.5 pl-9 pr-3 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.email ? 'border-red-500 bg-red-50' : 'border-border'"
                    >
                </div>

                <p
                    v-if="form.errors.email"
                    class="mt-1.5 text-xs text-red-600"
                >
                    {{ form.errors.email }}
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

                {{ form.processing ? t.auth.sending : t.auth.sendResetLink }}
            </button>
        </form>

        <p class="mt-6 border-t border-border pt-4 text-center text-sm">
            <Link
                href="/login"
                class="font-medium text-primary hover:underline"
            >
                {{ t.auth.backToLogin }}
            </Link>
        </p>
    </AuthLayout>
</template>
