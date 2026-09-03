/**
 * @file
 * Opens the LinkedIn / X OAuth "Connect" link in a popup window instead of a
 * full-page redirect. When the popup finishes it postMessages the opener (see
 * OAuthController::finish()) or is detected as closed; either way the settings
 * form is reloaded so the new "Connected as …" status shows.
 */
((Drupal, once) => {
  Drupal.behaviors.drubuzzOauthPopup = {
    attach(context) {
      once('dz-oauth-popup', 'a.drubuzz-oauth-connect', context).forEach((link) => {
        link.addEventListener('click', (event) => {
          event.preventDefault();

          const w = 600;
          const h = 760;
          const left = window.screenX + Math.max(0, (window.outerWidth - w) / 2);
          const top = window.screenY + Math.max(0, (window.outerHeight - h) / 2);
          const popup = window.open(
            link.href,
            'drubuzz_oauth',
            `popup,width=${w},height=${h},left=${Math.round(left)},top=${Math.round(top)}`,
          );

          // Popup blocked — fall back to a normal navigation.
          if (!popup || popup.closed || typeof popup.closed === 'undefined') {
            window.location.href = link.href;
            return;
          }

          let done = false;
          const finish = () => {
            if (done) {
              return;
            }
            done = true;
            window.removeEventListener('message', onMessage);
            window.clearInterval(poll);
            try {
              popup.close();
            } catch (e) {
              // Ignore: cross-origin close guard.
            }
            window.location.reload();
          };

          const onMessage = (e) => {
            if (e.origin === window.location.origin && e.data && e.data.drubuzzOauth) {
              finish();
            }
          };
          window.addEventListener('message', onMessage);

          const poll = window.setInterval(() => {
            if (popup.closed) {
              finish();
            }
          }, 700);
        });
      });
    },
  };
})(Drupal, once);
