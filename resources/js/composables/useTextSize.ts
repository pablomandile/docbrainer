import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { update } from '@/routes/text-size';

export type TextSize = 'small' | 'normal' | 'large';

export type TextSizeOption = {
    value: TextSize;
    label: string;
    description: string;
    /** El porcentaje como número (87.5, 100, 112.5), para calcular la muestra. */
    scale: number;
};

export type UseTextSizeReturn = {
    size: ComputedRef<TextSize>;
    options: ComputedRef<TextSizeOption[]>;
    changeSize: (value: TextSize) => void;
};

/**
 * El servidor ya escribe `data-text` en el <html> de arranque, así que al
 * cargar no hay nada que hacer. Esto es para el otro momento: cuando la
 * persona elige un tamaño. Inertia no recarga el documento, así que el
 * atributo lo mueve el cliente o el cambio no se ve hasta una carga completa.
 */
function applyToDocument(value: TextSize): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.documentElement.dataset.text = value;
}

export function useTextSize(): UseTextSizeReturn {
    const page = usePage();

    const size = computed<TextSize>(
        () => (page.props.textSize as TextSize | undefined) ?? 'normal',
    );

    const options = computed<TextSizeOption[]>(
        () => (page.props.textSizes as TextSizeOption[] | undefined) ?? [],
    );

    function changeSize(value: TextSize): void {
        if (value === size.value) {
            return;
        }

        // Se aplica antes de que conteste el servidor: es un cambio visual
        // puro y esperar la ida y vuelta lo haría sentir trabado.
        const previous = size.value;

        applyToDocument(value);

        router.put(
            update().url,
            { text_size: value },
            {
                preserveScroll: true,
                // Para que `textSize` se relea de los props: con el estado
                // preservado el selector seguiría marcando la opción vieja.
                preserveState: false,
                onError: () => applyToDocument(previous),
            },
        );
    }

    return { size, options, changeSize };
}
