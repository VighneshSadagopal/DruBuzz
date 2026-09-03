<?php

declare(strict_types=1);

namespace Drupal\drubuzz_social_preview\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Identity shown on every social post preview: display name, handle and avatar.
 *
 * The profile picture is a Media entity (image bundle) chosen through the media
 * library, matching how the post graphic field works. No service injection is
 * needed - the media library element caches the form, and everything the submit
 * handler touches is plain config plus the submitted media ID.
 */
final class SocialPreviewSettingsForm extends ConfigFormBase {

  private const CONFIG = 'drubuzz_social_preview.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'drubuzz_social_preview_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::CONFIG];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::CONFIG);

    $form['identity'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Preview identity'),
      '#description' => $this->t('Shown as the posting account in every platform preview. Leave a field blank to fall back to the node author.'),
    ];

    $form['identity']['display_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Display name'),
      '#default_value' => $config->get('display_name'),
      '#maxlength' => 100,
      '#placeholder' => $this->t('DruBuzz'),
      '#description' => $this->t('The bold name at the top of the post (LinkedIn, X, Facebook, Mastodon).'),
    ];

    $form['identity']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username / handle'),
      '#default_value' => $config->get('username'),
      '#maxlength' => 60,
      '#field_prefix' => '@',
      '#placeholder' => 'drubuzz',
      '#description' => $this->t('Used for @handles (X, Mastodon) and the Instagram username. Lower-case letters, numbers and underscores only.'),
    ];

    $form['identity']['headline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('LinkedIn headline'),
      '#default_value' => $config->get('headline'),
      '#maxlength' => 220,
      '#placeholder' => $this->t('Sharing updates from the DruBuzz team'),
      '#description' => $this->t('The grey line under the name on the LinkedIn preview. Leave blank to hide it.'),
    ];

    $form['identity']['mastodon_instance'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Mastodon instance'),
      '#default_value' => $config->get('mastodon_instance'),
      '#maxlength' => 100,
      '#field_prefix' => '@' . ($config->get('username') ?: 'handle') . '@',
      '#placeholder' => 'mastodon.social',
      '#description' => $this->t('Host shown in the full Mastodon handle.'),
    ];

    $avatar_id = $config->get('profile_avatar_media');
    $form['profile_avatar_media'] = [
      '#type' => 'media_library',
      '#title' => $this->t('Profile avatar'),
      '#allowed_bundles' => ['image'],
      '#cardinality' => 1,
      '#default_value' => $avatar_id ? (int) $avatar_id : NULL,
      '#description' => $this->t('The avatar shown beside the name on every preview. Pick or upload an image Media entity — this is separate from the image on the post itself. Square images work best. Falls back to the node author’s picture, then their initials, when empty.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $username = (string) $form_state->getValue('username');
    if ($username !== '' && !preg_match('/^[a-z0-9_]+$/', $username)) {
      $form_state->setErrorByName('username', $this->t('The username may only contain lower-case letters, numbers and underscores.'));
    }

    $instance = (string) $form_state->getValue('mastodon_instance');
    if ($instance !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $instance)) {
      $form_state->setErrorByName('mastodon_instance', $this->t('Enter a valid host, e.g. mastodon.social.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // The media_library element returns a comma-separated string of media IDs.
    $selection = (string) $form_state->getValue('profile_avatar_media');
    $avatar_id = $selection === '' ? NULL : (int) explode(',', $selection)[0];

    $this->config(self::CONFIG)
      ->set('display_name', trim((string) $form_state->getValue('display_name')))
      ->set('username', trim((string) $form_state->getValue('username')))
      ->set('headline', trim((string) $form_state->getValue('headline')))
      ->set('mastodon_instance', trim((string) $form_state->getValue('mastodon_instance')) ?: 'mastodon.social')
      ->set('profile_avatar_media', $avatar_id ?: NULL)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
