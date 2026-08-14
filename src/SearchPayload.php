<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * A JSON envelope plus the cacheability it was built with.
 */
final class SearchPayload {

  /**
   * Constructs the payload.
   *
   * @param array $data
   *   The JSON-ready envelope.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The cacheability of the envelope.
   * @param int $statusCode
   *   The HTTP status code to respond with.
   */
  public function __construct(
    public readonly array $data,
    public readonly CacheableMetadata $cacheability,
    public readonly int $statusCode = 200,
  ) {}

}
