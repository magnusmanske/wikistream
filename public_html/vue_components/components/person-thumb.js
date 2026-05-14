/**
 * <person-thumb> — thumbnail card for a person (performer, director, …).
 *
 * Whole tile links to /person/<q>. Shows `person.as` (character role) under
 * the label if present.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';

export default {
    name: 'PersonThumb',
    mixins: [ttMixin],
    props: ['person'],
    template: `
        <div class="person-box">
            <router-link :to="'/person/'+person.q">
                <div class="person-picture">
                    <commons-thumbnail v-if="person.image!=''" loading="lazy" nolink="1" :filename="person.image" width="260"></commons-thumbnail>
                </div>
                <div>
                    {{person.label}}
                    <span v-if='typeof person.as!="undefined" && person.as!=""' class="text-truncate" style="font-size: 8pt;">
                        <br/><span tt="as"></span> {{person.as}}
                    </span>
                </div>
            </router-link>
        </div>
    `,
};
