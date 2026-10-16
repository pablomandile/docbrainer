<script setup lang="ts">
import { Download } from 'lucide-vue-next';
import { ref } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePwaInstall } from '@/composables/usePwaInstall';

/*
 * "Instalar app" en el menú. Se esconde si ya está instalada.
 *
 * El botón queda visible aunque el prompt ya se haya usado (el evento sirve una
 * sola vez, y quien lo descartó sin querer no podría reintentar): en ese caso
 * y en iOS, donde la instalación es manual, abre las instrucciones.
 */
const { canInstall, isIos, install } = usePwaInstall();

const showHelp = ref(false);
const busy = ref(false);

async function onClick() {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        if ((await install()) === 'manual') {
            showHelp.value = true;
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <SidebarMenu v-if="canInstall">
        <SidebarMenuItem>
            <SidebarMenuButton
                tooltip="Instalar app"
                :disabled="busy"
                data-test="install-app-button"
                @click="onClick"
            >
                <Download />
                <span>Instalar app</span>
            </SidebarMenuButton>
        </SidebarMenuItem>
    </SidebarMenu>

    <Dialog v-model:open="showHelp">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Instalar DocBrainer</DialogTitle>
                <DialogDescription>
                    Se abre en su propia ventana, con su ícono, como cualquier
                    otra app.
                </DialogDescription>
            </DialogHeader>

            <ol v-if="isIos" class="list-decimal space-y-2 pl-5 text-sm">
                <li>Tocá <strong>Compartir</strong> en la barra de Safari.</li>
                <li>Elegí <strong>Agregar a inicio</strong>.</li>
            </ol>
            <ol v-else class="list-decimal space-y-2 pl-5 text-sm">
                <li>Abrí el menú del navegador (los tres puntos).</li>
                <li>
                    Elegí <strong>Instalar aplicación</strong> o
                    <strong>Agregar a la pantalla de inicio</strong>.
                </li>
            </ol>
        </DialogContent>
    </Dialog>
</template>
