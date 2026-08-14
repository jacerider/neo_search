<?php

declare(strict_types=1);

namespace Drupal\neo_search\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_search\Exception\FloodException;
use Drupal\neo_search\Exception\VariationNotFoundException;
use Drupal\neo_search\SearchRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns quick search results as JSON.
 */
final class SearchController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly SearchRunner $runner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('neo_search.runner')
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, string $variation): JsonResponse {
    $query = (string) $request->query->get('q', '');

    try {
      $payload = $this->runner->run($variation, $query);
    }
    catch (VariationNotFoundException) {
      $response = new CacheableJsonResponse(['message' => 'Unknown search variation.'], 404);
      $response->addCacheableDependency((new CacheableMetadata())
        ->addCacheTags(['config:neo_search.settings'])
        ->addCacheContexts(['url.path']));
      return $response;
    }
    catch (FloodException $e) {
      $response = new JsonResponse(['message' => 'Too many requests.'], 429);
      $response->headers->set('Retry-After', (string) $e->retryAfter);
      return $response;
    }

    $response = new CacheableJsonResponse($payload->data, $payload->statusCode);
    $cacheability = CacheableMetadata::createFromObject($payload->cacheability)
      ->addCacheContexts(['url.query_args:q', 'url.path']);
    $response->addCacheableDependency($cacheability);

    // Let browsers (and, for anonymous users, proxies/CDN) absorb repeats for
    // a short TTL. Server-side invalidation stays tag-driven regardless.
    $maxAge = (int) ($this->runner->getHttpMaxAge($variation) ?? 0);
    if ($maxAge > 0) {
      $response->setMaxAge($maxAge);
      if ($this->currentUser()->isAnonymous()) {
        $response->setPublic();
        $response->setSharedMaxAge($maxAge);
      }
      else {
        $response->setPrivate();
      }
    }

    return $response;
  }

}
