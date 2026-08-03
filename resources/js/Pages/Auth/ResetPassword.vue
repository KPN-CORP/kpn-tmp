<script setup lang="ts">
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import AuthBrand from '@/Components/UI/AuthBrand.vue'
import LanguageSwitcher from '@/Components/UI/LanguageSwitcher.vue'
import { useLocale } from '@/Composables/useLocale'

const props = defineProps<{
    email: string
    token: string
}>()

const { t } = useLocale()

const showPassword = ref(false)

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="t.auth.resetTitle" />

    <AuthLayout>
        <!-- Language, matching the login screen's switcher -->
        <div class="mb-6 flex justify-end">
            <LanguageSwitcher align="right" />
        </div>

        <!-- Brand -->
        <AuthBrand
            :title="t.auth.resetTitle"
            :subtitle="t.auth.resetSubtitle"
        />

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

            <!-- New password -->
            <div>
                <label
                    for="password"
                    class="mb-1.5 block text-sm font-medium text-slate-700"
                >
                    {{ t.auth.newPassword }}
                </label>

                <div class="relative">
                    <i
                        class="fa-solid fa-lock pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                    />

                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        :placeholder="t.auth.passwordPlaceholder"
                        class="w-full rounded-md border py-2.5 pl-9 pr-10 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.password ? 'border-red-500 bg-red-50' : 'border-border'"
                    >

                    <button
                        type="button"
                        class="absolute right-0 top-0 flex h-full w-10 items-center justify-center text-slate-400 transition-colors hover:text-primary"
                        :aria-label="showPassword ? t.auth.hidePassword : t.auth.showPassword"
                        tabindex="-1"
                        @click="showPassword = !showPassword"
                    >
                        <i
                            class="fa-solid text-sm"
                            :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"
                        />
                    </button>
                </div>

                <p
                    v-if="form.errors.password"
                    class="mt-1.5 text-xs text-red-600"
                >
                    {{ form.errors.password }}
                </p>
            </div>

            <!-- Confirm password -->
            <div>
                <label
                    for="password_confirmation"
                    class="mb-1.5 block text-sm font-medium text-slate-700"
                >
                    {{ t.auth.confirmPassword }}
                </label>

                <div class="relative">
                    <i
                        class="fa-solid fa-lock pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400"
                    />

                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        required
                        :placeholder="t.auth.passwordPlaceholder"
                        class="w-full rounded-md border py-2.5 pl-9 pr-3 text-sm transition-colors focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                        :class="form.errors.password_confirmation ? 'border-red-500 bg-red-50' : 'border-border'"
                    >
                </div>

                <p
                    v-if="form.errors.password_confirmation"
                    class="mt-1.5 text-xs text-red-600"
                >
                    {{ form.errors.password_confirmation }}
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

                {{ form.processing ? t.auth.resetting : t.auth.resetButton }}
            </button>
        </form>
    </AuthLayout>
</template>
