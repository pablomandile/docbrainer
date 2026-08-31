<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = config('docbrainer.extraction.max_upload_mb') * 1024;
        $extensions = config('docbrainer.extraction.allowed_extensions');

        return [
            'files' => ['required', 'array', 'min:1', 'max:10'],
            'files.*' => [
                'required',
                File::types($extensions)->max($maxKb),
            ],
            'embedding_provider' => ['sometimes', 'string', 'in:gemini,ollama'],
        ];
    }

    public function messages(): array
    {
        $extensions = implode(', ', config('docbrainer.extraction.allowed_extensions'));

        return [
            'files.*.mimes' => "Solo se permiten archivos: {$extensions}.",
            'files.*.extensions' => "Solo se permiten archivos: {$extensions}.",
            'files.*.max' => 'El archivo supera el tamaño máximo de '.config('docbrainer.extraction.max_upload_mb').' MB.',
        ];
    }
}
