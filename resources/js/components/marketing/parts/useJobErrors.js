import { useI18n } from 'vue-i18n';

const KNOWN = ['ai_unavailable', 'ai_blocked', 'invalid_ai_output', 'insufficient_sources', 'internal_error', 'job_running'];

/** A job's `error_code` in words (the server's codes, not raw text). */
export default function useJobErrors() {
    const { t } = useI18n();

    return (code) => t(`marketing.job_errors.${KNOWN.includes(code) ? code : 'other'}`);
}
