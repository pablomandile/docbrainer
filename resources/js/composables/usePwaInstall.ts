import { computed, onMounted, onUnmounted, ref } from 'vue';

const STANDALONE = '(display-mode: standalone)';

/*
 * El evento `beforeinstallprompt` lo captura un script inline en el <head>
 * (app.blade.php) y lo deja en window.__pwaInstall: dispara apenas carga la
 * página, normalmente ANTES de que monte Vue, y un listener registrado acá lo
 * perdería de forma intermitente.
 */

// iOS/iPadOS Safari nunca dispara beforeinstallprompt: la instalación es manual.
function detectIosSafari(): boolean {
    const ua = navigator.userAgent;
    const isIosDevice =
        /iPad|iPhone|iPod/.test(ua) ||
        // iPadOS se reporta como Mac; se distingue por el touch.
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    // Chrome/Firefox/Edge en iOS usan WebKit pero no pueden instalar PWAs.
    return isIosDevice && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

function detectInstalled(): boolean {
    return (
        window.matchMedia?.(STANDALONE).matches === true ||
        (window.navigator as Navigator & { standalone?: boolean })
            .standalone === true || // iOS
        window.__pwaInstall?.installed === true
    );
}

export function usePwaInstall() {
    const isInstalled = ref(detectInstalled());
    const isIos = detectIosSafari();

    // Se mantiene en true aunque el prompt ya se haya consumido, para poder
    // caer en las instrucciones manuales en vez de dejar el botón muerto.
    const offered = ref(!!window.__pwaInstall?.prompt);

    const canInstall = computed(
        () => !isInstalled.value && (offered.value || isIos),
    );

    const onInstallable = () => (offered.value = true);
    const onInstalled = () => (isInstalled.value = true);
    const onDisplayModeChange = (e: MediaQueryListEvent) =>
        (isInstalled.value = e.matches);
    const mq = window.matchMedia?.(STANDALONE);

    onMounted(() => {
        window.addEventListener('pwa:installable', onInstallable);
        window.addEventListener('pwa:installed', onInstalled);
        mq?.addEventListener?.('change', onDisplayModeChange);
    });
    onUnmounted(() => {
        window.removeEventListener('pwa:installable', onInstallable);
        window.removeEventListener('pwa:installed', onInstalled);
        mq?.removeEventListener?.('change', onDisplayModeChange);
    });

    /** 'manual' = iOS, o el prompt ya se usó: hay que explicar los pasos. */
    async function install(): Promise<'accepted' | 'dismissed' | 'manual'> {
        const deferred = window.__pwaInstall?.prompt;

        if (!deferred) {
            return 'manual';
        }

        window.__pwaInstall!.prompt = null; // el evento sirve una sola vez
        await deferred.prompt();
        const { outcome } = await deferred.userChoice;

        return outcome;
    }

    return { canInstall, isInstalled, isIos, install };
}
