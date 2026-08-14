<?php

declare(strict_types=1);

namespace Drupal\neo_search\Exception;

/**
 * Thrown when the flood threshold for quick search queries is exceeded.
 */
class FloodException extends \RuntimeException {

  /**
   * Constructs the exception.
   *
   * @param int $retryAfter
   *   Seconds until the client may retry.
   */
  public function __construct(
    public readonly int $retryAfter,
  ) {
    parent::__construct('Too many search requests.');
  }

}
