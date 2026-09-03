<?php

declare(strict_types=1);

namespace Drupal\drubuzz_ai;

use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai\Service\PromptJsonDecoder\PromptJsonDecoder;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns a short post idea into the X/Mastodon and LinkedIn/FB/IG copy.
 *
 * Only the two description fields are AI-written; the title, body, media and
 * scheduling stay with the editor.
 */
final class PostContentGenerator {

  public function __construct(
    private readonly AiProviderPluginManager $aiProvider,
    private readonly PromptJsonDecoder $jsonDecoder,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * @return array{x:string,linkedin:string}
   *
   * @throws \RuntimeException
   *   When no provider is configured or the model reply cannot be used.
   */
  public function generate(string $idea, ?NodeInterface $node = NULL): array {
    $idea = trim($idea);
    if ($idea === '') {
      throw new \RuntimeException('Enter a post idea first.');
    }

    $defaults = $this->aiProvider->getDefaultProviderForOperationType('chat_with_complex_json')
      ?: $this->aiProvider->getDefaultProviderForOperationType('chat');
    if (empty($defaults['provider_id'])) {
      throw new \RuntimeException('No AI chat provider is set. Configure one under AI settings first.');
    }

    $config = $this->configFactory->get('drubuzz_ai.settings');
    $model = trim((string) $config->get('model_override')) ?: (string) $defaults['model_id'];

    $system = (string) $config->get('system_prompt');
    $examples = trim((string) $config->get('examples'));
    if ($examples !== '') {
      $system .= "\n\n## Example posts — match this voice and format\n\n" . $examples;
    }

    $user = "Post idea:\n" . $idea;
    if ($node) {
      $context = [];
      if ($node->hasField('title') && $node->label()) {
        $context[] = 'Working title: ' . $node->label();
      }
      if ($node->hasField('field_body') && !$node->get('field_body')->isEmpty()) {
        $notes = trim(strip_tags((string) $node->get('field_body')->first()->getValue()['value']));
        if ($notes !== '') {
          $context[] = "Editor notes:\n" . mb_substr($notes, 0, 1500);
        }
      }
      if ($context) {
        $user .= "\n\n" . implode("\n\n", $context);
      }
    }

    try {
      $provider = $this->aiProvider->createInstance($defaults['provider_id']);
      $output = $provider->chat(
        new ChatInput([
          new ChatMessage('system', $system),
          new ChatMessage('user', $user),
        ]),
        $model,
        ['drubuzz_ai'],
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('AI chat call failed: @m', ['@m' => $e->getMessage()]);
      throw new \RuntimeException('The AI request failed: ' . $e->getMessage());
    }

    $normalized = $output->getNormalized();
    $decoded = $this->jsonDecoder->decode($normalized);
    if (!is_array($decoded)) {
      // Last-ditch: pull a JSON object out of the raw text.
      $decoded = $this->looseJson($normalized instanceof ChatMessage ? $normalized->getText() : (string) $normalized);
    }

    $clean = static fn(string $s): string => trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $x = $clean((string) ($decoded['x'] ?? $decoded['X'] ?? ''));
    $linkedin = $clean((string) ($decoded['linkedin'] ?? $decoded['LinkedIn'] ?? $decoded['linkedIn'] ?? ''));
    if ($x === '' && $linkedin === '') {
      $this->logger->warning('AI reply had no usable x/linkedin keys: @r', [
        '@r' => mb_substr($normalized instanceof ChatMessage ? $normalized->getText() : '', 0, 500),
      ]);
      throw new \RuntimeException('The AI reply could not be read. Try rephrasing the idea.');
    }

    return ['x' => $x, 'linkedin' => $linkedin];
  }

  /**
   * @return array<string, mixed>
   */
  private function looseJson(string $text): array {
    if (preg_match('/\{.*\}/s', $text, $m)) {
      $data = json_decode($m[0], TRUE);
      if (is_array($data)) {
        return $data;
      }
    }
    return [];
  }

}
