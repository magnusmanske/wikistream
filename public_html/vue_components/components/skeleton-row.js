/**
 * <skeleton-row> — placeholder shown while a row of <entry-thumb>s is
 * being fetched. Matches the layout of <section-row multi_row>:
 * thumbnail-sized boxes that pulse subtly.
 *
 * Props:
 *   count {Number} — number of placeholder thumbnails (default 6)
 *
 * Accessibility: the visually-hidden text is announced by screen
 * readers via the existing tt="loading" key.
 */

export default {
    name: 'SkeletonRow',
    props: {
        count: { type: Number, default: 6 },
    },
    template: `
        <div class="section" aria-busy="true">
            <span class="sr-only" tt="loading">Loading</span>
            <div class="section-multi-row">
                <div v-for="n in count" :key="n" class="skeleton-thumb"></div>
            </div>
        </div>
    `,
};
