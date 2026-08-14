<?php

declare(strict_types=1);

namespace Drupal\neo_search\Drush\Commands;

use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\UserSession;
use Drupal\neo_search\Exception\FloodException;
use Drupal\neo_search\Exception\VariationNotFoundException;
use Drupal\neo_search\SearchRunner;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Debug commands for neo_search.
 */
final class NeoSearchCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'neo_search.runner')]
    private readonly SearchRunner $runner,
    #[Autowire(service: 'account_switcher')]
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct();
  }

  /**
   * Run a quick search and print the JSON envelope plus cacheability.
   */
  #[CLI\Command(name: 'neo:search:query', aliases: ['neo-search'])]
  #[CLI\Argument(name: 'variation', description: 'The search variation id (e.g. neo_search or neo_search_header).')]
  #[CLI\Argument(name: 'text', description: 'The query text.')]
  #[CLI\Option(name: 'uid', description: 'Run the search as this user id (default: anonymous).')]
  #[CLI\Usage(name: 'drush neo:search:query neo_search "well"', description: 'Query the core search instance as anonymous.')]
  #[CLI\Usage(name: 'drush neo:search:query header "well" --uid=1', description: 'Query the "header" variation as user 1.')]
  public function query(string $variation, string $text, array $options = ['uid' => NULL]): int {
    $switched = FALSE;
    if ($options['uid'] !== NULL) {
      $account = \Drupal::entityTypeManager()->getStorage('user')->load($options['uid']);
      if (!$account) {
        $this->io()->error(sprintf('Unknown user id %s.', $options['uid']));
        return self::EXIT_FAILURE;
      }
      $this->accountSwitcher->switchTo($account);
      $switched = TRUE;
    }
    else {
      $this->accountSwitcher->switchTo(new UserSession(['uid' => 0]));
      $switched = TRUE;
    }

    try {
      $payload = $this->runner->run($variation, $text);
    }
    catch (VariationNotFoundException $e) {
      $this->io()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    catch (FloodException $e) {
      $this->io()->error(sprintf('Flood control tripped; retry in %d seconds.', $e->retryAfter));
      return self::EXIT_FAILURE;
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }

    $this->output()->writeln(json_encode($payload->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $this->io()->section('Cacheability');
    $this->io()->writeln('Tags: ' . implode(', ', $payload->cacheability->getCacheTags()));
    $this->io()->writeln('Contexts: ' . implode(', ', $payload->cacheability->getCacheContexts()));
    return self::EXIT_SUCCESS;
  }

}
