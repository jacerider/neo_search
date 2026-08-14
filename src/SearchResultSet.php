<?php

declare(strict_types=1);

namespace Drupal\neo_search;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;

/**
 * A mutable, cacheability-carrying set of quick search results.
 */
final class SearchResultSet implements RefinableCacheableDependencyInterface, \Countable {

  use RefinableCacheableDependencyTrait;

  /**
   * The result items.
   *
   * @var \Drupal\neo_search\SearchResultItem[]
   */
  protected array $items = [];

  /**
   * The grouped results.
   *
   * Each group is ['id' => string, 'label' => string, 'weight' => int,
   * 'items' => SearchResultItem[]].
   *
   * @var array
   */
  protected array $groups = [];

  /**
   * Adds an item.
   */
  public function addItem(SearchResultItem $item): static {
    $this->items[$item->id] = $item;
    return $this;
  }

  /**
   * Removes an item by id.
   */
  public function removeItem(string $id): static {
    unset($this->items[$id]);
    foreach ($this->groups as &$group) {
      $group['items'] = array_filter($group['items'], fn (SearchResultItem $item) => $item->id !== $id);
    }
    return $this;
  }

  /**
   * Gets the items.
   *
   * @return \Drupal\neo_search\SearchResultItem[]
   *   The items, keyed by item id.
   */
  public function getItems(): array {
    return $this->items;
  }

  /**
   * Replaces all items.
   *
   * @param \Drupal\neo_search\SearchResultItem[] $items
   *   The items.
   */
  public function setItems(array $items): static {
    $this->items = [];
    foreach ($items as $item) {
      $this->addItem($item);
    }
    return $this;
  }

  /**
   * Gets the groups.
   */
  public function getGroups(): array {
    return $this->groups;
  }

  /**
   * Sets the groups.
   *
   * @param array $groups
   *   Arrays of ['id', 'label', 'weight', 'items' => SearchResultItem[]].
   */
  public function setGroups(array $groups): static {
    $this->groups = $groups;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    return count($this->items);
  }

}
