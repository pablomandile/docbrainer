# 🧠 DocBrainer

Consultá y resumí tus documentos con IA. Creá un **proyecto** (por ejemplo, *Ampliación de la casa*), subí documentos (PDF, Word, texto, Markdown, JSON) y:

- **💬 Chat**: hacé preguntas y recibí respuestas basadas *exclusivamente* en tus documentos, con **citas verificables** (documento, sección, página). Si la respuesta no está en los documentos, el asistente lo dice en vez de inventar.
- **📝 Resumen**: generá resúmenes de un documento específico o del proyecto completo, con parámetros de longitud (corto/medio/extenso), estilo (ejecutivo/académico/divulgativo) e idioma. Los libros largos se procesan con **map-reduce** (resumir secciones → resumir los resúmenes) sin saturar la API.
- **⚡ Dos proveedores de IA**: la **API de Gemini** (nivel gratuito, con guardián de cuota propio) o **modelos locales en tu GPU** vía [Ollama](https://ollama.com) (sin límites de cuota). Elegís el proveedor por documento al subirlo, y podés re-indexar cuando quieras.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel 12 (PHP 8.4) |
| Frontend | Vue 3 + TypeScript + Inertia.js 2 + Tailwind CSS 4 + PrimeVue 4.5 |
| Base de datos | MySQL 8 (vectores como BLOB + similitud coseno en PHP, índice FULLTEXT para búsqueda híbrida) |
| IA (nube) | Google Gemini API — embeddings + generación, free tier |
| IA (local) | Ollama — `embeddinggemma` (embeddings) + `gemma3:4b` (chat) en GPU NVIDIA/CUDA |
| Procesamiento | Colas de Laravel (driver `database`), jobs idempotentes y reanudables |

## Instalación (Laragon / Windows)

### Requisitos

- PHP ≥ 8.2 con `mbstring`, `fileinfo`, `pdo_mysql`
- Composer, Node.js ≥ 20, MySQL 8
- `pdftotext` para extraer PDFs — usá [Poppler para Windows](https://github.com/oschwartz10612/poppler-windows/releases) (moderno, con mejor fidelidad en columnas/tablas que el xpdf de 2017 que trae Git for Windows). Descargá el `Release-*.zip`, extraelo a `C:\laragon\bin\poppler` y verificá con `C:\laragon\bin\poppler\Library\bin\pdftotext.exe -v`
- Una clave de la API de Gemini (gratuita): [aistudio.google.com/apikey](https://aistudio.google.com/apikey)
- *(Opcional, modo GPU)* GPU NVIDIA con drivers CUDA + [Ollama](https://ollama.com)

### Pasos

```bash
cd c:\laragon\www
git clone <este-repo> docbrainer && cd docbrainer

composer install
npm install

copy .env.example .env
php artisan key:generate
```

Crear la base de datos y configurar `.env`:

```bash
mysql -u root -e "CREATE DATABASE docbrainer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

```dotenv
DB_CONNECTION=mysql
DB_DATABASE=docbrainer

GEMINI_API_KEY=tu_clave_acá        # ← todos los datos de la API viven SOLO en .env
PDFTOTEXT_PATH=C:/laragon/bin/poppler/Library/bin/pdftotext.exe
```

Migrar y compilar:

```bash
php artisan migrate
npm run build
```

### Ejecutar

```bash
composer dev
```

Esto levanta el servidor (`http://localhost:8000`), el **worker de cola** (imprescindible: el procesamiento de documentos y los resúmenes corren en segundo plano), los logs y Vite, todo junto. Registrate en `/register` y creá tu primer proyecto.

> ⚠️ Si corrés la app de otra forma, recordá mantener `php artisan queue:listen` (o `queue:work`) abierto: sin worker, los documentos quedan "Pendiente" para siempre.

### Modo GPU local (opcional)

1. Instalá [Ollama](https://ollama.com) (detecta CUDA automáticamente).
2. Descargá los modelos: `ollama pull embeddinggemma` y `ollama pull gemma3:4b`.
3. En DocBrainer: **Configuración → Proveedor de IA** — si tu GPU y Ollama están disponibles vas a ver el estado en **verde** con el nombre de tu placa y VRAM.
4. Al subir documentos, elegí "Indexar con: GPU local". Los documentos ya indexados con Gemini se pueden **re-indexar** desde la tabla de documentos.

## Cómo funciona el procesamiento de documentos (vectorización / RAG)

La técnica completa se llama **RAG** (*Retrieval-Augmented Generation*, generación aumentada por recuperación). La "vectorización" —técnicamente **embeddings**— es su corazón, pero es solo una etapa del pipeline.

### La idea central: convertir significado en geometría

Un embedding es una función que toma un texto y devuelve un punto en un espacio de muchas dimensiones (en DocBrainer, 768 números decimales):

```
"El presupuesto de la obra es de 5 millones"  →  [0.021, -0.348, 0.114, ...] (768 números)
"¿Cuánto cuesta la ampliación?"               →  [0.019, -0.352, 0.109, ...] (768 números)
"Receta de milanesas a la napolitana"         →  [-0.887, 0.023, 0.671, ...] (768 números)
```

El modelo de embeddings (una red neuronal entrenada con billones de textos) garantiza que **textos de significado parecido caigan cerca** en ese espacio, aunque no compartan palabras. La cercanía se mide con la **similitud coseno** (el ángulo entre dos vectores: ángulo chico = mismo tema). Esto permite buscar por *significado* y no por palabra exacta: encuentra "costo de la remodelación" cuando se preguntó por "presupuesto".

### El pipeline de indexación (una sola vez por documento)

1. **Extracción** — Sacar texto limpio del PDF/DOCX preservando estructura (capítulos, secciones, páginas). Es la etapa más subestimada: PDFs con columnas, tablas o escaneados (imagen sin texto) son el principal punto de falla. DocBrainer detecta PDFs escaneados y los marca como fallidos con un mensaje claro (sin OCR en esta versión).

2. **Chunking (troceado)** — Cortar el texto en fragmentos de ~450 tokens (~350 palabras). Demasiado grande → el vector "promedia" muchas ideas y la búsqueda se vuelve borrosa; demasiado chico → cada fragmento pierde contexto. Se respeta la estructura (capítulo → sección → párrafo, nunca a mitad de oración), con ~10% de solapamiento entre chunks consecutivos, y a cada chunk se le antepone su ubicación antes de vectorizar (`"title: Manual de obra — Capítulo 2 > Presupuestos | text: ..."`) — contexto gratis desde la metadata, sin llamadas extra a la IA.

3. **Vectorización** — Cada chunk pasa por el modelo de embeddings (Gemini o un modelo local en tu GPU vía Ollama) y su vector se guarda en MySQL junto al texto y su metadata (documento, sección, páginas). Es la fase "procesando…" con barra de progreso al subir un documento.

### El momento de la pregunta (chat)

1. La pregunta se vectoriza (1 llamada mínima)
2. Se compara su vector contra todos los del proyecto → los ~8 chunks más cercanos por similitud coseno, reforzados con búsqueda léxica exacta (FULLTEXT + fusión RRF: **búsqueda híbrida**)
3. Esos fragmentos + la pregunta van al modelo con la instrucción: *"respondé usando SOLO estos fragmentos y citá las fuentes [1]..[8]"*
4. La respuesta llega con citas verificables (documento, sección, página); si la información no está en los documentos, el modelo lo dice en vez de inventar

```
SUBIDA (una vez):    PDF → texto → chunks → vectores → base de datos
PREGUNTA (cada vez): pregunta → vector → chunks cercanos → prompt → modelo → respuesta con citas
```

### Por qué encaja con el free tier de Gemini

| Operación | Costo en llamadas API |
|---|---|
| Indexar un libro de 500 páginas (~1.500 chunks) | ~47 llamadas de embedding (lotes de 32) — una sola vez |
| Cada pregunta del chat | 2 llamadas (1 embedding + 1 generación) |
| Resumen de un libro | 1 llamada por sección + reduce en árbol ("resumir los resúmenes") |

Comparado con mandar el libro entero en cada pregunta (~300.000 tokens), es órdenes de magnitud más barato y rápido. Además:

- **Guardián de cuota propio**: gate de requests por minuto + tope diario persistido en DB que corta *antes* de golpear la API (reset a medianoche, hora del Pacífico — el reloj de cuota de Google). El uso del día se ve en **Configuración → Proveedor de IA**.
- **Jobs reanudables**: si la cuota se agota a mitad de un libro, la vectorización continúa sola tras el reset — el estado por chunk (`embedded_at`) es la fuente de verdad.
- **Espacios vectoriales incompatibles**: los vectores de modelos distintos nunca se comparan entre sí; cada vector guarda su modelo, y los proyectos mixtos fusionan *rankings* (RRF), no puntajes.

*(Esta explicación también está en la app: solapa "Cómo funciona" del menú, y en [docs/como-funciona.md](docs/como-funciona.md).)*

## Configuración (.env)

| Variable | Default | Descripción |
|---|---|---|
| `GEMINI_API_KEY` | — | Clave de la API de Gemini (obligatoria para el proveedor nube) |
| `GEMINI_EMBEDDING_MODEL` | `gemini-embedding-001` | Modelo de embeddings |
| `GEMINI_CHAT_MODEL` | `gemini-flash-lite-latest` | Modelo de chat/resúmenes |
| `GEMINI_EMBED_RPM` / `GEMINI_GENERATE_RPM` | 60 / 8 | Topes propios de requests por minuto |
| `GEMINI_EMBED_DAILY_LIMIT` / `GEMINI_GENERATE_DAILY_LIMIT` | 900 / 150 | Topes propios diarios (bajo el free tier real) |
| `GEMINI_EMBED_BATCH_SIZE` | 32 | Chunks por llamada de embedding |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL de Ollama |
| `OLLAMA_EMBEDDING_MODEL` / `OLLAMA_CHAT_MODEL` | `embeddinggemma` / `gemma3:4b` | Modelos locales |
| `PDFTOTEXT_PATH` | — | Ruta al binario pdftotext |
| `DOCBRAINER_CHUNK_TARGET_TOKENS` | 450 | Tamaño objetivo de chunk |
| `DOCBRAINER_CHUNK_OVERLAP_PCT` | 10 | Solapamiento entre chunks |
| `DOCBRAINER_RETRIEVAL_TOP_K` | 8 | Fragmentos recuperados por pregunta |
| `DOCBRAINER_MAX_UPLOAD_MB` | 50 | Tamaño máximo de archivo |

> Si cambiás `GEMINI_EMBEDDING_MODEL`, los documentos ya indexados quedan en otro espacio vectorial: re-indexalos desde la tabla de documentos.

## Tests

```bash
php artisan test
```

La suite cubre extractores (incluido round-trip DOCX real), chunking, guardián de cuota, vector store, pipeline completo de embeddings (API fakeada), chat RAG con citas, árbol map-reduce de resúmenes, proveedor Ollama, detección de GPU y autorización (cada usuario solo ve sus proyectos).

## Limitaciones conocidas (v1)

- **Sin OCR**: los PDFs escaneados se detectan y rechazan con un mensaje claro.
- Los documentos de Google Docs se suben **exportados** como `.docx` o `.pdf` (sin integración con Drive).
- El streaming del chat degrada a respuesta completa si el servidor bufferea (Apache); con `composer dev` (artisan serve) funciona en vivo.
- La búsqueda vectorial es fuerza bruta en PHP: excelente hasta decenas de miles de chunks por proyecto; la interfaz `VectorStore` permite migrar a pgvector/Qdrant si hiciera falta.
