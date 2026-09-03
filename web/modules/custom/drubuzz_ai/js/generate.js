/**
 * @file
 * "Generate with AI" panel on the Posts form. Sends the idea to the endpoint
 * and drops the returned copy into the two description fields.
 */
((Drupal, once, drupalSettings) => {
  const setField = (selector, value) => {
    const el = document.querySelector(selector);
    if (!el || typeof value !== 'string') {
      return false;
    }
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  };

  Drupal.behaviors.drubuzzAiGenerate = {
    attach(context) {
      once('drubuzz-ai', '.drubuzz-ai', context).forEach((panel) => {
        const s = drupalSettings.drubuzzAi || {};
        const btn = panel.querySelector('.drubuzz-ai__go');
        const idea = panel.querySelector('.drubuzz-ai__idea');
        const status = panel.querySelector('.drubuzz-ai__status');
        if (!btn || !idea) {
          return;
        }

        btn.addEventListener('click', async () => {
          const text = idea.value.trim();
          status.className = 'drubuzz-ai__status';
          if (!text) {
            status.textContent = Drupal.t('Type a post idea first.');
            idea.focus();
            return;
          }

          btn.disabled = true;
          panel.classList.add('is-busy');
          status.textContent = Drupal.t('Generating — this can take a few seconds…');

          try {
            const res = await fetch(s.url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ idea: text, nid: s.nid || null }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.error) {
              throw new Error(data.error || `HTTP ${res.status}`);
            }

            const wroteX = setField(s.selectors.x, data.x);
            const wroteLi = setField(s.selectors.linkedin, data.linkedin);
            if (!wroteX && !wroteLi) {
              throw new Error(Drupal.t('Could not find the description fields on this form.'));
            }
            status.classList.add('is-ok');
            status.textContent = Drupal.t('Done — the copy is in the fields below. Review and adjust before saving.');
          } catch (e) {
            status.classList.add('is-error');
            status.textContent = Drupal.t('Could not generate: @m', { '@m': e.message });
          } finally {
            btn.disabled = false;
            panel.classList.remove('is-busy');
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
