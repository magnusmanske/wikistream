/**
 * <person-page> — single person view with optional Wikipedia description
 * and a grid of entries they're involved in.
 */

import { ttMixin } from '../../resources/vue_es6/state.js';
import { useLog } from '../composables/useLog.js';
import { useFetch } from '../composables/useFetch.js';
import { useWikipediaDescription } from '../composables/useWikipediaDescription.js';

const { ref, onMounted } = Vue;

export default {
	name: 'PersonPage',
	mixins: [ttMixin],
	props: ['q'],
	setup(props) {
		const loading = ref(true);
		const person = ref({});
		const description = ref('');

		const { log } = useLog();
		const { error, run } = useFetch();
		const { load: loadWikipediaDescription } = useWikipediaDescription();

		onMounted(async () => {
			const q = parseInt(props.q, 10) || 0;
			log('person_loaded', { q });

			// Fetch person data and the Wikipedia description in parallel —
			// they're independent.
			const personPromise = run(`./api.php?action=get_person&q=${q}`)
				.then((j) => {
					if (j) person.value = j.data || {};
				})
				.finally(() => {
					loading.value = false;
				});

			const descPromise = loadWikipediaDescription(`Q${q}`, 'en').then((d) => {
				if (d) description.value = d;
			});

			await Promise.all([personPromise, descPromise]);
		});

		return { loading, person, description, error };
	},
	template: `
        <div class="container-fluid">
            <page-header></page-header>
            <div class="row" style="width:100%;">
                <error-banner v-if="error" :error="error"></error-banner>
                <skeleton-row v-else-if="loading" :count="12"></skeleton-row>
                <div v-else>
                    <div style="display:flex;margin-bottom: 1rem;">
                        <div style="width:280px; text-align: center;">
                            <commons-thumbnail v-if='typeof person.image!="undefined" && person.image!=null && person.image!=""' :filename="person.image" width="260" height="400"></commons-thumbnail>
                        </div>
                        <div style="margin-left: 1rem;">
                            <div style="flex-grow: 1;display: flex;">
                                <h2>{{person.label}}</h2>
                                <div style="margin-left:1rem;">
                                    <a :href="'https://www.wikidata.org/wiki/Q'+person.q" class="wikidata" target="_blank" rel="noopener">
                                        <img border="0" src="https://upload.wikimedia.org/wikipedia/commons/thumb/7/71/Wikidata.svg/330px-Wikidata.svg.png" width="32px" />
                                    </a>
                                </div>
                            </div>
                            <div v-if='description!=""' class="person-description">
                                {{description}}
                            </div>
                        </div>
                    </div>
                    <h3 tt="entries"></h3>
                    <section-row :entries="person.entries" multi_row="1"></section-row>
                </div>
            </div>
        </div>
    `,
};
