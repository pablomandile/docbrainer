<script setup lang="ts">
/*
 * Logo con el nombre para login y registro.
 *
 * Por defecto cambia solo según el modo claro/oscuro; `onDark` lo fuerza a la
 * versión de fondo oscuro (el panel lateral del layout partido es siempre oscuro).
 */
defineProps<{
    onDark?: boolean;
}>();
</script>

<template>
    <img
        v-if="!onDark"
        src="/img/docbrainer-logo-nombre.webp"
        alt="DocBrainer"
        class="h-12 w-auto dark:hidden"
    />
    <img
        src="/img/docbrainer-logo-nombre-fondo-oscuro.webp"
        alt="DocBrainer"
        class="h-12 w-auto"
        :class="{ 'hidden dark:block': !onDark }"
    />
</template>
