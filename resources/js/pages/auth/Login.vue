<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import GoogleButton from '@/components/GoogleButton.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    error?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

// En la demo (APP_DEMO=true) se entra con un usuario compartido.
const demo = usePage().props.demo;
</script>

<template>
    <AuthBase
        title="Iniciá sesión en tu cuenta"
        description="Ingresá tu email y tu contraseña para entrar"
    >
        <Head title="Iniciar sesión" />

        <div
            v-if="status"
            class="mb-4 text-center text-sm font-medium text-green-600"
        >
            {{ status }}
        </div>

        <div
            v-if="error"
            role="alert"
            class="mb-4 text-center text-sm font-medium text-red-600"
        >
            {{ error }}
        </div>

        <!--
            La demo: los datos a la vista y un botón que entra con ellos. Es un
            formulario aparte, con los valores en inputs reales, para no tocar el
            de abajo, que sigue sirviendo para tipearlos.
        -->
        <Form
            v-if="demo"
            v-bind="store.form()"
            v-slot="{ processing }"
            class="mb-6 rounded-xl border border-primary/30 bg-primary/5 p-4"
            data-test="recuadro-demo"
        >
            <p class="font-semibold">Probá la demo</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Dos proyectos ya indexados —la Constitución con la Ley de
                Contrato de Trabajo, y el Martín Fierro—, con resúmenes y
                conversaciones. Podés preguntar, pedir resúmenes y subir tus
                propios documentos. Se reinicia todas las noches.
            </p>
            <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                <dt class="text-muted-foreground">Email</dt>
                <dd class="font-medium wrap-anywhere">{{ demo.email }}</dd>
                <dt class="text-muted-foreground">Contraseña</dt>
                <dd class="font-medium">{{ demo.password }}</dd>
            </dl>
            <input type="hidden" name="email" :value="demo.email" />
            <input type="hidden" name="password" :value="demo.password" />
            <Button
                type="submit"
                class="mt-4 h-11 w-full"
                :disabled="processing"
                data-test="entrar-demo"
            >
                <Spinner v-if="processing" />
                Entrar a la demo
            </Button>
        </Form>

        <GoogleButton v-else class="mb-6" />

        <Form
            v-bind="store.form()"
            :reset-on-success="['password']"
            v-slot="{ errors, processing }"
            class="flex flex-col gap-6"
        >
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Contraseña</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-sm"
                            :tabindex="5"
                        >
                            ¿Olvidaste tu contraseña?
                        </TextLink>
                    </div>
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Contraseña"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>Recordarme</span>
                    </Label>
                </div>

                <Button
                    type="submit"
                    class="mt-4 w-full"
                    :tabindex="4"
                    :disabled="processing"
                    data-test="login-button"
                >
                    <Spinner v-if="processing" />
                    Iniciar sesión
                </Button>
            </div>

            <div
                class="text-center text-sm text-muted-foreground"
                v-if="canRegister"
            >
                ¿No tenés cuenta?
                <TextLink :href="register()" :tabindex="5">Registrate</TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
