'use strict';

let router ;
let app ;
let wd = new WikiData() ;

var wikipediaDescriptionMixin = {
    methods: {
        load_description : function(q,language,callback) {
            wd.getItemBatch([q],function(){
                let item = wd.getItem(q);
                if ( typeof item=='undefined' ) return callback();
                let lang = '';
                let page = '';
                $.each(item.getWikiLinks(),function(wiki,v){
                    if ( wiki=='enwiki' && lang=='' ) { // Fallback
                        lang = 'en';
                        page = v.title;
                    } else if ( wiki==language+'wiki' ) {
                        lang = language;
                        page = v.title;
                    }
                });
                if ( lang=='' || page=='' ) return callback();
                $.getJSON("https://"+lang+".wikipedia.org/w/api.php?callback=?",{
                    action:'query',
                    prop:'extracts',
                    exsentences:7,
                    exlimit:1,
                    titles:page,
                    explaintext:1,
                    formatversion:2,
                    format:'json'
                },function(d){
                    if ( typeof d.query=='undefined' ) return callback();
                    if ( typeof d.query.pages=='undefined' ) return callback();
                    if ( d.query.pages.length!=1 ) return callback();
                    callback(d.query.pages[0].extract);
                });
            });
        }
    }
};

$(document).ready ( function () {
    vue_components.toolname = config.misc.toolname ;
    Promise.all ( [
        vue_components.loadComponents ( ['wd-date','wd-link','tool-translate','tool-navbar','commons-thumbnail','widar','autodesc','typeahead-search','value-validator','mastodon-button','batch-navigator',
            'vue_components/entry-thumb.html',
            'vue_components/person-thumb.html',
            'vue_components/section-row.html',
            'vue_components/page-header.html',
            'vue_components/main-page.html',
            'vue_components/entry-page.html',
            'vue_components/play-page.html',
            'vue_components/section-page.html',
            'vue_components/sections-page.html',
            'vue_components/search-page.html',
            'vue_components/person-page.html',
            'vue_components/candidates-page.html',
            'vue_components/year-page.html',
            'vue_components/you-page.html',
            ] )
    ] )
    .then ( () => {
        widar_api_url = "https://"+config.misc.toolname+".toolforge.org/api.php";
        wd_link_wd = wd ;
        const routes = [
            { path: '/', component: MainPage , props:true },
            { path: '/play/:source_prop/:source_key', component: PlayPage , props:true },
            { path: '/entry/:q', component: EntryPage , props:true },
            { path: '/search', component: SearchPage , props:true },
            { path: '/search/:initial_query', component: SearchPage , props:true },
            { path: '/sections', component: SectionsPage , props:true },
            { path: '/section/:section_q', component: SectionPage , props:true },
            { path: '/section/:section_q/:setion_prop', component: SectionPage , props:true },
            { path: '/person/:q', component: PersonPage , props:true },
            { path: '/candidates', component: CandidatesPage , props:true },
            { path: '/candidates/:offset', component: CandidatesPage , props:true },
            { path: '/year', component: YearPage , props:true },
            { path: '/year/:year', component: YearPage , props:true },
            { path: '/you', component: YouPage , props:true },
        ] ;
        router = new VueRouter({routes}) ;
        app = new Vue ( { router } ) .$mount('#app') ;
        // $('#help_page').attr('href',wd.page_path.replace(/\$1/,config.source_page));
    } ) ;
} ) ;
