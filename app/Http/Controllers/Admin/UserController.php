<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->withCount('projects')
            // Primero los pendientes de activación, después los más nuevos
            ->orderBy('is_active')
            ->latest()
            ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at']);

        return Inertia::render('admin/Users', [
            'users' => $users,
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['sometimes', 'required', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        // El admin no puede quitarse el acceso a sí mismo y dejar la app sin admin
        if ($user->is($request->user())
            && (($data['role'] ?? User::ROLE_ADMIN) !== User::ROLE_ADMIN
                || ($data['is_active'] ?? true) === false)) {
            throw ValidationException::withMessages([
                'user' => 'No podés quitarte el rol de admin ni desactivar tu propia cuenta.',
            ]);
        }

        $user->forceFill($data)->save();

        return back()->with('success', 'Usuario actualizado.');
    }
}
