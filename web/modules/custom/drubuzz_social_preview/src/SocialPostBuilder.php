<?php

declare(strict_types=1);

namespace Drupal\drubuzz_social_preview;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;

/**
 * Assembles the render data for a platform-specific social post preview.
 */
final class SocialPostBuilder {

  /**
   * The Posts bundle this module renders previews for.
   */
  public const BUNDLE = 'posts';

  /**
   * Per-platform configuration.
   *
   * - label: human name shown on the button.
   * - field: description field the platform pulls its body text from.
   * - limit: character budget shown in the composer meta line.
   * - width: modal dialog width in pixels.
   */
  public const PLATFORMS = [
    'linkedin' => [
      'label' => 'LinkedIn',
      'field' => 'field_description_linkedin',
      'limit' => 3000,
      'width' => 555,
    ],
    'x' => [
      'label' => 'X',
      'field' => 'field_description_x',
      'limit' => 280,
      'width' => 600,
    ],
    'instagram' => [
      'label' => 'Instagram',
      'field' => 'field_description_linkedin',
      'limit' => 2200,
      'width' => 470,
    ],
    'facebook' => [
      'label' => 'Facebook',
      'field' => 'field_description_linkedin',
      'limit' => 63206,
      'width' => 500,
    ],
    'mastodon' => [
      'label' => 'Mastodon',
      'field' => 'field_description_x',
      'limit' => 500,
      'width' => 560,
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RendererInterface $renderer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Builds the render array for one platform preview of a node.
   */
  public function build(NodeInterface $node, string $platform): array {
    $config = self::PLATFORMS[$platform];
    $settings = $this->configFactory->get('drubuzz_social_preview.settings');
    $author = $node->getOwner();

    // Identity comes from the settings form; each field falls back to the
    // node author when left blank.
    $author_name = trim((string) $settings->get('display_name'))
      ?: $author->getDisplayName();
    $username = trim((string) $settings->get('username'))
      ?: (preg_replace('/[^a-z0-9_]+/', '', strtolower(str_replace(' ', '', (string) $author_name))) ?: 'user');
    $instance = trim((string) $settings->get('mastodon_instance')) ?: 'mastodon.social';

    $body = '';
    if ($node->hasField($config['field']) && !$node->get($config['field'])->isEmpty()) {
      $body = trim((string) $node->get($config['field'])->value);
    }
    // Fall back to the other description, then the title, so the preview is
    // never empty while an editor is still filling the form in.
    if ($body === '') {
      foreach (['field_description_linkedin', 'field_description_x'] as $fallback) {
        if ($node->hasField($fallback) && !$node->get($fallback)->isEmpty()) {
          $body = trim((string) $node->get($fallback)->value);
          break;
        }
      }
    }
    if ($body === '') {
      $body = (string) $node->label();
    }

    $created = (int) $node->getCreatedTime();

    return [
      '#theme' => 'social_post_preview',
      '#platform' => $platform,
      '#post' => [
        'platform' => $platform,
        'platform_label' => $config['label'],
        'author_name' => $author_name,
        'username' => $username,
        'handle' => $this->handle($platform, $username, $instance),
        'headline' => $platform === 'linkedin' ? trim((string) $settings->get('headline')) : '',
        'avatar' => $this->avatar($author, (int) $settings->get('profile_avatar_media'), (string) $author_name),
        'initials' => $this->initials((string) $author_name),
        'timestamp' => $this->timestamp($platform, $created),
        'datetime' => $this->dateFormatter->format($created, 'custom', 'c'),
        'body' => $body,
        'char_count' => mb_strlen($body),
        'char_limit' => $config['limit'],
        'over_limit' => mb_strlen($body) > $config['limit'],
        'image' => $this->graphic($node),
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ],
      '#attached' => ['library' => ['drubuzz_social_preview/preview']],
      '#cache' => [
        'tags' => Cache::mergeTags($node->getCacheTags(), $settings->getCacheTags()),
        'contexts' => ['url'],
      ],
    ];
  }

  /**
   * The inline tabbed preview shown on the node page (LinkedIn first).
   */
  public function buildTabs(NodeInterface $node): array {
    $settings = $this->configFactory->get('drubuzz_social_preview.settings');
    $tabs = [];
    foreach (self::PLATFORMS as $platform => $config) {
      $tabs[] = [
        'platform' => $platform,
        'label' => $config['label'],
        'content' => $this->build($node, $platform),
      ];
    }

    return [
      '#theme' => 'social_preview_tabs',
      '#tabs' => $tabs,
      '#default' => array_key_first(self::PLATFORMS),
      '#attached' => [
        'library' => [
          'drubuzz_social_preview/preview',
          'drubuzz_social_preview/tabs',
        ],
      ],
      '#cache' => [
        'tags' => Cache::mergeTags($node->getCacheTags(), $settings->getCacheTags()),
        'contexts' => ['url'],
      ],
    ];
  }

  private function handle(string $platform, string $username, string $instance): string {
    return match ($platform) {
      'x' => '@' . $username,
      'mastodon' => '@' . $username . '@' . $instance,
      'instagram' => $username,
      default => '',
    };
  }

  private function timestamp(string $platform, int $created): string {
    $diff = \Drupal::time()->getRequestTime() - $created;
    $short = $this->shortAge($diff);
    return match ($platform) {
      'x' => $diff < 604800 ? $short : $this->dateFormatter->format($created, 'custom', 'M j'),
      'mastodon' => $this->dateFormatter->format($created, 'custom', 'M j, Y'),
      'instagram' => $this->longAge($diff),
      default => $short,
    };
  }

  private function shortAge(int $seconds): string {
    if ($seconds < 60) {
      return 'now';
    }
    if ($seconds < 3600) {
      return floor($seconds / 60) . 'm';
    }
    if ($seconds < 86400) {
      return floor($seconds / 3600) . 'h';
    }
    if ($seconds < 604800) {
      return floor($seconds / 86400) . 'd';
    }
    return floor($seconds / 604800) . 'w';
  }

  private function longAge(int $seconds): string {
    if ($seconds < 3600) {
      return max(1, (int) floor($seconds / 60)) . ' minutes ago';
    }
    if ($seconds < 86400) {
      $h = (int) floor($seconds / 3600);
      return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = (int) floor($seconds / 86400);
    return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
  }

  private function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
      $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters ?: 'U';
  }

  /**
   * Avatar render array: the configured profile picture Media entity, else the
   * author's user picture, else NULL (the template then shows initials).
   */
  private function avatar($author, int $configured_media_id, string $alt): ?array {
    if ($configured_media_id > 0) {
      $media = $this->entityTypeManager->getStorage('media')->load($configured_media_id);
      if ($media && $media->hasField('field_media_image') && !$media->get('field_media_image')->isEmpty()) {
        $build = $media->get('field_media_image')->view([
          'label' => 'hidden',
          'type' => 'image',
          'settings' => ['image_style' => 'thumbnail'],
        ]);
        if (!empty($build[0]['#item'])) {
          $build[0]['#item_attributes']['alt'] = $alt;
        }
        return $build;
      }
    }
    if ($author->hasField('user_picture') && !$author->get('user_picture')->isEmpty()) {
      return $author->get('user_picture')->view([
        'label' => 'hidden',
        'type' => 'image',
        'settings' => ['image_style' => 'thumbnail'],
      ]);
    }
    return NULL;
  }

  /**
   * Post graphic render array (from the field_graphic media), or NULL.
   */
  private function graphic(NodeInterface $node): ?array {
    if (!$node->hasField('field_graphic') || $node->get('field_graphic')->isEmpty()) {
      return NULL;
    }
    $media = $node->get('field_graphic')->entity;
    if (!$media || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return NULL;
    }
    return $media->get('field_media_image')->view([
      'label' => 'hidden',
      'type' => 'image',
      'settings' => ['image_style' => 'large'],
    ]);
  }

}
