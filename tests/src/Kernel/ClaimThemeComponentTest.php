<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_search\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_search\SearchRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the module hands the quick-search panel to the theme.
 *
 * This writes real files, so it targets a disposable fixture theme shipped
 * alongside the test (tests/themes/ns_search_target) and removes what it
 * wrote in tearDown. Pointing it at a real theme would leave a search_quick
 * behind in someone's checkout.
 *
 * The three literals the module hands the claim -- the shipped default, the
 * config name and the config key -- are all pinned by the one assertion that
 * the setting ends at the theme's copy: get any of them wrong and nothing is
 * repointed.
 */
#[Group('neo_search')]
#[RunTestsInSeparateProcesses]
class ClaimThemeComponentTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'views',
    'search_api',
    'neo',
    'neo_settings',
    'neo_color',
    'neo_alchemist',
    'neo_search',
  ];

  /**
   * The theme the panel component is claimed for.
   */
  const THEME = 'ns_search_target';

  /**
   * The claim function every call site reaches.
   */
  const CLAIM = 'neo_search_claim_theme_component';

  /**
   * The absolute path the test writes to.
   */
  protected string $target;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The theme goes in before the module's config does: installing a theme
    // fires the very hook under test, and a claim against a settings object
    // that does not exist yet writes nothing.
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->installConfig(['neo_search']);
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->target = $this->container->getParameter('app.root') . '/'
      . $this->container->get('extension.list.theme')->getPath(self::THEME)
      . '/components';
    $this->removeTarget();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTarget();
    parent::tearDown();
  }

  /**
   * Deletes anything the test wrote into the fixture theme.
   */
  protected function removeTarget(): void {
    if (isset($this->target) && is_dir($this->target)) {
      $this->container->get('file_system')->deleteRecursive($this->target);
    }
  }

  /**
   * Gets the component the quick-search panel is built from.
   */
  protected function panelComponent(): ?string {
    return $this->config('neo_search.settings')->get('component');
  }

  /**
   * Reads the source of a function the module declares.
   */
  protected function functionSource(string $name): string {
    $this->assertTrue(function_exists($name), "{$name}() is declared.");
    $function = new \ReflectionFunction($name);
    $lines = (array) file((string) $function->getFileName());
    return implode('', array_slice(
      $lines,
      $function->getStartLine() - 1,
      $function->getEndLine() - $function->getStartLine() + 1
    ));
  }

  /**
   * Reads a file from the module directory.
   */
  protected function moduleFile(string $name): string {
    $path = $this->container->getParameter('app.root') . '/'
      . $this->container->get('extension.list.module')->getPath('neo_search')
      . '/' . $name;
    return (string) file_get_contents($path);
  }

  /**
   * Tests that a theme install hands the panel component to the theme.
   */
  public function testClaimsThePanelComponentForTheTheme(): void {
    $this->assertSame(SearchRunner::DEFAULT_COMPONENT, $this->panelComponent());

    neo_search_themes_installed([self::THEME]);

    $this->assertSame(
      self::THEME . ':search_quick',
      $this->panelComponent(),
      'The setting names the theme copy, not the module copy.'
    );
    $this->assertFileExists(
      $this->target . '/search_quick/search_quick.component.yml',
      'The claim swept the component into the theme on its way.'
    );
  }

  /**
   * Tests that a component a site builder already chose is left alone.
   *
   * All three call sites can fire on one request and they fire again on the
   * next install, so anything other than the shipped default is a decision
   * someone made and a claim never reverts one.
   */
  public function testExistingChoiceIsKept(): void {
    $this->config('neo_search.settings')
      ->set('component', self::THEME . ':something_else')
      ->save();

    neo_search_claim_theme_component();

    $this->assertSame(
      self::THEME . ':something_else',
      $this->panelComponent()
    );
  }

  /**
   * Tests that the claim function reaches the container once.
   *
   * The six steps and the two container lookups they needed -- the config
   * factory and the SDC plugin manager -- now live in neo_alchemist. What is
   * left is the installer lookup and the call.
   */
  public function testTheClaimReachesTheContainerOnce(): void {
    $source = $this->functionSource(self::CLAIM);

    $this->assertSame(
      1,
      substr_count($source, '\Drupal::'),
      'The claim function reaches the container only for the installer.'
    );
  }

  /**
   * Tests that all three call sites still resolve the function.
   *
   * One of them is the body of an update hook that has not run on every site,
   * so the function surviving is what keeps that update working.
   */
  public function testAllThreeCallSitesResolveTheFunction(): void {
    $this->container->get('module_handler')
      ->loadInclude('neo_search', 'install');

    $callers = [
      'neo_search_install',
      'neo_search_themes_installed',
      'neo_search_update_10002',
    ];
    foreach ($callers as $caller) {
      $this->assertStringContainsString(
        self::CLAIM . '()',
        $this->functionSource($caller),
        "{$caller}() calls the claim function."
      );
    }
  }

  /**
   * Tests that the module requires an neo_alchemist that carries the claim.
   *
   * Without the constraint a site can install this module against a release
   * with no claimComponent(), which fatals on install.
   */
  public function testRequiresTheAlchemistCarryingTheClaim(): void {
    $composer = Json::decode($this->moduleFile('composer.json'));

    $this->assertSame(
      '^1.2',
      $composer['require']['jacerider/neo_alchemist']
    );
  }

}
