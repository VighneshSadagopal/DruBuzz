<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\drubuzz_publish\TokenStore;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the OAuth 2.0 authorization-code flow for LinkedIn and X.
 */
final class OAuthController extends ControllerBase {

  public function __construct(
    private readonly TokenStore $tokenStore,
    private readonly ClientInterface $httpClient,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drubuzz_publish.token_store'),
      $container->get('http_client'),
    );
  }

  /**
   * Redirects (or popup-redirects) the admin to the provider's consent screen.
   */
  public function start(string $platform, Request $request): Response {
    $store = \Drupal::service('tempstore.private')->get('drubuzz_publish');
    $store->set($platform . ':popup', $request->query->getBoolean('popup'));

    if (!$this->tokenStore->isConfigured($platform)) {
      $this->messenger()->addError($this->t('Add the @p client ID and secret before connecting.', [
        '@p' => $this->tokenStore->label($platform),
      ]));
      return $this->finish($platform);
    }

    $state = Crypt::randomBytesBase64(32);
    $store->set($platform . ':state', $state);

    $query = [
      'response_type' => 'code',
      'client_id' => $this->tokenStore->clientId($platform),
      'redirect_uri' => $this->callbackUrl($platform),
      'scope' => TokenStore::SCOPES[$platform],
      'state' => $state,
    ];

    if ($platform === 'x') {
      // X requires PKCE. The verifier must be URL-safe (RFC 7636).
      $verifier = rtrim(strtr(Crypt::randomBytesBase64(64), '+/', '-_'), '=');
      $store->set('x:verifier', $verifier);
      $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, TRUE)), '+/', '-_'), '=');
      $query['code_challenge'] = $challenge;
      $query['code_challenge_method'] = 'S256';
    }

    $url = $this->tokenStore->authorizeEndpoint($platform) . '?' . http_build_query($query);
    // The target host is external, so a plain RedirectResponse is rejected.
    $response = new TrustedRedirectResponse($url);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * Handles the provider redirect back, exchanges the code, stores tokens.
   */
  public function callback(string $platform, Request $request): Response {
    $store = \Drupal::service('tempstore.private')->get('drubuzz_publish');

    if ($error = $request->query->get('error')) {
      $this->messenger()->addError($this->t('@p returned an error: @e', [
        '@p' => $this->tokenStore->label($platform),
        '@e' => $error . ' — ' . (string) $request->query->get('error_description'),
      ]));
      return $this->finish($platform);
    }

    $expected = $store->get($platform . ':state');
    $got = (string) $request->query->get('state');
    if (!$expected || !hash_equals((string) $expected, $got)) {
      $this->messenger()->addError($this->t('The @p sign-in could not be verified (state mismatch). Try again.', [
        '@p' => $this->tokenStore->label($platform),
      ]));
      return $this->finish($platform);
    }
    $store->delete($platform . ':state');

    $code = (string) $request->query->get('code');
    if ($code === '') {
      $this->messenger()->addError($this->t('No authorization code was returned by @p.', [
        '@p' => $this->tokenStore->label($platform),
      ]));
      return $this->finish($platform);
    }

    $form = [
      'grant_type' => 'authorization_code',
      'code' => $code,
      'redirect_uri' => $this->callbackUrl($platform),
    ];
    $options = ['http_errors' => FALSE];
    if ($platform === 'x') {
      $form['client_id'] = $this->tokenStore->clientId($platform);
      $form['code_verifier'] = (string) $store->get('x:verifier');
      $store->delete('x:verifier');
      $options['headers']['Authorization'] = 'Basic ' . base64_encode(
        $this->tokenStore->clientId($platform) . ':' . $this->tokenStore->clientSecret($platform)
      );
    }
    else {
      $form['client_id'] = $this->tokenStore->clientId($platform);
      $form['client_secret'] = $this->tokenStore->clientSecret($platform);
    }
    $options['form_params'] = $form;

    try {
      $response = $this->httpClient->request('POST', $this->tokenStore->tokenEndpoint($platform), $options);
      $body = (string) $response->getBody();
      $data = Json::decode($body) ?: [];
      if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
        throw new \RuntimeException(sprintf('token endpoint %d: %s', $response->getStatusCode(), mb_substr($body, 0, 400)));
      }

      $set = [
        'access_token' => $data['access_token'],
        'connected' => \Drupal::time()->getRequestTime(),
      ];
      if (!empty($data['refresh_token'])) {
        $set['refresh_token'] = $data['refresh_token'];
      }
      if (!empty($data['expires_in'])) {
        $set['expires'] = \Drupal::time()->getRequestTime() + (int) $data['expires_in'];
      }

      $set += $this->fetchIdentity($platform, $data['access_token']);
      $this->tokenStore->setTokens($platform, $set);

      $this->messenger()->addStatus($this->t('Connected @p as %name.', [
        '@p' => $this->tokenStore->label($platform),
        '%name' => $set['account_name'] ?? $set['account_id'] ?? $this->t('unknown account'),
      ]));
    }
    catch (\Throwable $e) {
      \Drupal::logger('drubuzz_publish')->error('@p OAuth callback failed: @m', [
        '@p' => $platform,
        '@m' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Could not complete the @p connection: @m', [
        '@p' => $this->tokenStore->label($platform),
        '@m' => $e->getMessage(),
      ]));
    }

    return $this->finish($platform);
  }

  /**
   * Ends the flow: closes the popup (if this run was started in one) or
   * redirects the full page back to the settings form. Messenger messages set
   * during the flow show on the settings form either way — the popup opener
   * reloads it.
   */
  private function finish(string $platform): Response {
    $store = \Drupal::service('tempstore.private')->get('drubuzz_publish');
    $popup = (bool) $store->get($platform . ':popup');
    $store->delete($platform . ':popup');

    if (!$popup) {
      return $this->redirect('drubuzz_publish.settings');
    }

    $origin = \Drupal::request()->getSchemeAndHttpHost();
    $html = <<<HTML
<!doctype html>
<meta charset="utf-8">
<title>Finishing sign-in…</title>
<body style="font:15px system-ui,sans-serif;background:#141210;color:#f4efe6;padding:2rem">
Finishing sign-in — you can close this window.
<script>
(function () {
  try {
    (window.opener || window.parent).postMessage(
      { drubuzzOauth: true, platform: "$platform" },
      "$origin"
    );
  } catch (e) {}
  setTimeout(function () { window.close(); }, 200);
})();
</script>
</body>
HTML;
    return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
  }

  /**
   * Reads the connected account's identity so it can be shown on the form.
   *
   * @return array<string, string>
   */
  private function fetchIdentity(string $platform, string $accessToken): array {
    try {
      if ($platform === 'linkedin') {
        $res = $this->httpClient->request('GET', 'https://api.linkedin.com/v2/userinfo', [
          'headers' => ['Authorization' => 'Bearer ' . $accessToken],
          'http_errors' => FALSE,
        ]);
        $me = Json::decode((string) $res->getBody()) ?: [];
        $sub = (string) ($me['sub'] ?? '');
        return array_filter([
          'account_id' => $sub,
          'account_name' => (string) ($me['name'] ?? ''),
          'author_urn' => $sub !== '' ? 'urn:li:person:' . $sub : '',
        ]);
      }

      $res = $this->httpClient->request('GET', 'https://api.twitter.com/2/users/me', [
        'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        'http_errors' => FALSE,
      ]);
      $me = Json::decode((string) $res->getBody())['data'] ?? [];
      return array_filter([
        'account_id' => (string) ($me['id'] ?? ''),
        'account_name' => (string) ($me['name'] ?? ''),
        'account_handle' => (string) ($me['username'] ?? ''),
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('drubuzz_publish')->warning('Could not read @p identity: @m', [
        '@p' => $platform,
        '@m' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Absolute callback URL for a platform — must match the provider app config.
   */
  private function callbackUrl(string $platform): string {
    return Url::fromRoute('drubuzz_publish.oauth_callback', ['platform' => $platform], ['absolute' => TRUE])
      ->toString();
  }

}
