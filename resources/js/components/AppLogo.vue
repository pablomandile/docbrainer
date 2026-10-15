<script setup lang="ts">
/*
 * Logo del menú lateral.
 *
 * - Escritorio con el menú abierto: logo con el nombre.
 * - Menú mobile (sheet) o escritorio colapsado: solo el ícono.
 *
 * `iconOnly` lo fuerza el menú mobile. El colapsado lo resuelve el CSS con el
 * `data-collapsible` que pone el Sidebar en su contenedor (`group`).
 */
defineProps<{
    iconOnly?: boolean;
}>();
</script>

<template>
    <img
        src="/img/docbrainer-icono.webp"
        alt="DocBrainer"
        width="32"
        height="32"
        class="size-8 shrink-0 rounded-md"
        :class="{ 'hidden group-data-[collapsible=icon]:block': !iconOnly }"
    />

    <span
        v-if="!iconOnly"
        class="flex min-w-0 items-center group-data-[collapsible=icon]:hidden"
    >
        <!-- Dos archivos: el de fondo oscuro lleva "Doc" en blanco -->
        <img
            src="/img/docbrainer-logo-nombre.webp"
            alt="DocBrainer"
            class="h-9 w-auto dark:hidden"
        />
        <img
            src="/img/docbrainer-logo-nombre-fondo-oscuro.webp"
            alt="DocBrainer"
            class="hidden h-9 w-auto dark:block"
        />
    </span>
</template>
