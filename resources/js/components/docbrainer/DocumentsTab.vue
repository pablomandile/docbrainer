<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Column from 'primevue/column';
import DataTable from 'primevue/datatable';
import FileUpload from 'primevue/fileupload';
import type { FileUploadUploaderEvent } from 'primevue/fileupload';
import Menu from 'primevue/menu';
import ProgressBar from 'primevue/progressbar';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DocumentStatusTag from '@/components/docbrainer/DocumentStatusTag.vue';
import {
    destroy as documentsDestroy,
    download as documentsDownload,
    reindex as documentsReindex,
    retry as documentsRetry,
    status as documentsStatus,
    store as documentsStore,
} from '@/routes/projects/documents';
import type {
    Document,
    DocumentStatusPoll,
    EmbeddingProvider,
    Project,
} from '@/types';

const props = defineProps<{
    project: Project;
    documents: Document[];
    ai: {
        default_index_provider: EmbeddingProvider;
        ollama_available: boolean;
    };
}>();

const emit = defineEmits<{
    changed: [];
}>();

const toast = useToast();
const confirm = useConfirm();

// Copia local para poder refrescar estados vía polling sin recargar la página
const rows = ref<Document[]>([...props.documents]);

watch(
    () => props.documents,
    (docs) => {
        rows.value = [...docs];
    },
);

/* ---------- Subida ---------- */

const providerOptions = computed(() => [
    { label: 'Gemini API', value: 'gemini', disabled: false },
    {
        label: props.ai.ollama_available
            ? 'GPU local (Ollama)'
            : 'GPU local (no disponible)',
        value: 'ollama',
        disabled: !props.ai.ollama_available,
    },
]);

const uploadForm = useForm<{
    files: File[];
    embedding_provider: EmbeddingProvider;
}>({
    files: [],
    embedding_provider:
        props.ai.default_index_provider === 'ollama' &&
        !props.ai.ollama_available
            ? 'gemini'
            : props.ai.default_index_provider,
});

const uploader = (event: FileUploadUploaderEvent) => {
    uploadForm.files = Array.isArray(event.files) ? event.files : [event.files];

    uploadForm.post(documentsStore(props.project.id).url, {
        forceFormData: true,
        onSuccess: () => {
            toast.add({
                severity: 'success',
                summary: 'Documentos subidos',
                detail: 'El procesamiento comenzó en segundo plano.',
                life: 4000,
            });
            uploadForm.reset();
            emit('changed');
        },
        onError: (errors) => {
            toast.add({
                severity: 'error',
                summary: 'Error al subir',
                detail:
                    Object.values(errors)[0] ??
                    'Revisá los archivos e intentá de nuevo.',
                life: 6000,
            });
        },
    });
};

/* ---------- Polling de estado ---------- */

const anyProcessing = computed(() => rows.value.some((d) => d.is_processing));

let pollTimer: ReturnType<typeof setInterval> | null = null;

const poll = async () => {
    try {
        const response = await fetch(documentsStatus(props.project.id).url, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) return;

        const data: { documents: DocumentStatusPoll[] } = await response.json();

        for (const update of data.documents) {
            const row = rows.value.find((d) => d.id === update.id);
            if (row) {
                Object.assign(row, update);
            }
        }
    } catch {
        // Silencioso: el próximo tick reintenta
    }
};

watch(
    anyProcessing,
    (processing) => {
        if (processing && pollTimer === null) {
            pollTimer = setInterval(poll, 3000);
        } else if (!processing && pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (pollTimer !== null) clearInterval(pollTimer);
});

/* ---------- Acciones ---------- */

const reindexMenu = ref<InstanceType<typeof Menu> | null>(null);
const reindexTarget = ref<Document | null>(null);

const reindexItems = computed(() => [
    {
        label: 'Re-indexar con Gemini API',
        icon: 'pi pi-cloud',
        command: () => reindex('gemini'),
    },
    {
        label: 'Re-indexar con GPU local',
        icon: 'pi pi-microchip',
        disabled: !props.ai.ollama_available,
        command: () => reindex('ollama'),
    },
]);

const openReindexMenu = (event: Event, doc: Document) => {
    reindexTarget.value = doc;
    reindexMenu.value?.toggle(event);
};

const reindex = (provider: EmbeddingProvider) => {
    if (reindexTarget.value === null) return;

    router.post(
        documentsReindex([props.project.id, reindexTarget.value.id]).url,
        { embedding_provider: provider },
        {
            preserveScroll: true,
            onSuccess: () => {
                toast.add({
                    severity: 'info',
                    summary: 'Re-indexando documento',
                    life: 3000,
                });
                emit('changed');
            },
        },
    );
};

const retry = (doc: Document) => {
    router.post(
        documentsRetry([props.project.id, doc.id]).url,
        {},
        {
            onSuccess: () => {
                toast.add({
                    severity: 'info',
                    summary: 'Reprocesando documento',
                    life: 3000,
                });
                emit('changed');
            },
        },
    );
};

const confirmDelete = (doc: Document) => {
    confirm.require({
        message: `¿Eliminar "${doc.title}"?`,
        header: 'Eliminar documento',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: 'Eliminar', severity: 'danger' },
        accept: () => {
            router.delete(documentsDestroy([props.project.id, doc.id]).url, {
                onSuccess: () => {
                    toast.add({
                        severity: 'success',
                        summary: 'Documento eliminado',
                        life: 3000,
                    });
                    emit('changed');
                },
            });
        },
    });
};

/* ---------- Helpers ---------- */

const formatSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const embeddingProgress = (doc: Document): number =>
    doc.chunk_count > 0
        ? Math.round((doc.embedded_chunk_count / doc.chunk_count) * 100)
        : 0;

const acceptedTypes = '.pdf,.docx,.txt,.md,.json';
</script>

<template>
    <div class="flex flex-col gap-6">
        <div class="flex items-center gap-2">
            <label class="text-sm font-medium">Indexar con:</label>
            <Select
                v-model="uploadForm.embedding_provider"
                :options="providerOptions"
                option-label="label"
                option-value="value"
                option-disabled="disabled"
                size="small"
                class="w-56"
            />
            <span
                v-if="!ai.ollama_available"
                class="text-xs text-muted-foreground"
            >
                GPU local requiere Ollama corriendo (ver Configuración →
                Proveedor de IA)
            </span>
        </div>

        <FileUpload
            name="files[]"
            :accept="acceptedTypes"
            :multiple="true"
            custom-upload
            :max-file-size="50 * 1024 * 1024"
            choose-label="Elegir archivos"
            upload-label="Subir"
            cancel-label="Cancelar"
            :disabled="uploadForm.processing"
            @uploader="uploader"
        >
            <template #empty>
                <div class="p-4 text-center text-sm text-muted-foreground">
                    Arrastrá archivos acá o hacé clic en «Elegir archivos».
                    <br />
                    Formatos: PDF, Word (.docx), texto, Markdown y JSON — hasta
                    50 MB.
                    <br />
                    <span class="text-xs">
                        Los documentos de Google Docs se suben exportados como
                        .docx o .pdf.
                    </span>
                </div>
            </template>
        </FileUpload>

        <DataTable :value="rows" data-key="id" striped-rows>
            <template #empty>
                <div class="p-4 text-center text-sm text-muted-foreground">
                    Todavía no hay documentos en este proyecto.
                </div>
            </template>

            <Column field="title" header="Documento">
                <template #body="{ data }">
                    <div class="flex flex-col">
                        <span class="font-medium">{{ data.title }}</span>
                        <span class="text-xs text-muted-foreground">
                            {{ data.original_name }}
                            <template v-if="data.page_count">
                                · {{ data.page_count }} págs.</template
                            >
                        </span>
                    </div>
                </template>
            </Column>

            <Column field="extension" header="Tipo" class="w-20">
                <template #body="{ data }">
                    <span class="uppercase">{{ data.extension }}</span>
                </template>
            </Column>

            <Column field="size_bytes" header="Tamaño" class="w-28">
                <template #body="{ data }">
                    {{ formatSize(data.size_bytes) }}
                </template>
            </Column>

            <Column field="embedding_provider" header="Proveedor" class="w-32">
                <template #body="{ data }">
                    <Tag
                        :severity="
                            data.embedding_provider === 'ollama'
                                ? 'warn'
                                : 'secondary'
                        "
                        :value="
                            data.embedding_provider === 'ollama'
                                ? 'GPU local'
                                : 'Gemini'
                        "
                    />
                </template>
            </Column>

            <Column field="status" header="Estado" class="w-56">
                <template #body="{ data }">
                    <div class="flex flex-col gap-1">
                        <DocumentStatusTag
                            :status="data.status"
                            :label="data.status_label"
                        />
                        <ProgressBar
                            v-if="
                                data.status === 'embedding' &&
                                data.chunk_count > 0
                            "
                            :value="embeddingProgress(data)"
                            class="h-2"
                            :show-value="false"
                        />
                        <span
                            v-if="data.failure_reason"
                            class="text-xs text-red-500"
                        >
                            {{ data.failure_reason }}
                        </span>
                    </div>
                </template>
            </Column>

            <Column header="" class="w-36">
                <template #body="{ data }">
                    <div class="flex justify-end gap-1">
                        <Button
                            v-if="data.status === 'failed'"
                            icon="pi pi-refresh"
                            severity="warn"
                            text
                            rounded
                            aria-label="Reintentar"
                            @click="retry(data)"
                        />
                        <Button
                            v-if="
                                data.status === 'ready' ||
                                data.status === 'failed'
                            "
                            icon="pi pi-sync"
                            severity="secondary"
                            text
                            rounded
                            aria-label="Re-indexar"
                            @click="openReindexMenu($event, data)"
                        />
                        <a :href="documentsDownload([project.id, data.id]).url">
                            <Button
                                icon="pi pi-download"
                                severity="secondary"
                                text
                                rounded
                                aria-label="Descargar"
                            />
                        </a>
                        <Button
                            icon="pi pi-trash"
                            severity="danger"
                            text
                            rounded
                            aria-label="Eliminar"
                            @click="confirmDelete(data)"
                        />
                    </div>
                </template>
            </Column>
        </DataTable>

        <Menu ref="reindexMenu" :model="reindexItems" popup />
    </div>
</template>
