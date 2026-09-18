import { ref } from 'vue';
import { rememberAgreementReturn } from './agreementReturn';

export function useAccountRequest(t) {
    const busy = ref(false);
    const error = ref('');
    async function request(url, body, method = 'POST') {
        const multipart = body instanceof FormData;
        const response = await fetch(url, {
            method: body === undefined ? 'GET' : method,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '', ...(!multipart && body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
            ...(body !== undefined ? { body: multipart ? body : JSON.stringify(body) } : {}),
        });
        const payload = await response.json().catch(() => ({}));
        if (response.status === 401 || response.status === 419) {
            window.location.assign('/');
            throw new Error(t().accountError);
        }
        if (payload.code === 'agreements_required') {
            rememberAgreementReturn();
            window.location.assign('/panel/documents');
        }
        const statusMessage = { 429: t().accountTooMany, 403: t().accountForbidden, 404: t().accountMissing }[response.status];
        if (statusMessage) throw new Error(statusMessage);
        if (!response.ok) throw new Error(Object.values(payload.errors ?? {}).flat()[0] || payload.message || t().accountError);
        return payload;
    }
    async function run(action) {
        if (busy.value) return;
        busy.value = true; error.value = '';
        try { return await action(); } catch (failure) { error.value = failure.message || t().accountError; }
        finally { busy.value = false; }
    }
    return { busy, error, request, run };
}
