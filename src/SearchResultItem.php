<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Render\Markup;

/**
 * A single quick search result.
 */
final class SearchResultItem {

  /**
   * Constructs a search result item.
   *
   * @param string $id
   *   A unique id within the result set (e.g. "node:12").
   * @param string $label
   *   The plain-text label.
   * @param string $url
   *   The URL the result links to.
   * @param string|null $entityTypeId
   *   (optional) The entity type id when the result is an entity.
   * @param string|null $entityId
   *   (optional) The entity id when the result is an entity.
   * @param string|null $bundle
   *   (optional) The entity bundle when the result is an entity.
   * @param string|null $groupId
   *   (optional) An explicit group id that overrides derived grouping.
   * @param string|null $excerpt
   *   (optional) A sanitized excerpt. May contain <mark> highlighting only.
   * @param array $extra
   *   (optional) Arbitrary extra data exposed to the client.
   * @param float $weight
   *   (optional) Sort weight within the result set.
   */
  public function __construct(
    public string $id,
    public string $label,
    public string $url,
    public ?string $entityTypeId = NULL,
    public ?string $entityId = NULL,
    public ?string $bundle = NULL,
    public ?string $groupId = NULL,
    public ?string $excerpt = NULL,
    public array $extra = [],
    public float $weight = 0,
  ) {}

  /**
   * The per-item rendered HTML when view mode rendering is enabled.
   *
   * @var string|null
   */
  public ?string $rendered = NULL;

  /**
   * A human-readable type label (e.g. the bundle label).
   *
   * @var string|null
   */
  public ?string $typeLabel = NULL;

  /**
   * Converts the item to props for the search_quick component.
   *
   * Keys are snake_case because they are read from twig. $excerpt and $rendered
   * are already safe — Xss::filter down to mark/strong/em, and the render
   * pipeline respectively — so they are wrapped as Markup and print without
   * |raw. $label is deliberately left a plain string so twig escapes it.
   *
   * @return array
   *   The prop representation.
   */
  public function toProps(): array {
    return [
      'id' => $this->id,
      'label' => $this->label,
      'url' => $this->url,
      'entity_type' => $this->entityTypeId,
      'bundle' => $this->bundle,
      'type_label' => $this->typeLabel,
      'excerpt' => $this->excerpt === NULL ? NULL : Markup::create($this->excerpt),
      'rendered' => $this->rendered === NULL ? NULL : Markup::create($this->rendered),
      'extra' => $this->extra,
    ];
  }

}
