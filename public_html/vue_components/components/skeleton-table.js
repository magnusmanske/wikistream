/**
 * <skeleton-table> — placeholder shown while a table of data is being
 * fetched. Used by sections-page and candidates-page.
 *
 * Props:
 *   rows {Number} — number of placeholder rows (default 8)
 *   cols {Number} — number of placeholder columns (default 4)
 */

export default {
    name: 'SkeletonTable',
    props: {
        rows: { type: Number, default: 8 },
        cols: { type: Number, default: 4 },
    },
    template: `
        <table class="table" aria-busy="true">
            <caption class="sr-only" tt="loading">Loading</caption>
            <tbody>
                <tr v-for="r in rows" :key="r">
                    <td v-for="c in cols" :key="c">
                        <div class="skeleton-text"></div>
                    </td>
                </tr>
            </tbody>
        </table>
    `,
};
