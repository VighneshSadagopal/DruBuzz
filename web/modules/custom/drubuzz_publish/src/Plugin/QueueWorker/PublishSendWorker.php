<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish\Plugin\QueueWorker;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\drubuzz_publish\SocialPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends one queued delivery item to its platform.
 *
 * @QueueWorker(
 *   id = "drubuzz_publish_send",
 *   title = @Translation("DruBuzz Publish: send to social network"),
 *   cron = {"time" = 60}
 * )
 */
final class PublishSendWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SocialPublisher $publisher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('drubuzz_publish.publisher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $item_id = is_array($data) ? (int) ($data['item_id'] ?? 0) : 0;
    if (!$item_id) {
      return;
    }

    $row = $this->database->select('drubuzz_publish_item', 'i')
      ->fields('i')
      ->condition('id', $item_id)
      ->execute()
      ->fetchObject();
    if (!$row || $row->status !== 'pending') {
      return;
    }

    $node = $this->entityTypeManager->getStorage('node')->load($row->nid);
    if (!$node) {
      $this->database->update('drubuzz_publish_item')
        ->fields(['status' => 'failed', 'message' => 'Source node no longer exists.', 'changed' => time()])
        ->condition('id', $item_id)
        ->execute();
      return;
    }

    // publish() records the outcome against this item id.
    $this->publisher->publish($node, $row->platform, $item_id);
  }

}
