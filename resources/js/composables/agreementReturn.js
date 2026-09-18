const key = 'kr.agreement-return';

function valid(path) {
    return typeof path === 'string' && /^\/panel(?:\/|\?|$)/.test(path)
        && !/[\\\r\n]/.test(path) && !/^\/panel\/(documents|agreement-doc)(?:\/|\?|$)/.test(path);
}

export function rememberAgreementReturn(path = location.pathname + location.search) {
    if (!valid(path)) return;
    try { sessionStorage.setItem(key, path); } catch { /* Storage may be unavailable in private browsing. */ }
}

export function consumeAgreementReturn() {
    try {
        const path = sessionStorage.getItem(key);
        sessionStorage.removeItem(key);
        return valid(path) ? path : null;
    } catch { return null; }
}
