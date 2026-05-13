/**
 * Route table for the Vue 2.7 / Composition API build.
 *
 * vue-router 3 is loaded as a classic script (global `VueRouter`).
 * Default mode is hash, matching the legacy build.
 */

import MainPage       from './pages/main-page.js';
import EntryPage      from './pages/entry-page.js';
import PlayPage       from './pages/play-page.js';
import SearchPage     from './pages/search-page.js';
import SectionsPage   from './pages/sections-page.js';
import SectionPage    from './pages/section-page.js';
import SpecialPage    from './pages/special-page.js';
import PersonPage     from './pages/person-page.js';
import CandidatesPage from './pages/candidates-page.js';
import YearPage       from './pages/year-page.js';
import YouPage        from './pages/you-page.js';

export const routes = [
    { path: '/',                                  component: MainPage,       props: true },
    { path: '/play/:source_prop/:source_key',     component: PlayPage,       props: true },
    { path: '/entry/:q',                          component: EntryPage,      props: true },
    { path: '/search',                            component: SearchPage,     props: true },
    { path: '/search/:initial_query',             component: SearchPage,     props: true },
    { path: '/sections',                          component: SectionsPage,   props: true },
    { path: '/section/:section_q',                component: SectionPage,    props: true },
    { path: '/section/:section_q/:section_prop',  component: SectionPage,    props: true },
    // `key` is reserved by Vue, so map the route param to a renamed prop.
    { path: '/special/:key',                      component: SpecialPage,
      props: (route) => ({ specialKey: route.params.key }) },
    { path: '/person/:q',                         component: PersonPage,     props: true },
    { path: '/candidates',                        component: CandidatesPage, props: true },
    { path: '/candidates/:offset',                component: CandidatesPage, props: true },
    { path: '/year',                              component: YearPage,       props: true },
    { path: '/year/:year',                        component: YearPage,       props: true },
    { path: '/you',                               component: YouPage,        props: true },
];

export function createRouter() {
    return new VueRouter({ routes });
}
