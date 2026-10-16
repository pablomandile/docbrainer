<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import TextSizeSelector from '@/components/TextSizeSelector.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { edit } from '@/routes/appearance';
import type { BreadcrumbItem } from '@/types';

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Apariencia',
        href: edit(),
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Apariencia" />

        <h1 class="sr-only">Apariencia</h1>

        <SettingsLayout>
            <div class="space-y-10">
                <section class="space-y-4">
                    <Heading
                        variant="small"
                        title="Tamaño de la letra"
                        description="Elegí el que te resulte más cómodo de leer. Cambia en toda la app."
                    />
                    <TextSizeSelector />
                </section>

                <section class="space-y-4">
                    <Heading
                        variant="small"
                        title="Claro u oscuro"
                        description="Cómo se ven los colores de la app"
                    />
                    <AppearanceTabs />
                </section>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
