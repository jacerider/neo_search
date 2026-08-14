<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_search\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\search_api\Entity\Index;
use Drupal\views\Entity\View;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the turnkey search-setup building blocks.
 */
#[Group('neo_search')]
#[RunTestsInSeparateProcesses]
class SearchSetupTest extends KernelTestBase {

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
    'taxonomy',
    'link',
    'options',
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
   * The test index.
   */
  protected Index $index;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('search_api_task');
    $this->installConfig(['search_api']);

    $this->index = Index::create([
      'id' => 'testidx',
      'name' => 'Test index',
      'status' => TRUE,
      'datasource_settings' => [
        'entity:node' => [],
        'entity:taxonomy_term' => [],
      ],
      'tracker_settings' => ['default' => []],
      'field_settings' => [
        'title' => [
          'label' => 'Title',
          'datasource_id' => 'entity:node',
          'property_path' => 'title',
          'type' => 'text',
        ],
        'content_kind' => [
          'label' => 'Content type',
          'datasource_id' => 'entity:node',
          'property_path' => 'type',
          'type' => 'string',
        ],
        'vocab' => [
          'label' => 'Vocabulary',
          'datasource_id' => 'entity:taxonomy_term',
          'property_path' => 'vid',
          'type' => 'string',
        ],
      ],
    ]);
    $this->index->save();
  }

  /**
   * Gets the setup service.
   */
  protected function setupService() {
    return $this->container->get('neo_search.setup');
  }

  /**
   * Tests view generation from the template.
   */
  public function testBuildViewValues(): void {
    $values = $this->setupService()->buildViewValues($this->index, 'search');
    $this->assertSame('search', $values['id']);
    $this->assertSame('search_api_index_testidx', $values['base_table']);

    $options = $values['display']['default']['display_options'];
    $this->assertSame('full', $options['pager']['type']);
    $this->assertSame('search_api_tag', $options['cache']['type']);
    $this->assertSame('s', $options['filters']['search_api_fulltext']['expose']['identifier']);
    $this->assertSame('search_api_index_testidx', $options['filters']['search_api_fulltext']['table']);

    // Type badges are token-driven (bundle info) — no view fields injected.
    $this->assertSame(['search_api_excerpt'], array_keys($options['fields']));

    // The values are config-schema valid and saveable.
    View::create($values)->save();
    $view = View::load('search');
    $this->assertNotNull($view);
    $this->assertContains('search_api.index.testidx', $view->getDependencies()['config']);
  }

  /**
   * Tests the two-save binding creation.
   *
   * The SDC comes from the neo_search_test fixture copy — on a real site the
   * setup command copies install/components/list_search into the theme and
   * binds "<theme>:list_search" the same way.
   */
  public function testEnsureBinding(): void {
    $result = $this->setupService()->ensureBinding('neo_search_test:list_search', 'search', 's');
    $this->assertSame('created', $result['status']);

    /** @var \Drupal\neo_alchemist\ComponentInterface $component */
    $component = $this->container->get('entity_type.manager')->getStorage('neo_component')->load('list_search');
    $this->assertNotNull($component);
    $this->assertSame('neo_search_test:list_search', $component->get('component'));
    $this->assertNotEmpty($component->get('expression'));

    $settings = $component->get('settings');
    $views = $settings['props']['items']['plugins']['items']['views']['settings'];
    $this->assertSame('search', $views['view_id']);
    $this->assertSame('_entity:label', $views['shape_fields']['title']['field']);
    $this->assertSame('_entity:icon_page', $views['shape_fields']['icon']['field']);
    $this->assertSame('_entity:bundle_label_page', $views['shape_fields']['type']['field']);
    $this->assertSame('_view:search_api_excerpt', $views['shape_fields']['excerpt']['field']);
    $this->assertArrayHasKey('views_exposed_filter', $settings['props']['search_filter']['plugins']['search_filter']);
    $this->assertSame('s', $settings['props']['search_filter']['plugins']['search_filter']['views_exposed_filter']['settings']['filter']);
    $this->assertArrayHasKey('views_active_filters', $settings['props']['active_filters']['plugins']['active_filters']);
    $this->assertArrayHasKey('views_summary', $settings['props']['summary']['plugins']['summary']);
    $slotPlugins = array_values($settings['slots']['footer']['plugins'] ?? []);
    $this->assertSame('views_pager', $slotPlugins[0]['plugin'] ?? NULL);

    // Second call is a pure no-op.
    $again = $this->setupService()->ensureBinding('neo_search_test:list_search', 'search', 's');
    $this->assertSame('exists', $again['status']);
  }

}
