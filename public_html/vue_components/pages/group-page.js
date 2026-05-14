/**
 * <group-page> — single group (series/franchise/…) view.
 *
 * Shows group metadata (label, image, year, Wikidata link, Wikipedia
 * description) and the list of items belonging to it. If the API
 * response includes subgroups (seasons for TV series), each subgroup
 * renders as its own labelled <section-row> of episodes.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useFetch } from '../composables/useFetch.js';
import { useWikipediaDescription } from '../composables/useWikipediaDescription.js';

const { ref, onMounted, computed } = Vue;

export default {
    name: 'GroupPage',
    mixins: [ttMixin],
    props: ['q'],
    setup(props) {
        const loading = ref(true);
        const group = ref(null);
        const description = ref('');

        const { log } = useLog();
        const { error, run } = useFetch();
        const { load: loadWikipediaDescription } = useWikipediaDescription();

        // Sections to render: each subgroup is one section-shaped object,
        // plus an "ungrouped" section for items without a subgroup.
        const sections = computed(() => {
            if (!group.value) return [];
            const out = [];
            (group.value.subgroups || []).forEach((sg) => {
                out.push({
                    q: sg.q,
                    title: sg.title || `Q${sg.q}`,
                    total: (sg.entries || []).length,
                    entries: sg.entries || [],
                });
            });
            const ungrouped = group.value.entries || [];
            if (ungrouped.length > 0) {
                out.push({
                    title: out.length > 0 ? 'Other' : (group.value.title || ''),
                    title_key: out.length > 0 ? 'other' : null,
                    total: ungrouped.length,
                    entries: ungrouped,
                });
            }
            return out;
        });

        onMounted(async () => {
            const qNum = parseInt(props.q, 10) || 0;
            log('group_loaded', { q: qNum });

            const groupPromise = run(`./api.php?action=get_group&q=${qNum}`)
                .then((j) => {
                    if (j) group.value = j.data || null;
                })
                .finally(() => {
                    loading.value = false;
                });

            const descPromise = loadWikipediaDescription(`Q${qNum}`, 'en').then((d) => {
                if (d) description.value = d;
            });

            await Promise.all([groupPromise, descPromise]);
        });

        return { loading, group, description, sections, error };
    },
    template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <error-banner v-if="error" :error="error"></error-banner>
                <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                <div v-else-if="group==null" style="width:100%">
                    <i tt="item_not_in_wikiflix"></i>
                </div>
                <div v-else style="width:100%">
                    <div style="display:flex; margin-bottom: 1rem;">
                        <div v-if="group.image" style="width:280px; text-align:center; margin-right:1rem;">
                            <commons-thumbnail :filename="group.image" width="260" height="400"></commons-thumbnail>
                        </div>
                        <div style="flex-grow:1;">
                            <div style="display:flex; align-items:center;">
                                <h2 style="margin:0;">{{group.title}}</h2>
                                <a :href="'https://www.wikidata.org/wiki/Q'+group.q"
                                   class="wikidata" target="_blank" rel="noopener"
                                   style="margin-left:1rem;">
                                    <img border="0" decoding="async"
                                         src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/71/Wikidata.svg/330px-Wikidata.svg.png"
                                         width="32px" />
                                </a>
                            </div>
                            <div v-if="group.year" style="margin-top:0.5rem;">{{group.year}}</div>
                            <div v-if="description!=''" class="entry-description" style="margin-top:0.5rem;">
                                {{description}}
                            </div>
                            <div v-if="description!=''" style="font-size:6pt; text-align:right;">
                                Description from Wikipedia, under <a class="external" target="_blank" rel="noopener" href="https://en.wikipedia.org/wiki/Wikipedia:Text_of_the_Creative_Commons_Attribution-ShareAlike_4.0_International_License">Creative Commons Attribution-ShareAlike 4.0 License</a>.
                            </div>
                        </div>
                    </div>
                    <div v-if="sections.length===0" tt="no_entries_in_group" style="opacity:0.7;">No entries.</div>
                    <section-row v-for="s in sections" :key="'sg-'+(s.q||s.title)" :section="s" nolink="1" multi_row="1"></section-row>
                </div>
            </div>
        </div>
    `,
};
