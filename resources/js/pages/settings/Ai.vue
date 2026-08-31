<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Cpu, RefreshCw } from 'lucide-vue-next';
import Button from 'primevue/button';
import Message from 'primevue/message';
import ProgressBar from 'primevue/progressbar';
import RadioButton from 'primevue/radiobutton';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import { useToast } from 'primevue/usetoast';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    edit as editAi,
    gpuStatus as gpuStatusRoute,
    update as updateAi,
} from '@/routes/ai-settings';
import type { BreadcrumbItem } from '@/types';

interface GpuInfo {
    gpu: {
        available: boolean;
        name: string | null;
        vram_gb: number | null;
        driver: string | null;
    };
    ollama: { available: boolean; version: string | null; models: string[] };
    available: boolean;
    level: 'success' | 'warn' | 'error';
    message: string;
}

const props = defineProps<{
    settings: {
        chat_provider: 'gemini' | 'ollama';
        default_index_provider: 'gemini' | 'ollama';
        ollama_chat_model: string | null;
    };
    gpu: GpuInfo;
    ollamaDefaults: { embedding_model: string; chat_model: string };
    usage: {
        embed_used: number;
        embed_limit: number;
        generate_used: number;
        generate_limit: number;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Proveedor de IA', href: editAi() },
];

const toast = useToast();

const gpuInfo = ref<GpuInfo>(props.gpu);
const checking = ref(false);

const recheckGpu = async () => {
    checking.value = true;

    try {
        const response = await fetch(gpuStatusRoute().url, {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            gpuInfo.value = await response.json();
        }
    } finally {
        checking.value = false;
    }
};

const form = useForm({
    chat_provider: props.settings.chat_provider,
    default_index_provider: props.settings.default_index_provider,
    ollama_chat_model: props.settings.ollama_chat_model,
});

const chatModelOptions = computed(() =>
    gpuInfo.value.ollama.models.length > 0
        ? gpuInfo.value.ollama.models
        : [props.ollamaDefaults.chat_model],
);

const submit = () => {
    form.patch(updateAi().url, {
        preserveScroll: true,
        onSuccess: () =>
            toast.add({
                severity: 'success',
                summary: 'Configuración guardada',
                life: 3000,
            }),
    });
};

const usagePct = (used: number, limit: number) =>
    limit > 0 ? Math.min(100, Math.round((used / limit) * 100)) : 0;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Proveedor de IA" />
        <Toast />

        <SettingsLayout>
            <div class="space-y-8">
                <Heading
                    variant="small"
                    title="Proveedor de IA"
                    description="Elegí si DocBrainer usa la API de Gemini o modelos locales en tu GPU (vía Ollama)"
                />

                <!-- Estado de GPU / Ollama -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <Cpu class="size-4" />
                            Estado del modo GPU local
                        </span>
                        <Button
                            size="small"
                            severity="secondary"
                            outlined
                            label="Volver a comprobar"
                            :loading="checking"
                            @click="recheckGpu"
                        >
                            <template #icon>
                                <RefreshCw class="size-4" />
                            </template>
                        </Button>
                    </div>

                    <Message :severity="gpuInfo.level" :closable="false">
                        {{ gpuInfo.message }}
                    </Message>

                    <div
                        v-if="
                            gpuInfo.ollama.available &&
                            gpuInfo.ollama.models.length > 0
                        "
                        class="flex flex-wrap items-center gap-1.5"
                    >
                        <span class="text-xs text-muted-foreground"
                            >Modelos instalados:</span
                        >
                        <Tag
                            v-for="model in gpuInfo.ollama.models"
                            :key="model"
                            severity="secondary"
                            :value="model"
                        />
                    </div>

                    <p
                        v-if="!gpuInfo.ollama.available"
                        class="text-xs text-muted-foreground"
                    >
                        Para el modo GPU: instalá Ollama desde
                        <a
                            href="https://ollama.com"
                            target="_blank"
                            class="underline"
                            >ollama.com</a
                        >
                        y descargá los modelos con
                        <code class="rounded bg-muted px-1"
                            >ollama pull
                            {{ ollamaDefaults.embedding_model }}</code
                        >
                        y
                        <code class="rounded bg-muted px-1"
                            >ollama pull {{ ollamaDefaults.chat_model }}</code
                        >.
                    </p>
                </div>

                <!-- Preferencias -->
                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-2">
                        <span class="text-sm font-medium"
                            >Proveedor para chat y resúmenes</span
                        >
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <RadioButton
                                    v-model="form.chat_provider"
                                    value="gemini"
                                />
                                API de Gemini (nube, requiere GEMINI_API_KEY en
                                .env)
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm"
                                :class="!gpuInfo.available ? 'opacity-50' : ''"
                            >
                                <RadioButton
                                    v-model="form.chat_provider"
                                    value="ollama"
                                    :disabled="!gpuInfo.available"
                                />
                                GPU local (Ollama) — sin límites de cuota
                            </label>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <span class="text-sm font-medium">
                            Proveedor de indexado por defecto (al subir
                            documentos)
                        </span>
                        <div class="flex flex-col gap-2">
                            <label class="flex items-center gap-2 text-sm">
                                <RadioButton
                                    v-model="form.default_index_provider"
                                    value="gemini"
                                />
                                API de Gemini
                            </label>
                            <label
                                class="flex items-center gap-2 text-sm"
                                :class="!gpuInfo.available ? 'opacity-50' : ''"
                            >
                                <RadioButton
                                    v-model="form.default_index_provider"
                                    value="ollama"
                                    :disabled="!gpuInfo.available"
                                />
                                GPU local (Ollama)
                            </label>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            El proveedor se elige por documento al subirlo; esto
                            define la opción preseleccionada. Cambiar de
                            proveedor requiere re-indexar los documentos
                            existentes (botón «Re-indexar» en la tabla de
                            documentos).
                        </p>
                    </div>

                    <div
                        v-if="form.chat_provider === 'ollama'"
                        class="space-y-2"
                    >
                        <span class="text-sm font-medium"
                            >Modelo de chat local</span
                        >
                        <Select
                            v-model="form.ollama_chat_model"
                            :options="chatModelOptions"
                            :placeholder="
                                ollamaDefaults.chat_model + ' (por defecto)'
                            "
                            show-clear
                            class="w-full max-w-sm"
                            size="small"
                        />
                    </div>

                    <Button
                        type="submit"
                        label="Guardar"
                        :loading="form.processing"
                    />
                </form>

                <!-- Uso de la API hoy -->
                <div class="space-y-3">
                    <Heading
                        variant="small"
                        title="Uso de la API de Gemini hoy"
                        description="Topes propios configurados en .env, por debajo del free tier real"
                    />

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="space-y-1 rounded-xl border p-3">
                            <div class="flex justify-between text-sm">
                                <span>Embeddings (indexado)</span>
                                <span class="text-muted-foreground">
                                    {{ usage.embed_used }} /
                                    {{ usage.embed_limit }}
                                </span>
                            </div>
                            <ProgressBar
                                :value="
                                    usagePct(
                                        usage.embed_used,
                                        usage.embed_limit,
                                    )
                                "
                                class="h-2"
                                :show-value="false"
                            />
                        </div>

                        <div class="space-y-1 rounded-xl border p-3">
                            <div class="flex justify-between text-sm">
                                <span>Generación (chat + resúmenes)</span>
                                <span class="text-muted-foreground">
                                    {{ usage.generate_used }} /
                                    {{ usage.generate_limit }}
                                </span>
                            </div>
                            <ProgressBar
                                :value="
                                    usagePct(
                                        usage.generate_used,
                                        usage.generate_limit,
                                    )
                                "
                                class="h-2"
                                :show-value="false"
                            />
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        La cuota se reinicia a medianoche, hora del Pacífico (el
                        reloj de cuota de Google). El proveedor GPU local no
                        consume cuota.
                    </p>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
