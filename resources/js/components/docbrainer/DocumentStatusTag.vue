<script setup lang="ts">
import Tag from 'primevue/tag';
import { computed } from 'vue';
import type { DocumentStatus } from '@/types';

const props = defineProps<{
    status: DocumentStatus;
    label: string;
}>();

const severity = computed(() => {
    switch (props.status) {
        case 'ready':
            return 'success';
        case 'failed':
            return 'danger';
        case 'pending':
            return 'secondary';
        default:
            return 'info'; // extracting | chunking | embedding
    }
});

const icon = computed(() =>
    props.status === 'ready'
        ? 'pi pi-check'
        : props.status === 'failed'
          ? 'pi pi-times'
          : 'pi pi-spin pi-spinner',
);
</script>

<template>
    <Tag :severity="severity" :value="label" :icon="icon" />
</template>
