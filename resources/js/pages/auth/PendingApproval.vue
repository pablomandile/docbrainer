<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { logout } from '@/routes';
</script>

<template>
    <AuthLayout
        title="Cuenta pendiente de activación"
        description="Tu cuenta se creó correctamente. Un administrador tiene que activarla antes de que puedas usar DocBrainer."
    >
        <Head title="Cuenta pendiente" />

        <div class="space-y-6 text-center">
            <Clock class="mx-auto size-10 text-muted-foreground" />

            <Button variant="secondary" @click="router.reload()">
                Ya me activaron, volver a intentar
            </Button>

            <TextLink
                :href="logout()"
                as="button"
                class="mx-auto block text-sm"
            >
                Cerrar sesión
            </TextLink>
        </div>
    </AuthLayout>
</template>
