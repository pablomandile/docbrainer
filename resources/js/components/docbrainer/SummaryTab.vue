<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Copy, Download, FileText, Sparkles, Trash2 } from 'lucide-vue-next';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ProgressBar from 'primevue/progressbar';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import MarkdownContent from '@/components/docbrainer/MarkdownContent.vue';
import {
    destroy as summariesDestroy,
    status as summariesStatus,
    store as summariesStore,
} from '@/routes/projects/summaries';
import type { Document, Project, Summary } from '@/types';

const props = defineProps<{
    project: Project;
    documents: Document[];
    summaries: Summary[];
}>();

const toast = useToast();
const confirm = useConfirm();

const readyDocuments = computed(() =>
    props.documents.filter((d) => d.status === 'ready'),
);

/* ---------- Formulario ---------- */

const documentOptions = computed(() => [
    { id: null, title: '📁 Todo el proyecto' },
    ...readyDocuments.value.map((d) => ({ id: d.id, title: d.title })),
]);

const form = useForm<{
    document_id: number | null;
    length: string;
    style: string;
    language: string;
}>({
    document_id: null,
    length: 'medio',
    style: 'divulgativo',
    language: 'es',
});

const lengthOptions = [
    { label: 'Corto', value: 'corto' },
    { label: 'Medio', value: 'medio' },
    { label: 'Extenso', value: 'extenso' },
];

const styleOptions = [
    { label: 'Ejecutivo', value: 'ejecutivo' },
    { label: 'Académico', value: 'academico' },
    { label: 'Divulgativo', value: 'divulgativo' },
];

const languageOptions = [
    { label: 'Español', value: 'es' },
    { label: 'English', value: 'en' },
];

const submit = () => {
    form.post(summariesStore(props.project.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Resumen en cola',
                detail: 'La generación comenzó. El progreso se actualiza automáticamente.',
                life: 4000,
            });
            refresh();
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'No se pudo iniciar',
                detail: Object.values(errors)[0] ?? 'Revisá los parámetros.',
                life: 6000,
            });
        },
    });
};

/* ---------- Lista + polling ---------- */

const rows = ref<Summary[]>([...props.summaries]);

watch(
    () => props.summaries,
    (value) => {
        rows.value = [...value];
    },
);

const anyRunning = computed(() =>
    rows.value.some((s) => s.status === 'pending' || s.status === 'running'),
);

let pollTimer: ReturnType<typeof setInterval> | null = null;

const poll = async () => {
    try {
        const response = await fetch(summariesStatus(props.project.id).url, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const data: { summaries: Summary[] } = await response.json();
            rows.value = data.summaries;
        }
    } catch {
        // El próximo tick reintenta
    }
};

watch(
    anyRunning,
    (running) => {
        if (running && pollTimer === null) {
            pollTimer = setInterval(poll, 3000);
        } else if (!running && pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (pollTimer !== null) clearInterval(pollTimer);
});

const refresh = () => {
    router.reload({ only: ['summaries'] });
    void poll();
};

/* ---------- Visualización ---------- */

const selected = ref<Summary | null>(null);

watch(rows, (value) => {
    // Mantener la selección sincronizada tras el polling
    if (selected.value !== null) {
        selected.value = value.find((s) => s.id === selected.value?.id) ?? null;
    } else {
        selected.value = value.find((s) => s.status === 'completed') ?? null;
    }
});

const progressOf = (s: Summary): number =>
    s.progress_total > 0
        ? Math.round((s.progress_done / s.progress_total) * 100)
        : 0;

const paramsLabel = (s: Summary): string =>
    `${s.params.length} · ${s.params.style} · ${s.params.language === 'es' ? 'español' : 'inglés'}`;

const severityOf = (s: Summary) =>
    s.status === 'completed'
        ? 'success'
        : s.status === 'failed'
          ? 'danger'
          : 'info';

const scopeLabel = (s: Summary): string =>
    s.document_title ?? 'Todo el proyecto';

const copyResult = async (s: Summary) => {
    if (s.result === null) return;
    await navigator.clipboard.writeText(s.result);
    toast.add({
        severity: 'success',
        summary: 'Copiado al portapapeles',
        life: 2500,
    });
};

const downloadResult = (s: Summary) => {
    if (s.result === null) return;

    const name = `resumen-${(s.document_title ?? props.project.name).toLowerCase().replace(/\s+/g, '-')}.md`;
    const blob = new Blob([s.result], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.click();
    URL.revokeObjectURL(url);
};

const confirmDelete = (s: Summary) => {
    confirm.require({
        message: '¿Eliminar este resumen?',
        header: 'Eliminar resumen',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: 'Eliminar', severity: 'danger' },
        accept: () => {
            router.delete(summariesDestroy([props.project.id, s.id]).url, {
                preserveScroll: true,
                onSuccess: () => {
                    if (selected.value?.id === s.id) selected.value = null;
                    refresh();
                },
            });
        },
    });
};
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Sin documentos listos -->
        <div
            v-if="readyDocuments.length === 0"
            class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center"
        >
            <FileText class="size-10 text-muted-foreground" />
            <p class="font-medium">Todavía no hay documentos listos</p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Subí documentos y esperá a que terminen de procesarse para poder
                resumirlos.
            </p>
        </div>

        <template v-else>
            <!-- Formulario de parámetros -->
            <form
                class="flex flex-col gap-4 rounded-xl border p-4"
                @submit.prevent="submit"
            >
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Qué resumir</label>
                        <Select
                            v-model="form.document_id"
                            :options="documentOptions"
                            option-label="title"
                            option-value="id"
                            size="small"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Longitud</label>
                        <SelectButton
                            v-model="form.length"
                            :options="lengthOptions"
                            option-label="label"
                            option-value="value"
                            :allow-empty="false"
                            size="small"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Estilo</label>
                        <Select
                            v-model="form.style"
                            :options="styleOptions"
                            option-label="label"
                            option-value="value"
                            size="small"
                        />
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Idioma</label>
                        <Select
                            v-model="form.language"
                            :options="languageOptions"
                            option-label="label"
                            option-value="value"
                            size="small"
                        />
                    </div>
                </div>

                <div class="flex justify-end">
                    <Button
                        type="submit"
                        label="Generar resumen"
                        :loading="form.processing"
                    >
                        <template #icon>
                            <Sparkles class="size-4" />
                        </template>
                    </Button>
                </div>
            </form>

            <!-- Historial -->
            <div v-if="rows.length > 0" class="flex flex-col gap-2">
                <span class="text-sm font-medium">Resúmenes generados</span>

                <div
                    v-for="s in rows"
                    :key="s.id"
                    class="flex flex-col gap-2 rounded-xl border p-3"
                    :class="
                        selected?.id === s.id ? 'ring-2 ring-primary/40' : ''
                    "
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            class="flex-1 cursor-pointer text-left"
                            @click="selected = s"
                        >
                            <span class="text-sm font-medium">{{
                                scopeLabel(s)
                            }}</span>
                            <span class="ml-2 text-xs text-muted-foreground">{{
                                paramsLabel(s)
                            }}</span>
                        </button>

                        <Tag
                            :severity="severityOf(s)"
                            :value="s.status_label"
                        />

                        <Button
                            severity="danger"
                            text
                            rounded
                            size="small"
                            aria-label="Eliminar"
                            @click="confirmDelete(s)"
                        >
                            <template #icon>
                                <Trash2 class="size-4" />
                            </template>
                        </Button>
                    </div>

                    <template
                        v-if="s.status === 'pending' || s.status === 'running'"
                    >
                        <ProgressBar
                            :value="progressOf(s)"
                            class="h-2.5"
                            :show-value="false"
                        />
                        <span class="text-xs text-muted-foreground">
                            {{ s.progress_done }} / {{ s.progress_total }} pasos
                            — la velocidad depende del límite gratuito de la
                            API.
                        </span>
                    </template>

                    <Message
                        v-if="s.failure_reason"
                        severity="error"
                        size="small"
                        variant="simple"
                    >
                        {{ s.failure_reason }}
                    </Message>
                </div>
            </div>

            <!-- Resultado seleccionado -->
            <div
                v-if="selected?.result"
                class="flex flex-col gap-3 rounded-xl border p-4"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium">
                        {{ scopeLabel(selected) }}
                        <span class="ml-1 text-xs text-muted-foreground">{{
                            paramsLabel(selected)
                        }}</span>
                    </span>
                    <div class="flex gap-1">
                        <Button
                            severity="secondary"
                            text
                            rounded
                            aria-label="Copiar"
                            @click="copyResult(selected)"
                        >
                            <template #icon>
                                <Copy class="size-4" />
                            </template>
                        </Button>
                        <Button
                            severity="secondary"
                            text
                            rounded
                            aria-label="Descargar .md"
                            @click="downloadResult(selected)"
                        >
                            <template #icon>
                                <Download class="size-4" />
                            </template>
                        </Button>
                    </div>
                </div>

                <div class="text-sm">
                    <MarkdownContent :content="selected.result" />
                </div>
            </div>
        </template>
    </div>
</template>
