<?php

declare(strict_types=1);

namespace Drupal\neo_search_test\EventSubscriber;

use Drupal\neo_search\Event\NeoSearchResultsEvent;
use Drupal\neo_search\SearchResultItem;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injects results for the query "inject-me".
 */
class NeoSearchTestSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      NeoSearchResultsEvent::EVENT_NAME => 'onResults',
    ];
  }

  /**
   * Injects an item in a new group.
   */
  public function onResults(NeoSearchResultsEvent $event): void {
    if ($event->request->query !== 'inject-me') {
      return;
    }
    $item = new SearchResultItem(
      id: 'injected:1',
      label: 'Injected result',
      url: '/injected',
    );
    $event->results->addItem($item);
    $groups = $event->results->getGroups();
    $groups[] = [
      'id' => 'injected',
      'label' => 'Injected group',
      'weight' => 100,
      'items' => [$item],
    ];
    $event->results->setGroups($groups);
    $event->addCacheTags(['neo_search_test:injected']);
  }

}
