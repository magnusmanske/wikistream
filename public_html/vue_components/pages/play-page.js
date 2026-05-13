/**
 * <play-page> — full-screen video player for an item.
 *
 * Supported source properties:
 *   P10, P51    – Commons file (via MediaWiki TimedMediaHandler iframe)
 *   P724        – Internet Archive
 *   P4015       – Vimeo
 *   P11731      – DailyMotion
 *   P1651       – YouTube (via youtube-nocookie.com per CLAUDE.md)
 *
 * On mount: best-effort fullscreen via Fullscreen API. Always-visible
 * fullscreen button overlay as a manual fallback. "Open at source" link
 * for every embeddable provider.
 *
 * Vue 2.7 Composition API.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useFullscreen } from '../composables/useFullscreen.js';
import { useLog } from '../composables/useLog.js';

const { ref, computed } = Vue;

export default {
    name: 'PlayPage',
    mixins: [ttMixin],
    props: ['source_prop', 'source_key'],
    setup(props) {
        const stage = ref(null);

        const prop_id = computed(() => parseInt(props.source_prop, 10));
        const encoded_key = computed(() => encodeURIComponent(props.source_key));

        const embed_url = computed(() => {
            const key = encoded_key.value;
            switch (prop_id.value) {
                case 10:
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

        const embeddable = computed(() => embed_url.value !== null);
        const { is_fullscreen, enterFullscreen } = useFullscreen(stage, embeddable);

        const { log } = useLog();
        log('play_page_loaded', {
            source_prop: props.source_prop,
            source_key: props.source_key,
        });

        return {
            stage,
            is_fullscreen,
            embed_url,
            iframe_allow,
            external_link,
            enterFullscreen,
        };
    },
    template: `
        <div class="container-fluid">
            <page-header v-if="!is_fullscreen"></page-header>
            <div class="play-stage" ref="stage">
                <iframe
                    v-if="embed_url"
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
                    v-if="embed_url && !is_fullscreen"
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
