/**
 * @file
 * Accessible tab switching for the inline social post preview
 * (WAI-ARIA Authoring Practices "tabs" pattern: click + Left/Right/Home/End).
 */
((Drupal, once) => {
  Drupal.behaviors.drubuzzSocialPreviewTabs = {
    attach(context) {
      once('sp-tabs', '[data-sp-tabs]', context).forEach((root) => {
        const tabs = Array.from(root.querySelectorAll('[role="tab"]'));
        const panels = Array.from(root.querySelectorAll('[role="tabpanel"]'));

        const select = (tab, focus = true) => {
          tabs.forEach((t) => {
            const on = t === tab;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
            t.classList.toggle('is-active', on);
          });
          panels.forEach((p) => {
            const on = p.id === tab.getAttribute('aria-controls');
            p.classList.toggle('is-active', on);
            p.hidden = !on;
          });
          if (focus) {
            tab.focus();
          }
        };

        tabs.forEach((tab, i) => {
          tab.addEventListener('click', () => select(tab));
          tab.addEventListener('keydown', (e) => {
            let next = null;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') {
              next = tabs[(i + 1) % tabs.length];
            } else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
              next = tabs[(i - 1 + tabs.length) % tabs.length];
            } else if (e.key === 'Home') {
              next = tabs[0];
            } else if (e.key === 'End') {
              next = tabs[tabs.length - 1];
            }
            if (next) {
              e.preventDefault();
              select(next);
            }
          });
        });
      });
    },
  };
})(Drupal, once);
