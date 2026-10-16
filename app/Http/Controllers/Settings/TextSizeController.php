<?php

namespace App\Http\Controllers\Settings;

use App\Enums\TextSize;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

class TextSizeController extends Controller
{
    /**
     * Guarda el tamaño elegido en los dos lados: la cookie evita el salto en
     * la próxima carga y la cuenta hace que la preferencia siga a la persona.
     *
     * No pide sesión: quien no llega a leer el login es justo quien más
     * necesita agrandar la letra, y ahí todavía no hay cuenta. Sin sesión
     * queda solo la cookie.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'text_size' => ['required', Rule::enum(TextSize::class)],
        ]);

        $size = TextSize::from($data['text_size']);

        $request->user()?->forceFill(['text_size' => $size])->save();

        return back()->withCookie(Cookie::forever('text_size', $size->value));
    }
}
