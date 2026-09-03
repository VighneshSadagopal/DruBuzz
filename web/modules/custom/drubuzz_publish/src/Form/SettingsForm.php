<?php

declare(strict_types=1);

namespace Drupal\drubuzz_publish\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\drubuzz_publish\TokenStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Credentials + account connection form for DruBuzz Publish.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    $config_factory,
    $typedConfigManager,
    private readonly TokenStore $tokenStore,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('drubuzz_publish.token_store'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['drubuzz_publish.settings'];
  }

  public function getFormId(): string {
    return 'drubuzz_publish_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drubuzz_publish.settings');
    $form['#attached']['library'][] = 'drubuzz_publish/oauth_popup';

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable scheduled publishing'),
      '#description' => $this->t('When on, cron sends due Posts (those with a past <em>Publish at</em> time) to the connected networks.'),
      '#default_value' => (bool) $config->get('enabled'),
    ];

    foreach ($this->tokenStore->platforms() as $platform) {
      $label = $this->tokenStore->label($platform);
      $form[$platform] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => TRUE,
        '#tree' => FALSE,
      ];

      $form[$platform]['redirect'] = [
        '#type' => 'item',
        '#title' => $this->t('Redirect / callback URL'),
        '#markup' => '<code>' . Url::fromRoute('drubuzz_publish.oauth_callback', ['platform' => $platform], ['absolute' => TRUE])->toString() . '</code>',
        '#description' => $this->t('Register this exact URL as an authorised redirect URL in your @label developer app.', ['@label' => $label]),
      ];

      $form[$platform][$platform . '_client_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('@label client ID', ['@label' => $label]),
        '#default_value' => (string) $config->get($platform . '_client_id'),
        '#maxlength' => 255,
      ];

      $has_secret = $this->tokenStore->clientSecret($platform) !== '';
      $form[$platform][$platform . '_client_secret'] = [
        '#type' => 'password',
        '#title' => $this->t('@label client secret', ['@label' => $label]),
        '#description' => $has_secret
          ? $this->t('A secret is stored. Leave blank to keep it, or type a new one to replace it.')
          : $this->t('Stored in State, not in the config export.'),
        '#attributes' => $has_secret ? ['placeholder' => '••••••••••••'] : [],
      ];

      // Connection status + action link.
      $tokens = $this->tokenStore->tokens($platform);
      if ($this->tokenStore->isConnected($platform)) {
        $who = $tokens['account_name'] ?? $tokens['account_id'] ?? $this->t('unknown');
        if (!empty($tokens['account_handle'])) {
          $who .= ' (@' . $tokens['account_handle'] . ')';
        }
        $form[$platform]['status'] = [
          '#type' => 'item',
          '#title' => $this->t('Status'),
          '#markup' => $this->t('Connected as <strong>@who</strong>.', ['@who' => $who]),
        ];
        $form[$platform]['disconnect'] = [
          '#type' => 'link',
          '#title' => $this->t('Disconnect @label', ['@label' => $label]),
          '#url' => Url::fromRoute('drubuzz_publish.oauth_disconnect', ['platform' => $platform]),
          '#attributes' => ['class' => ['button', 'button--danger']],
        ];
      }
      else {
        $form[$platform]['status'] = [
          '#type' => 'item',
          '#title' => $this->t('Status'),
          '#markup' => $this->t('Not connected.'),
        ];
        $form[$platform]['connect'] = [
          '#type' => 'link',
          '#title' => $this->t('Connect @label', ['@label' => $label]),
          '#url' => Url::fromRoute('drubuzz_publish.oauth_start', ['platform' => $platform], [
            'query' => ['popup' => 1],
          ]),
          '#attributes' => ['class' => ['button', 'button--primary', 'drubuzz-oauth-connect']],
          '#access' => $this->tokenStore->isConfigured($platform),
        ];
        if (!$this->tokenStore->isConfigured($platform)) {
          $form[$platform]['hint'] = [
            '#type' => 'item',
            '#markup' => $this->t('Save a client ID and secret first, then the Connect button appears.'),
          ];
        }
      }
    }

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced'),
      '#open' => FALSE,
    ];
    $form['advanced']['linkedin_api_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LinkedIn versioned API date'),
      '#description' => $this->t('The <code>LinkedIn-Version</code> header sent with post requests, format <code>YYYYMM</code>.'),
      '#default_value' => (string) ($config->get('linkedin_api_version') ?: '202405'),
      '#size' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('drubuzz_publish.settings');
    $config
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('linkedin_client_id', trim((string) $form_state->getValue('linkedin_client_id')))
      ->set('x_client_id', trim((string) $form_state->getValue('x_client_id')))
      ->set('linkedin_api_version', trim((string) $form_state->getValue('linkedin_api_version')) ?: '202405')
      ->save();

    foreach ($this->tokenStore->platforms() as $platform) {
      $secret = (string) $form_state->getValue($platform . '_client_secret');
      if ($secret !== '') {
        $this->tokenStore->setClientSecret($platform, $secret);
      }
    }

    parent::submitForm($form, $form_state);
  }

}
