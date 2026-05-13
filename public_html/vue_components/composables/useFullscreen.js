/**
 * useFullscreen — Composition API wrapper around the Fullscreen API.
 *
 * Best-effort `requestFullscreen()` on mount (browsers may block without a
 * user gesture; fails silently). Tracks fullscreen state via `fullscreenchange`
 * so callers can react to the user exiting fullscreen.
 *
 * @param {import('vue').Ref<HTMLElement|null>} targetRef – ref pointing at the element
 * @param {import('vue').Ref<boolean>}          enabledRef – when false, skip fullscreen attempts (e.g. unknown source)
 * @param {Object} [options]
 * @param {() => void} [options.onAfterEnter] – callback invoked after the
 *        fullscreen request is dispatched. Runs in the same call stack so the
 *        user-gesture context is preserved (useful for calling `play()` on a
 *        native <video> element from a click handler).
 * @returns {{ is_fullscreen: import('vue').Ref<boolean>, enterFullscreen: () => void }}
 */
const { ref, onMounted, onBeforeUnmount, nextTick } = Vue;

export function useFullscreen(targetRef, enabledRef, options = {}) {
    const is_fullscreen = ref(false);
    let handler = null;
    const onAfterEnter = typeof options.onAfterEnter === 'function' ? options.onAfterEnter : null;

    function enterFullscreen() {
        const el = targetRef.value;
        if (!el || !enabledRef.value) return;
        const req =
            el.requestFullscreen ||
            el.webkitRequestFullscreen ||
            el.mozRequestFullScreen;
        if (req) {
            try {
                const result = req.call(el);
                if (result && typeof result.catch === 'function') {
                    result.catch(() => {});
                }
            } catch (_) {
                // Browser blocked fullscreen (no user gesture, iOS, etc.) — silent.
            }
        }
        if (onAfterEnter) onAfterEnter();
    }

    function onFullscreenChange() {
        const fsEl =
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            null;
        is_fullscreen.value = fsEl === targetRef.value;
    }

    onMounted(() => {
        handler = onFullscreenChange;
        document.addEventListener('fullscreenchange', handler);
        document.addEventListener('webkitfullscreenchange', handler);
        nextTick(enterFullscreen);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('fullscreenchange', handler);
        document.removeEventListener('webkitfullscreenchange', handler);
        if (is_fullscreen.value) {
            const exit =
                document.exitFullscreen || document.webkitExitFullscreen;
            if (!exit) return;
            try {
                const result = exit.call(document);
                if (result && typeof result.catch === 'function') {
                    result.catch(() => {});
                }
            } catch (_) {}
        }
    });

    return { is_fullscreen, enterFullscreen };
}
