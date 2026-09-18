import { ref, watch, onBeforeUnmount } from 'vue';
import { useAccountRequest } from './useAccountRequest';

export function useAdminList(url, labels, filters = () => ({})) {
    const api = useAccountRequest(labels);
    const rows = ref([]), page = ref(1), last = ref(1), total = ref(0), loading = ref(false), search = ref(''), selected = ref([]), message = ref('');
    const range = ref({ from: null, to: null });
    let sequence = 0, timer;
    async function load(next = 1) {
        const ticket = ++sequence;
        loading.value = true;
        api.error.value = '';
        try {
            const params = new URLSearchParams({ search: search.value, page: next, ...filters() });
            const data = await api.request(`${url()}?${params}`);
            if (ticket !== sequence) return;
            if (next > (data.last_page ?? 1)) return await load(data.last_page ?? 1);
            rows.value = data.data; page.value = data.current_page ?? 1; last.value = data.last_page ?? 1; total.value = data.total ?? data.data.length;
            range.value = { from: data.from ?? null, to: data.to ?? null };
        } catch (error) { if (ticket === sequence) api.error.value = error.message; }
        finally { if (ticket === sequence) loading.value = false; }
    }
    watch([url, search, filters], () => { ++sequence; loading.value = true; message.value = ''; selected.value = []; clearTimeout(timer); timer = setTimeout(() => load(1), 220); }, { deep: true, immediate: true });
    onBeforeUnmount(() => { ++sequence; clearTimeout(timer); });
    async function mutate(action) {
        message.value = '';
        return api.run(async () => { await action(); selected.value = []; message.value = labels().saved; await load(page.value); });
    }
    return { ...api, rows, page, last, total, range, loading, search, selected, message, load, mutate };
}
