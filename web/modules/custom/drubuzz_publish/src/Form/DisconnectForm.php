<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drubuzz_publish\TokenStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirms removal of a stored LinkedIn / X token set.
 */
final class DisconnectForm extends ConfirmFormBase {

  private string $platform = '';

  public function __construct(private readonly TokenStore $tokenStore) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('drubuzz_publish.token_store'));
  }

  public function getFormId(): string {
    return 'drubuzz_publish_disconnect';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $platform = NULL): array {
    $this->platform = $platform === 'x' ? 'x' : 'linkedin';
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return (string) $this->t('Disconnect the @label account?', ['@label' => $this->tokenStore->label($this->platform)]);
  }

  public function getDescription(): string {
    return (string) $this->t('The stored access and refresh tokens are deleted. Scheduled posts for @label will stop sending until you reconnect.', [
      '@label' => $this->tokenStore->label($this->platform),
    ]);
  }

  public function getConfirmText(): string {
    return (string) $this->t('Disconnect');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('drubuzz_publish.settings');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->tokenStore->disconnect($this->platform);
    $this->messenger()->addStatus($this->t('@label account disconnected.', [
      '@label' => $this->tokenStore->label($this->platform),
    ]));
    $form_state->setRedirect('drubuzz_publish.settings');
  }

}
