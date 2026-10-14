<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PasswordController from '@/actions/App/Http/Controllers/Settings/PasswordController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/user-password';
import type { BreadcrumbItem } from '@/types';

// Las cuentas creadas con Google no tienen contraseña: acá crean la primera
const hasPassword = computed(() => usePage().props.auth.hasPassword);

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Password settings',
        href: edit(),
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Password settings" />

        <h1 class="sr-only">Password settings</h1>

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    v-if="hasPassword"
                    variant="small"
                    title="Update password"
                    description="Ensure your account is using a long, random password to stay secure"
                />
                <Heading
                    v-else
                    variant="small"
                    title="Crear contraseña"
                    description="Entraste con Google, así que tu cuenta no tiene contraseña. Creá una si también querés entrar con tu email (y para activar la verificación en dos pasos)."
                />

                <Form
                    v-bind="PasswordController.update.form()"
                    :options="{
                        preserveScroll: true,
                    }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="space-y-6"
                    v-slot="{ errors, processing, recentlySuccessful }"
                >
                    <div v-if="hasPassword" class="grid gap-2">
                        <Label for="current_password">Current password</Label>
                        <PasswordInput
                            id="current_password"
                            name="current_password"
                            class="mt-1 block w-full"
                            autocomplete="current-password"
                            placeholder="Current password"
                        />
                        <InputError :message="errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">New password</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="New password"
                        />
                        <InputError :message="errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation"
                            >Confirm password</Label
                        >
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            class="mt-1 block w-full"
                            autocomplete="new-password"
                            placeholder="Confirm password"
                        />
                        <InputError :message="errors.password_confirmation" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button
                            :disabled="processing"
                            data-test="update-password-button"
                            >Save password</Button
                        >

                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p
                                v-show="recentlySuccessful"
                                class="text-sm text-neutral-600"
                            >
                                Saved.
                            </p>
                        </Transition>
                    </div>
                </Form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
