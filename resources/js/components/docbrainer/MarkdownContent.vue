<script setup lang="ts">
import DOMPurify from 'dompurify';
import MarkdownIt from 'markdown-it';
import { computed } from 'vue';

const props = defineProps<{
    content: string;
}>();

const md = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
});

const html = computed(() => {
    // Citas [n] → superíndices estilizados antes de renderizar
    const withCitations = props.content.replace(
        /\[(\d{1,2})\]/g,
        '<sup class="citation-ref">[$1]</sup>',
    );

    return DOMPurify.sanitize(md.render(withCitations), {
        ALLOWED_TAGS: [
            'p',
            'br',
            'strong',
            'em',
            'code',
            'pre',
            'blockquote',
            'ul',
            'ol',
            'li',
            'a',
            'h1',
            'h2',
            'h3',
            'h4',
            'h5',
            'h6',
            'table',
            'thead',
            'tbody',
            'tr',
            'th',
            'td',
            'hr',
            'sup',
        ],
        ALLOWED_ATTR: ['href', 'class'],
    });
});
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html — sanitizado con DOMPurify -->
    <div class="markdown-content" v-html="html" />
</template>

<style scoped>
.markdown-content :deep(p) {
    margin-bottom: 0.5rem;
}
.markdown-content :deep(ul),
.markdown-content :deep(ol) {
    margin: 0.5rem 0 0.5rem 1.25rem;
    list-style: revert;
}
.markdown-content :deep(h1),
.markdown-content :deep(h2),
.markdown-content :deep(h3) {
    font-weight: 600;
    margin: 0.75rem 0 0.35rem;
}
.markdown-content :deep(code) {
    background: color-mix(in srgb, currentColor 10%, transparent);
    border-radius: 0.25rem;
    padding: 0.1rem 0.3rem;
    font-size: 0.85em;
}
.markdown-content :deep(pre) {
    background: color-mix(in srgb, currentColor 8%, transparent);
    border-radius: 0.5rem;
    padding: 0.75rem;
    overflow-x: auto;
    margin: 0.5rem 0;
}
.markdown-content :deep(pre code) {
    background: transparent;
    padding: 0;
}
.markdown-content :deep(table) {
    border-collapse: collapse;
    margin: 0.5rem 0;
    width: 100%;
}
.markdown-content :deep(th),
.markdown-content :deep(td) {
    border: 1px solid color-mix(in srgb, currentColor 20%, transparent);
    padding: 0.3rem 0.6rem;
    text-align: left;
}
.markdown-content :deep(blockquote) {
    border-left: 3px solid color-mix(in srgb, currentColor 25%, transparent);
    padding-left: 0.75rem;
    margin: 0.5rem 0;
    opacity: 0.85;
}
.markdown-content :deep(.citation-ref) {
    font-size: 0.7em;
    font-weight: 600;
    opacity: 0.75;
    margin-left: 1px;
}
.markdown-content :deep(a) {
    text-decoration: underline;
}
</style>
