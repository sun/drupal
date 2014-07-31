<?php

/**
 * @file
 * Contains \Drupal\Tests\KernelTestBase.
 */

namespace Drupal\Tests;

use Drupal\Component\Utility\String;
use Drupal\Core\Config\ConfigImporter;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Entity\Schema\EntitySchemaProviderInterface;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Language\Language;
use Drupal\Core\Site\Settings;
use Drupal\simpletest\RandomGeneratorTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\Request;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\visitor\vfsStreamPrintVisitor;

/**
 * Base class for functional integration tests.
 *
 * Tests extending this base class can access files and the database, but the
 * entire environment is initially empty. Drupal runs in a minimal mocked
 * environment, comparable to the one in the early installer.
 *
 * Unlike \Drupal\Tests\UnitTestCase, modules specified in the $modules
 * property are automatically added to the service container for each test.
 * The module/hook system is functional and operates on a fixed module list.
 * Additional modules needed in a test may be loaded and added to the fixed
 * module list.
 *
 * Unlike \Drupal\simpletest\WebTestBase, the modules are only loaded, but not
 * installed. Modules need to be installed manually, if needed.
 *
 * @see \Drupal\Tests\KernelTestBase::$modules
 * @see \Drupal\Tests\KernelTestBase::enableModules()
 *
 * @todo Extend ::setRequirementsFromAnnotation() and ::checkRequirements() to
 *   account for '@requires module'.
 */
abstract class KernelTestBase extends \PHPUnit_Framework_TestCase implements ServiceProviderInterface, LoggerInterface {

  use AssertLegacyTrait;
  #use AssertContentTrait;
  use LoggerTrait;
  use RandomGeneratorTrait;

  /**
   * Implicitly TRUE by default, but MUST be TRUE for kernel tests.
   *
   * @var bool
   */
  protected $backupGlobals = TRUE;

  protected $backupStaticAttributes = TRUE;
  protected $backupStaticAttributesBlacklist = array(
    // Ignore static discovery/parser caches to speed up tests.
    'Drupal\Component\Discovery\YamlDiscovery' => array('parsedFiles'),
    'Drupal\Core\DependencyInjection\YamlFileLoader' => array('yaml'),
    'Drupal\Core\Extension\ExtensionDiscovery' => array('files'),
    'Drupal\Core\Extension\InfoParser' => array('parsedInfos'),
    // Drupal::$container cannot be serialized.
    'Drupal' => array('container'),
  );

  /**
   * If a test requests process isolation, do not backup state.
   *
   * @var bool
   */
  protected $preserveGlobalState = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function prepareTemplate(\Text_Template $template) {
    $bootstrap_globals = '';
    // Fix missing bootstrap.php when $preserveGlobalState is FALSE.
    // @see https://github.com/sebastianbergmann/phpunit/pull/797
    $bootstrap_globals .= '$__PHPUNIT_BOOTSTRAP = ' . var_export($GLOBALS['__PHPUNIT_BOOTSTRAP'], TRUE) . ";\n";
    // @see /core/tests/bootstrap.php
    $bootstrap_globals .= '$namespaces = ' . var_export($GLOBALS['namespaces'], TRUE) . ";\n";
    $template->setVar(array(
      'constants' => '',
      'included_files' => '',
      'globals' => $bootstrap_globals,
    ));
  }

  /**
   * Returns whether the current test runs in isolation.
   *
   * @return bool
   *
   * @see https://github.com/sebastianbergmann/phpunit/pull/1350
   */
  protected function isTestInIsolation() {
    return function_exists('__phpunit_run_isolated_test');
  }

  public function run(\PHPUnit_Framework_TestResult $result = null) {
    if (in_array(get_class($this), array(
      'Drupal\config\Tests\ConfigImportRecreateTest',
      'Drupal\file\Tests\UsageTest',
      'Drupal\file\Tests\ValidatorTest',
      'Drupal\system\Tests\Cache\ApcuBackendUnitTest',
      'Drupal\system\Tests\DrupalKernel\DrupalKernelTest',
      'Drupal\system\Tests\Entity\EntityFieldTest',
      'Drupal\system\Tests\Entity\EntityValidationTest',
      'Drupal\system\Tests\Extension\ModuleHandlerTest',
      'Drupal\system\Tests\Path\AliasTest',
      'Drupal\system\Tests\System\ScriptTest',
      'Drupal\user\Tests\UserAccountFormFieldsTest',
      'Drupal\views\Tests\Handler\RelationshipTest',
      'Drupal\views\Tests\Plugin\DisplayPageTest',
      'Drupal\views\Tests\Plugin\JoinTest',
    ))) {
      $this->runTestInSeparateProcess = TRUE;
    }
    return parent::run($result);
  }

  protected $classLoader;
  protected $siteDirectory;
  protected $databasePrefix;
  protected $container;

  private static $initialContainerBuilder;

  /**
   * Modules to enable.
   *
   * Test classes extending this class, and any classes in the hierarchy up to
   * this class, may specify individual lists of modules to enable by setting
   * this property. The values of all properties in all classes in the hierarchy
   * are merged.
   *
   * @see \Drupal\Tests\KernelTestBase::enableModules()
   * @see \Drupal\Tests\KernelTestBase::setUp()
   *
   * @var array
   */
  public static $modules = array();

  /**
   * The virtual filesystem root directory.
   *
   * @var \org\bovigo\vfs\vfsStreamDirectory
   */
  protected $vfsRoot;

  /**
   * A list of stream wrappers that have been registered for this test.
   *
   * @see \Drupal\Tests\KernelTestBase::registerStreamWrapper()
   *
   * @var array
   */
  private $streamWrappers = array();

  /**
   * @todo Remove.
   *
   * @var \Drupal\Core\Config\ConfigImporter
   */
  protected $configImporter;

  /**
   * {@inheritdoc}
   */
  public static function setUpBeforeClass() {
    parent::setUpBeforeClass();
    chdir(__DIR__ . '/../../../../');
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->streamWrappers = array();
    \Drupal::setContainer(NULL);

    require_once DRUPAL_ROOT . '/core/includes/bootstrap.inc';

    // @todo Better way? PHPUnit seems to access it from constants.
    // @see /core/tests/bootstrap.php
    $this->classLoader = $GLOBALS['loader'];

    // Set up virtual filesystem.
    // Uses a random ID, since created test files may be processed by file
    // discovery/parser services that are using a static cache to avoid parsing
    // the identical files multiple times.
    $suffix = mt_rand(100000, 999999);
    $this->vfsRoot = vfsStream::setup('root', NULL, array(
      'sites' => array(
        'simpletest' => array(
          $suffix => array(),
        ),
      ),
    ));
    $this->siteDirectory = vfsStream::url('root/sites/simpletest/' . $suffix);

    mkdir($this->siteDirectory . '/files', 0775);
    mkdir($this->siteDirectory . '/files/config/' . CONFIG_ACTIVE_DIRECTORY, 0775, TRUE);
    mkdir($this->siteDirectory . '/files/config/' . CONFIG_STAGING_DIRECTORY, 0775, TRUE);

    // Ensure that all code that relies on drupal_valid_test_ua() can still be
    // safely executed. This primarily affects the (test) site directory
    // resolution (which is used by e.g. LocalStream and PhpStorage).
    // @todo Misleading property name, because sqlite://:memory:
    $this->databasePrefix = 'simpletest' . $suffix;
    drupal_valid_test_ua($this->databasePrefix);

    $settings = array(
      'hash_salt' => get_class($this),
      'file_public_path' => $this->siteDirectory . '/files',
      // Disable Twig template caching/dumping.
      'twig_cache' => FALSE,
    );
    $GLOBALS['config_directories'] = array(
      CONFIG_ACTIVE_DIRECTORY => $this->siteDirectory . '/files/config/active',
      CONFIG_STAGING_DIRECTORY => $this->siteDirectory . '/files/config/staging',
    );

    $databases['default']['default'] = array(
      'driver' => 'sqlite',
      'namespace' => 'Drupal\\Core\\Database\\Driver\\sqlite',
      'host' => '',
      'database' => ':memory:',
      'username' => '',
      'password' => '',
      'prefix' => array(
        'default' => '',
      ),
    );
    foreach (Database::getAllConnectionInfo() as $key => $targets) {
      Database::removeConnection($key);
    }
    Database::setMultipleConnectionInfo($databases);

    // Allow for global test environment overrides.
    if (file_exists($test_env = DRUPAL_ROOT . '/sites/default/testing.services.yml')) {
      $GLOBALS['conf']['container_yamls']['testing'] = $test_env;
    }
    // Add this test class as a service provider.
    $GLOBALS['conf']['container_service_providers']['test'] = $this;

    new Settings($settings);

    $modules = self::getModulesToEnable(get_class($this));

    // Prepare a precompiled container for all tests of this class.
    // Substantially improves the performance of setUp() per test (because
    // ContainerBuilder::compile() is very expensive), which in turn encourages
    // smaller test methods.
    // Theoretically, this is a setUpBeforeClass() operation, but object scope
    // is required in order to inject $this test class instance as a service
    // provider into DrupalKernel (see above).
    $rc = new \ReflectionClass(get_class($this));
    $test_method_count = count(array_filter($rc->getMethods(), function ($method) {
      // PHPUnit's @test annotations are intentionally ignored/not supported.
      return strpos($method->getName(), 'test') === 0;
    }));
    if ($test_method_count > 1 && !$this->isTestInIsolation()) {
      // Variant #1: Actually compiled + dumped Container class.
      //$container = $this->getCompiledContainer($modules);
      // Variant #2: Clone of a compiled, empty ContainerBuilder instance.
      $container = $this->getCompiledContainerBuilder($modules);
    }

    // Bootstrap a kernel. Don't use createFromRequest to retain Settings.
    $kernel = new DrupalKernel('testing', $this->classLoader, FALSE);
    $kernel->setSitePath($this->siteDirectory);
    // Boot the precompiled container. The kernel will enhance it with synthetic
    // services.
    if (isset($container)) {
      $kernel->setContainer($container);
      unset($container);
    }
    // Boot a new one-time container from scratch. Ensure to set the module list
    // upfront to avoid a subsequent rebuild.
    elseif ($modules && $extensions = $this->getExtensionsForModules($modules)) {
      $kernel->updateModules($extensions, $extensions);
    }
    // DrupalKernel::boot() is not sufficient as it does not invoke
    // DrupalKernel::preHandle(), which initializes legacy global variables.
    $request = Request::create('/');
    $kernel->prepareLegacyRequest($request);

    // register() is only called if a new container was built/compiled.
    $this->container = $kernel->getContainer();

    if ($modules) {
      $this->container->get('module_handler')->loadAll();
    }

    $this->container->set('test.logger', $this);

    // Create a minimal core.extension configuration object so that the list of
    // enabled modules can be maintained allowing
    // \Drupal\Core\Config\ConfigInstaller::installDefaultConfig() to work.
    // Write directly to active storage to avoid early instantiation of
    // the event dispatcher which can prevent modules from registering events.
    $this->container->get('config.storage')->write('core.extension', array(
      'module' => array_fill_keys($modules, 0),
      'theme' => array(),
      'disabled' => array('theme' => array()),
    ));

    // Record custom stream wrappers that have been registered by modules during
    // kernel boot.
    // @todo Move StreamWrapper management into DrupalKernel.
    // @see https://drupal.org/node/2028109
    $wrappers = &drupal_static('file_get_stream_wrappers', array());
    foreach ($wrappers[STREAM_WRAPPERS_ALL] as $scheme => $info) {
      $this->streamWrappers[$scheme] = $info['type'];
    }

    // Register default stream wrappers to avoid needless dependencies on System
    // module in tests.
    if (!isset($this->streamWrappers['public'])) {
      // The public stream wrapper only depends on 'file_public_path'.
      $this->registerStreamWrapper('public', 'Drupal\Core\StreamWrapper\PublicStream');
    }
    if (!isset($this->streamWrappers['temporary'])) {
      // The temporary stream wrapper only depends on the OS temp directory.
      $this->registerStreamWrapper('temporary', 'Drupal\Core\StreamWrapper\TemporaryStream');
    }
  }

  /**
   * Prepares the initial, compiled, and dumped Container for tests.
   *
   * Advantages:
   * - Truly compiled Container instead of a (frozen) ContainerBuilder.
   *
   * Disadvantages:
   * - Each dumped Container is loaded separately into memory.
   * - Initial PhpDumper invocation (once per class) is slow.
   */
  private function getCompiledContainer(array $modules) {
    // The container classname is the name of the current test class, but in a
    // fake \Drupal\Container namespace, so as to guarantee that it does not
    // conflict with any code that might introspect available classes.
    $container_classname = substr_replace(get_class($this), 'Drupal\Container', 0, strlen('Drupal'));
    $container_parts = explode('\\', $container_classname);
    $container_shortname = array_pop($container_parts);

    if (!class_exists($container_classname, FALSE)) {
      $kernel = new DrupalKernel('testing', $this->classLoader, FALSE);
      $kernel->setSitePath($this->siteDirectory);
      if ($modules && $extensions = $this->getExtensionsForModules($modules)) {
        $kernel->updateModules($extensions, $extensions);
      }
      $kernel->boot();

      // Dump the container to disk and load its PHP code.
      $dumper = new PhpDumper($kernel->getContainer());
      $code = $dumper->dump(array(
        'namespace' => implode('\\', $container_parts),
        'class' => $container_shortname,
        'base_class' => \Drupal\Core\DrupalKernel::CONTAINER_BASE_CLASS,
      ));
      $container_file = tempnam(sys_get_temp_dir(), 'drupal-phpunit-container-');
      file_put_contents($container_file, $code);
      include $container_file;
      unlink($container_file);
      // Destruct and trigger garbage collection.
      \Drupal::setContainer(NULL);
      $this->container = NULL; // @see register()
      $kernel->shutdown();
      $kernel = NULL;
    }
    return new $container_classname();
  }

  /**
   * Prepares the initial, compiled ContainerBuilder for tests.
   *
   * Advantages:
   * - No memory pollution from many different Container classes.
   * - No filesystem dumping.
   *
   * Disadvantages:
   * - A ContainerBuilder does not match actual Drupal environment.
   */
  private function getCompiledContainerBuilder(array $modules) {
    if (!isset(self::$initialContainerBuilder)) {
      $kernel = new DrupalKernel('testing', $this->classLoader, FALSE);
      $kernel->setSitePath($this->siteDirectory);
      if ($modules && $extensions = $this->getExtensionsForModules($modules)) {
        $kernel->updateModules($extensions, $extensions);
      }
      $kernel->boot();

      // Remove all instantiated services, so the container is safe for cloning.
      // Technically, ContainerBuilder::set($id, NULL) removes each definition,
      // but the container is compiled/frozen already.
      self::$initialContainerBuilder = $kernel->getContainer();
      foreach (self::$initialContainerBuilder->getServiceIds() as $id) {
        self::$initialContainerBuilder->set($id, NULL);
      }
      // Destruct and trigger garbage collection.
      \Drupal::setContainer(NULL);
      $this->container = NULL; // @see register()
      $kernel->shutdown();
      $kernel = NULL;
    }
    $container = clone self::$initialContainerBuilder;
    // @see https://github.com/symfony/symfony/pull/11422
    $container->set('service_container', $container);
    return $container;
  }

  /**
   * Returns Extension objects for test class $modules.
   *
   * @param array $modules
   *   The list of modules to install.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   Extension objects for $modules, keyed by module name.
   *
   * @throws \PHPUnit_Framework_Exception
   *   If a module is not available.
   *
   * @see \Drupal\Tests\KernelTestBase::enableModules()
   * @see \Drupal\Core\Extension\ModuleHandler::add()
   */
  private function getExtensionsForModules(array $modules) {
    $extensions = array();
    $discovery = new ExtensionDiscovery();
    $discovery->setProfileDirectories(array());
    $list = $discovery->scan('module');
    foreach ($modules as $name) {
      // @todo Move into helper method? cf. enableModules()
      if (!isset($list[$name])) {
        throw new \PHPUnit_Framework_Exception("Unavailable module: '$name'. For optional module dependencies, annotate the test class with '@requires module $name'.");
      }
      $extensions[$name] = $list[$name];
    }
    return $extensions;
  }

  /**
   * Sets up the base service container for this test.
   *
   * Extend this method in your test to register additional service overrides
   * that need to persist a DrupalKernel reboot. This method is called whenever
   * the kernel is rebuilt.
   *
   * @see \Drupal\Tests\KernelTestBase::setUp()
   * @see \Drupal\Tests\KernelTestBase::enableModules()
   * @see \Drupal\Tests\KernelTestBase::disableModules()
   */
  public function register(ContainerBuilder $container) {
    $this->container = $container;

    $container
      ->register('flood', 'Drupal\Core\Flood\MemoryBackend')
      ->addArgument(new Reference('request_stack'));
    $container
      ->register('lock', 'Drupal\Core\Lock\NullLockBackend');
    $container
      ->register('cache_factory', 'Drupal\Core\Cache\MemoryBackendFactory');
    $container
      ->register('keyvalue.memory', 'Drupal\Core\KeyValueStore\KeyValueMemoryFactory');
    $container
      ->setAlias('keyvalue', 'keyvalue.memory');

    if ($container->hasDefinition('path_processor_alias')) {
      // Prevent the alias-based path processor, which requires a url_alias db
      // table, from being registered to the path processor manager. We do this
      // by removing the tags that the compiler pass looks for. This means the
      // url generator can safely be used within tests.
      $container->getDefinition('path_processor_alias')
        ->clearTag('path_processor_inbound')
        ->clearTag('path_processor_outbound');
    }

    if ($container->hasDefinition('password')) {
      $container->getDefinition('password')
        ->setArguments(array(1));
    }

    // @todo Remove this BC layer.
    $this->containerBuild($container);

    $container
      ->register('test.logger', __CLASS__)
      ->setSynthetic(TRUE)
      ->addTag('logger');
  }

  public function containerBuild(ContainerBuilder $container) {
  }

  /**
   * {@inheritdoc}
   */
  protected function assertPostConditions() {
    // Execute registered Drupal shutdown functions prior to tearing down.
    // @see _drupal_shutdown_function()
    $callbacks = &drupal_register_shutdown_function();
    while ($callback = array_shift($callbacks)) {
      call_user_func_array($callback['callback'], $callback['arguments']);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    // Die hard if any (new) shutdown functions exist; PHP will halt with a
    // fatal error in addition to the exception, because the shutdown functions
    // will still be executed but won't be able to access any services.
    if ($count = count(drupal_register_shutdown_function())) {
      throw new \RuntimeException(sprintf('%d Drupal shutdown callbacks left (not executed).', $count));
    }

    // tearDown() is always invoked, even in case setUp() failed.
    if ($this->container) {
      $this->container->get('kernel')->shutdown();
    }

    // Stream wrappers are a native global state construct of PHP core, which
    // has to be maintained manually. Ensure that no stream wrapper of this test
    // leaks into subsequently executed tests.
    // @todo Move StreamWrapper management into DrupalKernel.
    // @see https://drupal.org/node/2028109
    $this->unregisterAllStreamWrappers();

    foreach (Database::getAllConnectionInfo() as $key => $targets) {
      Database::removeConnection($key);
    }

    $this->container = NULL;
    \Drupal::setContainer(NULL);
    new Settings(array());
    drupal_static_reset();

    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  public static function tearDownAfterClass() {
    self::$initialContainerBuilder = NULL;
    parent::tearDownAfterClass();
  }

  /**
   * Installs default configuration for a given list of modules.
   *
   * @param string|array $modules
   *   A list of modules for which to install default configuration.
   *
   * @throws \LogicException
   *   If any module listed in $modules is not enabled.
   */
  protected function installConfig($modules) {
    foreach ((array) $modules as $module) {
      if (!$this->container->get('module_handler')->moduleExists($module)) {
        throw new \LogicException("$module module is not enabled.");
      }
      $this->container->get('config.installer')->installDefaultConfig('module', $module);
    }
  }

  /**
   * Installs a specific table from a module schema definition.
   *
   * @param string $module
   *   The name of the module that defines the table's schema.
   * @param string|array $tables
   *   The name or an array of the names of the tables to install.
   *
   * @throws \LogicException
   *   If $module is not enabled or the table schema cannot be found.
   */
  protected function installSchema($module, $tables) {
    // drupal_get_schema_unprocessed() is technically able to install a schema
    // of a non-enabled module, but its ability to load the module's .install
    // file depends on many other factors. To prevent differences in test
    // behavior and non-reproducible test failures, we only allow the schema of
    // explicitly loaded/enabled modules to be installed.
    if (!$this->container->get('module_handler')->moduleExists($module)) {
      throw new \LogicException("$module module is not enabled.");
    }
    $tables = (array) $tables;
    foreach ($tables as $table) {
      $schema = drupal_get_schema_unprocessed($module, $table);
      if (empty($schema)) {
        throw new \LogicException("$module module does not define a schema for table '$table'.");
      }
      $this->container->get('database')->schema()->createTable($table, $schema);
    }
    // We need to refresh the schema cache, as any call to drupal_get_schema()
    // would not know of/return the schema otherwise.
    // @todo Refactor Schema API to make this obsolete.
    drupal_get_schema(NULL, TRUE);
  }

  /**
   * Installs the tables for a specific entity type.
   *
   * @param string $entity_type_id
   *   The ID of the entity type.
   *
   * @throws \LogicException
   *   If the entity type does not support automatic schema installation.
   */
  protected function installEntitySchema($entity_type_id) {
    /** @var \Drupal\Core\Entity\EntityManagerInterface $entity_manager */
    $entity_manager = $this->container->get('entity.manager');
    /** @var \Drupal\Core\Database\Schema $schema_handler */
    $schema_handler = $this->container->get('database')->schema();

    $storage = $entity_manager->getStorage($entity_type_id);
    if ($storage instanceof EntitySchemaProviderInterface) {
      $schema = $storage->getSchema();
      foreach ($schema as $table_name => $table_schema) {
        $schema_handler->createTable($table_name, $table_schema);
      }
    }
    else {
      throw new \LogicException("Entity type '$entity_type_id' does not support automatic schema installation.");
    }
  }

  /**
   * Enables modules for this test.
   *
   * @param array $modules
   *   A list of modules to enable. Dependencies are not resolved; i.e.,
   *   multiple modules have to be specified with dependent modules first.
   *   The new modules are only added to the active module list and loaded.
   *
   * @throws \LogicException
   *   If any module in $modules is already enabled.
   * @throws \RuntimeException
   *   If a module is not enabled after enabling it.
   */
  protected function enableModules(array $modules) {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    if ($trace[1]['function'] === 'setUp') {
      $this->triggerDeprecated('KernelTestBase::enableModules() should not be called from setUp(). Use the $modules property instead.');
    }
    unset($trace);

    // Set the list of modules in the extension handler.
    $module_handler = $this->container->get('module_handler');

    // Write directly to active storage to avoid early instantiation of
    // the event dispatcher which can prevent modules from registering events.
    $active_storage = \Drupal::service('config.storage');
    $extension_config = $active_storage->read('core.extension');

    foreach ($modules as $module) {
      if ($module_handler->moduleExists($module)) {
        throw new \LogicException("$module module is already enabled.");
      }
      $module_handler->addModule($module, drupal_get_path('module', $module));
      // Maintain the list of enabled modules in configuration.
      $extension_config['module'][$module] = 0;
    }
    $active_storage->write('core.extension', $extension_config);

    // Update the kernel to make their services available.
    $extensions = $module_handler->getModuleList();
    $this->container->get('kernel')->updateModules($extensions, $extensions);

    // Ensure isLoaded() is TRUE in order to make _theme() work.
    // Note that the kernel has rebuilt the container; this $module_handler is
    // no longer the $module_handler instance from above.
    $module_handler = $this->container->get('module_handler');
    $module_handler->reload();
    foreach ($modules as $module) {
      if (!$module_handler->moduleExists($module)) {
        throw new \RuntimeException("$module module is not enabled after enabling it.");
      }
    }
  }

  /**
   * Disables modules for this test.
   *
   * @param array $modules
   *   A list of modules to disable. Dependencies are not resolved; i.e.,
   *   multiple modules have to be specified with dependent modules first.
   *   Code of previously active modules is still loaded. The modules are only
   *   removed from the active module list.
   *
   * @throws \LogicException
   *   If any module in $modules is already disabled.
   * @throws \RuntimeException
   *   If a module is not disabled after disabling it.
   */
  protected function disableModules(array $modules) {
    // Unset the list of modules in the extension handler.
    $module_handler = $this->container->get('module_handler');
    $extensions = $module_handler->getModuleList();
    $extension_config = $this->container->get('config.factory')->get('core.extension');
    foreach ($modules as $module) {
      if (!$module_handler->moduleExists($module)) {
        throw new \LogicException("$module module cannot be disabled because it is not enabled.");
      }
      unset($extensions[$module]);
      $extension_config->clear('module.' . $module);
    }
    $extension_config->save();
    $module_handler->setModuleList($extensions);
    $module_handler->resetImplementations();
    // Update the kernel to remove their services.
    $this->container->get('kernel')->updateModules($extensions, $extensions);

    // Ensure isLoaded() is TRUE in order to make _theme() work.
    // Note that the kernel has rebuilt the container; this $module_handler is
    // no longer the $module_handler instance from above.
    $module_handler = $this->container->get('module_handler');
    $module_handler->reload();
    foreach ($modules as $module) {
      if ($module_handler->moduleExists($module)) {
        throw new \RuntimeException("$module module is not disabled after disabling it.");
      }
    }
  }

  /**
   * Registers a stream wrapper for this test.
   *
   * @param string $scheme
   *   The scheme to register.
   * @param string $class
   *   The fully qualified class name to register.
   * @param int $type
   *   The Drupal Stream Wrapper API type. Defaults to
   *   STREAM_WRAPPERS_LOCAL_NORMAL.
   */
  protected function registerStreamWrapper($scheme, $class, $type = STREAM_WRAPPERS_LOCAL_NORMAL) {
    if (isset($this->streamWrappers[$scheme])) {
      $this->triggerDeprecated(sprintf("Stream wrapper scheme '%s' is already registered; possibly by hook_stream_wrappers() of an enabled (test) module. Either do not call %s() or do not enable the module.", $scheme, __FUNCTION__));
      $this->unregisterStreamWrapper($scheme, $this->streamWrappers[$scheme]);
    }
    $this->streamWrappers[$scheme] = $type;
    if (($type & STREAM_WRAPPERS_LOCAL) == STREAM_WRAPPERS_LOCAL) {
      stream_wrapper_register($scheme, $class);
    }
    else {
      stream_wrapper_register($scheme, $class, STREAM_IS_URL);
    }
    // @todo Revamp Drupal's stream wrapper API for D8.
    // @see https://drupal.org/node/2028109
    $wrappers = &drupal_static('file_get_stream_wrappers', array());
    $wrappers[STREAM_WRAPPERS_ALL][$scheme] = array(
      'type' => $type,
      'class' => $class,
    );
    if (($type & STREAM_WRAPPERS_WRITE_VISIBLE) == STREAM_WRAPPERS_WRITE_VISIBLE) {
      $wrappers[STREAM_WRAPPERS_WRITE_VISIBLE][$scheme] = $wrappers[STREAM_WRAPPERS_ALL][$scheme];
    }
  }

  /**
   * Unregisters a stream wrapper previously registered by this test.
   *
   * KernelTestBase::tearDown() automatically cleans up all registered
   * stream wrappers, so this usually does not have to be called manually.
   *
   * @param string $scheme
   *   The scheme to unregister.
   * @param int $type
   *   The Drupal Stream Wrapper API type of the scheme to unregister.
   */
  protected function unregisterStreamWrapper($scheme, $type) {
    stream_wrapper_unregister($scheme);
    unset($this->streamWrappers[$scheme]);
    // @todo Revamp Drupal's stream wrapper API for D8.
    // @see https://drupal.org/node/2028109
    $wrappers = &drupal_static('file_get_stream_wrappers', array());
    foreach ($wrappers as $filter => $schemes) {
      if (is_int($filter) && (($filter & $type) == $filter)) {
        unset($wrappers[$filter][$scheme]);
      }
    }
  }

  /**
   * Unregisters all custom stream wrappers.
   *
   * @todo Revamp Drupal's stream wrapper API for D8.
   * @see https://drupal.org/node/2028109
   */
  protected function unregisterAllStreamWrappers() {
    // The file_get_stream_wrappers() static may have been reset, so unregister
    // all known wrappers first.
    foreach ($this->streamWrappers as $scheme => $type) {
      $this->unregisterStreamWrapper($scheme, $type);
    }

    $wrappers = &drupal_static('file_get_stream_wrappers', array());
    if (empty($wrappers)) {
      return;
    }
    foreach ($wrappers[STREAM_WRAPPERS_ALL] as $scheme => $info) {
      $this->unregisterStreamWrapper($scheme, $info['type']);
    }
  }

  /**
   * Renders a render array.
   *
   * @param array $elements
   *   The elements to render.
   *
   * @return string
   *   The rendered string output (typically HTML).
   */
  protected function render(array $elements) {
    $content = drupal_render($elements);
    $this->setRawContent($content);
    $this->verbose('<pre style="white-space: pre-wrap">' . String::checkPlain($content));
    return $content;
  }

  /**
   * Changes in-memory settings.
   *
   * @param string $name
   *   The name of the setting to set.
   * @param bool|string|int|array|null $value
   *   The value to set.
   *
   * @return void
   *
   * @see \Drupal\Core\Site\Settings::get()
   */
  protected function settingsSet($name, $value) {
    $settings = Settings::getAll();
    $settings[$name] = $value;
    new Settings($settings);
  }

  /**
   * Converts a list of possible parameters into a stack of permutations.
   *
   * Takes a list of parameters containing possible values, and converts all of
   * them into a list of items containing every possible permutation.
   *
   * Example:
   * @code
   * $parameters = array(
   *   'one' => array(0, 1),
   *   'two' => array(2, 3),
   * );
   * $permutations = KernelTestBase::generatePermutations($parameters);
   * // Result:
   * $permutations == array(
   *   array('one' => 0, 'two' => 2),
   *   array('one' => 1, 'two' => 2),
   *   array('one' => 0, 'two' => 3),
   *   array('one' => 1, 'two' => 3),
   * )
   * @endcode
   *
   * @param array $parameters
   *   An associative array of parameters, keyed by parameter name, and whose
   *   values are arrays of parameter values.
   *
   * @return array
   *   A list of permutations, which is an array of arrays. Each inner array
   *   contains the full list of parameters that have been passed, but with a
   *   single value only.
   */
  public static function generatePermutations(array $parameters) {
    $all_permutations = array(array());
    foreach ($parameters as $parameter => $values) {
      $new_permutations = array();
      // Iterate over all values of the parameter.
      foreach ($values as $value) {
        // Iterate over all existing permutations.
        foreach ($all_permutations as $permutation) {
          // Add the new parameter value to existing permutations.
          $new_permutations[] = $permutation + array($parameter => $value);
        }
      }
      // Replace the old permutations with the new permutations.
      $all_permutations = $new_permutations;
    }
    return $all_permutations;
  }

  /**
   * Returns a ConfigImporter object to import test configuration.
   *
   * @return \Drupal\Core\Config\ConfigImporter
   *
   * @todo Move into Config test-specific base class.
   */
  protected function configImporter() {
    if (!$this->configImporter) {
      // Set up the ConfigImporter object for testing.
      $storage_comparer = new StorageComparer(
        $this->container->get('config.storage.staging'),
        $this->container->get('config.storage'),
        $this->container->get('config.manager')
      );
      $this->configImporter = new ConfigImporter(
        $storage_comparer,
        $this->container->get('event_dispatcher'),
        $this->container->get('config.manager'),
        $this->container->get('lock'),
        $this->container->get('config.typed'),
        $this->container->get('module_handler'),
        $this->container->get('theme_handler'),
        $this->container->get('string_translation')
      );
    }
    // Always recalculate the changelist when called.
    return $this->configImporter->reset();
  }

  /**
   * Copies configuration objects from a source storage to a target storage.
   *
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   The source config storage.
   * @param \Drupal\Core\Config\StorageInterface $target_storage
   *   The target config storage.
   *
   * @todo Move into Config test-specific base class.
   */
  protected function copyConfig(StorageInterface $source_storage, StorageInterface $target_storage) {
    $target_storage->deleteAll();
    foreach ($source_storage->listAll() as $name) {
      $target_storage->write($name, $source_storage->read($name));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @todo Add '@expectedLogSeverity CONST' + '@expectedLogMessage string'.
   */
  public function log($level, $message, array $context = array()) {
    if ($level <= WATCHDOG_WARNING) {
      $message_placeholders = $this->container->get('logger.log_message_parser')
        ->parseMessagePlaceholders($message, $context);
      if (!empty($message_placeholders)) {
        $message = strtr($message, $message_placeholders);
      }
      // @todo Unnecessary?
      if (!isset($context['backtrace'])) {
        $context['backtrace'][0]['file'] = __FILE__;
        $context['backtrace'][0]['line'] = __LINE__;
      }
      throw new \ErrorException($message, $level, $level, $context['backtrace'][0]['file'], $context['backtrace'][0]['line']);
    }
  }

  /**
   * Stops test execution.
   */
  protected function stop() {
    $this->getTestResultObject()->stop();
  }

  /**
   * Dumps the current state of the virtual filesystem to STDOUT.
   */
  protected function vfsDump() {
    vfsStream::inspect(new vfsStreamPrintVisitor());
  }

  /**
   * Returns the modules to enable for this test.
   *
   * @return array
   */
  private static function getModulesToEnable($class) {
    $modules = array();
    while ($class) {
      if (property_exists($class, 'modules')) {
        // Only add the modules, if the $modules property was not inherited.
        $rp = new \ReflectionProperty($class, 'modules');
        if ($rp->class == $class) {
          $modules[$class] = $class::$modules;
        }
      }
      $class = get_parent_class($class);
    }
    // Modules have been collected in reverse class hierarchy order; modules
    // defined by base classes should be sorted first. Then, merge the results
    // together.
    $modules = array_reverse($modules);
    return call_user_func_array('array_merge_recursive', $modules);
  }

  public function __get($name) {
    if (in_array($name, array(
      'public_files_directory',
      'private_files_directory',
      'temp_files_directory',
      'translation_files_directory',
    ))) {
      $this->triggerDeprecated(sprintf("KernelTestBase::\$%s no longer exists. Use the regular API method to retrieve it instead (e.g., Settings).", $name));
      switch ($name) {
        case 'public_files_directory':
          return Settings::get('file_public_path', conf_path() . '/files');

        case 'private_files_directory':
          return $this->container->get('config.factory')->get('system.file')->get('path.private');

        case 'temp_files_directory':
          return file_directory_temp();

        case 'translation_files_directory':
          return Settings::get('file_public_path', conf_path() . '/translations');
      }
    }

    if ($name === 'configDirectories') {
      $this->triggerDeprecated(sprintf("KernelTestBase::\$%s no longer exists. Use config_get_config_directory() directly instead.", $name));
      return array(
        CONFIG_ACTIVE_DIRECTORY => config_get_config_directory(CONFIG_ACTIVE_DIRECTORY),
        CONFIG_STAGING_DIRECTORY => config_get_config_directory(CONFIG_STAGING_DIRECTORY),
      );
    }

    $denied = array(
      // @see \Drupal\simpletest\TestBase
      'testId',
      'databasePrefix', // @todo 
      'timeLimit',
      'results',
      'assertions',
      'skipClasses',
      'verbose',
      'verboseId',
      'verboseClassName',
      'verboseDirectory',
      'verboseDirectoryUrl',
      'dieOnFail',
      'kernel',
      // @see \Drupal\simpletest\TestBase::prepareEnvironment()
      'generatedTestFiles',
      // @see \Drupal\simpletest\KernelTestBase::containerBuild()
      'keyValueFactory',
    );
    if (in_array($name, $denied) || strpos($name, 'original') === 0) {
      throw new \RuntimeException(sprintf('TestBase::$%s property no longer exists', $name));
    }
  }

  public function __set($name, $value) {
    $this->__get($name);
  }

  /**
   * Triggers a test framework feature deprecation warning.
   *
   * Does not halt test execution.
   *
   * @param string $message
   *   The plain-text message to output (on CLI).
   */
  private function triggerDeprecated($message) {
    if (class_exists('PHPUnit_Util_DeprecatedFeature')) {
      $message = get_class($this) . '::' . $this->getName() . "\n" . $message;
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
      $this->getTestResultObject()->addDeprecatedFeature(
        new \PHPUnit_Util_DeprecatedFeature($message, $trace[1])
      );
    }
    else {
      trigger_error($message, E_USER_DEPRECATED);
    }
  }

}
