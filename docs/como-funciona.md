# Cómo funciona el procesamiento de documentos (vectorización / RAG)

La técnica completa se llama **RAG** (*Retrieval-Augmented Generation*, generación aumentada por recuperación). La "vectorización" —técnicamente **embeddings**— es su corazón, pero es solo una etapa del pipeline.

## La idea central: convertir significado en geometría

Un embedding es una función que toma un texto y devuelve un punto en un espacio de muchas dimensiones (en DocBrainer, 768 números decimales):

```
"El presupuesto de la obra es de 5 millones"  →  [0.021, -0.348, 0.114, ...] (768 números)
"¿Cuánto cuesta la ampliación?"               →  [0.019, -0.352, 0.109, ...] (768 números)
"Receta de milanesas a la napolitana"         →  [-0.887, 0.023, 0.671, ...] (768 números)
```

El modelo de embeddings (una red neuronal entrenada con billones de textos) garantiza que **textos de significado parecido caigan cerca** en ese espacio, aunque no compartan palabras. La cercanía se mide con la **similitud coseno** (el ángulo entre dos vectores: ángulo chico = mismo tema). Esto permite buscar por *significado* y no por palabra exacta: encuentra "costo de la remodelación" cuando se preguntó por "presupuesto".

## El pipeline de indexación (una sola vez por documento)

1. **Extracción** — Sacar texto limpio del PDF/DOCX preservando estructura (capítulos, secciones, páginas). Es la etapa más subestimada: PDFs con columnas, tablas o escaneados (imagen sin texto) son el principal punto de falla. DocBrainer detecta PDFs escaneados y los marca como fallidos con un mensaje claro (sin OCR en esta versión).

2. **Chunking (troceado)** — Cortar el texto en fragmentos de ~450 tokens (~350 palabras). Demasiado grande → el vector "promedia" muchas ideas y la búsqueda se vuelve borrosa; demasiado chico → cada fragmento pierde contexto. Se respeta la estructura (capítulo → sección → párrafo, nunca a mitad de oración), con ~10% de solapamiento entre chunks consecutivos, y a cada chunk se le antepone su ubicación antes de vectorizar (`"title: Manual de obra — Capítulo 2 > Presupuestos | text: ..."`) — contexto gratis desde la metadata, sin llamadas extra a la IA.

3. **Vectorización** — Cada chunk pasa por el modelo de embeddings (Gemini o un modelo local en tu GPU vía Ollama) y su vector se guarda en MySQL junto al texto y su metadata (documento, sección, páginas). Es la fase "procesando…" con barra de progreso al subir un documento.

## El momento de la pregunta (chat)

1. La pregunta se vectoriza (1 llamada mínima)
2. Se compara su vector contra todos los del proyecto → los ~8 chunks más cercanos por similitud coseno
3. Esos fragmentos + la pregunta van al modelo con la instrucción: *"respondé usando SOLO estos fragmentos y citá las fuentes [1]..[8]"*
4. La respuesta llega con citas verificables (documento, sección, página); si la información no está en los documentos, el modelo lo dice en vez de inventar

```
SUBIDA (una vez):    PDF → texto → chunks → vectores → base de datos
PREGUNTA (cada vez): pregunta → vector → chunks cercanos → prompt → modelo → respuesta con citas
```

## Por qué encaja con el free tier de Gemini

| Operación | Costo en llamadas API |
|---|---|
| Indexar un libro de 500 páginas (~1.500 chunks) | ~47 llamadas de embedding (lotes de 32) — una sola vez |
| Cada pregunta del chat | 2 llamadas (1 embedding + 1 generación) |
| Resumen de un libro | 1 llamada por sección + reduce en árbol ("resumir los resúmenes") |

Comparado con mandar el libro entero en cada pregunta (~300.000 tokens), es órdenes de magnitud más barato y rápido. La calidad final depende tanto de la extracción y el chunking como de la vectorización misma — por eso el preprocesado estructural de libros es parte central del diseño.

## Detalles de implementación en DocBrainer

- **Espacios vectoriales incompatibles**: los vectores de modelos distintos (Gemini vs. modelo local) no se comparan nunca entre sí. Cada vector se guarda con su `embedding_model`, y si un proyecto mezcla proveedores la búsqueda se hace por espacio y se fusionan los *rankings* (RRF), no los puntajes.
- **Cuota free-tier**: todos los llamados a Gemini pasan por un guardián propio (gate de requests por minuto + tope diario persistido en DB) que corta *antes* de golpear la API. Los trabajos de vectorización son reanudables: si se agota la cuota a mitad de un libro, continúan solos tras el reset.
- **Normalización L2**: los vectores se normalizan al guardarse, así la similitud coseno se reduce a un producto punto (más rápido).
