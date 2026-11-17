<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Cpu } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { edit as aiSettings } from '@/routes/ai-settings';
import { status as gpuStatusRoute } from '@/routes/gpu';

/**
 * Semáforo de la GPU (Ollama en la PC, por el túnel) en el encabezado.
 * Verde: lista. Amarillo: indexando o le falta un modelo. Rojo: no responde.
 * Solo se dibuja si Ollama está habilitado en el .env (no en la demo).
 */
type GpuState = 'available' | 'busy' | 'incomplete' | 'offline' | 'disabled';

type GpuStatus = {
    state: GpuState;
    message: string;
    working?: number;
    checked_at?: string;
    // Solo para el admin
    details?: { host: string | null; version: string | null; models: string[] };
};

// El server guarda el sondeo 30 s en caché: más seguido no trae nada nuevo.
const POLL_MS = 30_000;

const page = usePage();
const enabled = computed(() => page.props.gpuIndicator === true);
const status = ref<GpuStatus | null>(null);
let timer: ReturnType<typeof setInterval> | undefined;
let controller: AbortController | undefined;

const labels: Record<GpuState, string> = {
    available: 'GPU lista',
    busy: 'GPU trabajando',
    incomplete: 'GPU incompleta',
    offline: 'GPU apagada',
    disabled: 'GPU deshabilitada',
};

const dotClass: Record<GpuState, string> = {
    available: 'bg-emerald-500',
    busy: 'bg-amber-500',
    incomplete: 'bg-amber-500',
    offline: 'bg-red-500',
    disabled: 'bg-zinc-400 dark:bg-zinc-500',
};

const label = computed(() =>
    status.value ? labels[status.value.state] : 'Consultando la GPU…',
);

const checkedAgo = computed(() => {
    if (!status.value?.checked_at) {
        return null;
    }

    const seconds = Math.max(
        0,
        Math.round((Date.now() - Date.parse(status.value.checked_at)) / 1000),
    );

    return seconds < 60
        ? `hace ${seconds} s`
        : `hace ${Math.round(seconds / 60)} min`;
});

async function refresh(): Promise<void> {
    if (!enabled.value || document.visibilityState === 'hidden') {
        return;
    }

    controller?.abort();
    controller = new AbortController();

    try {
        const response = await fetch(gpuStatusRoute().url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (response.ok) {
            status.value = (await response.json()) as GpuStatus;
        }
    } catch {
        // Sin conexión o pedido cancelado: queda lo último que se vio.
    }
}

function onVisibility(): void {
    if (document.visibilityState === 'visible') {
        void refresh();
    }
}

onMounted(() => {
    void refresh();
    timer = setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    clearInterval(timer);
    controller?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <DropdownMenu v-if="enabled && status?.state !== 'disabled'">
        <DropdownMenuTrigger
            class="inline-flex h-8 shrink-0 items-center gap-2 rounded-full border border-sidebar-border/70 px-3 text-xs font-medium text-foreground/80 transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-label="`Estado de la GPU: ${label}`"
            data-test="gpu-status"
        >
            <span class="relative flex size-2.5">
                <span
                    v-if="status?.state === 'busy'"
                    class="absolute inline-flex size-full animate-ping rounded-full bg-amber-400 opacity-60"
                />
                <span
                    class="relative inline-flex size-2.5 rounded-full"
                    :class="status ? dotClass[status.state] : 'bg-zinc-300'"
                    :data-state="status?.state ?? 'loading'"
                />
            </span>
            <span class="hidden sm:inline">{{ label }}</span>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel class="flex items-center gap-2">
                <Cpu class="size-4" />
                {{ label }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <div class="space-y-1.5 px-2 py-1.5 text-sm">
                <p>{{ status?.message ?? 'Consultando la GPU…' }}</p>
                <template v-if="status?.details">
                    <p class="text-xs text-muted-foreground">
                        {{ status.details.host }}
                        <template v-if="status.details.version">
                            · Ollama v{{ status.details.version }}
                        </template>
                        <template v-if="status.details.models.length">
                            <br />{{ status.details.models.join(', ') }}
                        </template>
                    </p>
                </template>
                <p v-if="checkedAgo" class="text-xs text-muted-foreground">
                    Consultado {{ checkedAgo }}.
                </p>
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem :as-child="true">
                <Link :href="aiSettings()" class="w-full cursor-pointer">
                    Elegir el proveedor de IA
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
