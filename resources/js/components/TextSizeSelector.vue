<script setup lang="ts">
import { Check } from 'lucide-vue-next';
import { computed } from 'vue';
import { useTextSize } from '@/composables/useTextSize';
import type { TextSize } from '@/composables/useTextSize';

const { size, options, changeSize } = useTextSize();

const currentScale = computed(
    () => options.value.find((o) => o.value === size.value)?.scale ?? 100,
);

/*
 * La muestra se dibuja al tamaño que tendría la app si se eligiera esa opción,
 * en `em` contra el tamaño vigente y no en píxeles fijos: en píxeles mentiría
 * para quien cambió el tamaño base de su navegador.
 */
function sampleSize(scale: number): string {
    return `${scale / currentScale.value}em`;
}

const isSelected = (value: TextSize) => value === size.value;
</script>

<template>
    <fieldset class="space-y-3">
        <legend class="sr-only">Tamaño de la letra</legend>

        <label
            v-for="option in options"
            :key="option.value"
            :class="[
                'flex cursor-pointer gap-3 rounded-lg border p-4 transition-colors',
                'has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring has-[:focus-visible]:ring-offset-2',
                isSelected(option.value)
                    ? 'border-primary bg-muted'
                    : 'border-border hover:bg-muted/50',
            ]"
        >
            <!-- Un radio de verdad: flechas, barra espaciadora y lectura del
                 estado gratis. sr-only y no display:none, para que siga
                 siendo accesible. -->
            <input
                type="radio"
                name="text_size"
                class="sr-only"
                :value="option.value"
                :checked="isSelected(option.value)"
                @change="changeSize(option.value)"
            />

            <div
                :class="[
                    'mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full border-2',
                    isSelected(option.value)
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-muted-foreground/40',
                ]"
                aria-hidden="true"
            >
                <Check v-if="isSelected(option.value)" class="size-4" />
            </div>

            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <span class="font-medium">{{ option.label }}</span>
                    <span
                        v-if="isSelected(option.value)"
                        class="text-xs text-muted-foreground"
                        >(el que estás usando)</span
                    >
                </div>

                <p class="text-sm text-muted-foreground">
                    {{ option.description }}
                </p>

                <!-- La muestra, al tamaño real: hay que ver la diferencia
                     antes de elegir, no después. -->
                <p
                    class="border-t border-border/60 pt-2 leading-snug wrap-anywhere"
                    :style="{ fontSize: sampleSize(option.scale) }"
                >
                    ¿Qué dice el contrato sobre la rescisión anticipada?
                </p>
            </div>
        </label>
    </fieldset>
</template>
