<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Chunking = 'chunking';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * ¿El documento está en algún paso del pipeline de procesamiento?
     */
    public function isProcessing(): bool
    {
        return match ($this) {
            self::Pending, self::Extracting, self::Chunking, self::Embedding => true,
            self::Ready, self::Failed => false,
        };
    }

    /**
     * Etiqueta en español para la UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Extracting => 'Extrayendo texto',
            self::Chunking => 'Troceando',
            self::Embedding => 'Vectorizando',
            self::Ready => 'Listo',
            self::Failed => 'Falló',
        };
    }
}
