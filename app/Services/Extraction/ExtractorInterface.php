<?php

namespace App\Services\Extraction;

use App\Models\Document;

interface ExtractorInterface
{
    /**
     * ¿Este extractor puede procesar el documento (según extensión/mime)?
     */
    public function supports(Document $document): bool;

    /**
     * Extrae el texto estructurado del archivo.
     *
     * @param  string  $absolutePath  Ruta absoluta al archivo original en disco.
     *
     * @throws ExtractionFailedException si el archivo no puede procesarse
     *                                   (corrupto, escaneado, vacío, etc.).
     */
    public function extract(Document $document, string $absolutePath): ExtractedDocument;
}
