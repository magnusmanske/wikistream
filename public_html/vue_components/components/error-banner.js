/**
 * <error-banner> — uniform error message for failed API calls.
 *
 * Props:
 *   error {Object} — `{ status: Number, message: String }` from useFetch
 *
 * Status `0` means a network error (offline, DNS, etc.). Non-zero is
 * an HTTP error code from the server.
 */

export default {
    name: 'ErrorBanner',
    props: ['error'],
    template: `
        <div class="alert alert-danger" role="alert" style="max-width: 40rem; margin: 1rem auto;">
            <strong tt="error">Error</strong>
            <span v-if="error && error.status">  {{error.status}}</span>
            <span v-if="error && error.message">: {{error.message}}</span>
        </div>
    `,
};
