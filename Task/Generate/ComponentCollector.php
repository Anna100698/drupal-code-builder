<?php

namespace DrupalCodeBuilder\Task\Generate;

use DrupalCodeBuilder\Definition\GeneratorDefinition;
use DrupalCodeBuilder\Environment\EnvironmentInterface;
use DrupalCodeBuilder\ExpressionLanguage\AcquisitionExpressionLanguageProvider;
use DrupalCodeBuilder\Generator\Collection\ComponentCollection;
use DrupalCodeBuilder\MutableTypedData\DrupalCodeBuilderDataItemFactory;
use DrupalCodeBuilder\Generator\RootComponent;
use MutableTypedData\Data\DataItem;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use DrupalCodeBuilder\Generator\BaseGenerator;
use DrupalCodeBuilder\Generator\GeneratorInterface;

/**
 * Task helper for collecting components recursively.
 *
 * This takes a structured array of data for the root component, and produces
 * an array of components, that is, instantiated Generator objects. These are
 * added to the ComponentCollection object, which keeps track of the
 * relationships between them.
 *
 * Before being passed to instantiated components, the data is processed in
 * various ways:
 *  - data values can be acquired from the component that requested the current
 *    one.
 *  - preset values are expanded.
 *  - default values are filled in.
 *  - 'processing' callbacks are called for each property.
 *
 * Component data arrays have their structure defined by data property info
 * arrays, which each Generator class defines in its componentDataDefinition()
 * static method.
 *
 * The component data array for the root component, and any subsequent component
 * data arrays, can produce generators in three ways:
 * - The actual generator class for the data array in question; in other words,
 *   a data array for a Module or a Permission will try to get a generator of
 *   that type. ('Try' because of duplicates and merging: see below.)
 * - Properties in the structured component data can be declared in the property
 *   info as having a component themselves, with the 'component_type' key. This
 *   will cause the value of this single property to be expanded and, upon
 *   suitable treatment, passed back in as a component data array in its own
 *   right. The nature of the expansion and treatment depends on the format of
 *   the property:
 *   - boolean: This simply represents whether the subcomponent exists or not.
 *   - array: Each value in the array produces a component.
 *   - compound: The data is an array keyed by delta, where each value is itself
 *     a component data array.
 * - Once a component is instantiated, its requiredComponents() method is
 *   called, which returns an array where each value is a component data array.
 *   Here, the component type is given in the data itself, in the
 *   'component_type' property.
 *
 * It is possible for the attempted creation of a component to not produce a
 * new component, if the ComponentCollection determines that the new component
 * is in fact a duplicate of one it already has. This checking is done by
 * ComponentCollection::getMatchingComponent(), which compares the match tag,
 * component type, and closest root component. If this happens, there are two
 * possible outcome:
 * - The request data array for the new component and the existing one are
 *   identical. Nothing further happens and the new component is discarded. An
 *   alias is added to the ComponentCollection.
 * - The request data arrays are different. The new request data array is merged
 *   into the existing component. The process continues with the existing
 *   component, allowing it to request data. This is done even though the
 *   component will have previously done this when it was instantiated, because
 *   with the new data, it may request further components.
 */
class ComponentCollector {

  /**
   * Whether debug mode is enabled.
   *
   * TODO: Make this use an environment settings so UIs can pass it in.
   *
   * @var boolean
   */
  const DEBUG = FALSE;

  /**
   * The components collected by the process.
   */
  protected $component_collection;

  /**
   * The record of requested data, keyed by generator ID.
   *
   * This allows us to prevent creation of duplicate generators which may be
   * requested by different things.
   */
  protected $requested_data_record = [];

  /**
   * The expression language to use for acquiring data from requesters.
   *
   * Acquiring data uses a separate expression language from the typed data
   * defaults system, because it doesn't need the Javascript compatibility, and
   * instead needs a dedicated custom function for handling the
   * root_name/root_component_name switcheroo,.
   *
   * @var \Symfony\Component\ExpressionLanguage\ExpressionLanguage
   */
  protected $acquisitionExpressionLanguage;

  /**
   * Constructs a new ComponentCollector.
   *
   * @param EnvironmentInterface $environment
   *   The environment object.
   * @param ComponentClassHandler $class_handler
   *   The class handler helper.
   * @param ComponentDataInfoGatherer $data_info_gatherer
   *   The data info gatherer helper.
   */
  public function __construct(
    EnvironmentInterface $environment,
    ComponentClassHandler $class_handler,
    ComponentDataInfoGatherer $data_info_gatherer
  ) {
    $this->environment = $environment;
    $this->classHandler = $class_handler;
    $this->dataInfoGatherer = $data_info_gatherer;
  }

  /**
   * Get the list of required components for an initial request.
   *
   * This iterates down the tree of component requests: starting with the root
   * component, each component may request further components, and then those
   * components may request more, and so on.
   *
   * Generator classes should implement requiredComponents() to return the list
   * of component types they require, possibly depending on incoming data.
   *
   * Obviously, it's important that eventually this process terminate with
   * generators that return an empty array for requiredComponents().
   *
   * @param $component_data
   *  The requested component data.
   *
   * @return \DrupalCodeBuilder\Generator\Collection\ComponentCollection
   *  The collection of components.
   */
  public function assembleComponentList(DataItem $component_data): ComponentCollection {
    // Reset all class properties. We don't normally run this twice, but
    // probably needed for tests.
    $this->requested_data_record = [];

    $this->component_collection = new \DrupalCodeBuilder\Generator\Collection\ComponentCollection;

    // Fiddly different handling for the type in the root component...
    // $component_type = $component_data->getMachineName();
    // $component_data['component_type'] = $component_type;

    // The name for the root component is its root name, which will be the
    // name of the Drupal extension.
    $this->getComponentsFromData($component_data, NULL);

    // $this->component_collection->dumpStructure();

    return $this->component_collection;
  }

  /**
   * Create components from a data item.
   *
   * Provided this data does not duplicate already created components, the
   * populates the $this->component_collection property with:
   * - The component itself given by the component_type property.
   * - Components that are specified by properties which themselves have
   *   component_type set.
   * - Components which the component itself requests in its
   *   requiredComponents() method.
   *
   * @param $name
   *   The name of the component in a containing array.
   * @param $component_data
   *   The data array. This must contain at least a 'component_type' property
   *   the gives the type of the component.
   * @param $requesting_component
   *   The generator that is in scope when the components are requested, or
   *   NULL if this is the first iteration and we are building the root
   *   component.
   *
   * @return
   *   The new generator that the top level of the data array is requesting, or
   *   NULL if the data is a duplicate set. Note that nothing needs to be done
   *   with the return; the generator gets added to $this->component_collection.
   */
  protected function getComponentsFromData(DataItem $component_data, ?GeneratorInterface $requesting_component) {
    $name = $component_data->getName();

    // Prepend the parent name to array data items, as their name is just the
    // delta and that's not unique within the requester.
    if (is_numeric($name)) {
      $name = $component_data->getParent()->getName() . '_' . $name;
    }

    dump(sprintf("STARTING getComponentsFromData with %s %s requested by %s",
      $name,
      $component_data->getAddress(),
      $requesting_component ? $requesting_component->component_data->getAddress() : 'nothing'
    ));

    // dump("getComponentsFromDataItem");
    // dump($component_data->export());
    // dump($name);



    // Debugging: record the chain of how we get here each time.
    static $chain;
    $chain[] = $name;
    // $this->debug($chain, "collecting {$component_data['component_type']} $name", '-');

    $component_type = $component_data->getComponentType();
    // $component_data_info = $this->dataInfoGatherer->getComponentDataInfo($component_type, TRUE);

    // Acquire data from the requesting component. We call this even if there
    // isn't a requesting component, as in that case, an exception is thrown
    // if an acquisition is attempted.
    // NOT NEEDED - can be done with defaults??
    $this->acquireDataFromRequestingComponent($component_data, $requesting_component);

    // Process the component's data.
    // dump("GOING TO MAKE $component_type with this data:");
    // dump($component_data->export());
    // dump(array_keys($component_data->getProperties()));
    // TODO: restore!
    // TODO: ARGH this causes repeat walking, since this is called on each
    // instantiated thing, but THEN WE WALK IT!!!
    $this->processComponentData($component_data);

    // Instantiate the generator in question.
    // We always pass in the root component.
    // We need to ensure that we create the root generator first, before we
    // recurse, as all subsequent generators need it.
    $generator = $this->classHandler->getGenerator($component_type, $component_data);

    $this->debug($chain, "instantiated name $name; type: $component_type; ID");

    $existing_matching_component = $this->component_collection->getMatchingComponent($generator, $requesting_component);
    if ($existing_matching_component) {
      // There is a matching component already in the collection.
      // We determine whether our data needs to be merged in with it.
      $this->debug($chain, "record has matching existing component name $name; type: $component_type");

      $differences_merged = $existing_matching_component->mergeComponentData($component_data);

      if (!$differences_merged) {
        // There was nothing new in our component's data that needed to be
        // merged into the existing one. We give up on this current component,
        // as it's identical to one already in the collection.
        $this->debug($chain, "bailing on name $name; type: $component_type");
        array_pop($chain);

        $this->component_collection->addAliasedComponent($name, $existing_matching_component, $requesting_component);

        return;
      }

      // If it already exists, we merge the received data in with the existing
      // component, and use the existing generator instead. We now carry on with
      // the existing component, getting required components  from it again, as
      // it may need further components based on the data it has just been
      // given.
      $generator = $existing_matching_component;

      $this->component_collection->addAliasedComponent($name, $generator, $requesting_component);
    }
    else {
      // Add the new component to the collection of components.
      $this->component_collection->addComponent($name, $generator, $requesting_component);
    }

    // We're now ready to look at components which the current component
    // spawns.
    // Assemble a list of all the local names to guard against clashes.
    $local_names = [];

    // Pick out any data properties which are components themselves, and create the
    // child components.
    foreach ($component_data as $item_name => $data_item) {
      // dump("spawn $item_name?");
      // We're only interested in component properties.
      if (!($data_item->getDefinition() instanceof GeneratorDefinition)) {
        // dump("not spawning $item_name - not generator.");
        continue;
      }

      if ($data_item->isEmpty()) {
        // dump("not spawning $item_name - data is empty.");
        continue;
      }

      $item_component_type = $data_item->getComponentType();
      if ($data_item->getType() == 'boolean') {
        if (!$data_item->value) {
          // dump("not spawning $item_name - boolean FALSE.");
          continue;
        }

        // Filthy hack.
        // This is because on the one hand, a boolean property is just a boolean
        // because that's what the UI needs, but the component itself has
        // various properties that it expects to acquire.
        $definition =  $this->classHandler->getComponentPropertyDefinition($item_component_type, $item_name);
        $data_item = DrupalCodeBuilderDataItemFactory::createFromDefinition($definition);

        // dump("switcheroo data item for boolean $item_name.");
      }

      // dump("YES spawn $item_name?");
      $this->debug($chain, "spawning $item_name; type: $item_component_type");


      // Get the component type.
      // TODO: mutable types might have different component type per variant!!

      if ($data_item->isMultiple()) {
        foreach ($data_item as $delta_item) {
          $this->getComponentsFromData($delta_item, $generator);
        }
      }
      else {
        $this->getComponentsFromData($data_item, $generator);
      }
    }

    /*
    foreach ($component_data_info as $property_name => $property_info) {
      // We're only interested in component properties.
      if (!isset($property_info['component_type'])) {
        continue;
      }
      // Only work with properties for which there is data.
      if (empty($component_data[$property_name])) {
        continue;
      }

      // Get the component type.
      $item_component_type = $property_info['component_type'];

      switch ($property_info['format']) {
        case 'compound':
          // Create a component for each delta item.
          foreach ($component_data[$property_name] as $delta => $item_data) {
            $item_data['component_type'] = $item_component_type;

            // Form an item request name with the delta.
            $item_request_name = "{$item_component_type}_{$delta}";

            $local_names[$item_request_name] = TRUE;

            // Recurse to create the component (and any child components, and
            // any requests).
            $property_component = $this->getComponentsFromData($item_request_name, $item_data, $generator);
          }
          break;
        case 'boolean':
          // We use the type as the request name; would it make more sense to
          // use the property name?
          $item_request_name = $item_component_type;
          $item_data = [
            'component_type' => $item_component_type,
          ];

          $local_names[$item_request_name] = TRUE;

          $property_component = $this->getComponentsFromData($item_request_name, $item_data, $generator);
          break;
        case 'array':
          // The value in the array is set to the component's primary property.
          // Find the primary property.
          $child_component_data_info = $this->dataInfoGatherer->getComponentDataInfo($item_component_type, TRUE);
          $primary_property = NULL;
          foreach ($child_component_data_info as $child_property_name => $child_property_info) {
            if (!empty($child_property_info['primary'])) {
              $primary_property = $child_property_name;
              break;
            }
          }
          assert(!is_null($primary_property), "No primary property found for array format property.");

          foreach ($component_data[$property_name] as $item_value) {
            $item_data = [
              'component_type' => $item_component_type,
              // Set the value from the array to the primary property.
              $primary_property => $item_value,
            ];
            // Use the value in the array as the local name.
            $item_request_name = $item_value;

            $local_names[$item_request_name] = TRUE;

            $property_component = $this->getComponentsFromData($item_request_name, $item_data, $generator);
          }
          break;
      }
    }
    */

    // Ask the generator for its required components.
    // TODO: lots to figure out here!
    $item_required_subcomponent_list = $generator->requiredComponents();

    // Collect the resulting components so we can set IDs for containment.
    $required_components = [];

    // Each item in the list is itself a component data array. Recurse for each
    // one to get generators.
    foreach ($item_required_subcomponent_list as $required_item_name => $required_item_data) {
      dump("Converting $required_item_name");
      // Conversion to data items!
      if (is_array($required_item_data)) {
        $definition =  $this->classHandler->getComponentPropertyDefinition($required_item_data['component_type'], $required_item_name);
        // $definition->setMachineName($required_item_name);

        unset($required_item_data['component_type']);

        $required_item_data_item = DrupalCodeBuilderDataItemFactory::createFromDefinition($definition);

        $required_item_data_item->set($required_item_data);

        $required_item_data = $required_item_data_item;

        // ARGH but the name is wrong!
        // problem here is that MTB thinks of the name as set in the definition
        // and unchangeable and here it's all fluffy and per-item.

//         we NEED per-item because:

//         "Converting %module.permissions.yml"
// "STARTING getComponentsFromData with ymlfile ymlfile requested by module:permissions:0"
// "adding 252 with local name ymlfile"

//         we could be adding more than one local ymlfile data item!
      }



      // Validate so defaults are filled in.
      $required_item_data->validate();

      // dump("VALIDATE!");
      // dump($required_item_data->export());
      // dump(array_keys($required_item_data->getProperties()));


      // Guard against a clash of required item key.
      // In other words, a key in requiredComponents() can't be the same as a
      // property name.
      if (isset($local_names[$required_item_name])) {
        throw new \Exception("$required_item_name already used as a required item key.");
      }

      $local_names[$required_item_name] = TRUE;

      // dump($required_item_data);

      $main_required_component = $this->getComponentsFromData($required_item_data, $generator);
      $required_components[$required_item_name] = $main_required_component;
    }

    $this->debug($chain, "done");
    array_pop($chain);

    return $generator;
  }

  /**
   * Acquire additional data from the requesting component.
   *
   * Helper for getComponentsFromData().
   *
   * @param &$component_data
   *  The component data array. On the first call, this is the entire array; on
   *  recursive calls this is the local data subset.
   * @param $component_data_info
   *  The component data info for the data being processed.
   * @param $requesting_component
   *  The component data info for the component that is requesting the current
   *  data, or NULL if there is none.
   *
   * @throws \Exception
   *   Throws an exception if a property has the 'acquired' attribute, but
   *   there is no requesting component present.
   */
  protected function acquireDataFromRequestingComponent(DataItem $component_data, $requesting_component) {
    // dump("acquireDataFromRequestingComponent! -- " . $component_data->getMachineName());
    // dump($component_data->getDefinition());

    if (!isset($this->acquisitionExpressionLanguage)) {
      $this->acquisitionExpressionLanguage = new ExpressionLanguage();
      $this->acquisitionExpressionLanguage->registerProvider(new AcquisitionExpressionLanguageProvider());
    }

    // Initialize a map of property acquisition aliases in the requesting
    // component. This is lazily computed if we find no other way to find the
    // acquired property.
    $requesting_component_alias_map = NULL;

    // Allow the new generator to acquire properties from the requester.
    foreach ($component_data->getProperties() as $property_name => $property_info) {
      if (!$property_info->getAcquiringExpression()) {
        continue;
      }

      if (!$requesting_component) {
        throw new \Exception("Component $name needs to acquire property '$property_name' but there is no requesting component.");
      }

      $expression = $property_info->getAcquiringExpression();
      $add = $component_data->getAddress();
      // dump("ACQUIRING for $add - $property_name on with '$expression'");
      // dump($expression);
      // dump($requesting_component->component_data->export());
      // dump(get_class($requesting_component->component_data));

      try {
        $acquired_value = $this->acquisitionExpressionLanguage->evaluate($expression, [
          'requester' => $requesting_component->component_data,
        ]);
      }
      catch (\RuntimeException $e) {
        dump("Unable to evaluate expression '$expression'.");
        dump($requesting_component->component_data->export());
        throw $e;
      }
      catch (\MutableTypedData\Exception\InvalidAccessException $e) {
        dump("Unable to evaluate expression '$expression'.");
        dump($requesting_component->component_data->export());
        throw $e;
      }
      // dump($acquired_value);

      $component_data->{$property_name}->set($acquired_value);
    }
  }

  /**
   * Recursively process component data prior instantiating components.
   *
   * Performs final processing for the component data:
   *  - sets default values on empty properties. To prevent a default being set
   *    and keep the component a property represents absent, set it to FALSE.
   *  - sets values forced or suggested by other properties' presets.
   *  - performs additional processing that a property may require
   *
   * @param DataItem $component_data
   *  The component data. On the first call, this is the entire array; on
   *  recursive calls this is the local data subset.
   */
  protected function processComponentData(DataItem $component_data) {
    $component_data->walk([$this, 'forceCreateChildren']);

    // Only set presets once, when processing the root data.
    if (!$component_data->getParent()) {
      $component_data->walk([$this, 'setPresetValues']);
    }

    $component_data->walk([$this, 'applyProcessing']);

    return;

    // Work over each property.
    foreach ($component_data_info as $property_name => &$property_info) {
      // Set defaults for properties that don't have a value yet.
      // NO- defaults are done earlier by validation.
      // $this->setComponentDataPropertyDefault($property_name, $property_info, $component_data);

      // Set values from a preset.
      // We do this after defaults, so preset properties can have a default
      // value. Note this means that presets can only affect properties that
      // come after them in the property info order.
      if (isset($property_info['presets'])) {
        $this->setPresetValues($property_name, $component_data_info, $component_data);
      }

      // Allow each property to apply its processing callback. Note that this
      // may set or alter other properties in the component data array, and may
      // also make changes to the property info.
      $this->applyComponentDataPropertyProcessing($property_name, $property_info, $component_data);
    }
    // Clear the loop reference, otherwise PHP does Bad Things.
    unset($property_info);

    // Recurse into compound properties.
    // We do this last to allow the parent property to have default and
    // processing applied to the child data as a whole.
    // (TODO: test this!)
    foreach ($component_data_info as $property_name => $property_info) {
      // Only work with compound properties.
      if ($property_info['format'] != 'compound') {
        continue;
      }

      // Don't work with component child properties, as the generator will
      // handle this.
      if (isset($property_info['component_type'])) {
        continue;
      }

      if (!isset($component_data[$property_name])) {
        // Skip if no data for this property.
        continue;
      }

      foreach ($component_data[$property_name] as $delta => &$item_data) {
        $this->processComponentData($item_data, $property_info['properties']);
      }
    }
  }

  /**
   * Walk callback to forcibly create values if needed.
   *
   * @param DataItem $component_data
   *  The component data item.
   */
  public function forceCreateChildren(DataItem $component_data) {
    foreach ($component_data->getProperties() as $property_name => $property_definition) {
      if (!$property_definition->getForceCreate()) {
        continue;
      }

      if ($component_data->isMultiple()) {
        foreach ($component_data as $delta_item) {
          // Access the property to instantiate its value.
          $delta_item->{$property_name};
        }
      }
      else {
        // Access the property to instantiate its value.
        $component_data->{$property_name};
      }
    }
  }

  /**
   * Walk callback to set values from a presets property into other properties.
   *
   * @param DataItem $component_data
   *  The component data item.
   */
  public function setPresetValues(DataItem $component_data) {
    // Bail if this is not a data item that has presets.
    if (!$component_data->getPresets()) {
      return;
    }

    // Bail if this data item has no value set.
    if ($component_data->isEmpty()) {
      return;
    }

    $presets = $component_data->getPresets();
    // dump("APPLYING PRESETS FOR " . $component_data->getAddress());
    // dump($presets);
    // dump($component_data);

    // Values which are forced by the preset.
    foreach ($component_data as $preset_item) {
      // dump("DOING " . $preset_item->value);
      $preset_item_preset_data = $presets[$preset_item->value];
      // dump($preset_item_preset_data);
      if (isset($preset_item_preset_data['data']['force'])) {
        foreach ($preset_item_preset_data['data']['force'] as $forced_property_name => $forced_data) {
          // dump("FORCING $forced_property_name at address: - ");
          // dump($component_data->getParent()->{$forced_property_name}->getAddress());
          // dump($forced_data['value']);
          // Literal value. (This is the only type we support at the moment.)
          if (isset($forced_data['value'])) {
            // Append values rather than replacing them, as for a multi-valued
            // preset, different presets may provide different values for a
            // multi-valued target property, which should all be merged.
            $component_data->getParent()->{$forced_property_name}->add($forced_data['value']);
          }
        }
      }
    }

    // dump($component_data->getParent()->export());
    // exit();
    // ARGH it's worked but TOO LATE after the defaults were set!?!?!? WTF

    return;





    // Treat the selected preset as an array, even if it's single-valued.
    if ($component_data->isMultiple()) {
      // TODO: argh, what's the way to get a flat array of multiple scalar values?
      $preset_keys = $component_data->export();
      $multiple_presets = TRUE;
    }
    else {
      $preset_keys = [$component_data->value];
      $multiple_presets = FALSE;
    }

    // First, gather up the values that the selected preset items want to set.
    // We will then apply them to the data.
    $forced_values = [];
    $suggested_values = [];

    foreach ($preset_keys as $preset_key) {
      $selected_preset_info = $presets[$preset_key];

      // Ensure these are both preset as at least empty arrays.
      // TODO: handle filling this in in ComponentDataInfoGatherer?
      // TODO: testing should cover that it's ok to have these absent.
      $selected_preset_info['data'] += [
        'force' => [],
        'suggest' => [],
      ];

      // Values which are forced by the preset.
      foreach ($selected_preset_info['data']['force'] as $forced_property_name => $forced_data) {
        // Literal value. (This is the only type we support at the moment.)
        if (isset($forced_data['value'])) {
          $this->collectPresetValue(
            $forced_values,
            $multiple_presets,
            $forced_property_name,
            $component_data_info[$forced_property_name],
            $forced_data['value']
          );
        }

        // TODO: processed value, using chain of processing instructions.
      }

      // Values which are only suggested by the preset.
      foreach ($selected_preset_info['data']['suggest'] as $suggested_property_name => $suggested_data) {
        // Literal value. (This is the only type we support at the moment.)
        if (isset($suggested_data['value'])) {
          $this->collectPresetValue(
            $suggested_values,
            $multiple_presets,
            $suggested_property_name,
            $component_data_info[$suggested_property_name],
            $suggested_data['value']
          );
        }

        // TODO: processed value, using chain of processing instructions.
      }
    }

    // Set the collected data values into the actual component data, if
    // applicable.
    foreach ($forced_values as $forced_property_name => $forced_data) {
      $component_data_local[$forced_property_name] = $forced_data;
    }

    foreach ($suggested_values as $suggested_property_name => $suggested_data) {
      // Suggested values from the preset only get set if there is nothing
      // already set there in the incoming data.
      // TODO: boolean FALSE counts as non-empty for a boolean property in
      // other places! ARGH!
      if (!empty($component_data_local[$suggested_property_name])) {
        continue;
      }

      $component_data_local[$suggested_property_name] = $suggested_data;
    }
  }

  /**
   * Collect values for presets.
   *
   * Helper for setPresetValues(). Merges values from multiple presets so that
   * they can be set into the actual component data in one go.
   *
   * @param array &$collected_data
   *   The data collected from presets so far. Further data for this call should
   *   be added to this.
   * @param bool $multiple_presets
   *   Whether the current preset property is multi-valued or not.
   * @param string $target_property_name
   *   The name of the property to set from the preset.
   * @param array $target_property_info
   *   The property info array of the target property.
   * @param mixed $preset_property_data
   *   The value from the preset for the target property.
   */
  protected function collectPresetValue(
    &$collected_data,
    $multiple_presets,
    $target_property_name,
    $target_property_info,
    $preset_property_data
  ) {
    // Check the format of the value is compatible with multiple presets.
    if ($multiple_presets) {
      if (!in_array($target_property_info['format'], ['array', 'compound'])) {
        // TODO: check cardinality is allowable as well!
        // TODO: give the preset name! ARGH another parameter :(
        throw new \Exception("Multiple presets not compatible with single-valued properties, with target property {$target_property_name}.");
      }
    }

    if (isset($collected_data[$target_property_name])) {
      // Merge the data. The check above should have covered that this is
      // allowed.
      $collected_data[$target_property_name] = array_merge($collected_data[$target_property_name], $preset_property_data);
    }
    else {
      $collected_data[$target_property_name] = $preset_property_data;
    }
  }

  /**
   * Set the default value for a property in component data.
   *
   * Helper for processComponentData().
   *
   * @param $property_name
   *  The name of the property. For child properties, this is the name of just
   *  the child property.
   * @param $property_info
   *  The property info array for the property.
   * @param &$component_data_local
   *  The array of component data, or for child properties, the item array that
   *  immediately contains the property. In other words, this array would have
   *  a key $property_name if data has been supplied for this property.
   */
  protected function setComponentDataPropertyDefault($property_name, $property_info, &$component_data_local) {
    // Determine whether we should fill in a default value.
    if (isset($property_info['component_type'])) {
      // No need to set defaults on components here; the defaults will be filled
      // in when the component is instantiated in assembleComponentList() and
      // and this is called with the component's own data.
      // TODO? Still true?
      return;
    }

    // If the user has provided a default, don't clobber that.
    if (!empty($component_data_local[$property_name])) {
      return;
    }
    // For boolean properties, the value might be FALSE, and this counts as
    // being specified.
    if ($property_info['format'] == 'boolean' && isset($component_data_local[$property_name])) {
      return;
    }

    if (empty($property_info['process_default']) &&
      empty($property_info['computed']) &&
      empty($property_info['internal'])
    ) {
      // Allow an empty value to remain empty if the property is neither:
      //  - computed: this never gets shown to the user, so we must provide a
      //    default always.
      //  - internal: we always want our own defaults to be processed.
      //  - process_default: this forces a default value, effectively
      //    preventing a property from being left empty.
      //  For array properties, set an empty array for the benefit of iterators.
      //  (This is mostly for tests, as UIs will bring in the empty array that
      //  is set in by ComponentPropertyPreparer.)
      if ($property_info['format'] == 'array' || $property_info['format'] == 'compound') {
        $component_data_local[$property_name] = [];
      }
      return;
    }

    if (isset($property_info['default'])) {
      if (is_callable($property_info['default'])) {
        $default_callback = $property_info['default'];
        $default_value = $default_callback($component_data_local);
      }
      else {
        $default_value = $property_info['default'];
      }
      $component_data_local[$property_name] = $default_value;
    }
  }

  /**
   * Walk callback to apply processing callbacks to component data.
   *
   * Note that 'process_empty' is handled earlier, as empty values won't
   * necessarily exist to get this walk callback applied.
   *
   * @param DataItem $component_data
   *  The component data item.
   */
  public function applyProcessing(DataItem $component_data) {
    $processing = $component_data->getProcessing();

    if (!$processing) {
      // No processing: nothing to do.
      return;
    }

    // dump("PROCESS - " . $component_data->getAddress());
    // dump($component_data->export());

    $processing($component_data);
    // dump($component_data->export());
  }

  /**
   * Output debug message, with indentation for the current iteration.
   *
   * @param string[] $chain
   *   The current chain of component names.
   * @param string $message
   *   A message to output.
   */
  protected function debug($chain, $message, $indent_char = ' ') {
    if (!self::DEBUG) {
      return;
    }

    dump(str_repeat($indent_char, count($chain)) . ' ' . implode(':', $chain) . ': ' . $message);
  }

}
