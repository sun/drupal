<?php

/**
 * @file
 * Contains \Drupal\Core\ImageToolkit\ImageToolkitManager.
 */

namespace Drupal\Core\ImageToolkit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Component\Plugin\Factory\DefaultFactory;

/**
 * Manages toolkit plugins.
 */
class ImageToolkitManager extends DefaultPluginManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The image toolkit operation manager.
   *
   * @var \Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface
   */
  protected $operationManager;

  /**
   * Constructs the ImageToolkitManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_backend
   *   Cache backend instance to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface $operation_manager
   *   The toolkit operation manager.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, ImageToolkitOperationManagerInterface $operation_manager) {
    parent::__construct('Plugin/ImageToolkit', $namespaces, $module_handler, 'Drupal\Core\ImageToolkit\Annotation\ImageToolkit');

    $this->setCacheBackend($cache_backend, 'image_toolkit_plugins');
    $this->configFactory = $config_factory;
    $this->operationManager = $operation_manager;
  }

  /**
   * Gets the default image toolkit ID.
   *
   * @return string|bool
   *   ID of the default toolkit, or FALSE on error.
   */
  public function getDefaultToolkitId() {
    $toolkit_id = $this->configFactory->get('system.image')->get('toolkit');
    $toolkits = $this->getAvailableToolkits();

    if (!isset($toolkits[$toolkit_id]) || !class_exists($toolkits[$toolkit_id]['class'])) {
      // The selected toolkit isn't available so return the first one found. If
      // none are available this will return FALSE.
      reset($toolkits);
      $toolkit_id = key($toolkits);
    }

    return $toolkit_id;
  }

  /**
   * Gets the default image toolkit.
   *
   * @return \Drupal\Core\ImageToolkit\ImageToolkitInterface
   *   Object of the default toolkit, or FALSE on error.
   */
  public function getDefaultToolkit() {
    if ($toolkit_id = $this->getDefaultToolkitId()) {
      return $this->createInstance($toolkit_id);
    }
    return FALSE;
  }

  /**
   * Gets a list of available toolkits.
   *
   * @return array
   *   An array with the toolkit names as keys and the descriptions as values.
   */
  public function getAvailableToolkits() {
    // Use plugin system to get list of available toolkits.
    $toolkits = $this->getDefinitions();

    $output = array();
    foreach ($toolkits as $id => $definition) {
      // Only allow modules that aren't marked as unavailable.
      if (call_user_func($definition['class'] . '::isAvailable')) {
        $output[$id] = $definition;
      }
    }

    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = array()) {
    $plugin_definition = $this->getDefinition($plugin_id);
    $plugin_class = DefaultFactory::getPluginClass($plugin_id, $plugin_definition);
    return new $plugin_class($configuration, $plugin_id, $plugin_definition, $this->operationManager);
  }

}
