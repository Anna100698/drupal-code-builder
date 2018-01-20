<?php

namespace DrupalCodeBuilder\Task\Collect;

use DrupalCodeBuilder\Environment\EnvironmentInterface;

/**
 * Task helper for collecting data on plugin types.
 */
class PluginTypesCollector {

  /**
   * The method collector helper
   */
  protected $methodCollector;

  /**
   * The names of plugin type managers to collect for testing sample data.
   */
  protected $testingPluginManagerServiceIds = [
    'plugin.manager.block',
    'plugin.manager.field.formatter',
    'plugin.manager.image.effect',
  ];

  /**
   * Constructs a new helper.
   *
   * @param \DrupalCodeBuilder\Environment\EnvironmentInterface $environment
   *   The environment object.
   * @param MethodCollector $method_collector
   *   The method collector helper.
   */
  public function __construct(
    EnvironmentInterface $environment,
    MethodCollector $method_collector
  ) {
    $this->environment = $environment;
    $this->methodCollector = $method_collector;
  }

  /**
   * Get definitions of services.
   *
   * @return array
   */
  public function collect() {
    $plugin_manager_service_ids = $this->getPluginManagerServices();

    // Filter for testing sample data collection.
    if (!empty($this->environment->sample_data_write)) {
      // Note this is not an intersect on keys like the other collectors!
      $plugin_manager_service_ids = array_intersect($plugin_manager_service_ids, $this->testingPluginManagerServiceIds);
    }

    //$plugin_manager_service_ids = ['plugin.manager.block'];

    $plugin_type_data = $this->gatherPluginTypeInfo($plugin_manager_service_ids);

    return $plugin_type_data;
  }

  /**
   * Detects services which are plugin managers.
   *
   * @return
   *  An array of service IDs of all the services which we detected to be plugin
   *  managers.
   */
  protected function getPluginManagerServices() {
    // Get the IDs of all services from the container.
    $service_ids = \Drupal::getContainer()->getServiceIds();
    //drush_print_r($service_ids);

    // Filter them down to the ones that are plugin managers.
    // TODO: this omits some that don't conform to this pattern! Deal with
    // these! See https://www.drupal.org/node/2086181
    $plugin_manager_service_ids = array_filter($service_ids, function($element) {
      if (strpos($element, 'plugin.manager.') === 0) {
        return TRUE;
      }
    });

    //drush_print_r($plugin_manager_service_ids);

    // Developer trapdoor: just process the block plugin type, to make terminal
    // debug output easier to read through.
    //$plugin_manager_service_ids = array('plugin.manager.block');

    return $plugin_manager_service_ids;
  }

  /**
   * Detects information about plugin types from the plugin manager services
   *
   * @param $plugin_manager_service_ids
   *  An array of service IDs.
   *
   * @return
   *  The assembled plugin type data. This is an array keyed by plugin type ID
   *  (where we take this to be the name of the plugin manager service for that
   *  type, with the 'plugin.manager.' prefix removed). Values are arrays with
   *  the following properties:
   *    - 'type_id': The plugin type ID.
   *    - 'type_label': A label for the plugin type. If Plugin module is present
   *      then this is the label from the definition there, if found. Otherwise,
   *      this duplicates the ID.
   *    - 'service_id': The ID of the service for the plugin type's manager.
   *    - 'subdir: The subdirectory of /src that plugin classes must go in.
   *      E.g., 'Plugin/Filter'.
   *    - 'plugin_interface': The interface that plugin classes must implement,
   *      as a qualified name (but without initial '\').
   *    - 'plugin_definition_annotation_name': The class that the plugin
   *      annotation uses, as a qualified name (but without initial '\').
   *      E.g, 'Drupal\filter\Annotation\Filter'.
   *    - 'plugin_interface_methods': An array of methods that the plugin's
   *      interface has. This is keyed by the method name, with each value an
   *      array with these properties:
   *      - 'name': The method name.
   *      - 'declaration': The method declaration line from the interface.
   *      - 'description': The text from the first line of the docblock.
   *    - 'plugin_properties: Properties that the plugin class may declare in
   *      its annotation. These are deduced from the class properties of the
   *      plugin type's annotation class. An array keyed by the property name,
   *      whose values are arrays with these properties:
   *      - 'name': The name of the property.
   *      - 'description': The description, taken from the docblock of the class
   *        property on the annotation class.
   *      - 'type': The data type.
   *    - 'construction': An array of injected service parameters for the plugin
   *      base class. Each item has:
   *      - 'type': The typehint.
   *      - 'name': The parameter name, without the $.
   *      - 'extraction': The code for getting the service from the container.
   *    - 'constructor_fixed_parameters': An array of the fixed parameters for
   *      plugin base class __construct(). This is only set if they differ from
   *      the standard ones.
   *      - 'type': The typehint.
   *      - 'name': The parameter name, without the $.
   *      - 'extraction': The code for getting the value to pass in from the
   *        parameters passed to create().
   *
   *  Due to the difficult nature of analysing the code for plugin types, some
   *  of these properties may be empty if they could not be deduced.
   */
  protected function gatherPluginTypeInfo($plugin_manager_service_ids) {
    // Assemble a basic array of plugin type data, that we will successively add
    // data to.
    $plugin_type_data = array();
    foreach ($plugin_manager_service_ids as $plugin_manager_service_id) {
      // We identify plugin types by the part of the plugin manager service name
      // that comes after 'plugin.manager.'.
      $plugin_type_id = substr($plugin_manager_service_id, strlen('plugin.manager.'));

      // Get the class name for the service.
      // Babysit modules that don't define services properly!
      // (I'm looking at Physical.)
      try {
        $service = \Drupal::service($plugin_manager_service_id);
      }
      catch (\Throwable $ex) {
        continue;
      }

      $service = \Drupal::service($plugin_manager_service_id);
      $service_class_name = get_class($service);
      $service_component_namespace = $this->getClassComponentNamespace($service_class_name);

      $plugin_type_data[$plugin_type_id] = [
        'type_id' => $plugin_type_id,
        'service_id' => $plugin_manager_service_id,
        'service_class_name' => $service_class_name,
        'service_component_namespace' => $service_component_namespace,
        // Plugin module may replace this if present.
        'type_label' => $plugin_type_id,
      ];

      // Add data from the plugin type manager service.
      // This gets us the subdirectory, interface, and annotation name.
      $this->addPluginTypeServiceData($plugin_type_data[$plugin_type_id]);

      // Try to detect a base class for plugins
      $this->addPluginBaseClass($plugin_type_data[$plugin_type_id]);

      // Add data about the factory method of the base class, if any.
      $this->addConstructionData($plugin_type_data[$plugin_type_id]);

      // Do the large arrays last, so they are last in the stored data so it's
      // easier to read.
      // Add data from the plugin annotation class.
      $this->addPluginAnnotationData($plugin_type_data[$plugin_type_id]);

      // Add data from the plugin interface (which the manager service gave us).
      $this->addPluginMethods($plugin_type_data[$plugin_type_id]);
    }

    // Get plugin type information if Plugin module is present.
    // We get data on all plugin types in one go, so this handles the whole
    // data.
    $this->addPluginModuleData($plugin_type_data);

    // Sort by ID.
    ksort($plugin_type_data);

    //drush_print_r($plugin_type_data);

    return $plugin_type_data;
  }

  /**
   * Adds plugin type information from the plugin type manager service.
   *
   * This adds:
   *  - subdir
   *  - pluginInterface
   *  - pluginDefinitionAnnotationName
   *
   * @param &$data
   *  The data for a single plugin type.
   */
  protected function addPluginTypeServiceData(&$data) {
    // Get the service, and then get the properties that the plugin manager
    // constructor sets.
    // E.g., most plugin managers pass this to the parent:
    //   parent::__construct('Plugin/Block', $namespaces, $module_handler, 'Drupal\Core\Block\BlockPluginInterface', 'Drupal\Core\Block\Annotation\Block');
    // See Drupal\Core\Plugin\DefaultPluginManager
    $service = \Drupal::service($data['service_id']);
    $reflection = new \ReflectionClass($service);

    // The list of properties we want to grab out of the plugin manager
    //  => the key in the plugin type data array we want to set this into.
    $plugin_manager_properties = [
      'subdir' => 'subdir',
      'pluginInterface' => 'plugin_interface',
      'pluginDefinitionAnnotationName' => 'plugin_definition_annotation_name',
    ];
    foreach ($plugin_manager_properties as $property_name => $data_key) {
      if (!$reflection->hasProperty($property_name)) {
        // plugin.manager.menu.link is different.
        $data[$data_key] = '';
        continue;
      }

      $property = $reflection->getProperty($property_name);
      $property->setAccessible(TRUE);
      $data[$data_key] = $property->getValue($service);
    }
  }

  /**
   * Adds a list of properties from the plugin annotation class.
   *
   * @param &$data
   *  The data for a single plugin type.
   */
  protected function addPluginAnnotationData(&$data) {
    $data['plugin_properties'] = [];

    if (!isset($data['plugin_definition_annotation_name'])) {
      return;
    }
    if (!class_exists($data['plugin_definition_annotation_name'])) {
      return;
    }

    // Get a reflection class for the annotation class.
    // Each property of the annotation class describes a property for the
    // plugin annotation.
    $annotation_reflection = new \ReflectionClass($data['plugin_definition_annotation_name']);
    $properties_reflection = $annotation_reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

    foreach ($properties_reflection as $property_reflection) {
      // Assemble data about this annotation property.
      $annotation_property_data = array();
      $annotation_property_data['name'] = $property_reflection->name;

      // Get the docblock for the property, so we can figure out whether the
      // annotation property requires translation, and also add detail to the
      // annotation code.
      $property_docblock = $property_reflection->getDocComment();
      $property_docblock_lines = explode("\n", $property_docblock);
      foreach ($property_docblock_lines as $line) {
        if (substr($line, 0, 3) == '/**') {
          continue;
        }

        // Take the first actual docblock line to be the description.
        if (!isset($annotation_property_data['description']) && substr($line, 0, 5) == '   * ') {
          $annotation_property_data['description'] = substr($line, 5);
        }

        // Look for a @var token, to tell us the type of the property.
        if (substr($line, 0, 10) == '   * @var ') {
          $annotation_property_data['type'] = substr($line, 10);
        }
      }

      $data['plugin_properties'][$property_reflection->name] = $annotation_property_data;
    }
  }

  /**
   * Determines the base class that plugins should use.
   *
   * @param &$data
   *  The data for a single plugin type.
   */
  protected function addPluginBaseClass(&$data) {
    $base_class = $this->analysePluginTypeBaseClass($data);
    if ($base_class) {
      $data['base_class'] = $base_class;
    }
  }

  /**
   * Analyses plugins of the given type to find a suitable base class.
   *
   * @param array $data
   *   The array of data for the plugin type.
   *
   * @return string
   *   The base class to use when generating plugins of this type.
   */
  protected function analysePluginTypeBaseClass($data) {
    // Work over each plugin of this type, finding a suitable candidate for
    // base class with each one.
    $potential_base_classes = [];

    $service = \Drupal::service($data['service_id']);
    $definitions = $service->getDefinitions();
    foreach ($definitions as $plugin_id => $definition) {
      // We can't work with plugins that don't define a class: skip the whole
      // plugin type.
      if (empty($definition['class'])) {
        return;
      }

      // Babysit modules that have a broken plugin class. This can be caused
      // if the namespace is incorrect for the file location, and so prevents
      // the class from being autoloaded.
      if (!class_exists($definition['class'])) {
        // Skip just this plugin.
        continue;
      }

      $plugin_component_namespace = $this->getClassComponentNamespace($definition['class']);

      // Get the full ancestry of the plugin's class.
      // Build a lineage array, from youngest to oldest, i.e. closest parents
      // first.
      $lineage = [];
      $plugin_class_reflection = new \ReflectionClass($definition['class']);
      $class_reflection = $plugin_class_reflection;
      while ($class_reflection = $class_reflection->getParentClass()) {
        $lineage[] = $class_reflection->getName();
      }

      // Try to get the nearest ancestor in the same namespace whose class
      // name ends in 'Base'.
      foreach ($lineage as $ancestor_class) {
        $parent_class_component_namespace = $this->getClassComponentNamespace($ancestor_class);
        if ($parent_class_component_namespace != $data['service_component_namespace']) {
          // No longer in the plugin manager's component: stop looking.
          break;
        }

        if (substr($ancestor_class, - strlen('Base')) == 'Base') {
          $potential_base_classes[] = $ancestor_class;

          // Done with this plugin.
          goto next_plugin;
        }
      } // foreach lineage

      // If we failed to find a class called FooBase in the same component.
      // Ue the youngest ancestor which is in the same component as the
      // plugin manager.
      // Getting the youngest ancestor accounts for modules that define
      // multiple plugin types and have a common base class for all of them
      // (e.g. Views), but unfortunately sometimes gets us a base class that
      // is too specialized in modules that provide several base classes,
      // for example a general base class and a more specific one. The next
      // step addresses that.
      foreach ($lineage as $ancestor_class) {
        $parent_class_component_namespace = $this->getClassComponentNamespace($ancestor_class);

        if ($parent_class_component_namespace == $data['service_component_namespace']) {
          // We've found an ancestor class in the plugin's hierarchy which is
          // in the same namespace as the plugin manager service. Assume it's
          // a good base class, and move on to the next plugin type.
          // TODO: don't take this if the class itself is a plugin.
          $potential_base_classes[] = $ancestor_class;

          // Done with this plugin.
          goto next_plugin;
        }
      } // foreach lineage

      // End of the loop for the current plugin.
      next_plugin:
    } // foreach $definitions

    // If we found nothing, we're done.
    if (empty($potential_base_classes)) {
      return;
    }

    // We now have an array of several base classes, potentially as many as one
    // for each plugin.
    // Collapse this down to unique values.
    $potential_base_classes_unique = array_unique($potential_base_classes);

    // If that leaves us with only one base class, return that.
    if (count($potential_base_classes_unique) == 1) {
      return reset($potential_base_classes_unique);
    }

    // If we have more than one base class, it's most likely because there are
    // specialized base classes in addition to the main one (e.g. if our plugins
    // were animals, AnimalBase and FelineBase). Now organize the classes by
    // ancestry, and take the oldest one.

    // Try to form an ancestry chain of the potential base classes.
    // Start by partitioning the set of base classes into sets of classes that
    // are related by ancestry, so we can pick the largest set, and then sort
    // it by ancestry.
    $ancestry_sets = [];
    $current_set = [];
    foreach ($potential_base_classes_unique as $class) {
      // If the current set is empty, then the class goes in as it's trivially
      // related to itself.
      if (empty($current_set)) {
        $current_set[] = $class;
        continue;
      }

      // Get a sample class from the set. It doesn't matter which.
      $other_class = reset($current_set);

      if (is_subclass_of($class, $other_class) || is_subclass_of($other_class, $class)) {
        // If the current class is related to a class in the current set, add
        // it to the set.
        $current_set[] = $class;
      }
      else {
        // The current class is not related. Stash the current set, as we are
        // done with it, and start a new one.
        $ancestry_sets[] = $current_set;

        $current_set = [];
        $current_set[] = $class;
      }
    }
    // Once we're done with the loop, our current set remains: put it into the
    // partition list.
    $ancestry_sets[] = $current_set;

    // If there is more than one partition, take the largest.
    if (count($ancestry_sets) > 1) {
      $ancestry_sets_counts = [];
      foreach ($ancestry_sets as $index => $set) {
        $ancestry_sets_counts[$index] = count($set);
      }

      // Find the index of the largest set.
      // (If more than one are joint largest, it doesn't matter which one we
      // take as we have no way of choosing anyway.)
      // (Also note that in core, the only plugin type that produces more than
      // one set is archiver, which is hardly going to be used much.)
      $max_count = max($ancestry_sets_counts);
      $max_index = array_search($max_count, $ancestry_sets_counts);

      $set = $ancestry_sets[$max_index];
    }
    else {
      // There is only one set; take that.
      $set = $ancestry_sets[0];
    }

    // Sort the array of classes by parentage, youngest to oldest.
    usort($set, function($a, $b) {
      if (is_subclass_of($a, $b)) {
        return -1;
      }
      else {
        return 1;
      }
      // We filtered the array for uniqueness, so the 0 case won't ever happen,
      // and we partitioned the array, so we know the classes are always
      // related.
    });

    // Return the oldest parent, as this is the most generic of the found base
    // classes.
    $base_class = end($set);

    return $base_class;
  }

  /**
   * Adds list of methods for the plugin based on the plugin interface.
   *
   * @param &$data
   *  The data for a single plugin type.
   */
  protected function addPluginMethods(&$data) {
    // Set an empty array at the least.
    $data['plugin_interface_methods'] = [];

    if (empty($data['plugin_interface'])) {
      // If we didn't find an interface for the plugin, we can't do anything
      // here.
      return;
    }

    // Analyze the interface, if there is one.
    $reflection = new \ReflectionClass($data['plugin_interface']);
    $methods = $reflection->getMethods();

    $data['plugin_interface_methods'] = [];

    // Check each method from the plugin interface for suitability.
    foreach ($methods as $method_reflection) {
      // Start by assuming we won't include it.
      $include_method = FALSE;

      // Get the actual class/interface that declares the method, as the
      // plugin interface will in most cases inherit from one or more
      // interfaces.
      $declaring_class = $method_reflection->getDeclaringClass()->getName();
      // Get the namespace of the component the class belongs to.
      $declaring_class_component_namespace = $this->getClassComponentNamespace($declaring_class);

      if ($declaring_class_component_namespace == $data['service_component_namespace']) {
        // The plugin interface method is declared in the same component as
        // the plugin manager service.
        // Add it to the list of methods a plugin should implement.
        $include_method = TRUE;
      }
      else {
        // The plugin interface method is declared in a namespace other
        // than the plugin manager. Therefore it's something from a base
        // interface, e.g. from PluginInspectionInterface, and we shouldn't
        // add it to the list of methods a plugin should implement...
        // except for a few special cases.

        if ($declaring_class == 'Drupal\Core\Plugin\PluginFormInterface') {
          $include_method = TRUE;
        }
        if ($declaring_class == 'Drupal\Core\Cache\CacheableDependencyInterface') {
          $include_method = TRUE;
        }
      }

      if ($include_method) {
        $data['plugin_interface_methods'][$method_reflection->getName()] = $this->methodCollector->getMethodData($method_reflection);
      }
    }
  }

  /**
   * Adds data about the __construct() and create() methods for the plugins.
   *
   * The norm for plugins is to have three paramters for the constructor:
   *   $configuration, $plugin_id, $plugin_definition
   * A plugin that implements ContainerFactoryPluginInterface adds its injected
   * service parameters after these, and its create() method receives them and
   * passes them in. We thus have fixed parameters, and injection parameters.
   *
   * However, there are two ways in which a plugin type can deviate from this,
   * and which affect a plugin that injects services of its own:
   *  - The plugin base class already has injected services. In this case:
   *    - The create() method must extract the base class services and pass them
   *      in to the creation call.
   *    - The __construct() method must receive them as parameters after the
   *      basic parameters, and pass them up to the parent call.
   *  - The plugin manager service passes fixed parameters to the plugin base
   *    class which deviate from the basic parameters. In this case:
   *    - The create() method must pass these different parameters in the
   *      creation call. All the examples of this in core either pass a subset
   *      of the basic parameters, or pass extra parameters which are values in
   *      the $configuration array.
   *    - The __construct() method must receive the fixed parameters and pass
   *      them up to the parent call.
   *  - Fortunately, so far there are no known plugin types that combine
   *    both cases!
   *
   * To handle these cases, we detect the following:
   *  - parameters of the plugin base class's __construct() method.
   *  - values passed 'new static' call in the plugin base class's create()
   *    method.
   *
   * This allows generated plugins that have injected services to correctly
   * call the parent constructor, and pass the injected services to the base
   * class.
   *
   * @param &$data
   *  The data for a single plugin type.
   */
  protected function addConstructionData(&$data) {
    if (!isset($data['base_class'])) {
      // Can't do anything if we didn't find a base class.
      return;
    }

    // First see if the fixed parameters are non-standard.
    $fixed_parameters = $this->getFixedParametersFromManager($data['service_class_name']);
    if ($fixed_parameters) {
      // TODO: there is an unlikely (and not yet encountered) case where the
      // plugin manager implements createInstance() for other reasons, and
      // passes in the standard fixed parameters.

      foreach ($fixed_parameters as $i => $extraction) {
        $data['constructor_fixed_parameters'][$i]['extraction'] = $extraction;
      }
    }

    if (isset($data['constructor_fixed_parameters'])) {
      $fixed_parameter_count = count($data['constructor_fixed_parameters']);
    }
    else {
      $fixed_parameter_count = 3;
    }

    $base_class_construct_params_data = $this->getBaseClassConstructParameters($data, $fixed_parameter_count);
    if ($base_class_construct_params_data) {
      //dump($base_class_construct_params_data);
      foreach ($base_class_construct_params_data['fixed'] as $i => $param) {
        //dump($param);
        $data['constructor_fixed_parameters'][$i] += $param;
      }

      if ($base_class_construct_params_data['injection']) {
        $data['construction'] = $base_class_construct_params_data['injection'];
      }
    }

    $base_class_injected_params_extraction = $this->getBaseClassInjectedParamExtraction($data, $fixed_parameter_count);
    if ($base_class_injected_params_extraction) {
      foreach ($base_class_injected_params_extraction as $i => $extraction) {
        $data['construction'][$i]['extraction'] = $extraction;
      }
    }
  }

  /**
   * Get fixed parameters for plugin class __construct().
   *
   * Some plugin types have a constructor for the plugin classes with
   * non-standard parameters. In this case, the plugin manager will override
   * createInstance() to take care of passing them in. We need to be aware of
   * this because when generating a plugin with injected services, we implement
   * create() and __construct():
   * - create() needs to repeat the work of the plugin manager's
   *   createInstance() to get the right parameters to pass. Note that this can
   *   not be 100% accurate, as we can't detect whether createInstance() has any
   *   lines of code that prepare the variables to be passed to the constructor
   *   call.
   * - construct() needs to know the parameters that come before the injected
   *   services.
   *
   * @param [type] $plugin_type_data [description]
   */
  protected function getFixedParametersFromManager($service_class_name) {
    $method_ref = new \ReflectionMethod($service_class_name, 'createInstance');
    if ($method_ref->getDeclaringClass()->getName() != $service_class_name) {
      // The plugin manager does not override createInstance(), therefore the
      // plugin class construct() method must have the normal parameters.
      return;
    }

    // Get the method's body code.
    // We could use the PHP parser library here rather than sniff with regexes,
    // but it would probably end up being just as much work poking around in
    // the syntax tree.
    $body = $this->getMethodBody($method_ref);

    if (strpos($body, 'return new') === FALSE) {
      // The method doesn't construct an object: therefore the paramters for
      // the plugin constructor are the standard ones.
      return;
    }

    $matches = [];
    preg_match('@return new .+\((.+)\)@', $body, $matches);
    $construction_params = $matches[1];

    // Use preg_split() rather than explode() in case the code has formatting
    // errors or uses linebreaks.
    $construction_params = preg_split('@\s*,\s*@', $construction_params);

    //dump($construction_params);
    return $construction_params;
  }

  /**
   * Helper for addConstructionData().
   *
   * @param array $data
   *   The data for the plugin type.
   * @param int $fixed_parameter_count
   *   The number of fixed parameters for the __construct() method of plugin
   *   classes for this plugin type.
   */
  protected function getBaseClassConstructParameters($data, $fixed_parameter_count) {
    if (!method_exists($data['base_class'], '__construct')) {
      return;
    }

    // Analyze the params to the __construct() method to get the typehints
    // and parameter names.
    $construct_R = new \ReflectionMethod($data['base_class'], '__construct');
    $construct_params_R = $construct_R->getParameters();
    $construct_fixed_params_ref = array_slice($construct_params_R, 0, $fixed_parameter_count);
    $construct_injection_params_ref = array_slice($construct_params_R, $fixed_parameter_count);

    // Only analyze the fixed parameters if we know that they are non-standard.
    $fixed_parameters = [];
    if (isset($data['constructor_fixed_parameters'])) {
      foreach ($construct_fixed_params_ref as $i => $parameter) {
        $name = $parameter->getName();
        $type = (string) $parameter->getType();
        // TODO: Get description from the docblock.

        $fixed_parameters[] = [
          'type' => $type,
          'name' => $parameter->getName(),
        ];
      }
    }

    $construct_parameters = [];
    foreach ($construct_injection_params_ref as $i => $parameter) {
      $name = $parameter->getName();
      $type = (string) $parameter->getType();
      // TODO: Get description from the docblock.

      $construct_parameters[] = [
        'type' => $type,
        'name' => $parameter->getName(),
      ];
    }

    return [
      'fixed' => $fixed_parameters,
      'injection' => $construct_parameters,
    ];
  }

  protected function getBaseClassInjectedParamExtraction($data, $fixed_parameter_count) {
    if (!method_exists($data['base_class'], 'create')) {
      return;
    }

    // Get the call from the body of the create() method.
    $create_R = new \ReflectionMethod($data['base_class'], 'create');
    $create_method_body = $this->getMethodBody($create_R);

    $matches = [];
    preg_match('@ new \s+ static \( ( [^;]+ ) \) ; @x', $create_method_body, $matches);

    $parameters = explode(',', $matches[1]);

    $create_container_extractions = [];
    foreach (array_slice($parameters, 3) as $i => $parameter) {
      $create_container_extractions[$i] = trim($parameter);
    }

    return $create_container_extractions;
  }

  /**
   * Adds plugin type information from Plugin module if present.
   *
   * @param &$plugin_type_data
   *  The array of data for all plugin types.
   */
  protected function addPluginModuleData(&$plugin_type_data) {
    // Bail if Plugin module isn't present.
    if (!\Drupal::hasService('plugin.plugin_type_manager')) {
      return;
    }

    // This gets us labels for the plugin types which are declared to Plugin
    // module.
    $plugin_types = \Drupal::service('plugin.plugin_type_manager')->getPluginTypes();

    // We need to re-key these by the service ID, as Plugin module uses IDs for
    // plugin types which don't always the ID we use for them based on the
    // plugin manager service ID, , e.g. views_access vs views.access.
    // Unfortunately, there's no accessor for this, so some reflection hackery
    // is required until https://www.drupal.org/node/2907862 is fixed.
    $reflection = new \ReflectionProperty(\Drupal\plugin\PluginType\PluginType::class, 'pluginManagerServiceId');
    $reflection->setAccessible(TRUE);

    foreach ($plugin_types as $plugin_type) {
      // Get the service ID from the reflection, and then our ID.
      $plugin_manager_service_id = $reflection->getValue($plugin_type);
      $plugin_type_id = substr($plugin_manager_service_id, strlen('plugin.manager.'));

      if (!isset($plugin_type_data[$plugin_type_id])) {
        return;
      }

      // Replace the default label with the one from Plugin module, casting it
      // to a string so we don't have to deal with TranslatableMarkup objects.
      $plugin_type_data[$plugin_type_id]['type_label'] = (string) $plugin_type->getLabel();
    }
  }

  /**
   * Gets the namespace for the component a class is in.
   *
   * This is either a module namespace, or a core component namespace, e.g.:
   *  - 'Drupal\foo'
   *  - 'Drupal\Core\Foo'
   *  - 'Drupal\Component\Foo'
   *
   * @param string $class_name
   *  The class name.
   *
   * @return string
   *  The namespace.
   */
  protected function getClassComponentNamespace($class_name) {
    $pieces = explode('\\', $class_name);

    if ($pieces[1] == 'Core' || $pieces[1] == 'Component') {
      return implode('\\', array_slice($pieces, 0, 3));
    }
    else {
      return implode('\\', array_slice($pieces, 0, 2));
    }
  }

  /**
   * Gets the body code of a method.
   *
   * @param \ReflectionMethod $method_reflection
   *   The reflection class for the method.
   *
   * @return string
   *   The body of the method's code.
   */
  protected function getMethodBody(\ReflectionMethod $method_reflection) {
    // Get the method's body code.
    // We could use the PHP parser library here rather than sniff with regexes,
    // but it would probably end up being just as much work poking around in
    // the syntax tree.
    $filename = $method_reflection->getFileName();
    $start_line = $method_reflection->getStartLine() - 1; // it's actually - 1, otherwise you wont get the function() block
    $end_line = $method_reflection->getEndLine();
    $length = $end_line - $start_line;
    $file_source = file($filename);
    $body = implode("", array_slice($file_source, $start_line, $length));

    return $body;
  }

}
