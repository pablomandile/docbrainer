<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Binary,
    BookOpenText,
    FileSearch,
    MessageCircleQuestion,
    Scissors,
    Sparkles,
} from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Cómo funciona', href: '/como-funciona' },
];

const pipelineSteps = [
    {
        icon: FileSearch,
        title: '1. Extracción',
        text: 'Del PDF/Word se extrae el texto limpio preservando la estructura: capítulos, secciones y páginas. Es la etapa más subestimada: los PDFs escaneados (imagen sin texto) se detectan y se marcan con un mensaje claro — el OCR no está soportado en esta versión.',
    },
    {
        icon: Scissors,
        title: '2. Chunking (troceado)',
        text: 'El texto se corta en fragmentos de ~450 tokens (~350 palabras) respetando la estructura: capítulo → sección → párrafo, nunca a mitad de una oración, con ~10% de solapamiento entre fragmentos consecutivos. A cada fragmento se le antepone su ubicación ("Manual de obra — Capítulo 2 > Presupuestos") antes de vectorizarlo: contexto gratis desde la metadata.',
    },
    {
        icon: Binary,
        title: '3. Vectorización (embeddings)',
        text: 'Cada fragmento pasa por el modelo de embeddings (Gemini o un modelo local en tu GPU vía Ollama) y devuelve un vector: 768 números que representan su significado. Los vectores se guardan en la base de datos junto al texto y su metadata. Esta es la fase "procesando…" con barra de progreso, y ocurre una sola vez por documento.',
    },
];

const questionSteps = [
    'Tu pregunta se vectoriza (1 llamada mínima).',
    'Su vector se compara contra todos los del proyecto → los ~8 fragmentos más cercanos por similitud coseno, reforzados con búsqueda léxica exacta (híbrida).',
    'Esos fragmentos + tu pregunta van al modelo con la instrucción: «respondé usando SOLO estos fragmentos y citá las fuentes [1]..[8]».',
    'La respuesta llega con citas verificables (documento, sección, página). Si la información no está en los documentos, el asistente lo dice en vez de inventar.',
];
</script>

<template>
    <Head title="Cómo funciona" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex max-w-3xl flex-col gap-10 p-6">
            <header class="flex flex-col gap-2">
                <h1 class="flex items-center gap-2 text-2xl font-semibold">
                    <BookOpenText class="size-6" />
                    Cómo funciona DocBrainer
                </h1>
                <p class="text-muted-foreground">
                    La técnica se llama <strong>RAG</strong> (<em
                        >Retrieval-Augmented Generation</em
                    >, generación aumentada por recuperación). La
                    «vectorización» —técnicamente <strong>embeddings</strong>—
                    es su corazón, pero es solo una etapa del proceso.
                </p>
            </header>

            <!-- La idea central -->
            <section class="flex flex-col gap-3">
                <h2 class="text-lg font-semibold">
                    La idea central: convertir significado en geometría
                </h2>
                <p class="text-sm leading-relaxed">
                    Un embedding es una función que toma un texto y devuelve un
                    punto en un espacio de muchas dimensiones (en DocBrainer,
                    768 números decimales):
                </p>
                <pre
                    class="overflow-x-auto rounded-xl bg-muted p-4 text-xs leading-relaxed"
                ><code>"El presupuesto de la obra es de 5 millones"  →  [0.021, -0.348, 0.114, ...]
"¿Cuánto cuesta la ampliación?"               →  [0.019, -0.352, 0.109, ...]  ← ¡cerca!
"Receta de milanesas a la napolitana"         →  [-0.887, 0.023, 0.671, ...] ← lejos</code></pre>
                <p class="text-sm leading-relaxed">
                    El modelo de embeddings (una red neuronal entrenada con
                    billones de textos) garantiza que
                    <strong>textos de significado parecido caigan cerca</strong>
                    en ese espacio, aunque no compartan palabras. La cercanía se
                    mide con la
                    <strong>similitud coseno</strong> (el ángulo entre dos
                    vectores: ángulo chico = mismo tema). Por eso DocBrainer
                    encuentra «costo de la remodelación» cuando preguntaste por
                    «presupuesto»: busca por <em>significado</em>, no por
                    palabra exacta.
                </p>
            </section>

            <!-- Pipeline de indexación -->
            <section class="flex flex-col gap-4">
                <h2 class="text-lg font-semibold">
                    Al subir un documento (una sola vez)
                </h2>
                <div class="flex flex-col gap-4">
                    <div
                        v-for="step in pipelineSteps"
                        :key="step.title"
                        class="flex gap-4 rounded-xl border p-4"
                    >
                        <component
                            :is="step.icon"
                            class="mt-0.5 size-6 shrink-0 text-primary"
                        />
                        <div>
                            <h3 class="font-medium">{{ step.title }}</h3>
                            <p
                                class="mt-1 text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ step.text }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- El momento de la pregunta -->
            <section class="flex flex-col gap-3">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <MessageCircleQuestion class="size-5" />
                    Al hacer una pregunta (cada vez)
                </h2>
                <ol class="flex flex-col gap-2">
                    <li
                        v-for="(step, i) in questionSteps"
                        :key="i"
                        class="flex gap-3 text-sm leading-relaxed"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                        >
                            {{ i + 1 }}
                        </span>
                        {{ step }}
                    </li>
                </ol>
                <pre
                    class="overflow-x-auto rounded-xl bg-muted p-4 text-xs leading-relaxed"
                ><code>SUBIDA (una vez):    PDF → texto → fragmentos → vectores → base de datos
PREGUNTA (cada vez): pregunta → vector → fragmentos cercanos → prompt → modelo → respuesta con citas</code></pre>
            </section>

            <!-- Costos -->
            <section class="flex flex-col gap-3">
                <h2 class="flex items-center gap-2 text-lg font-semibold">
                    <Sparkles class="size-5" />
                    Por qué encaja con el nivel gratuito de Gemini
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left">
                                <th class="py-2 pr-4 font-medium">Operación</th>
                                <th class="py-2 font-medium">
                                    Costo en llamadas a la API
                                </th>
                            </tr>
                        </thead>
                        <tbody class="text-muted-foreground">
                            <tr class="border-b">
                                <td class="py-2 pr-4">
                                    Indexar un libro de 500 páginas (~1.500
                                    fragmentos)
                                </td>
                                <td class="py-2">
                                    ~47 llamadas de embedding (lotes de 32) —
                                    una sola vez
                                </td>
                            </tr>
                            <tr class="border-b">
                                <td class="py-2 pr-4">
                                    Cada pregunta del chat
                                </td>
                                <td class="py-2">
                                    2 llamadas (1 embedding + 1 generación)
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4">Resumen de un libro</td>
                                <td class="py-2">
                                    1 llamada por sección + reducción en árbol
                                    («resumir los resúmenes»)
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-sm leading-relaxed text-muted-foreground">
                    Comparado con mandar el libro entero en cada pregunta
                    (~300.000 tokens), es órdenes de magnitud más barato y
                    rápido. Además, todos los llamados a Gemini pasan por un
                    guardián de cuota propio que corta <em>antes</em> de agotar
                    el límite gratuito, y los trabajos pesados son reanudables:
                    si la cuota se agota a mitad de un libro, continúan solos
                    tras el reset. Con el
                    <strong>modo GPU local</strong> (Ollama + tu placa NVIDIA)
                    no hay cuotas: todo corre en tu máquina.
                </p>
            </section>

            <section
                class="rounded-xl border border-dashed p-4 text-xs leading-relaxed text-muted-foreground"
            >
                <strong>Detalle técnico:</strong> los vectores de modelos
                distintos (Gemini vs. modelo local) viven en espacios
                incompatibles y nunca se comparan entre sí. Cada vector se
                guarda con su modelo, y si un proyecto mezcla proveedores la
                búsqueda se hace por espacio y se fusionan los
                <em>rankings</em> (Reciprocal Rank Fusion), no los puntajes. Por
                eso al cambiar de proveedor existe el botón «Re-indexar».
            </section>
        </div>
    </AppLayout>
</template>
