<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores OAuth credentials + tokens and keeps access tokens fresh.
 *
 * Client IDs live in config (drubuzz_publish.settings). Client secrets and
 * the OAuth token sets live in State so they are never written to the config
 * export directory.
 *
 * Token set shape (State key drubuzz_publish.tokens.<platform>):
 *   - access_token   (string)
 *   - refresh_token  (string, optional)
 *   - expires        (int unix timestamp, optional)
 *   - account_id     (string)   provider user id
 *   - account_name   (string)   display name
 *   - account_handle (string)   @handle / vanity, optional
 *   - author_urn     (string)   LinkedIn: urn:li:person:<id>
 *   - connected      (int unix timestamp)
 */
final class TokenStore {

  private const AUTHORIZE = [
    'linkedin' => 'https://www.linkedin.com/oauth/v2/authorization',
    'x' => 'https://twitter.com/i/oauth2/authorize',
  ];
  private const TOKEN = [
    'linkedin' => 'https://www.linkedin.com/oauth/v2/accessToken',
    'x' => 'https://api.twitter.com/2/oauth2/token',
  ];
  public const SCOPES = [
    'linkedin' => 'openid profile w_member_social',
    'x' => 'tweet.read tweet.write users.read media.write offline.access',
  ];

  public function __construct(
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Platform machine names this module supports.
   *
   * @return string[]
   */
  public function platforms(): array {
    return ['linkedin', 'x'];
  }

  public function label(string $platform): string {
    return $platform === 'x' ? 'X' : 'LinkedIn';
  }

  public function authorizeEndpoint(string $platform): string {
    return self::AUTHORIZE[$platform];
  }

  public function tokenEndpoint(string $platform): string {
    return self::TOKEN[$platform];
  }

  public function clientId(string $platform): string {
    return (string) $this->configFactory->get('drubuzz_publish.settings')
      ->get($platform . '_client_id');
  }

  public function clientSecret(string $platform): string {
    return (string) $this->state->get('drubuzz_publish.' . $platform . '.client_secret', '');
  }

  public function setClientSecret(string $platform, string $secret): void {
    $secret = trim($secret);
    if ($secret === '') {
      $this->state->delete('drubuzz_publish.' . $platform . '.client_secret');
      return;
    }
    $this->state->set('drubuzz_publish.' . $platform . '.client_secret', $secret);
  }

  public function isConfigured(string $platform): bool {
    return $this->clientId($platform) !== '' && $this->clientSecret($platform) !== '';
  }

  /**
   * @return array<string, mixed>
   */
  public function tokens(string $platform): array {
    $set = $this->state->get('drubuzz_publish.tokens.' . $platform, []);
    return is_array($set) ? $set : [];
  }

  public function setTokens(string $platform, array $set): void {
    $this->state->set('drubuzz_publish.tokens.' . $platform, $set);
  }

  public function isConnected(string $platform): bool {
    $set = $this->tokens($platform);
    return !empty($set['access_token']) || !empty($set['refresh_token']);
  }

  public function disconnect(string $platform): void {
    $this->state->delete('drubuzz_publish.tokens.' . $platform);
  }

  /**
   * Returns a usable access token, refreshing it first if it looks expired.
   *
   * @return string|null
   *   NULL when the platform is not connected or a refresh failed.
   */
  public function getAccessToken(string $platform): ?string {
    $set = $this->tokens($platform);
    if (empty($set['access_token']) && empty($set['refresh_token'])) {
      return NULL;
    }

    $expires = isset($set['expires']) ? (int) $set['expires'] : 0;
    $needs_refresh = $expires > 0 && $expires - 60 <= $this->time->getRequestTime();

    if ($needs_refresh || empty($set['access_token'])) {
      if (empty($set['refresh_token'])) {
        // Access token expired and there is nothing to refresh with.
        return $set['access_token'] ?? NULL;
      }
      $refreshed = $this->refresh($platform, (string) $set['refresh_token']);
      if ($refreshed === NULL) {
        return NULL;
      }
      $set = $refreshed + $set;
      $this->setTokens($platform, $set);
    }

    return $set['access_token'] ?? NULL;
  }

  /**
   * Exchanges a refresh token for a new token set.
   *
   * @return array<string, mixed>|null
   */
  private function refresh(string $platform, string $refreshToken): ?array {
    $client_id = $this->clientId($platform);
    $client_secret = $this->clientSecret($platform);
    $form = [
      'grant_type' => 'refresh_token',
      'refresh_token' => $refreshToken,
      'client_id' => $client_id,
    ];
    $options = ['form_params' => $form, 'http_errors' => FALSE];
    if ($platform === 'x') {
      // Confidential client: HTTP Basic auth, client_id may be omitted.
      $options['headers']['Authorization'] = 'Basic ' . base64_encode($client_id . ':' . $client_secret);
    }
    else {
      $form['client_secret'] = $client_secret;
      $options['form_params'] = $form;
    }

    try {
      $response = $this->httpClient->request('POST', $this->tokenEndpoint($platform), $options);
      $body = (string) $response->getBody();
      $data = Json::decode($body) ?: [];
      if ($response->getStatusCode() >= 400 || empty($data['access_token'])) {
        $this->logger->error('@p token refresh failed (@code): @body', [
          '@p' => $platform,
          '@code' => $response->getStatusCode(),
          '@body' => mb_substr($body, 0, 500),
        ]);
        return NULL;
      }
      $set = ['access_token' => $data['access_token']];
      if (!empty($data['refresh_token'])) {
        $set['refresh_token'] = $data['refresh_token'];
      }
      if (!empty($data['expires_in'])) {
        $set['expires'] = $this->time->getRequestTime() + (int) $data['expires_in'];
      }
      return $set;
    }
    catch (\Throwable $e) {
      $this->logger->error('@p token refresh error: @m', ['@p' => $platform, '@m' => $e->getMessage()]);
      return NULL;
    }
  }

}
