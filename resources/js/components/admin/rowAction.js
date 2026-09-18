export function activateAdminRow(event, action) {
    if (event.target.closest('button, a, input, label, select, textarea, summary, video')) return;
    if (window.getSelection()?.toString()) return;
    action();
}
