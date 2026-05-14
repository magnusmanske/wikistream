/**
 * <group-thumb> — thumbnail card for a group (series, franchise, …).
 *
 * Mirrors <entry-thumb> visually but links to /group/<q>, has no
 * favourite button (groups can't be favourited), and renders only
 * title + year. `group` object shape:
 *   { q, title, year?, image?, type_q? }
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'GroupThumb',
    mixins: [ttMixin],
    props: ['group'],
    methods: {
        missing_icon() {
            return window.config?.misc?.missing_icon || '';
        },
    },
    template: `
        <div class="entry-container">
            <div class="thumbnail-container">
                <router-link :to="'/group/'+group.q">
                    <commons-thumbnail v-if="group.image!=null && group.image!=''" loading="lazy" :filename="group.image" width="200" nolink="1"></commons-thumbnail>
                    <commons-thumbnail v-else :filename="missing_icon()" loading="lazy" width="200" nolink="1"></commons-thumbnail>
                </router-link>
                <div class="legend">
                    {{group.title || ('Q'+group.q)}}
                    <div v-if="group.year!=null">[{{group.year}}]</div>
                </div>
            </div>
        </div>
    `,
};
