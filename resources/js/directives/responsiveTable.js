// Keep a single set of controls and row state for desktop tables and mobile records.
function labelCells(table) {
    const headers = Array.from(table.tHead?.rows[0]?.cells ?? []);
    for (const header of headers) {
        header.dataset.mobileLabel = header.querySelector('input')?.getAttribute('aria-label') ?? '';
    }
    for (const body of table.tBodies) {
        for (const row of body.rows) {
            let column = 0;
            for (const cell of row.cells) {
                cell.dataset.mobileLabel = headers[column]?.textContent.trim() ?? '';
                cell.dataset.mobileColumn = String(column);
                cell.classList.toggle('mobile-cell-wide', cell.colSpan > 1 || Boolean(cell.querySelector('.row-actions, .table-row-actions, .list-row-actions')) || cell.textContent.trim().length > 40);
                cell.classList.toggle('mobile-cell-avatar', Boolean(cell.querySelector('.team-avatar')));
                column += cell.colSpan;
            }
        }
    }
}

export const responsiveTable = {
    mounted(table) {
        table.classList.add('responsive-table');
        labelCells(table);
        table._mobileObserver = new MutationObserver(() => labelCells(table));
        table._mobileObserver.observe(table, { childList: true, subtree: true, characterData: true });
    },
    updated: labelCells,
    beforeUnmount(table) { table._mobileObserver?.disconnect(); },
};
