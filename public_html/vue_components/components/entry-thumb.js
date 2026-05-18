/**
 * <entry-thumb> — thumbnail card for an item in a list.
 *
 * Renders the item's image (or the first Commons video frame, or a missing-
 * image placeholder) above a label with title + year + minutes + silent-movie
 * icon. Whole tile links to /entry/<q>.
 *
 * When the user is logged in (via <widar>), a heart-shaped overlay button
 * lets them toggle the favourite state without leaving the listing.
 */

import { state, ttMixin } from '../../resources/vue_es6/state.js';
import { useFavorites } from '../composables/useFavorites.js';
import { useHoverPrefetch } from '../composables/useHoverPrefetch.js';

const { computed } = Vue;

export default {
    name: 'EntryThumb',
    mixins: [ttMixin],
    // `removable` is typed as Boolean so Vue 2 coerces the shorthand
    // attribute (<entry-thumb removable>) to true. Without the type
    // declaration it would arrive as an empty string — falsy in v-if.
    props: {
        entry: null,
        removable: { type: Boolean, default: false },
    },
    emits: ['remove'],
    setup(props) {
        const { isFavorite, toggleFavorite } = useFavorites();
        const { start: prefetchStart, cancel: prefetchCancel } = useHoverPrefetch();

        const logged_in = computed(
            () => !!(state.widar && state.widar.userinfo && state.widar.userinfo.name),
        );
        const is_fav = computed(() => isFavorite(props.entry && props.entry.q));

        // Episode badge: server stamps item.primary_type_q during
        // ingestion; config.misc.episode_type_qs lists the Q-IDs that
        // count as episodes for this tool. Empty list = badge disabled.
        const is_episode = computed(() => {
            const type_q = props.entry && Number(props.entry.primary_type_q);
            if (!type_q) return false;
            const types = (window.config && window.config.misc && window.config.misc.episode_type_qs) || [];
            return types.includes(type_q);
        });

        function onHeartClick() {
            if (props.entry && props.entry.q) toggleFavorite(props.entry.q);
        }

        function onHoverStart() {
            const q = props.entry && props.entry.q;
            if (!q) return;
            prefetchStart(`./api.php?action=get_entry&q=${encodeURIComponent(q)}`);
        }

        return { logged_in, is_fav, is_episode, onHeartClick, onHoverStart, prefetchCancel };
    },
    methods: {
        missing_icon() {
            return window.config?.misc?.missing_icon || '';
        },
        first_commons_video() {
            let ret = '';
            (this.entry?.files || []).forEach((v) => {
                if (ret === '' && v.property === 10) ret = v.key;
            });
            return ret;
        },
        onRemoveClick() {
            if (this.entry && this.entry.q) this.$emit('remove', this.entry.q);
        },
    },
    template: `
        <div class="entry-container" @mouseenter="onHoverStart" @mouseleave="prefetchCancel">
            <div class="thumbnail-container">
                <router-link :to="'/entry/'+entry.q">
                    <commons-thumbnail v-if="entry.image!=null" loading="lazy" :filename="entry.image" videothumbnail="1" width="200" nolink="1"></commons-thumbnail>
                    <commons-thumbnail v-else-if="first_commons_video()!=''" loading="lazy" :filename="first_commons_video()" videothumbnail="1" width="200" nolink="1"></commons-thumbnail>
                    <commons-thumbnail v-else :filename="missing_icon()" loading="lazy" width="200" nolink="1"></commons-thumbnail>
                </router-link>
                <button
                    v-if="logged_in"
                    type="button"
                    class="entry-thumb-fav"
                    :class="{ 'is-favorite': is_fav }"
                    @click.stop.prevent="onHeartClick"
                    :tt_title="is_fav ? 'remove_from_favourites' : 'add_to_favourites'"
                    :aria-label="is_fav ? 'Remove from favourites' : 'Add to favourites'"
                >
                    <i v-if="is_fav" class="bi bi-heart-fill"></i>
                    <i v-else class="bi bi-heart"></i>
                </button>
                <span
                    v-if="is_episode"
                    class="entry-thumb-type-badge"
                    tt_title="series_episode"
                    aria-label="Series episode"
                >
                    <i class="bi bi-tv-fill"></i>
                </span>
                <button
                    v-if="removable"
                    type="button"
                    class="entry-thumb-remove"
                    :class="{ 'has-episode-badge': is_episode }"
                    @click.stop.prevent="onRemoveClick"
                    tt_title="remove_from_recently_viewed"
                    aria-label="Remove from recently viewed"
                >
                    <i class="bi bi-x-lg"></i>
                </button>
                <div class="legend">
                    {{entry.title}}
                    <div>
                        <div v-if="entry.minutes!=null || entry.year!=null" style="display:inline;">[<span v-if="entry.year!=null">{{entry.year}}</span><span v-if="entry.minutes!=null && entry.year!=null">;&nbsp;</span><span v-if="entry.minutes!=null">{{entry.minutes}} min</span>]</div>
                        <div v-if="(entry.is_silent??0)*1==1" style="display:inline;" tt_title="silent_movie">🔇</div>
                    </div>
                </div>
            </div>
        </div>
    `,
};
