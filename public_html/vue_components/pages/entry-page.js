/**
 * <entry-page> — single item detail page.
 *
 * Fetches the entry summary from `api.php`, loads the Wikidata item in
 * parallel, derives cast and content tags from item claims, fetches a
 * Wikipedia description, and links to all available play sources.
 */

import { state, ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useWikipediaDescription } from '../composables/useWikipediaDescription.js';

const { ref, onMounted, computed } = Vue;

const TAG_LEVELS = {
    danger: ['Q698752', 'Q47131', 'Q8463'],
    warning: ['Q3587621', 'Q880808'],
};

export default {
    name: 'EntryPage',
    mixins: [ttMixin],
    props: ['q'],
    setup(props) {
        const loading = ref(true);
        const item = ref(null);
        const entry = ref(null);
        const cast = ref([]);
        const tags = ref([]);
        const description = ref('');

        const cfg = window.config || {};

        const { log } = useLog();
        const { load: loadWikipediaDescription } = useWikipediaDescription();

        const associated_people_props = computed(() =>
            cfg.misc?.associated_people_props || [],
        );

        onMounted(async () => {
            const entryQ = `Q${parseInt(props.q, 10) || 0}`;
            log('entry_loaded', { q: props.q });

            // 1. Fetch the entry summary from our API.
            try {
                const res = await fetch(`./api.php?action=get_entry&q=${encodeURIComponent(props.q)}`);
                const j = await res.json();
                entry.value = j.data;
            } catch (_) {
                entry.value = null;
            }

            // 2. Load the Wikidata item via the shared WikiData batch loader.
            await new Promise((resolve) => {
                state.wd.getItemBatch([entryQ], () => {
                    item.value = state.wd.getItem(entryQ);
                    resolve();
                });
            });

            if (item.value != null && entry.value != null) {
                add_cast();
                add_tags();
                loading.value = false;

                // 3. Wikipedia description (fire-and-forget).
                loadWikipediaDescription(entryQ, 'en').then((d) => {
                    if (d) description.value = d;
                });
            } else {
                loading.value = false;
            }
        });

        function add_tags() {
            if (!item.value) return;
            const next = [];

            item.value.getClaimsForProperty('P180').forEach((c) => {
                const tag = { level: 'light' };
                tag.q = item.value.getClaimTargetItemID(c);
                for (const [level, items] of Object.entries(TAG_LEVELS)) {
                    if (items.includes(tag.q)) tag.level = level;
                }
                next.push(tag);
            });

            // Content rating (P5021) with qualifier P9259 (rating value).
            item.value.getClaimsForProperty('P5021').forEach((c) => {
                if (item.value.getClaimTargetItemID(c) !== 'Q4165246') return;
                const quals = c.qualifiers?.P9259;
                if (!quals) return;
                quals.forEach((qual) => {
                    const ratingId = qual?.datavalue?.value?.id;
                    if (ratingId === 'Q105773168') next.push({ level: 'success', q: 'Q4165246' });
                    else if (ratingId === 'Q105773155') next.push({ level: 'danger', q: 'Q4165246' });
                    else if (ratingId === 'Q105729336') next.push({ level: 'light', q: 'Q4165246' });
                });
            });

            tags.value = next;
        }

        function add_cast() {
            if (!item.value || !entry.value) return;
            const performer_prop = cfg.misc?.performer_prop;
            if (!performer_prop) return;
            const next = [];

            item.value.getClaimsForProperty(performer_prop).forEach((c) => {
                const person_q = item.value.getClaimTargetItemID(c);
                const people_by_prop = entry.value.people?.[performer_prop];
                if (!people_by_prop || typeof people_by_prop[person_q] === 'undefined') return;
                const cm = JSON.parse(JSON.stringify(people_by_prop[person_q]));
                // Optional character role qualifier (P4633).
                const role_quals = c.qualifiers?.P4633;
                if (role_quals && role_quals[0]?.datavalue?.value) {
                    cm.as = role_quals[0].datavalue.value;
                }
                next.push(cm);
            });

            cast.value = next;
        }

        function social(key) {
            if (!item.value) return '';
            let label = item.value.getFirstStringForProperty('P154');
            if (!label) label = item.value.getLabel();
            if (key === 'message') {
                const tpl = state.tt?.t('mastodon_message') || '$1';
                return tpl.replace(/\$1/, label);
            }
            if (key === 'url') {
                return `https://${window.location.host}/#/entry/${encodeURIComponent(props.q)}`;
            }
            return '';
        }

        return {
            loading, item, entry, cast, tags, description,
            associated_people_props, social,
        };
    },
    methods: {
        // Uses `this.$router`-adjacent state and instance for the API call.
        async toggleUserItemList() {
            if (!this.entry || !this.item) return;
            this.entry.on_user_item_list = !this.entry.on_user_item_list;
            try {
                const res = await fetch(
                    `./api.php?action=set_user_item_list&q=${this.item.getID()}&state=${this.entry.on_user_item_list ? 1 : 0}`,
                );
                const j = await res.json();
                if (j.status !== 'OK') alert(j.status);
            } catch (e) {
                alert(String(e));
            }
        },
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width: 100%">
                <div v-if="loading">
                    <i tt="loading"></i>
                </div>
                <div v-else-if="item==null||entry==null">
                    <i tt="item_not_in_wikiflix"></i>
                </div>
                <div v-else style="width: 100%">
                    <div style="display: flex">
                        <div style="margin-right: 1rem">
                            <commons-thumbnail v-if='item.hasClaims("P3383")' :filename='item.getFirstStringForProperty("P3383")' width="260" height="400"></commons-thumbnail>
                            <commons-thumbnail v-else-if='item.hasClaims("P18")' :filename='item.getFirstStringForProperty("P18")' width="260" height="400"></commons-thumbnail>
                        </div>
                        <div>
                            <div style="display: flex; margin-bottom: 0.5rem">
                                <div v-if='item.hasClaims("P154")' style="background-color: white">
                                    <commons-thumbnail :filename='item.getFirstStringForProperty("P154")' width="300" height="65" nolink="1"></commons-thumbnail>
                                </div>
                                <div v-else>
                                    <h1>{{item.getLabel()}}</h1>
                                </div>
                                <div style="margin-left: 1rem">
                                    <a :href="item.getURL()" class="wikidata" target="_blank" rel="noopener">
                                        <img border="0" src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/e4/Wikidata-logo_S.svg/180px-Wikidata-logo_S.svg.png" width="32px" />
                                    </a>
                                </div>
                            </div>
                            <div style="margin-bottom: 0.5rem">
                                <span v-for="tag in tags" :key="tag.q+'-'+tag.level" :class="'badge badge-pill badge-'+tag.level" style="margin-right: 0.5rem">
                                    <wd-link :item="tag.q" as_text="1"></wd-link>
                                </span>
                            </div>
                            <div style="margin-bottom: 0.5rem">
                                <span style="margin-right: 1rem">
                                    <a href="#" @click.prevent="toggleUserItemList" style="text-decoration: none">
                                        <span v-if="entry.on_user_item_list" tt_title="remove_from_favourites">♥️</span>
                                        <span v-else tt_title="add_to_favourites"> 🤍 </span>
                                    </a>
                                </span>
                                {{entry.minutes}} min
                            </div>
                            <div style="display: flex; margin-bottom: 0.5rem">
                                <div v-for="v in entry.entry_files" :key="v.property+'-'+v.key" style="margin-right: 1rem">
                                    <div>
                                        <router-link type="button" class="btn btn-outline-light btn-lg" :to="'/play/'+v.property+'/'+v.key" :title="v.key" style="height: 54px">
                                            ▶
                                            <span v-if="v.is_trailer" tt="play_trailer"></span>
                                            <span v-else tt="play"></span>
                                            <img v-if="v.property==10" src="https://upload.wikimedia.org/wikipedia/commons/thumb/4/4a/Commons-logo.svg/180px-Commons-logo.svg.png" width="24px" />
                                            <img v-if="v.property==724" src="https://upload.wikimedia.org/wikipedia/commons/thumb/5/51/Internet_Archive_Logo.svg/180px-Internet_Archive_Logo.svg.png" width="32px" />
                                            <img v-if="v.property==1651" src="https://upload.wikimedia.org/wikipedia/commons/thumb/0/09/YouTube_full-color_icon_%282017%29.svg/180px-YouTube_full-color_icon_%282017%29.svg.png" width="32px" />
                                            <img v-if="v.property==4015" src="https://upload.wikimedia.org/wikipedia/commons/thumb/f/f1/Vimeo_icon_block.png/180px-Vimeo_icon_block.png" width="32px" />
                                            <img v-if="v.property==11731" src="https://upload.wikimedia.org/wikipedia/commons/thumb/a/aa/Dailymotion_Wordmark_(2020).svg/180px-Dailymotion_Wordmark_(2020).svg.png" width="64px" />
                                        </router-link>
                                        <div v-if="v.minutes!=null" class="play_button_legend">
                                            [{{v.minutes}} min]
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div v-for="person_prop in associated_people_props" :key="person_prop">
                                <div v-if='item.hasClaims("P"+person_prop)' style="display: flex; margin-bottom: 0.5rem">
                                    <span><wd-link :item='"P"+person_prop' as_text="1" class="fluc"></wd-link></span>
                                    <span v-for='q in item.getTargets("P"+person_prop)' :key="q" style="margin-left: 0.5rem">
                                        <router-link :to='"/person/"+q.replace(/\\D/g,"")'>
                                            <wd-link :item="q" as_text="1"></wd-link>
                                        </router-link>
                                    </span>
                                </div>
                            </div>
                            <div v-if='description!=""'>
                                <div class="entry-description">
                                    {{description}}
                                </div>
                                <div style="font-size:6pt; text-align: right;">
                                    Description from Wikipedia, under <a class="external" target="_blank" rel="noopener" href="https://en.wikipedia.org/wiki/Wikipedia:Text_of_the_Creative_Commons_Attribution-ShareAlike_4.0_International_License">Creative Commons Attribution-ShareAlike 4.0 License</a>.
                                </div>
                            </div>
                            <div>
                                <mastodon-button :message="social('message')" :target="social('url')"></mastodon-button>
                            </div>
                        </div>
                    </div>
                    <div v-if="cast.length>0" style="clear: both; margin-bottom: 0.5rem; margin-top: 0.5rem; width: 100%;">
                        <h2 tt="performers"></h2>
                        <div style="display: flex; flex-wrap: wrap">
                            <person-thumb v-for="cm in cast" :key="cm.q" :person="cm"></person-thumb>
                        </div>
                    </div>
                    <div style="width: 100%">
                        <section-row v-for="section in entry.sections" :key="section.title" :section="section"></section-row>
                    </div>
                </div>
            </div>
        </div>
    `,
};
