<?php

declare(strict_types=1);

namespace Drupal\drubuzz_ai\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * System prompt + example posts used for AI generation.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct($config_factory, $typedConfigManager, private readonly AiProviderPluginManager $aiProvider) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('ai.provider'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['drubuzz_ai.settings'];
  }

  public function getFormId(): string {
    return 'drubuzz_ai_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('drubuzz_ai.settings');

    $default = $this->aiProvider->getDefaultProviderForOperationType('chat_with_complex_json')
      ?: $this->aiProvider->getDefaultProviderForOperationType('chat');
    $form['provider'] = [
      '#type' => 'item',
      '#title' => $this->t('AI provider'),
      '#markup' => $default
        ? $this->t('Using <strong>@p</strong>, model <strong>@m</strong> (from AI settings).', [
          '@p' => $default['provider_id'],
          '@m' => $default['model_id'],
        ])
        : $this->t('No default chat provider is configured. Set one under AI settings — generation will fail until then.'),
    ];

    $form['system_prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('System prompt'),
      '#description' => $this->t('Instructions sent before every request. It must ask for a JSON object with <code>x</code> and <code>linkedin</code> keys.'),
      '#default_value' => (string) $config->get('system_prompt'),
      '#rows' => 16,
    ];

    $form['examples'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Example posts'),
      '#description' => $this->t('Paste 2-6 real posts (with their platform copy) that show the voice and format to follow. Appended to the system prompt as few-shot context.'),
      '#default_value' => (string) $config->get('examples'),
      '#rows' => 18,
    ];

    $form['model_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model override'),
      '#description' => $this->t('Optional. A model id to use instead of the AI-settings default, e.g. <code>gpt-4o</code>.'),
      '#default_value' => (string) $config->get('model_override'),
      '#size' => 40,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('drubuzz_ai.settings')
      ->set('system_prompt', (string) $form_state->getValue('system_prompt'))
      ->set('examples', (string) $form_state->getValue('examples'))
      ->set('model_override', trim((string) $form_state->getValue('model_override')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
