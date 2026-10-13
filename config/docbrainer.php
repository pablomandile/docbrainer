<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Usuarios
    |--------------------------------------------------------------------------
    | Quien se registra con este email queda como admin y activo. El resto
    | espera a que un admin lo active y le asigne un rol.
    */

    'admin_email' => env('DOCBRAINER_ADMIN_EMAIL', 'pablo.mandile@gmail.com'),

    /*
    |--------------------------------------------------------------------------
    | Proveedor Gemini (API)
    |--------------------------------------------------------------------------
    | Todos los datos de la API de Gemini se toman del archivo .env.
    | Los límites (rpm / daily) son topes del LADO CLIENTE, por debajo del
    | free tier real, para nunca reventar la cuota de Google.
    */

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', ''),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-flash-lite-latest'),
        'embed_rpm' => (int) env('GEMINI_EMBED_RPM', 60),
        'generate_rpm' => (int) env('GEMINI_GENERATE_RPM', 8),
        'embed_daily_limit' => (int) env('GEMINI_EMBED_DAILY_LIMIT', 900),
        'generate_daily_limit' => (int) env('GEMINI_GENERATE_DAILY_LIMIT', 150),
        'embed_batch_size' => (int) env('GEMINI_EMBED_BATCH_SIZE', 32),
        'max_retries' => (int) env('GEMINI_MAX_RETRIES', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proveedor GPU local (Ollama)
    |--------------------------------------------------------------------------
    */

    'ollama' => [
        // En producción (hosting compartido sin GPU) se apaga con
        // OLLAMA_ENABLED=false para no sondear nvidia-smi ni el puerto local.
        'enabled' => (bool) env('OLLAMA_ENABLED', true),
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'embeddinggemma'),
        'embedding_dimensions' => (int) env('OLLAMA_EMBEDDING_DIMENSIONS', 768),
        'chat_model' => env('OLLAMA_CHAT_MODEL', 'gemma4:e4b'),
        // Ollama remoto detrás de un túnel de Cloudflare protegido con Access:
        // service token que se manda en cada request (vacío = sin Access).
        'cf_access_client_id' => env('OLLAMA_CF_ACCESS_CLIENT_ID', ''),
        'cf_access_client_secret' => env('OLLAMA_CF_ACCESS_CLIENT_SECRET', ''),
        // Textos por llamada a /api/embed (la GPU procesa en serie igualmente)
        'embed_batch_size' => 16,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extracción de texto
    |--------------------------------------------------------------------------
    */

    'extraction' => [
        'pdftotext_path' => env('PDFTOTEXT_PATH', 'pdftotext'),
        // Umbral de detección de PDF escaneado: promedio de caracteres por página
        'scanned_pdf_chars_per_page' => 100,
        // smalot/pdfparser (fallback puro PHP) se omite por encima de este tamaño
        'parser_fallback_max_mb' => 30,
        'max_upload_mb' => (int) env('DOCBRAINER_MAX_UPLOAD_MB', 50),
        'allowed_extensions' => ['pdf', 'txt', 'docx', 'json', 'md'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunking
    |--------------------------------------------------------------------------
    | Tokens estimados con la heurística mb_strlen/4 (funciona bien en español).
    */

    'chunking' => [
        'target_tokens' => (int) env('DOCBRAINER_CHUNK_TARGET_TOKENS', 450),
        'overlap_pct' => (int) env('DOCBRAINER_CHUNK_OVERLAP_PCT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retrieval (RAG)
    |--------------------------------------------------------------------------
    */

    'retrieval' => [
        'top_k' => (int) env('DOCBRAINER_RETRIEVAL_TOP_K', 8),
        // Constante k de Reciprocal Rank Fusion (fusión de rankings)
        'rrf_k' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resúmenes (map-reduce)
    |--------------------------------------------------------------------------
    */

    'summary' => [
        // Máximo de tokens de entrada por nodo "map" (nivel 0)
        'map_unit_tokens' => 3000,
        // Máximo de resúmenes combinados por llamada "reduce"
        'reduce_fan_in' => 10,
    ],

];
