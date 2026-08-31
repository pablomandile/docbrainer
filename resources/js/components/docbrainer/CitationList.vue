<script setup lang="ts">
import { computed } from 'vue';
import type { Citation } from '@/types';

const props = defineProps<{
    citations: Citation[];
    /** Texto del mensaje: solo se listan las fuentes realmente citadas [n] */
    messageContent?: string;
}>();

const visible = computed(() => {
    if (!props.messageContent) return props.citations;

    const cited = new Set(
        [...props.messageContent.matchAll(/\[(\d{1,2})\]/g)].map((m) =>
            Number(m[1]),
        ),
    );

    const used = props.citations.filter((c) => cited.has(c.n));

    return used.length > 0 ? used : props.citations;
});

const label = (c: Citation): string => {
    const parts = [c.document_title ?? 'Documento'];
    if (c.section_path) parts.push(c.section_path);
    if (c.page_start) {
        parts.push(
            c.page_end && c.page_end !== c.page_start
                ? `págs. ${c.page_start}–${c.page_end}`
                : `pág. ${c.page_start}`,
        );
    }
    return parts.join(' · ');
};
</script>

<template>
    <div v-if="visible.length > 0" class="mt-2 flex flex-col gap-1">
        <span class="text-xs font-medium text-muted-foreground">Fuentes</span>
        <div class="flex flex-wrap gap-1.5">
            <span
                v-for="citation in visible"
                :key="citation.n"
                class="inline-flex max-w-full items-center gap-1 truncate rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                :title="label(citation)"
            >
                <span class="font-semibold">[{{ citation.n }}]</span>
                <span class="truncate">{{ label(citation) }}</span>
            </span>
        </div>
    </div>
</template>
