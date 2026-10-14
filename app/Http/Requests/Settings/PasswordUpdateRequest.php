<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Quien entró con Google no tiene contraseña: acá crea la primera
            'current_password' => filled($this->user()->getAuthPassword())
                ? $this->currentPasswordRules()
                : ['prohibited'],
            'password' => $this->passwordRules(),
        ];
    }
}
