<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish\Form;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\drubuzz_publish\SocialPublisher;
use Drupal\drubuzz_publish\TokenStore;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * "Publish" tab on a Posts node: connection status, delivery log, send now.
 */
final class SendNowForm extends FormBase {

  public function __construct(
    private readonly TokenStore $tokenStore,
    private readonly SocialPublisher $publisher,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('drubuzz_publish.token_store'),
      $container->get('drubuzz_publish.publisher'),
    );
  }

  public function getFormId(): string {
    return 'drubuzz_publish_send_now';
  }

  /**
   * Route access: Posts nodes only, for users who may publish them.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(
      $node->bundle() === 'posts' && $account->hasPermission('publish drubuzz posts'),
    )->addCacheableDependency($node);
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $form['#node'] = $node;
    $connected = [];

    $rows = [];
    foreach ($this->tokenStore->platforms() as $platform) {
      $label = $this->tokenStore->label($platform);
      $is_connected = $this->tokenStore->isConnected($platform);
      if ($is_connected) {
        $connected[] = $platform;
      }
      $tokens = $this->tokenStore->tokens($platform);
      $rows[] = [
        $label,
        $is_connected
          ? $this->t('Connected as @who', ['@who' => $tokens['account_name'] ?? $tokens['account_id'] ?? $this->t('unknown')])
          : $this->t('Not connected'),
        $this->publisher->hasSuccessfulDelivery((int) $node->id(), $platform)
          ? $this->t('Already sent')
          : $this->t('Not sent'),
      ];
    }

    $form['status'] = [
      '#type' => 'table',
      '#header' => [$this->t('Network'), $this->t('Account'), $this->t('This post')],
      '#rows' => $rows,
    ];

    // Schedule summary.
    $schedule = $this->t('not scheduled (manual only)');
    if ($node->hasField('field_publish_at') && !$node->get('field_publish_at')->isEmpty()) {
      $value = $node->get('field_publish_at')->first()->getValue()['value'];
      $schedule = (new DrupalDateTime($value, 'UTC'))->format('D j M Y, H:i') . ' UTC';
    }
    $form['schedule'] = [
      '#type' => 'item',
      '#title' => $this->t('Publish at'),
      '#markup' => $schedule,
    ];

    // Delivery log.
    $log = $this->publisher->deliveryLog((int) $node->id());
    if ($log) {
      $log_rows = [];
      foreach ($log as $entry) {
        $when = $entry->changed ? \Drupal::service('date.formatter')->format((int) $entry->changed, 'short') : '';
        $target = $entry->remote_url
          ? ['data' => ['#type' => 'link', '#title' => $this->t('view'), '#url' => \Drupal\Core\Url::fromUri($entry->remote_url)]]
          : '';
        $log_rows[] = [
          $this->tokenStore->label($entry->platform),
          $entry->status,
          $when,
          mb_substr((string) $entry->message, 0, 160),
          $target,
        ];
      }
      $form['log'] = [
        '#type' => 'table',
        '#caption' => $this->t('Delivery log'),
        '#header' => [$this->t('Network'), $this->t('Status'), $this->t('When'), $this->t('Detail'), ''],
        '#rows' => $log_rows,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['send'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send now to connected networks'),
      '#disabled' => $connected === [],
    ];
    if ($connected === []) {
      $form['actions']['hint'] = [
        '#type' => 'item',
        '#markup' => $this->t('Connect an account under <a href=":url">DruBuzz Publish settings</a> first.', [
          ':url' => \Drupal\Core\Url::fromRoute('drubuzz_publish.settings')->toString(),
        ]),
      ];
    }

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\node\NodeInterface $node */
    $node = $form['#node'];
    $connected = array_values(array_filter(
      $this->tokenStore->platforms(),
      fn(string $p): bool => $this->tokenStore->isConnected($p),
    ));
    $targets = _drubuzz_publish_targets($node, $connected);
    if (!$targets) {
      $this->messenger()->addWarning($this->t('Nothing to send: no connected network has body text or is selected for this post.'));
      return;
    }

    foreach ($targets as $platform) {
      $result = $this->publisher->publish($node, $platform);
      $args = ['@p' => $this->tokenStore->label($platform), '@m' => $result['message']];
      if ($result['status'] === 'sent') {
        $this->messenger()->addStatus($this->t('@p: sent. @m', $args));
      }
      else {
        $this->messenger()->addError($this->t('@p: failed. @m', $args));
      }
    }
  }

}
