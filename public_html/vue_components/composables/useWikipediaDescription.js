/**
 * useWikipediaDescription — fetch a 7-sentence plain-text extract from
 * the Wikipedia article that the given Wikidata item links to.
 *
 * Prefers the user's language; falls back to English. Resolves to '' on
 * any failure or missing link.
 *
 * Replaces the `wikipediaDescriptionMixin` from `public_html/vue.js`.
 */

import { state } from '../../resources/vue_es6/state.js';

export function useWikipediaDescription() {
    function load(q, language) {
        return new Promise((resolve) => {
            const wd = state.wd;
            if (!wd) {
                resolve('');
                return;
            }
            wd.getItemBatch([q], () => {
                const item = wd.getItem(q);
                if (typeof item === 'undefined') {
                    resolve('');
                    return;
                }

                // Prefer the requested language; fall back to en.
                let lang = '';
                let page = '';
                const links = item.getWikiLinks();
                for (const wiki of Object.keys(links)) {
                    const v = links[wiki];
                    if (wiki === 'enwiki' && lang === '') {
                        lang = 'en';
                        page = v.title;
                    } else if (wiki === `${language}wiki`) {
                        lang = language;
                        page = v.title;
                    }
                }
                if (lang === '' || page === '') {
                    resolve('');
                    return;
                }

                const params = new URLSearchParams({
                    action: 'query',
                    prop: 'extracts',
                    exsentences: '7',
                    exlimit: '1',
                    titles: page,
                    explaintext: '1',
                    formatversion: '2',
                    format: 'json',
                    origin: '*',
                });
                fetch(`https://${lang}.wikipedia.org/w/api.php?${params.toString()}`)
                    .then((r) => r.json())
                    .then((d) => {
                        const pages = d?.query?.pages;
                        if (!Array.isArray(pages) || pages.length !== 1) {
                            resolve('');
                            return;
                        }
                        resolve(pages[0].extract || '');
                    })
                    .catch(() => resolve(''));
            });
        });
    }

    return { load };
}
