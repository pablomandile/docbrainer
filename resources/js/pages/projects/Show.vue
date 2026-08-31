<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import ConfirmDialog from 'primevue/confirmdialog';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import Toast from 'primevue/toast';
import { ref, watch } from 'vue';
import ChatTab from '@/components/docbrainer/ChatTab.vue';
import DocumentsTab from '@/components/docbrainer/DocumentsTab.vue';
import SummaryTab from '@/components/docbrainer/SummaryTab.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as projectsIndex,
    show as projectsShow,
} from '@/routes/projects';
import type {
    BreadcrumbItem,
    Conversation,
    Document,
    EmbeddingProvider,
    Project,
    Summary,
} from '@/types';

const props = defineProps<{
    project: Project;
    documents: Document[];
    conversations: Conversation[];
    summaries: Summary[];
    ai: {
        default_index_provider: EmbeddingProvider;
        ollama_available: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proyectos', href: projectsIndex() },
    { title: props.project.name, href: projectsShow(props.project.id) },
];

// Solapa activa sincronizada con ?tab= para que el refresh/enlaces la conserven
const validTabs = ['documentos', 'chat', 'resumen'];
const initialTab = new URLSearchParams(window.location.search).get('tab');
const activeTab = ref(
    validTabs.includes(initialTab ?? '')
        ? (initialTab as string)
        : 'documentos',
);

watch(activeTab, (tab) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.history.replaceState(window.history.state, '', url);
});

const reloadDocuments = () => {
    router.reload({ only: ['documents'] });
};
</script>

<template>
    <Head :title="project.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <Toast />
        <ConfirmDialog />

        <div class="flex h-full flex-1 flex-col gap-4 p-4">
            <div>
                <h1 class="text-xl font-semibold">{{ project.name }}</h1>
                <p
                    v-if="project.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ project.description }}
                </p>
            </div>

            <Tabs v-model:value="activeTab" class="flex-1">
                <TabList>
                    <Tab value="documentos">Documentos</Tab>
                    <Tab value="chat">Chat</Tab>
                    <Tab value="resumen">Resumen</Tab>
                </TabList>
                <TabPanels>
                    <TabPanel value="documentos">
                        <DocumentsTab
                            :project="project"
                            :documents="documents"
                            :ai="ai"
                            @changed="reloadDocuments"
                        />
                    </TabPanel>
                    <TabPanel value="chat">
                        <ChatTab
                            :project="project"
                            :documents="documents"
                            :conversations="conversations"
                        />
                    </TabPanel>
                    <TabPanel value="resumen">
                        <SummaryTab
                            :project="project"
                            :documents="documents"
                            :summaries="summaries"
                        />
                    </TabPanel>
                </TabPanels>
            </Tabs>
        </div>
    </AppLayout>
</template>
