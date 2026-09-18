function revealActive(nav) {
    const active = nav.querySelector('.active');
    if (!active || nav.scrollWidth <= nav.clientWidth) return;
    const item = active.getBoundingClientRect(), bounds = nav.getBoundingClientRect();
    if (item.left < bounds.left || item.right > bounds.right) {
        nav.scrollLeft += item.left - bounds.left - (bounds.width - item.width) / 2;
    }
}
export const responsiveTabs = {
    mounted(nav) {
        revealActive(nav);
        nav._tabsObserver = new MutationObserver(() => revealActive(nav));
        nav._tabsObserver.observe(nav, { subtree: true, attributes: true, attributeFilter: ['class'], childList: true });
    },
    updated: revealActive,
    beforeUnmount(nav) { nav._tabsObserver?.disconnect(); },
};
