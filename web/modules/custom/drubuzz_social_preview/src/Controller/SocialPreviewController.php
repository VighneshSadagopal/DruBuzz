<?php

declare(strict_types=1);

namespace Drupal\drubuzz_social_preview\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\drubuzz_social_preview\SocialPostBuilder;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Serves the per-platform social post preview shown inside the modal.
 */
final class SocialPreviewController extends ControllerBase {

  public function __construct(
    private readonly SocialPostBuilder $builder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self($container->get('drubuzz_social_preview.builder'));
  }

  /**
   * Renders one platform preview of a Posts node.
   */
  public function modal(NodeInterface $node, string $platform): array {
    return $this->builder->build($node, $platform);
  }

  /**
   * Modal title, e.g. "X preview".
   */
  public function title(string $platform): string {
    $label = SocialPostBuilder::PLATFORMS[$platform]['label'] ?? ucfirst($platform);
    return $this->t('@platform preview', ['@platform' => $label])->render();
  }

  /**
   * Only Posts nodes the user may view, and only known platforms.
   */
  public function access(NodeInterface $node, string $platform, AccountInterface $account): AccessResult {
    if (!isset(SocialPostBuilder::PLATFORMS[$platform]) || $node->bundle() !== SocialPostBuilder::BUNDLE) {
      return AccessResult::forbidden()->addCacheableDependency($node);
    }
    return AccessResult::allowedIf($node->access('view', $account))
      ->addCacheableDependency($node);
  }

}
