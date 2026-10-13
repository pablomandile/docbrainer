<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import Select from 'primevue/select';
import Tag from 'primevue/tag';
import Toast from 'primevue/toast';
import ToggleSwitch from 'primevue/toggleswitch';
import { useToast } from 'primevue/usetoast';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    index as usersIndex,
    update as usersUpdate,
} from '@/routes/admin/users';
import type { BreadcrumbItem, UserRole } from '@/types';

interface AdminUser {
    id: number;
    name: string;
    email: string;
    role: UserRole;
    is_active: boolean;
    projects_count: number;
    created_at: string;
}

defineProps<{
    users: AdminUser[];
    roles: UserRole[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Usuarios', href: usersIndex() },
];

const page = usePage();
const toast = useToast();

const roleLabels: Record<UserRole, string> = {
    admin: 'Admin',
    user: 'Usuario',
};

const isMe = (user: AdminUser) => user.id === page.props.auth.user.id;

const formatDate = (value: string) =>
    new Date(value).toLocaleDateString('es-AR', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const save = (
    user: AdminUser,
    data: Partial<Pick<AdminUser, 'role' | 'is_active'>>,
    summary: string,
) => {
    router.patch(usersUpdate(user.id).url, data, {
        preserveScroll: true,
        onSuccess: () =>
            toast.add({ severity: 'success', summary, life: 3000 }),
        onError: (errors) =>
            toast.add({
                severity: 'error',
                summary: 'No se pudo actualizar',
                detail: Object.values(errors)[0],
                life: 5000,
            }),
    });
};

const toggleActive = (user: AdminUser, isActive: boolean) =>
    save(
        user,
        { is_active: isActive },
        isActive
            ? `${user.name} ya puede usar la app`
            : `${user.name} desactivado`,
    );

const changeRole = (user: AdminUser, role: UserRole) =>
    save(user, { role }, `${user.name} ahora es ${roleLabels[role]}`);
</script>

<template>
    <Head title="Usuarios" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <Toast />

        <div class="flex h-full flex-1 flex-col gap-6 p-4">
            <div>
                <h1 class="text-xl font-semibold">Usuarios</h1>
                <p class="text-sm text-muted-foreground">
                    Las cuentas nuevas quedan pendientes hasta que las actives.
                    Cada usuario solo ve sus propios proyectos.
                </p>
            </div>

            <ul class="flex flex-col gap-3">
                <li
                    v-for="user in users"
                    :key="user.id"
                    class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ user.name }}</span>
                            <Tag
                                v-if="!user.is_active"
                                value="Pendiente"
                                severity="warn"
                            />
                            <Tag
                                v-if="isMe(user)"
                                value="Vos"
                                severity="info"
                            />
                        </div>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ user.email }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Registrado el {{ formatDate(user.created_at) }} ·
                            {{ user.projects_count }}
                            {{
                                user.projects_count === 1
                                    ? 'proyecto'
                                    : 'proyectos'
                            }}
                        </p>
                    </div>

                    <div class="flex items-center gap-4">
                        <Select
                            :model-value="user.role"
                            :options="roles"
                            :option-label="(role: UserRole) => roleLabels[role]"
                            :disabled="isMe(user)"
                            class="w-36"
                            @update:model-value="
                                (role: UserRole) => changeRole(user, role)
                            "
                        />

                        <label class="flex items-center gap-2 text-sm">
                            <ToggleSwitch
                                :model-value="user.is_active"
                                :disabled="isMe(user)"
                                @update:model-value="
                                    (value: boolean) =>
                                        toggleActive(user, value)
                                "
                            />
                            Activo
                        </label>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
