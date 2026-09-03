<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Publishes a Posts node to LinkedIn and X through their HTTP APIs.
 *
 * Every network call uses http_errors = FALSE and is inspected by status code
 * so a provider failure becomes a recorded error rather than an exception.
 */
final class SocialPublisher {

  public function __construct(
    private readonly TokenStore $tokenStore,
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
    private readonly ?ConfigFactoryInterface $configFactory = NULL,
  ) {}

  /**
   * Publishes one node to one platform and records the attempt.
   *
   * @return array{status:string,remote_id:string,remote_url:string,message:string}
   */
  public function publish(NodeInterface $node, string $platform, ?int $itemId = NULL): array {
    $result = $this->doPublish($node, $platform);
    $this->recordAttempt((int) $node->id(), $platform, $result, $itemId);
    return $result;
  }

  /**
   * @return array{status:string,remote_id:string,remote_url:string,message:string}
   */
  private function doPublish(NodeInterface $node, string $platform): array {
    $fail = static fn(string $m): array => [
      'status' => 'failed', 'remote_id' => '', 'remote_url' => '', 'message' => $m,
    ];

    if ($node->bundle() !== 'posts') {
      return $fail('Only Posts nodes can be published.');
    }
    if (!$this->tokenStore->isConnected($platform)) {
      return $fail(sprintf('%s is not connected.', $this->tokenStore->label($platform)));
    }
    $token = $this->tokenStore->getAccessToken($platform);
    if (!$token) {
      return $fail(sprintf('Could not obtain a valid %s access token (reconnect the account).', $this->tokenStore->label($platform)));
    }

    $text = $this->postText($node, $platform);
    if ($text === '') {
      return $fail(sprintf('This post has no body text for %s.', $this->tokenStore->label($platform)));
    }
    [$imageBytes, $imageMime, $imageAlt] = $this->postImage($node);

    try {
      return $platform === 'x'
        ? $this->publishToX($token, $text, $imageBytes, $imageMime)
        : $this->publishToLinkedIn($token, $text, $imageBytes, $imageMime, $imageAlt);
    }
    catch (\Throwable $e) {
      $this->logger->error('@p publish error for node @nid: @m', [
        '@p' => $platform, '@nid' => $node->id(), '@m' => $e->getMessage(),
      ]);
      return $fail($e->getMessage());
    }
  }

  /* ------------------------------------------------------------------ *
   * LinkedIn — classic UGC Posts API (/v2/ugcPosts + /v2/assets)
   *
   * This path works with the self-serve "Share on LinkedIn" product and the
   * w_member_social scope. The newer /rest/posts endpoint needs Community
   * Management API approval and a monthly-versioned header, so it is not used.
   * ------------------------------------------------------------------ */

  private function publishToLinkedIn(string $token, string $text, ?string $imageBytes, ?string $imageMime, string $alt): array {
    $author = (string) ($this->tokenStore->tokens('linkedin')['author_urn'] ?? '');
    if ($author === '') {
      return ['status' => 'failed', 'remote_id' => '', 'remote_url' => '', 'message' => 'LinkedIn person URN is missing; reconnect the account.'];
    }
    $headers = [
      'Authorization' => 'Bearer ' . $token,
      'X-Restli-Protocol-Version' => '2.0.0',
    ];

    $assetUrn = NULL;
    if ($imageBytes !== NULL) {
      $assetUrn = $this->uploadLinkedInImage($token, $author, $imageBytes, $imageMime);
    }

    $share = [
      'shareCommentary' => ['text' => $text],
      'shareMediaCategory' => $assetUrn ? 'IMAGE' : 'NONE',
    ];
    if ($assetUrn) {
      $share['media'] = [[
        'status' => 'READY',
        'media' => $assetUrn,
        'title' => ['text' => mb_substr($alt !== '' ? $alt : $text, 0, 200)],
      ]];
    }

    $payload = [
      'author' => $author,
      'lifecycleState' => 'PUBLISHED',
      'specificContent' => ['com.linkedin.ugc.ShareContent' => $share],
      'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
    ];

    $res = $this->httpClient->request('POST', 'https://api.linkedin.com/v2/ugcPosts', [
      'headers' => $headers + ['Content-Type' => 'application/json'],
      'json' => $payload,
      'http_errors' => FALSE,
    ]);
    $status = $res->getStatusCode();
    $body = (string) $res->getBody();
    if ($status < 200 || $status >= 300) {
      return ['status' => 'failed', 'remote_id' => '', 'remote_url' => '', 'message' => sprintf('LinkedIn /v2/ugcPosts %d: %s', $status, mb_substr($body, 0, 400))];
    }

    $urn = $res->getHeaderLine('x-restli-id') ?: (Json::decode($body)['id'] ?? '');
    return [
      'status' => 'sent',
      'remote_id' => $urn,
      'remote_url' => $urn ? 'https://www.linkedin.com/feed/update/' . $urn . '/' : '',
      'message' => $assetUrn ? 'Posted with image.' : 'Posted (text only).',
    ];
  }

  /**
   * Registers, uploads and returns a LinkedIn image asset URN, or NULL.
   */
  private function uploadLinkedInImage(string $token, string $author, string $bytes, ?string $mime): ?string {
    $register = $this->httpClient->request('POST', 'https://api.linkedin.com/v2/assets?action=registerUpload', [
      'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
      'json' => [
        'registerUploadRequest' => [
          'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
          'owner' => $author,
          'serviceRelationships' => [[
            'relationshipType' => 'OWNER',
            'identifier' => 'urn:li:userGeneratedContent',
          ]],
        ],
      ],
      'http_errors' => FALSE,
    ]);
    $data = Json::decode((string) $register->getBody()) ?: [];
    $asset = $data['value']['asset'] ?? NULL;
    $uploadUrl = $data['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? NULL;
    if (!$asset || !$uploadUrl) {
      $this->logger->warning('LinkedIn registerUpload failed (@c); posting text only: @b', [
        '@c' => $register->getStatusCode(), '@b' => mb_substr((string) $register->getBody(), 0, 300),
      ]);
      return NULL;
    }

    $put = $this->httpClient->request('POST', $uploadUrl, [
      'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => $mime ?: 'application/octet-stream'],
      'body' => $bytes,
      'http_errors' => FALSE,
    ]);
    if ($put->getStatusCode() >= 300) {
      $this->logger->warning('LinkedIn image upload failed (@c); posting text only.', ['@c' => $put->getStatusCode()]);
      return NULL;
    }
    return (string) $asset;
  }

  /* ------------------------------------------------------------------ *
   * X — API v2
   * ------------------------------------------------------------------ */

  private function publishToX(string $token, string $text, ?string $imageBytes, ?string $imageMime): array {
    $mediaId = NULL;
    if ($imageBytes !== NULL) {
      $mediaId = $this->uploadXMedia($token, $imageBytes, $imageMime);
    }

    $payload = ['text' => $text];
    if ($mediaId) {
      $payload['media'] = ['media_ids' => [$mediaId]];
    }

    $res = $this->httpClient->request('POST', 'https://api.twitter.com/2/tweets', [
      'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
      'json' => $payload,
      'http_errors' => FALSE,
    ]);
    $status = $res->getStatusCode();
    $body = (string) $res->getBody();
    if ($status < 200 || $status >= 300) {
      return ['status' => 'failed', 'remote_id' => '', 'remote_url' => '', 'message' => sprintf('X POST /2/tweets %d: %s', $status, mb_substr($body, 0, 400))];
    }

    $data = Json::decode($body)['data'] ?? [];
    $id = (string) ($data['id'] ?? '');
    $handle = (string) ($this->tokenStore->tokens('x')['account_handle'] ?? '');
    $url = $id === '' ? '' : ($handle !== '' ? "https://x.com/$handle/status/$id" : "https://twitter.com/i/web/status/$id");
    return [
      'status' => 'sent',
      'remote_id' => $id,
      'remote_url' => $url,
      'message' => $mediaId ? 'Tweeted with image.' : 'Tweeted (text only).',
    ];
  }

  /**
   * Uploads an image to X. Tries the v2 endpoint, falls back to v1.1.
   */
  private function uploadXMedia(string $token, string $bytes, ?string $mime): ?string {
    $multipart = [
      ['name' => 'media', 'contents' => $bytes, 'filename' => 'image', 'headers' => ['Content-Type' => $mime ?: 'application/octet-stream']],
      ['name' => 'media_category', 'contents' => 'tweet_image'],
    ];

    foreach (['https://api.x.com/2/media/upload', 'https://upload.twitter.com/1.1/media/upload.json'] as $endpoint) {
      try {
        $res = $this->httpClient->request('POST', $endpoint, [
          'headers' => ['Authorization' => 'Bearer ' . $token],
          'multipart' => $multipart,
          'http_errors' => FALSE,
        ]);
        if ($res->getStatusCode() >= 300) {
          $this->logger->warning('X media upload @e failed (@c): @b', [
            '@e' => $endpoint, '@c' => $res->getStatusCode(), '@b' => mb_substr((string) $res->getBody(), 0, 300),
          ]);
          continue;
        }
        $data = Json::decode((string) $res->getBody()) ?: [];
        $id = $data['data']['id'] ?? $data['media_id_string'] ?? ($data['id'] ?? NULL);
        if ($id) {
          return (string) $id;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('X media upload @e error: @m', ['@e' => $endpoint, '@m' => $e->getMessage()]);
      }
    }
    $this->logger->warning('X media upload failed on all endpoints; tweeting text only.');
    return NULL;
  }

  /* ------------------------------------------------------------------ *
   * Content helpers
   * ------------------------------------------------------------------ */

  private function postText(NodeInterface $node, string $platform): string {
    $field = $platform === 'x' ? 'field_description_x' : 'field_description_linkedin';
    foreach ([$field, 'field_body'] as $candidate) {
      if ($node->hasField($candidate) && !$node->get($candidate)->isEmpty()) {
        $value = (string) $node->get($candidate)->first()->getValue()['value'];
        $value = trim(strip_tags($value));
        if ($value !== '') {
          return $value;
        }
      }
    }
    return '';
  }

  /**
   * @return array{0:?string,1:?string,2:string}
   *   [image bytes, mime type, alt text].
   */
  private function postImage(NodeInterface $node): array {
    if (!$node->hasField('field_graphic') || $node->get('field_graphic')->isEmpty()) {
      return [NULL, NULL, ''];
    }
    try {
      $media = $node->get('field_graphic')->entity;
      if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
        return [NULL, NULL, ''];
      }
      $item = $media->get('field_media_image')->first();
      $file = $item->entity;
      if (!$file) {
        return [NULL, NULL, ''];
      }
      $uri = $file->getFileUri();
      $bytes = @file_get_contents($uri);
      if ($bytes === FALSE || $bytes === '') {
        return [NULL, NULL, ''];
      }
      $alt = (string) ($item->getValue()['alt'] ?? $media->label());
      return [$bytes, $file->getMimeType() ?: 'image/jpeg', $alt];
    }
    catch (\Throwable $e) {
      $this->logger->warning('Could not load image for node @nid: @m', ['@nid' => $node->id(), '@m' => $e->getMessage()]);
      return [NULL, NULL, ''];
    }
  }

  /* ------------------------------------------------------------------ *
   * Delivery log
   * ------------------------------------------------------------------ */

  public function hasSuccessfulDelivery(int $nid, string $platform): bool {
    return (bool) $this->database->select('drubuzz_publish_item', 'i')
      ->fields('i', ['id'])
      ->condition('nid', $nid)
      ->condition('platform', $platform)
      ->condition('status', 'sent')
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * @return object[]
   */
  public function deliveryLog(int $nid): array {
    return $this->database->select('drubuzz_publish_item', 'i')
      ->fields('i')
      ->condition('nid', $nid)
      ->orderBy('changed', 'DESC')
      ->range(0, 20)
      ->execute()
      ->fetchAll();
  }

  private function recordAttempt(int $nid, string $platform, array $result, ?int $itemId): void {
    $now = $this->time->getRequestTime();
    $fields = [
      'nid' => $nid,
      'platform' => $platform,
      'status' => $result['status'],
      'remote_id' => (string) ($result['remote_id'] ?? ''),
      'remote_url' => (string) ($result['remote_url'] ?? ''),
      'message' => (string) ($result['message'] ?? ''),
      'changed' => $now,
    ];

    if ($itemId) {
      $current = (int) ($this->database->select('drubuzz_publish_item', 'i')
        ->fields('i', ['attempts'])
        ->condition('id', $itemId)
        ->execute()
        ->fetchField() ?: 0);
      $this->database->update('drubuzz_publish_item')
        ->fields($fields + ['attempts' => $current + 1])
        ->condition('id', $itemId)
        ->execute();
      return;
    }

    $this->database->insert('drubuzz_publish_item')
      ->fields($fields + ['attempts' => 1, 'created' => $now])
      ->execute();
  }

}
