<?php

/**
 * @file
 * Contains \Drupal\Core\Display\Annotation\DisplayVariant.
 */

namespace Drupal\Core\Display\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a display variant annotation object.
 *
 * Display variants are used to dictate the output of a given Display, which
 * can be used to control the output of many parts of Drupal. For example, the
 * FullPageVariant is used by the Block module to control regions and output
 * block content placed in those regions.
 *
 * Variants are usually chosen by some selection criteria, and are instantiated
 * directly. Each variant must define its own approach to rendering, and can
 * either load its own data or be injected with data from another Display
 * object.
 *
 * @todo: Revise description when/if Displays are added to core:
 * https://www.drupal.org/node/2292733 
 *
 * @see \Drupal\Core\Display\VariantInterface
 * @see \Drupal\Core\Display\VariantManager
 *
 * @Annotation
 */
class DisplayVariant extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The administrative label.
   *
   * @var \Drupal\Core\Annotation\Translation
   *
   * @ingroup plugin_translatable
   */
  public $admin_label = '';

}
