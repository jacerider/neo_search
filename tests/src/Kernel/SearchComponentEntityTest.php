<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests rendering the panel through a saved component entity.
 *
 * Split from SearchRunnerTest, which covers the query pipeline and the
 * raw-component render, so a failure here names the entity path rather than
 * arriving as one more red line in an unrelated suite.
 */
#[Group('neo_search')]
#[RunTestsInSeparateProcesses]
class SearchComponentEntityTest extends KernelTestBase {

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
    // The scheme shape resolves to a list_string field item.
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
    // The scheme prop only resolves to a class when real neo_scheme entities
    // exist; without them the entity renders indistinguishably from the raw
    // component and these tests would assert nothing.
    $this->installConfig(['neo_color', 'neo_search']);
    $this->config('neo_search.settings')
      ->set('plugin', 'test')
      ->set('min_chars', 3)
      ->set('flood_enabled', FALSE)
      ->save();
  }

  /**
   * Creates a component entity for the shipped search_quick SDC.
   */
  protected function createComponentEntity(string $id = 'search_quick', bool $status = TRUE): void {
    $this->container->get('entity_type.manager')->getStorage('neo_component')->create([
      'id' => $id,
      'label' => 'Search | Quick',
      // Typed string on the entity — a null here is a TypeError on save.
      'description' => 'The quick-search typeahead panel.',
      'group' => 'general',
      'component' => 'neo_search:search_quick',
      'status' => $status,
    ])->save();
  }

  /**
   * Runs a search and returns the rendered panel markup.
   */
  protected function html(string $query = 'page'): string {
    return $this->container->get('neo_search.runner')->run('neo_search', $query)->data['html'];
  }

  /**
   * Tests that the entity's configuration reaches the markup.
   *
   * The scheme prop is the observable half of the entity being in the render
   * path at all — it resolves from the entity, never from the runner.
   */
  public function testEntityConfigurationApplies(): void {
    $this->createComponentEntity();
    $this->config('neo_search.settings')->set('component', 'search_quick')->save();

    $html = $this->html();
    $this->assertStringContainsString('scheme-', $html, 'The entity contributed its scheme class.');
    $this->assertStringContainsString('data-component-id="neo_search:search_quick"', $html);
  }

  /**
   * Tests that request data still wins over anything the entity resolved.
   *
   * This is the whole risk of routing through an entity: its value providers
   * (and the schema examples behind them) resolve props the runner also
   * supplies, and the search results must not lose that collision.
   */
  public function testRuntimeResultsOverrideTheEntity(): void {
    $this->createComponentEntity();
    $this->config('neo_search.settings')->set('component', 'search_quick')->save();

    $html = $this->html();
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringContainsString('Gamma page', $html);
    $this->assertSame(2, substr_count($html, 'role="option"'));
    // The component.yml examples must not leak through as results.
    $this->assertStringNotContainsString('Water Reclamation Facility Expansion', $html);
  }

  /**
   * Tests that a disabled entity falls back to the raw component.
   */
  public function testDisabledEntityFallsBack(): void {
    $this->createComponentEntity(status: FALSE);
    $this->config('neo_search.settings')->set('component', 'search_quick')->save();

    $html = $this->html();
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertStringNotContainsString('scheme-', $html, 'Nothing from the entity applied.');
  }

  /**
   * Tests that a bare component id renders the SDC directly.
   */
  public function testNoEntityRendersRawComponent(): void {
    $this->config('neo_search.settings')->set('component', 'neo_search:search_quick')->save();
    $html = $this->html();
    $this->assertStringContainsString('Alpha page', $html);
    $this->assertSame(2, substr_count($html, 'role="option"'));
  }

  /**
   * Tests that creating or editing an entity invalidates cached panels.
   *
   * The runner caches rendered HTML, so without the list tag a site builder's
   * new entity would not show up until something else expired the entry.
   */
  public function testEntityListTagIsBubbled(): void {
    $this->createComponentEntity();
    $this->config('neo_search.settings')->set('component', 'search_quick')->save();

    $payload = $this->container->get('neo_search.runner')->run('neo_search', 'page');
    $this->assertContains('config:neo_component_list', $payload->cacheability->getCacheTags());
  }

}
