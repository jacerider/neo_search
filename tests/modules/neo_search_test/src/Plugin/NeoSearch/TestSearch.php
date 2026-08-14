<?php

declare(strict_types=1);

namespace Drupal\neo_search_test\Plugin\NeoSearch;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_search\Attribute\NeoSearch;
use Drupal\neo_search\NeoSearchPluginBase;
use Drupal\neo_search\SearchRequest;
use Drupal\neo_search\SearchResultItem;
use Drupal\neo_search\SearchResultSet;

/**
 * Returns fixed results for testing.
 */
#[NeoSearch(
  id: 'test',
  label: new TranslatableMarkup('Test'),
)]
class TestSearch extends NeoSearchPluginBase {

  /**
   * {@inheritdoc}
   */
  public function search(SearchRequest $request): SearchResultSet {
    $state = \Drupal::state();
    $state->set('neo_search_test.calls', (int) $state->get('neo_search_test.calls', 0) + 1);

    $results = new SearchResultSet();
    $fixtures = [
      ['id' => 'node:1', 'label' => 'Alpha page', 'bundle' => 'page'],
      ['id' => 'node:2', 'label' => 'Beta insight', 'bundle' => 'insight'],
      ['id' => 'node:3', 'label' => 'Gamma page', 'bundle' => 'page'],
      ['id' => 'node:4', 'label' => 'Delta project', 'bundle' => 'project'],
    ];
    foreach ($fixtures as $weight => $fixture) {
      if (!str_contains(strtolower($fixture['label']), strtolower($request->query))) {
        continue;
      }
      $results->addItem(new SearchResultItem(
        id: $fixture['id'],
        label: $fixture['label'],
        url: '/' . str_replace(':', '/', $fixture['id']),
        entityTypeId: 'node',
        entityId: explode(':', $fixture['id'])[1],
        bundle: $fixture['bundle'],
        weight: $weight,
      ));
    }
    $results->addCacheTags(['neo_search_test:results']);
    return $results;
  }

}
