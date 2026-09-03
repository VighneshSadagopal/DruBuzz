<?php

declare(strict_types=1);

namespace Drupal\drubuzz_ai\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\drubuzz_ai\PostContentGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * AJAX endpoint behind the "Generate with AI" button on the Posts form.
 */
final class GenerateController extends ControllerBase {

  public function __construct(
    private readonly PostContentGenerator $generator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('drubuzz_ai.generator'));
  }

  public function generate(Request $request): JsonResponse {
    $payload = Json::decode($request->getContent()) ?: [];
    $idea = (string) ($payload['idea'] ?? '');
    $nid = isset($payload['nid']) ? (int) $payload['nid'] : 0;

    $node = NULL;
    if ($nid > 0) {
      $candidate = $this->entityTypeManager()->getStorage('node')->load($nid);
      if ($candidate && $candidate->bundle() === 'posts' && $candidate->access('update')) {
        $node = $candidate;
      }
    }

    try {
      $result = $this->generator->generate($idea, $node);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 422);
    }

    return new JsonResponse([
      'x' => $result['x'],
      'linkedin' => $result['linkedin'],
    ]);
  }

}
