<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_search\Exception\FloodException;
use Drupal\neo_search\Exception\VariationNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the quick search runner pipeline.
 */
#[Group('neo_search')]
#[RunTestsInSeparateProcesses]
class SearchRunnerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    // The search_quick component declares a `scheme` prop, an Alchemist shape
    // backed by a list_string field item. neo_search depends on neo_alchemist
    // anyway, so a lighter list here would be modelling an install that cannot
    // exist.
    'options',
    'link',
    'views',
    'search_api',
    'neo',
    'neo_settings',
    'neo_color',
    'neo_alchemist',
    'neo_search',
    'neo_search_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['neo_search']);
    $this->config('neo_search.settings')
      ->set('plugin', 'test')
      ->set('min_chars', 3)
      ->set('flood_enabled', FALSE)
      ->save();
  }

  /**
   * Gets the runner, fresh, so config changes made in the test apply.
   */
  protected function runner() {
    return $this->container->get('neo_search.runner');
  }

  /**
   * Tests the envelope shape and default (ungrouped) grouping.
   */
  public function testEnvelopeShape(): void {
    $payload = $this->runner()->run('neo_search', 'page');
    $data = $payload->data;
    $this->assertSame('page', $data['query']);
    $this->assertSame('neo_search', $data['variation']);
    $this->assertSame(2, $data['total']);
    $this->assertFalse($data['empty']);
    $this->assertNull($data['emptyMessage']);
    // Per-item data is not on the wire — only the rendered panel.
    $this->assertArrayNotHasKey('groups', $data);
    $html = $data['html'];
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringContainsString('Gamma page', $html);
    $this->assertStringContainsString('href="/node/1"', $html);
    // Ordering is the traversal order the keyboard navigation relies on.
    $this->assertLessThan(strpos($html, 'Gamma page'), strpos($html, 'Alpha page'));
    $this->assertContains('neo_search_test:results', $payload->cacheability->getCacheTags());
    $this->assertContains('neo_search', $payload->cacheability->getCacheTags());
    $this->assertContains('config:neo_search.settings', $payload->cacheability->getCacheTags());
  }

  /**
   * Tests the option contract the client-side keyboard navigation relies on.
   */
  public function testRenderedOptionsContract(): void {
    $html = $this->runner()->run('neo_search', 'page')->data['html'];
    // One role="option" per result, each carrying an href.
    $this->assertSame(2, substr_count($html, 'role="option"'));
    preg_match_all('/<a[^>]*role="option"[^>]*>/', $html, $matches);
    $this->assertCount(2, $matches[0]);
    foreach ($matches[0] as $tag) {
      $this->assertStringContainsString('href="', $tag);
    }
  }

  /**
   * Tests that the cards display renders the token-substituted results label.
   */
  public function testCardsDisplayResultsLabel(): void {
    $this->config('neo_search.settings')
      ->set('display', 'cards')
      ->set('results_label', 'Showing results for "[query]"')
      ->save();

    $html = $this->runner()->run('neo_search', 'page')->data['html'];
    $this->assertStringContainsString('Showing results for &quot;page&quot;', $html);
    $this->assertStringContainsString('--neo-search-columns', $html);
  }

  /**
   * Tests that a missing component falls back to the shipped default.
   */
  public function testMissingComponentFallsBack(): void {
    $this->config('neo_search.settings')
      ->set('component', 'nope:not_a_component')
      ->save();

    $html = $this->runner()->run('neo_search', 'page')->data['html'];
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringContainsString('role="option"', $html);
  }

  /**
   * Tests that a missing component entity falls back to the raw component.
   *
   * The entity is config a site builder can delete at any time, so the panel
   * has to survive it pointing at nothing.
   */
  public function testMissingComponentEntityFallsBack(): void {
    // No colon: the setting names a saved component, and that one is gone.
    $this->config('neo_search.settings')
      ->set('component', 'gone')
      ->save();

    $html = $this->runner()->run('neo_search', 'page')->data['html'];
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringContainsString('role="option"', $html);
  }

  /**
   * Tests bundle grouping with the overrides table.
   */
  public function testGroupingWithOverrides(): void {
    $this->config('neo_search.settings')
      ->set('group_by', 'bundle')
      ->set('groups', [
        [
          'id' => 'content',
          'label' => 'Pages & content',
          'map' => ['page', 'insight'],
          'weight' => 0,
          'hidden' => FALSE,
        ],
        [
          'id' => 'hide_me',
          'label' => 'Hidden things',
          'map' => ['project'],
          'weight' => 1,
          'hidden' => TRUE,
        ],
      ])
      ->save();

    // "a p" matches Alpha page and Gamma page (mapped into content) and
    // Delta project (mapped into a hidden group → dropped).
    $payload = $this->runner()->run('neo_search', 'a p');
    $data = $payload->data;
    $this->assertSame(2, $data['total']);
    $html = $data['html'];
    // The group heading is autoescaped by twig.
    $this->assertStringContainsString('Pages &amp; content', $html);
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringContainsString('Gamma page', $html);
    // Mapped into a hidden group — never reaches the markup.
    $this->assertStringNotContainsString('Delta project', $html);
  }

  /**
   * Tests that unmapped results honor the groups_other setting.
   */
  public function testUnmappedGroupBehavior(): void {
    $this->config('neo_search.settings')
      ->set('group_by', 'bundle')
      ->set('groups', [
        [
          'id' => 'content',
          'label' => 'Content',
          'map' => ['page'],
          'weight' => 0,
          'hidden' => FALSE,
        ],
      ])
      ->set('groups_other', 'hidden')
      ->save();

    $payload = $this->runner()->run('neo_search', 'eta');
    // "eta" matches Beta insight only; insight is unmapped and hidden.
    $this->assertSame(0, $payload->data['total']);
    $this->assertTrue($payload->data['empty']);
    $this->assertSame('No results found.', $payload->data['emptyMessage']);
    $this->assertStringContainsString('No results found.', $payload->data['html']);
    $this->assertStringNotContainsString('role="option"', $payload->data['html']);
  }

  /**
   * Tests the server-side minimum length gate.
   */
  public function testMinLengthGate(): void {
    $payload = $this->runner()->run('neo_search', 'pa');
    $this->assertTrue($payload->data['empty']);
    $this->assertSame(0, $payload->data['total']);
    // The plugin must never have run.
    $this->assertSame(0, (int) $this->container->get('state')->get('neo_search_test.calls', 0));
  }

  /**
   * Tests event injection of items and groups.
   */
  public function testResultsEventInjection(): void {
    $payload = $this->runner()->run('neo_search', 'inject-me');
    $this->assertStringContainsString('Injected result', $payload->data['html']);
    $this->assertContains('neo_search_test:injected', $payload->cacheability->getCacheTags());
  }

  /**
   * Tests that a second identical query is served from cache.
   */
  public function testCacheHitSkipsPlugin(): void {
    $state = $this->container->get('state');
    $this->runner()->run('neo_search', 'page');
    $this->assertSame(1, (int) $state->get('neo_search_test.calls'));
    $this->runner()->run('neo_search', 'page');
    $state->resetCache();
    $this->assertSame(1, (int) $state->get('neo_search_test.calls'), 'Second identical query did not reach the plugin.');
    // Case-insensitive cache key: different case, same entry.
    $this->runner()->run('neo_search', 'PAGE');
    $state->resetCache();
    $this->assertSame(1, (int) $state->get('neo_search_test.calls'), 'Case variant was served from cache.');
  }

  /**
   * Tests that content-driven invalidation clears cached results.
   */
  public function testCacheTagInvalidation(): void {
    $state = $this->container->get('state');
    $this->runner()->run('neo_search', 'page');
    $this->assertSame(1, (int) $state->get('neo_search_test.calls'));
    $this->container->get('cache_tags.invalidator')->invalidateTags(['neo_search_test:results']);
    $this->runner()->run('neo_search', 'page');
    $state->resetCache();
    $this->assertSame(2, (int) $state->get('neo_search_test.calls'), 'Invalidation forced a fresh search.');
  }

  /**
   * Tests flood control on the uncached path.
   */
  public function testFlood(): void {
    $this->config('neo_search.settings')
      ->set('flood_enabled', TRUE)
      ->set('flood_threshold', 2)
      ->set('flood_window', 60)
      ->save();

    $runner = $this->runner();
    $runner->run('neo_search', 'alpha');
    $runner->run('neo_search', 'beta');
    // Cached repeats do not count against the threshold.
    $runner->run('neo_search', 'alpha');
    $this->expectException(FloodException::class);
    $runner->run('neo_search', 'gamma');
  }

  /**
   * Tests the unknown variation path.
   */
  public function testUnknownVariation(): void {
    $this->expectException(VariationNotFoundException::class);
    $this->runner()->run('does_not_exist', 'page');
  }

}
