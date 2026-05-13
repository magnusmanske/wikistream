/**
 * <play-page> — full-screen video player for an item.
 *
 * Supported source properties:
 *   P10         – Commons video, rendered as a native HTML5 <video> so we
 *                 can call .play() in the fullscreen-button click handler
 *                 (the MediaWiki TimedMediaHandler iframe is cross-origin
 *                 and exposes no autoplay parameter)
 *   P51         – Commons audio (WikiVibes) via the TimedMediaHandler iframe
 *   P724        – Internet Archive
 *   P4015       – Vimeo
 *   P11731      – DailyMotion
 *   P1651       – YouTube (via youtube-nocookie.com per CLAUDE.md)
 *
 * On mount: best-effort fullscreen via Fullscreen API. Always-visible
 * fullscreen button overlay as a manual fallback. "Open at source" link
 * for every embeddable provider.
 *
 * Keyboard shortcuts (page-level, document keydown):
 *   f          – toggle fullscreen
 *   space      – play/pause (native <video> only)
 *   m          – mute/unmute (native <video> only)
 *   ← / →      – seek -10 s / +10 s (native <video> only)
 * Iframe sources rely on their own internal keyboard handling.
 *
 * Vue 2.7 Composition API.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useFullscreen } from '../composables/useFullscreen.js';
import { useLog } from '../composables/useLog.js';

const { ref, computed, onMounted, onBeforeUnmount } = Vue;

const SEEK_SECONDS = 10;

export default {
    name: 'PlayPage',
    mixins: [ttMixin],
    props: ['source_prop', 'source_key'],
    setup(props) {
        const stage = ref(null);
        const video = ref(null);

        const prop_id = computed(() => parseInt(props.source_prop, 10));
        const encoded_key = computed(() => encodeURIComponent(props.source_key));

        // Commons videos use a native <video> element (see file header for why).
        const native_video_url = computed(() => {
            if (prop_id.value !== 10) return null;
            return `https://commons.wikimedia.org/wiki/Special:FilePath/${encoded_key.value}`;
        });

        const embed_url = computed(() => {
            const key = encoded_key.value;
            switch (prop_id.value) {
                case 51:
                    return `https://commons.wikimedia.org/wiki/File:${key}?embedplayer=yes`;
                case 724:
                    return `https://archive.org/embed/${key}?autoplay=1`;
                case 4015:
                    return `https://player.vimeo.com/video/${key}?autoplay=1&autopause=0`;
                case 11731:
                    return `https://www.dailymotion.com/embed/video/${key}?autoplay=1`;
                case 1651:
                    return `https://www.youtube-nocookie.com/embed/${key}?autoplay=1`;
            }
            return null;
        });

        const iframe_allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';

        const external_link = computed(() => {
            const key = encoded_key.value;
            switch (prop_id.value) {
                case 10:
                case 51:
                    return `https://commons.wikimedia.org/wiki/File:${key}`;
                case 724:
                    return `https://archive.org/details/${key}`;
                case 4015:
                    return `https://vimeo.com/${key}`;
                case 11731:
                    return `https://www.dailymotion.com/video/${key}`;
                case 1651:
                    return `https://www.youtube.com/watch?v=${key}`;
            }
            return null;
        });

        const playable = computed(
            () => embed_url.value !== null || native_video_url.value !== null,
        );
        const { is_fullscreen, enterFullscreen } = useFullscreen(stage, playable, {
            // When the user clicks the fullscreen button, also start playback
            // of the native <video>. The Commons MediaWiki embed iframe has no
            // autoplay parameter and is cross-origin, so this is the only way
            // to start playback in a user-gesture context.
            onAfterEnter() {
                if (!video.value) return;
                const p = video.value.play();
                if (p && typeof p.catch === 'function') p.catch(() => {});
            },
        });

        const { log } = useLog();
        log('play_page_loaded', {
            source_prop: props.source_prop,
            source_key: props.source_key,
        });

        function onKeydown(e) {
            // Don't intercept when the user is typing in an input.
            const tag = e.target && e.target.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target.isContentEditable) return;
            // Don't fight modifier-key combos the OS / browser owns.
            if (e.ctrlKey || e.metaKey || e.altKey) return;

            const v = video.value;
            switch (e.key) {
                case 'f':
                case 'F':
                    if (!playable.value) return;
                    e.preventDefault();
                    enterFullscreen();
                    break;
                case ' ':
                    if (!v) return;
                    e.preventDefault();
                    if (v.paused) {
                        const p = v.play();
                        if (p && typeof p.catch === 'function') p.catch(() => {});
                    } else {
                        v.pause();
                    }
                    break;
                case 'm':
                case 'M':
                    if (!v) return;
                    e.preventDefault();
                    v.muted = !v.muted;
                    break;
                case 'ArrowLeft':
                    if (!v) return;
                    e.preventDefault();
                    v.currentTime = Math.max(0, v.currentTime - SEEK_SECONDS);
                    break;
                case 'ArrowRight':
                    if (!v) return;
                    e.preventDefault();
                    v.currentTime = Math.min(
                        Number.isFinite(v.duration) ? v.duration : Infinity,
                        v.currentTime + SEEK_SECONDS,
                    );
                    break;
            }
        }

        onMounted(() => document.addEventListener('keydown', onKeydown));
        onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

        return {
            stage,
            video,
            is_fullscreen,
            embed_url,
            native_video_url,
            iframe_allow,
            external_link,
            playable,
            enterFullscreen,
        };
    },
    template: `
        <div class="container-fluid">
            <page-header v-if="!is_fullscreen"></page-header>
            <div class="play-stage" ref="stage">
                <video
                    v-if="native_video_url"
                    ref="video"
                    :src="native_video_url"
                    controls
                    playsinline
                    preload="metadata"
                ></video>

                <iframe
                    v-else-if="embed_url"
                    :src="embed_url"
                    :allow="iframe_allow"
                    frameborder="0"
                    webkitallowfullscreen="true"
                    mozallowfullscreen="true"
                    allowfullscreen
                ></iframe>

                <div v-else class="play-external-card">
                    <p tt="unknown_source">Unknown source</p>
                </div>

                <button
                    v-if="playable && !is_fullscreen"
                    type="button"
                    class="play-fullscreen-btn"
                    @click="enterFullscreen"
                    tt_title="fullscreen"
                    aria-label="Fullscreen"
                >&#9974;</button>

                <a
                    v-if="external_link"
                    :href="external_link"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="play-source-link"
                >
                    <span tt="open_at_source">Open at source</span> &#8599;
                </a>
            </div>
        </div>
    `,
};
