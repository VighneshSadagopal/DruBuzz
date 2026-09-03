/**
 * @file
 * Auto-dismissing behaviour for the status-message toasts.
 *
 * Status / warning toasts fade after 5s, errors after 12s (long enough to
 * read, but they still close as asked). Hovering pauses the timer; each toast
 * also gets a close button if the template didn't provide one.
 */
((Drupal, once) => {
  const LIFETIME = { status: 5000, warning: 6000, error: 12000 };

  const dismiss = (el) => {
    if (el.dataset.dbToastGone) {
      return;
    }
    el.dataset.dbToastGone = '1';
    el.classList.add('is-leaving');
    window.setTimeout(() => el.remove(), 220);
  };

  Drupal.behaviors.drubuzzToasts = {
    attach(context) {
      const toasts = once(
        'db-toast',
        '[data-drupal-messages] .messages, .db-toasts .messages',
        context,
      );

      toasts.forEach((el) => {
        // Make sure there is a close control.
        if (!el.querySelector('.db-toast__close')) {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'db-toast__close';
          btn.setAttribute('aria-label', Drupal.t('Dismiss'));
          btn.innerHTML = '&times;';
          el.prepend(btn);
        }
        el.querySelector('.db-toast__close').addEventListener('click', () => dismiss(el));

        // Type from the modifier class.
        let type = 'status';
        if (el.classList.contains('messages--error')) {
          type = 'error';
        } else if (el.classList.contains('messages--warning')) {
          type = 'warning';
        }

        let timer = window.setTimeout(() => dismiss(el), LIFETIME[type] || 6000);
        el.addEventListener('mouseenter', () => window.clearTimeout(timer));
        el.addEventListener('mouseleave', () => {
          window.clearTimeout(timer);
          timer = window.setTimeout(() => dismiss(el), 2500);
        });
      });
    },
  };
})(Drupal, once);
