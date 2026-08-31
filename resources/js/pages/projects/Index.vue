<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { FileText, FolderKanban, Plus } from 'lucide-vue-next';
import Button from 'primevue/button';
import Card from 'primevue/card';
import ConfirmDialog from 'primevue/confirmdialog';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Textarea from 'primevue/textarea';
import Toast from 'primevue/toast';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    destroy as projectsDestroy,
    index as projectsIndex,
    show as projectsShow,
    store as projectsStore,
    update as projectsUpdate,
} from '@/routes/projects';
import type { BreadcrumbItem, Project } from '@/types';

defineProps<{
    projects: Project[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proyectos', href: projectsIndex() },
];

const toast = useToast();
const confirm = useConfirm();

const dialogVisible = ref(false);
const editingProject = ref<Project | null>(null);

const form = useForm({
    name: '',
    description: '' as string | null,
});

const openCreate = () => {
    editingProject.value = null;
    form.reset();
    form.clearErrors();
    dialogVisible.value = true;
};

const openEdit = (project: Project) => {
    editingProject.value = project;
    form.name = project.name;
    form.description = project.description ?? '';
    form.clearErrors();
    dialogVisible.value = true;
};

const submit = () => {
    const options = {
        onSuccess: () => {
            dialogVisible.value = false;
            toast.add({
                severity: 'success',
                summary: editingProject.value
                    ? 'Proyecto actualizado'
                    : 'Proyecto creado',
                life: 3000,
            });
        },
    };

    if (editingProject.value) {
        form.put(projectsUpdate(editingProject.value.id).url, options);
    } else {
        form.post(projectsStore().url, options);
    }
};

const confirmDelete = (project: Project) => {
    confirm.require({
        message: `¿Eliminar el proyecto "${project.name}"? Se borrarán todos sus documentos, chats y resúmenes.`,
        header: 'Eliminar proyecto',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: 'Eliminar', severity: 'danger' },
        accept: () => {
            router.delete(projectsDestroy(project.id).url, {
                onSuccess: () =>
                    toast.add({
                        severity: 'success',
                        summary: 'Proyecto eliminado',
                        life: 3000,
                    }),
            });
        },
    });
};

const openProject = (project: Project) => {
    router.visit(projectsShow(project.id).url);
};
</script>

<template>
    <Head title="Proyectos" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <Toast />
        <ConfirmDialog />

        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Proyectos</h1>
                    <p class="text-sm text-muted-foreground">
                        Cada proyecto agrupa documentos para consultarlos y
                        resumirlos con IA.
                    </p>
                </div>
                <Button label="Nuevo proyecto" @click="openCreate">
                    <template #icon>
                        <Plus class="size-4" />
                    </template>
                </Button>
            </div>

            <div
                v-if="projects.length === 0"
                class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center"
            >
                <FolderKanban class="size-10 text-muted-foreground" />
                <p class="font-medium">Todavía no tenés proyectos</p>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Creá tu primer proyecto y subí documentos (PDF, Word, texto,
                    Markdown o JSON) para empezar a chatear con ellos.
                </p>
                <Button label="Crear proyecto" outlined @click="openCreate" />
            </div>

            <div
                v-else
                class="grid auto-rows-min gap-4 md:grid-cols-2 xl:grid-cols-3"
            >
                <Card
                    v-for="project in projects"
                    :key="project.id"
                    class="cursor-pointer transition-shadow hover:shadow-md"
                    @click="openProject(project)"
                >
                    <template #title>
                        <span class="text-base">{{ project.name }}</span>
                    </template>
                    <template #subtitle>
                        <span class="flex items-center gap-1 text-sm">
                            <FileText class="size-4" />
                            {{ project.documents_count ?? 0 }}
                            {{
                                (project.documents_count ?? 0) === 1
                                    ? 'documento'
                                    : 'documentos'
                            }}
                        </span>
                    </template>
                    <template #content>
                        <p
                            class="line-clamp-2 min-h-10 text-sm text-muted-foreground"
                        >
                            {{ project.description || 'Sin descripción.' }}
                        </p>
                    </template>
                    <template #footer>
                        <div class="flex justify-end gap-2" @click.stop>
                            <Button
                                icon="pi pi-pencil"
                                severity="secondary"
                                text
                                rounded
                                aria-label="Editar"
                                @click="openEdit(project)"
                            />
                            <Button
                                icon="pi pi-trash"
                                severity="danger"
                                text
                                rounded
                                aria-label="Eliminar"
                                @click="confirmDelete(project)"
                            />
                        </div>
                    </template>
                </Card>
            </div>
        </div>

        <Dialog
            v-model:visible="dialogVisible"
            :header="editingProject ? 'Editar proyecto' : 'Nuevo proyecto'"
            modal
            class="w-full max-w-md"
        >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <div class="flex flex-col gap-1">
                    <label for="project-name" class="text-sm font-medium"
                        >Nombre</label
                    >
                    <InputText
                        id="project-name"
                        v-model="form.name"
                        autofocus
                        placeholder="Ej: Ampliación de la casa"
                        :invalid="Boolean(form.errors.name)"
                    />
                    <Message
                        v-if="form.errors.name"
                        severity="error"
                        size="small"
                        variant="simple"
                    >
                        {{ form.errors.name }}
                    </Message>
                </div>

                <div class="flex flex-col gap-1">
                    <label
                        for="project-description"
                        class="text-sm font-medium"
                    >
                        Descripción
                        <span class="text-muted-foreground">(opcional)</span>
                    </label>
                    <Textarea
                        id="project-description"
                        v-model="form.description"
                        rows="3"
                        auto-resize
                        placeholder="¿De qué trata este proyecto?"
                        :invalid="Boolean(form.errors.description)"
                    />
                    <Message
                        v-if="form.errors.description"
                        severity="error"
                        size="small"
                        variant="simple"
                    >
                        {{ form.errors.description }}
                    </Message>
                </div>

                <div class="flex justify-end gap-2">
                    <Button
                        label="Cancelar"
                        severity="secondary"
                        outlined
                        type="button"
                        @click="dialogVisible = false"
                    />
                    <Button
                        :label="editingProject ? 'Guardar' : 'Crear'"
                        type="submit"
                        :loading="form.processing"
                    />
                </div>
            </form>
        </Dialog>
    </AppLayout>
</template>
