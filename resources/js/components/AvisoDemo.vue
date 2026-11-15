<script setup lang="ts">
// Reemplaza los formularios de la cuenta en la demo (APP_DEMO=true): la
// cuenta es compartida y el server igual rechaza esos cambios.
withDefaults(defineProps<{ mensaje?: string }>(), {
    mensaje:
        'Estás en la demo: la cuenta es compartida, así que esto no se puede cambiar. Todo se reinicia cada noche.',
});
</script>

<template>
    <div
        class="rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm"
        data-test="aviso-demo"
    >
        {{ mensaje }}
    </div>
</template>
