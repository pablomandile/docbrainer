<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Bot,
    MessageSquarePlus,
    SendHorizontal,
    Trash2,
    User,
} from 'lucide-vue-next';
import Button from 'primevue/button';
import ProgressSpinner from 'primevue/progressspinner';
import Select from 'primevue/select';
import Textarea from 'primevue/textarea';
import { useConfirm } from 'primevue/useconfirm';
import { useToast } from 'primevue/usetoast';
import { computed, nextTick, ref, watch } from 'vue';
import CitationList from '@/components/docbrainer/CitationList.vue';
import MarkdownContent from '@/components/docbrainer/MarkdownContent.vue';
import { stream as chatStream } from '@/routes/projects/chat';
import {
    destroy as conversationsDestroy,
    show as conversationsShow,
} from '@/routes/projects/conversations';
import type {
    ChatMessage,
    Citation,
    Conversation,
    Document,
    Project,
} from '@/types';

const props = defineProps<{
    project: Project;
    documents: Document[];
    conversations: Conversation[];
}>();

const toast = useToast();
const confirm = useConfirm();

const hasReadyDocuments = computed(() =>
    props.documents.some((d) => d.status === 'ready'),
);

/* ---------- Estado del hilo ---------- */

const conversationId = ref<number | null>(null);
const messages = ref<ChatMessage[]>([]);
const input = ref('');
const sending = ref(false);
const streamingText = ref('');
const threadEl = ref<HTMLElement | null>(null);

const conversationOptions = computed(() => [
    { id: null, title: '➕ Nueva conversación' },
    ...props.conversations.map((c) => ({
        id: c.id,
        title: c.title ?? `Conversación #${c.id}`,
    })),
]);

const scrollToBottom = async () => {
    await nextTick();
    threadEl.value?.scrollTo({
        top: threadEl.value.scrollHeight,
        behavior: 'smooth',
    });
};

watch(conversationId, async (id) => {
    messages.value = [];
    streamingText.value = '';

    if (id === null) return;

    const response = await fetch(
        conversationsShow([props.project.id, id]).url,
        {
            headers: { Accept: 'application/json' },
        },
    );

    if (response.ok) {
        const data = await response.json();
        messages.value = data.messages;
        await scrollToBottom();
    }
});

/* ---------- Envío con streaming SSE ---------- */

const xsrfToken = (): string =>
    decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

const send = async () => {
    const question = input.value.trim();

    if (question === '' || sending.value) return;

    sending.value = true;
    input.value = '';
    streamingText.value = '';

    // Mensaje del usuario optimista
    messages.value.push({
        id: -Date.now(),
        conversation_id: conversationId.value ?? 0,
        role: 'user',
        content: question,
        citations: null,
        model: null,
        status: 'complete',
        created_at: new Date().toISOString(),
    });
    await scrollToBottom();

    try {
        const response = await fetch(chatStream(props.project.id).url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'text/event-stream',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                message: question,
                conversation_id: conversationId.value,
            }),
        });

        if (!response.ok || response.body === null) {
            throw new Error(`HTTP ${response.status}`);
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let newConversationId: number | null = null;

        const processEvent = (raw: string) => {
            const lines = raw.split('\n');
            const eventLine = lines.find((l) => l.startsWith('event:'));
            const dataLine = lines.find((l) => l.startsWith('data:'));

            if (!eventLine || !dataLine) return;

            const event = eventLine.slice(6).trim();
            const data = JSON.parse(dataLine.slice(5).trim());

            if (event === 'meta') {
                newConversationId = data.conversation_id;
            } else if (event === 'delta') {
                streamingText.value += data.text;
                void scrollToBottom();
            } else if (event === 'done') {
                messages.value.push(data.message as ChatMessage);
                streamingText.value = '';
            } else if (event === 'error') {
                streamingText.value = '';
                toast.add({
                    severity: 'warn',
                    summary: 'No se pudo responder',
                    detail: data.message,
                    life: 8000,
                });
            }
        };

        for (;;) {
            const { done, value } = await reader.read();

            if (done) break;

            buffer += decoder.decode(value, { stream: true });

            let separatorIndex: number;
            while ((separatorIndex = buffer.indexOf('\n\n')) !== -1) {
                processEvent(buffer.slice(0, separatorIndex));
                buffer = buffer.slice(separatorIndex + 2);
            }
        }

        // Conversación nueva creada por el servidor → refrescar lista lateral
        if (newConversationId !== null && conversationId.value === null) {
            conversationId.value = newConversationId;
            router.reload({ only: ['conversations'] });
        }
    } catch {
        toast.add({
            severity: 'error',
            summary: 'Error de conexión',
            detail: 'No se pudo enviar la pregunta. Verificá tu conexión e intentá de nuevo.',
            life: 6000,
        });
    } finally {
        sending.value = false;
        await scrollToBottom();
    }
};

const deleteConversation = () => {
    if (conversationId.value === null) return;

    confirm.require({
        message: '¿Eliminar esta conversación y todo su historial?',
        header: 'Eliminar conversación',
        icon: 'pi pi-exclamation-triangle',
        rejectProps: {
            label: 'Cancelar',
            severity: 'secondary',
            outlined: true,
        },
        acceptProps: { label: 'Eliminar', severity: 'danger' },
        accept: async () => {
            await fetch(
                conversationsDestroy([props.project.id, conversationId.value!])
                    .url,
                {
                    method: 'DELETE',
                    headers: {
                        'X-XSRF-TOKEN': xsrfToken(),
                        Accept: 'application/json',
                    },
                },
            );
            conversationId.value = null;
            router.reload({ only: ['conversations'] });
        },
    });
};

const citationsOf = (message: ChatMessage): Citation[] =>
    message.citations ?? [];
</script>

<template>
    <div class="flex h-[calc(100vh-16rem)] min-h-[420px] flex-col gap-3">
        <!-- Empty state sin documentos listos -->
        <div
            v-if="!hasReadyDocuments"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center"
        >
            <Bot class="size-10 text-muted-foreground" />
            <p class="font-medium">Todavía no hay documentos listos</p>
            <p class="max-w-sm text-sm text-muted-foreground">
                Subí documentos en la solapa «Documentos» y esperá a que
                terminen de procesarse para poder chatear con ellos.
            </p>
        </div>

        <template v-else>
            <!-- Selector de conversación -->
            <div class="flex items-center gap-2">
                <Select
                    v-model="conversationId"
                    :options="conversationOptions"
                    option-label="title"
                    option-value="id"
                    class="min-w-64"
                    size="small"
                />
                <Button
                    v-if="conversationId !== null"
                    severity="danger"
                    text
                    rounded
                    aria-label="Eliminar conversación"
                    @click="deleteConversation"
                >
                    <template #icon>
                        <Trash2 class="size-4" />
                    </template>
                </Button>
            </div>

            <!-- Hilo de mensajes -->
            <div
                ref="threadEl"
                class="flex flex-1 flex-col gap-4 overflow-y-auto rounded-xl border p-4"
            >
                <div
                    v-if="messages.length === 0 && streamingText === ''"
                    class="m-auto flex flex-col items-center gap-2 text-center text-sm text-muted-foreground"
                >
                    <MessageSquarePlus class="size-8" />
                    Preguntale lo que quieras a los documentos de este proyecto.
                </div>

                <div
                    v-for="message in messages"
                    :key="message.id"
                    class="flex gap-3"
                    :class="message.role === 'user' ? 'justify-end' : ''"
                >
                    <div
                        v-if="message.role === 'assistant'"
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
                    >
                        <Bot class="size-4" />
                    </div>

                    <div
                        class="max-w-[80%] rounded-xl px-4 py-2.5 text-sm"
                        :class="
                            message.role === 'user'
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted/60'
                        "
                    >
                        <MarkdownContent
                            v-if="message.role === 'assistant'"
                            :content="message.content"
                        />
                        <p v-else class="whitespace-pre-wrap">
                            {{ message.content }}
                        </p>

                        <CitationList
                            v-if="message.role === 'assistant'"
                            :citations="citationsOf(message)"
                            :message-content="message.content"
                        />
                    </div>

                    <div
                        v-if="message.role === 'user'"
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10"
                    >
                        <User class="size-4" />
                    </div>
                </div>

                <!-- Respuesta en streaming -->
                <div v-if="streamingText !== ''" class="flex gap-3">
                    <div
                        class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted"
                    >
                        <Bot class="size-4" />
                    </div>
                    <div
                        class="max-w-[80%] rounded-xl bg-muted/60 px-4 py-2.5 text-sm"
                    >
                        <MarkdownContent :content="streamingText" />
                    </div>
                </div>

                <div v-else-if="sending" class="flex items-center gap-2 pl-11">
                    <ProgressSpinner
                        style="width: 22px; height: 22px"
                        stroke-width="6"
                    />
                    <span class="text-xs text-muted-foreground"
                        >Buscando en los documentos…</span
                    >
                </div>
            </div>

            <!-- Input -->
            <form class="flex items-end gap-2" @submit.prevent="send">
                <Textarea
                    v-model="input"
                    auto-resize
                    rows="1"
                    class="max-h-40 flex-1"
                    placeholder="Escribí tu pregunta… (Enter para enviar, Shift+Enter para salto de línea)"
                    :disabled="sending"
                    @keydown.enter.exact.prevent="send"
                />
                <Button
                    type="submit"
                    :disabled="sending || input.trim() === ''"
                    aria-label="Enviar"
                >
                    <template #icon>
                        <SendHorizontal class="size-4" />
                    </template>
                </Button>
            </form>
        </template>
    </div>
</template>
