<?php

declare(strict_types=1);

namespace Drupal\neo_search\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The neo_search attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class NeoSearch extends AttributeBase {

  /**
   * Constructs a new NeoSearch instance.
   *
   * @param string $id
   *   The plugin id.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   The human-readable name of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) The description of the plugin.
   * @param string|null $provider
   *   (optional) The provider.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public ?string $provider = NULL,
    public readonly ?string $deriver = NULL,
  ) {}

}
