export type EmbeddingProvider = 'gemini' | 'ollama';

export type DocumentStatus =
    'pending' | 'extracting' | 'chunking' | 'embedding' | 'ready' | 'failed';

export interface Project {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
    documents_count?: number;
    created_at: string;
    updated_at: string;
}

export interface Document {
    id: number;
    project_id: number;
    title: string;
    original_name: string;
    mime_type: string | null;
    extension: string;
    size_bytes: number;
    status: DocumentStatus;
    status_label: string;
    is_processing: boolean;
    embedding_provider: EmbeddingProvider;
    failure_reason: string | null;
    page_count: number | null;
    char_count: number | null;
    chunk_count: number;
    embedded_chunk_count: number;
    processed_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Citation {
    n: number;
    chunk_id: number;
    document_id: number;
    document_title: string | null;
    section_path: string | null;
    page_start: number | null;
    page_end: number | null;
    score: number;
}

export interface ChatMessage {
    id: number;
    conversation_id: number;
    role: 'user' | 'assistant';
    content: string;
    citations: Citation[] | null;
    model: string | null;
    status: 'complete' | 'failed';
    created_at: string;
}

export interface Conversation {
    id: number;
    title: string | null;
    updated_at: string;
}

export interface SummaryParams {
    length: 'corto' | 'medio' | 'extenso';
    style: 'ejecutivo' | 'academico' | 'divulgativo';
    language: 'es' | 'en';
}

export interface Summary {
    id: number;
    document_id: number | null;
    document_title: string | null;
    params: SummaryParams;
    status: 'pending' | 'running' | 'completed' | 'failed';
    status_label: string;
    progress_total: number;
    progress_done: number;
    result: string | null;
    failure_reason: string | null;
    created_at: string;
}

/** Respuesta del endpoint de polling de estado de documentos */
export interface DocumentStatusPoll {
    id: number;
    status: DocumentStatus;
    status_label: string;
    is_processing: boolean;
    failure_reason: string | null;
    chunk_count: number;
    embedded_chunk_count: number;
    page_count: number | null;
}
