/**
 * <entry-thumb> — thumbnail card for an item in a list.
 *
 * Renders the item's image (or the first Commons video frame, or a missing-
 * image placeholder) above a label with title + year + minutes + silent-movie
 * icon. Whole tile links to /entry/<q>.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'EntryThumb',
    mixins: [ttMixin],
    props: ['entry'],
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
    },
    template: `
        <div class="entry-container">
            <div class="thumbnail-container">
                <router-link :to="'/entry/'+entry.q">
                    <commons-thumbnail v-if="entry.image!=null" loading="lazy" :filename="entry.image" videothumbnail="1" width="200" nolink="1"></commons-thumbnail>
                    <commons-thumbnail v-else-if="first_commons_video()!=''" loading="lazy" :filename="first_commons_video()" videothumbnail="1" width="200" nolink="1"></commons-thumbnail>
                    <commons-thumbnail v-else :filename="missing_icon()" loading="lazy" width="200" nolink="1"></commons-thumbnail>
                </router-link>
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
