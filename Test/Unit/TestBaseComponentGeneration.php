<?php

namespace DrupalCodeBuilder\Test\Unit;

use PHP_CodeSniffer;
use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;


/**
 * Base class for unit tests that generate code and test the result.
 */
abstract class TestBaseComponentGeneration extends TestBase {

  /**
   * The PHP_CodeSniffer instance set up in setUpBeforeClass().
   *
   * @var \PHP_CodeSniffer
   */
  static protected $phpcs;

  /**
   * The PHP CodeSniffer to exclude for this test.
   *
   * @var string[]
   */
  static protected $phpcsExcludedSniffs = [];

  /**
   * Sets up PHPCS.
   */
  public static function setUpBeforeClass() {
    // TODO: move this to setUp().
    // Set runtime config.
    PHP_CodeSniffer::setConfigData(
      'installed_paths',
      __DIR__ . '/../../vendor/drupal/coder/coder_sniffer',
      TRUE
    );

    // Check that the installed standard works.
    //$installedStandards = PHP_CodeSniffer::getInstalledStandards();
    //dump($installedStandards);
    //exit();

    $phpcs = new PHP_CodeSniffer(
      // Verbosity.
      0,
      // Tab width
      0,
      // Encoding.
      'iso-8859-1',
      // Interactive.
      FALSE
    );

    $phpcs->initStandard(
      'Drupal',
      // Include all standards.
      [],
      // Exclude standards defined in the test class.
      static::$phpcsExcludedSniffs
    );

    // Mock a PHP_CodeSniffer_CLI object, as the PHP_CodeSniffer object expects
    // to have this and be able to retrieve settings from it.
    $prophet = new \Prophecy\Prophet;
    $prophecy = $prophet->prophesize();
    $prophecy->willExtend(\PHP_CodeSniffer_CLI::class);
    // No way to set these on the phpcs object.
    $prophecy->getCommandLineValues()->willReturn([
      'reports' => [
        "full" => NULL,
      ],
      "showSources" => false,
      "reportWidth" => null,
      "reportFile" => null
    ]);
    $phpcs_cli = $prophecy->reveal();
    // Have to set these properties, as they are read directly, e.g. by
    // PHP_CodeSniffer_File::_addError()
    $phpcs_cli->errorSeverity = 5;
    $phpcs_cli->warningSeverity = 5;

    // Set the CLI object on the PHP_CodeSniffer object.
    $phpcs->setCli($phpcs_cli);

    static::$phpcs = $phpcs;
  }

  /**
   * Assert a string is correctly-formed PHP.
   *
   * @param $string
   *  The text of PHP to check. This is expected to begin with a '<?php' tag.
   * @param $message = NULL
   *  The assertion message.
   */
  function assertWellFormedPHP($code, $message = NULL) {
    if (!isset($message)) {
      $message = "String evaluates as correct PHP.";
    }

    // Escape all the backslashes. This is to prevent any escaped character
    // sequences from being formed by namespaces and long classes, e.g.
    // 'namespace Foo\testmodule;' will treat the '\t' as a tab character.
    // TODO: find a better way to do this that doesn't involve changing the
    // code.
    $escaped_code = str_replace('\\', '\\\\', $code);

    // Pass the code to PHP for linting.
    $output = NULL;
    $exit = NULL;
    $result = exec(sprintf('echo %s | php -l', escapeshellarg($escaped_code)), $output, $exit);

    if (!empty($exit)) {
      // Dump the code lines as an array so we get the line numbers.
      $code_lines = explode("\n", $code);
      // Re-key it so the line numbers start at 1.
      $code_lines = array_combine(range(1, count($code_lines)), $code_lines);
      dump($code_lines);

      $this->fail("Error parsing the code resulted in: \n" . implode("\n", $output));
    }
  }

  /**
   * Assert that code adheres to Drupal Coding Standards.
   *
   * This runs PHP Code Sniffer using the Drupal Coder module's standards.
   *
   * @param $string
   *  The text of PHP to check. This is expected to begin with a '<?php' tag.
   */
  function assertDrupalCodingStandards($code) {
    // Process the file with PHPCS.
    // We need to pass in a value for the filename, even though the file does
    // not exist, as the Drupal standard uses it to try to check the file when
    // it tries to find an associated module .info file to detect the Drupal
    // major version in DrupalPractice_Project::getCoreVersion(). We don't use
    // the DrupalPractice standard, so that shouldn't concern us, but the
    // Drupal_Sniffs_Array_DisallowLongArraySyntaxSniff sniff calls that to
    // determine whether to run itself. This check for the Drupal code version
    // will fail, which means that the short array sniff will not be run.
    $phpcsFile = static::$phpcs->processFile('fictious file name', $code);

    $error_count   = $phpcsFile->getErrorCount();
    $warning_count = $phpcsFile->getWarningCount();

    $total_error_count = $error_count + $warning_count;

    if (empty($total_error_count)) {
      // No pass method :(
      //$this->pass("PHPCS passed.");
      return;
    }

    // Get the reporting to process the errors.
    $this->reporting = new \PHP_CodeSniffer_Reporting();
    $reportClass = $this->reporting->factory('full');
    // Prepare the report, but don't call generateFileReport() as that echo()s
    // it!
    $reportData  = $this->reporting->prepareFileReport($phpcsFile);
    //$reportClass->generateFileReport($reportData, $phpcsFile);

    // Dump the code lines as an array so we get the line numbers.
    $code_lines = explode("\n", $code);
    // Re-key it so the line numbers start at 1.
    $code_lines = array_combine(range(1, count($code_lines)), $code_lines);
    dump($code_lines);

    foreach ($reportData['messages'] as $line_number => $columns) {
      foreach ($columns as $column_number => $messages) {
        $code_line = $code_lines[$line_number];
        $before = substr($code_line, 0, $column_number - 1);
        $after = substr($code_line, $column_number - 1);
        dump($before . '^' . $after);
        foreach ($messages as $message_info) {
          dump("{$message_info['type']}: line $line_number, column $column_number: {$message_info['message']} - {$message_info['source']}");
        }
      }
    }

    $this->fail("PHPCS failed with $error_count errors and $warning_count warnings.");
  }

  /**
   * Parses a code file string and sets various parser nodes on this test.
   *
   * This populates $this->parser_nodes with groups parser nodes, after
   * resetting it from any previous call to this method.
   *
   * @param string $code
   *   The code file to parse.
   */
  protected function parseCode($code) {
    $parser = (new ParserFactory)->create(ParserFactory::PREFER_PHP7);
    try {
      $ast = $parser->parse($code);
    }
    catch (Error $error) {
      $this->fail("Parse error: {$error->getMessage()}");
    }

    //dump($ast);

    // Reset our array of parser nodes.
    // This then passed into the anonymous visitor class, and populated with the
    // nodes we are interested in for subsequent assertions.
    $this->parser_nodes = [];

    // Group the parser nodes by type, so subsequent assertions can easily
    // find them.
    $visitor = new class($this->parser_nodes) extends NodeVisitorAbstract {

      public function __construct(&$nodes) {
        $this->nodes = &$nodes;
      }

      public function enterNode(Node $node) {
        switch (get_class($node)) {
          case \PhpParser\Node\Stmt\Namespace_::class:
            $this->nodes['namespace'][] = $node;
            break;
          case \PhpParser\Node\Stmt\Use_::class:
            $this->nodes['imports'][] = $node;
            break;
          case \PhpParser\Node\Stmt\Class_::class:
            $this->nodes['classes'][$node->name] = $node;
            break;
          case \PhpParser\Node\Stmt\Interface_::class:
            $this->nodes['interfaces'][$node->name] = $node;
            break;
          case \PhpParser\Node\Stmt\Property::class:
            $this->nodes['properties'][$node->props[0]->name] = $node;
            break;
          case \PhpParser\Node\Stmt\Function_::class:
            $this->nodes['functions'][$node->name] = $node;
            break;
          case \PhpParser\Node\Stmt\ClassMethod::class:
            $this->nodes['methods'][$node->name] = $node;
            break;
        }
      }
    };

    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);

    $ast = $traverser->traverse($ast);
  }

  /**
   * Asserts the parsed code is entirely procedural.
   *
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertIsProcedural($message = NULL) {
    $message = $message ?? "The file contains only procedural code.";

    $this->assertArrayNotHasKey('classes', $this->parser_nodes, $message);
    $this->assertArrayNotHasKey('interfaces', $this->parser_nodes, $message);
    // Technically we should cover traits too, but we don't generate any of
    // those.
  }

  /**
   * Asserts the parsed code imports the given class.
   *
   * @param string[] $class_name
   *   Array of the full class name pieces.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertImportsClassLike($class_name_parts, $message = NULL) {
    // Find the matching import statement.
    $seen = [];
    foreach ($this->parser_nodes['imports'] as $use_node) {
      if ($use_node->uses[0]->name->parts === $class_name_parts) {
        return;
      }

      $seen[] = implode('\\', $use_node->uses[0]->name->parts);
    }

    // Quick and dirty output of the imports that are there, for debugging
    // test failures.
    dump($seen);

    $class_name = implode('\\', $class_name_parts);
    $this->fail("The full class name for the parent class {$class_name} is imported.");
  }

  /**
   * Asserts the parsed code contains the class name.
   *
   * @param string $class_name
   *   The full class name, without the leading \.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertHasClass($full_class_name, $message = NULL) {
    $class_name_parts = explode('\\', $full_class_name);
    $class_short_name = end($class_name_parts);
    $namespace_parts = array_slice($class_name_parts, 0, -1);

    $message = $message ?? "The file contains the class {$full_class_name}.";

    // All the class files we generate contain only one class.
    $this->assertCount(1, $this->parser_nodes['classes']);
    $this->assertArrayHasKey($class_short_name, $this->parser_nodes['classes'], $message);

    // Check the namespace of the class.
    $this->assertCount(1, $this->parser_nodes['namespace']);
    $this->assertEquals($namespace_parts, $this->parser_nodes['namespace'][0]->name->parts, $message);
  }

  /**
   * Asserts the parsed code defines the interface.
   *
   * @param string $full_interface_name
   *   The full interface name, without the leading \.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertHasInterface($full_interface_name, $message = NULL) {
    $interface_name_parts = explode('\\', $full_interface_name);
    $interface_short_name = end($interface_name_parts);
    $namespace_parts = array_slice($interface_name_parts, 0, -1);

    $message = $message ?? "The file contains the interface {$interface_short_name}.";

    // All the class files we generate contain only one class.
    $this->assertCount(1, $this->parser_nodes['interfaces']);
    $this->assertArrayHasKey($interface_short_name, $this->parser_nodes['interfaces']);

    // Check the namespace of the interface.
    $this->assertCount(1, $this->parser_nodes['namespace']);
    $this->assertEquals($namespace_parts, $this->parser_nodes['namespace'][0]->name->parts);
  }

  /**
   * Asserts the parsed code's class extends the given parent class.
   *
   * @param string $class_name
   *   The full parent class name, without the leading \.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertClassHasParent($parent_full_class_name, $message = NULL) {
    $parent_class_name_parts = explode('\\', $parent_full_class_name);

    // There will be only one class.
    $class_node = reset($this->parser_nodes['classes']);
    $class_name = $class_node->name;

    $parent_class_short_name = end($parent_class_name_parts);

    $message = $message ?? "The class {$class_name} has inherits from parent class {$parent_full_class_name}.";

    // Check the class is declared as extending the short parent name.
    $extends_node = $class_node->extends;
    $this->assertTrue($extends_node->isUnqualified(), "The class parent is unqualified.");
    $this->assertEquals($parent_class_short_name, $extends_node->getLast(), $message);

    // Check the full parent name is imported.
    $this->assertImportsClassLike($parent_class_name_parts, $message);
  }

  /**
   * Asserts that the parsed class implements the given interfaces.
   *
   * @param string[] $expected_interface_names
   *   An array of fully-qualified interface names, without the leading '\'.
   */
  function assertClassHasInterfaces($expected_interface_names) {
    // There will be only one class.
    $class_node = reset($this->parser_nodes['classes']);

    $class_node_interfaces = [];
    foreach ($class_node->implements as $implements) {
      $this->assertCount(1, $implements->parts);
      $class_node_interfaces[] = $implements->parts[0];
    }

    foreach ($expected_interface_names as $interface_full_name) {
      $interface_parts = explode('\\', $interface_full_name);

      $this->assertContains(end($interface_parts), $class_node_interfaces);
      $this->assertImportsClassLike($interface_parts);
    }

  }

  /**
   * Assert the parsed class has the given property.
   *
   * @param string $property_name
   *   The name of the property, without the initial '$'.
   * @param string $typehint
   *   The typehint for the property, without the initial '\'.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertClassHasProperty($property_name, $typehint, $message = NULL) {
    $message = $message ?? "The class defines the property \${$property_name}";

    $this->assertArrayHasKey($property_name, $this->parser_nodes['properties'], $message);

    $property_node = $this->parser_nodes['properties'][$property_name];
    $property_docblock = $property_node->getAttribute('comments')[0]->getText();
    $this->assertContains("@var \\{$typehint}", $property_docblock, "The docblock for property \${$property_name} contains the typehint.");
  }

  /**
   * Assert the parsed class injects the given services.
   *
   * @param array $injected_services
   *   Array of the injected services.
   * @param string $message
   *   The assertion message.
   */
  protected function assertInjectedServices($injected_services, $message = NULL) {
    $service_count = count($injected_services);

    // Assert the constructor method.
    $this->assertHasMethod('__construct');
    $construct_node = $this->parser_nodes['methods']['__construct'];

    // Constructor parameters and container extraction much match the same
    // order.
    // Slice the construct method params to the count of services.
    $construct_service_params = array_slice($construct_node->params, - $service_count);
    // Check that the constructor has parameters for all the services, after
    // any basic parameters.
    foreach ($construct_service_params as $index => $param) {
      $this->assertEquals($injected_services[$index]['parameter_name'], $param->name);
    }

    // TODO: should check that __construct() calls its parent, though this is
    // not always the case!

    // Check the class property assignments in the constructor.
    $assign_index = 0;
    foreach ($construct_node->stmts as $stmt_node) {
      if (get_class($stmt_node) == \PhpParser\Node\Expr\Assign::class) {
        $this->assertEquals($injected_services[$assign_index]['property_name'], $stmt_node->var->name);
        $this->assertEquals($injected_services[$assign_index]['parameter_name'], $stmt_node->expr->name);

        $assign_index++;
      }
    }

    // For each service, assert the property.
    foreach ($injected_services as $injected_service_details) {
      $this->assertClassHasProperty($injected_service_details['property_name'], $injected_service_details['typehint']);
    }
  }

  /**
   * Assert the parsed class injects the given services using a static factory.
   *
   * @param array $injected_services
   *   Array of the injected services.
   * @param string $message
   *   The assertion message.
   */
  protected function assertInjectedServicesWithFactory($injected_services, $message = NULL) {
    $service_count = count($injected_services);

    // Assert the create() factory method.
    $this->assertHasMethod('create');
    $create_node = $this->parser_nodes['methods']['create'];
    $this->assertTrue($create_node->isStatic(), "The create() method is static.");

    // This should have a single return statement.
    $this->assertCount(1, $create_node->stmts);
    $return_statement = $create_node->stmts[0];
    $this->assertEquals(\PhpParser\Node\Stmt\Return_::class, get_class($return_statement), "The create() method's statement is a return.");
    $return_args = $return_statement->expr->args;

    // Slice the construct call arguments to the count of services.
    $construct_service_args = array_slice($return_args, - $service_count);

    // After the basic arguments, each one should match a service.
    foreach ($construct_service_args as $index => $arg) {
      // The argument is a method call.
      $this->assertInstanceOf(\PhpParser\Node\Expr\MethodCall::class, $arg->value,
        "The create() method's new call's parameter {$index} is a method call.");
      $method_call_node = $arg->value;

      // Typically, container extraction is a single method call, e.g.
      //   $container->get('foo')
      // but sometimes the call gets something out of the service, e.g.
      //   $container->get('logger.factory')->get('image')
      // PHP Parser sees the final method call first, and the first part as
      // being the var (the thing that is called on). In other words, it parses
      // this right-to-left, unlike humans who (well I do!) parse it
      // left-to-right.
      // So recurse into the method call's var until we get a name.
      $var_node = $method_call_node->var;
      while (get_class($var_node) != \PhpParser\Node\Expr\Variable::class) {
        $var_node = $var_node->var;
      }

      // The argument is a container extraction.
      $this->assertEquals('container', $var_node->name,
        "The create() method's new call's parameter {$index} is a method call on the \$container variable.");
      $this->assertEquals('get', $method_call_node->name,
        "The create() method's new call's parameter {$index} is a method call to get().");
      $this->assertCount(1, $arg->value->args);
      $this->assertEquals($injected_services[$index]['parameter_name'], $arg->value->args[0]->value->value);
    }

    // Assert the constructor and the class properties.
    $this->assertInjectedServices($injected_services, $message);
  }

  /**
   * Asserts that the class construction has the given base parameters.
   *
   * @param $parameters
   *   An array of parameters, in the same format as for
   *   assertMethodHasParameters().
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertConstructorBaseParameters($parameters, $message = NULL) {
    $message = $message ?? "The constructor method has the expected base parameters.";

    $expected_parameter_names = array_keys($parameters);

    $this->assertHasMethod('__construct');
    $this->assertHasMethod('create');

    // Check that the __construct() method has the base parameters before the
    // services.
    $this->assertHelperMethodHasParametersSlice($parameters, '__construct', $message, 0);

    // The first statement in the __construct() should be parent call, with
    // the base parameters.
    $parent_call_node = $this->parser_nodes['methods']['__construct']->stmts[0];
    $this->assertInstanceOf(\PhpParser\Node\Expr\StaticCall::class, $parent_call_node);
    $this->assertEquals('parent', $parent_call_node->class->parts[0]);
    $this->assertEquals('__construct', $parent_call_node->name);
    $call_arg_names = [];
    foreach ($parent_call_node->args as $arg) {
      $call_arg_names[] = $arg->value->name;
    }
    $this->assertEquals(array_keys($parameters), $call_arg_names, "The call to the parent constructor has the base parameters.");

    // The only statement in the create() method should return the new object.
    $create_node = $this->parser_nodes['methods']['create'];

    $object_create_node = $this->parser_nodes['methods']['create']->stmts[0];
    // Slice the construct call arguments to the given parameters.
    $construct_base_args = array_slice($create_node->stmts[0]->expr->args, 0, count($parameters));
    $create_arg_names = [];

    foreach ($construct_base_args as $index => $arg) {
      // Recurse into the arg until we get a name, to account for args which
      // are a method call on the container.
      $arg_value_node = $arg->value;

      if (get_class($arg_value_node) == \PhpParser\Node\Expr\Variable::class) {
        // Plain variable.
        $this->assertEquals($expected_parameter_names[$index], $arg_value_node->name,
          "The create() method's return statement's argument {$index} is the variable \${$arg_value_node->name}.");
      }
      else {
        //dump($arg_value_node);
        // TODO! check a constainer extraction.
      }
    }
  }

  /**
   * Assert the parsed code contains the given function.
   *
   * @param string $function_name
   *   The function name to check for.
   * @param string $message
   *   (optional) The assertion message.
   */
  function assertHasFunction($function_name, $message = NULL) {
    $message = $message ?? "The file contains the function {$function_name}.";

    $this->assertArrayHasKey($function_name, $this->parser_nodes['functions'], $message);
  }

  /**
   * Assert the parsed code contains the given method.
   *
   * @param string $method_name
   *   The method name to check for.
   * @param string $message
   *   (optional) The assertion message.
   */
  function assertHasMethod($method_name, $message = NULL) {
    $message = $message ?? "The file contains the method {$method_name}.";

    $this->assertArrayHasKey($method_name, $this->parser_nodes['methods'], $message);
  }

  /**
   * Asserts a method of the parsed class has the given parameters.
   *
   * @param $parameters
   *   An array of parameters: keys are the parameter names, values are the
   *   typehint, with NULL for no typehint.
   * @param string $method_name
   *   The method name.
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertMethodHasParameters($parameters, $method_name, $message = NULL) {
    $expected_parameter_names = array_keys($parameters);

    $parameter_names_string = implode(", ", $expected_parameter_names);
    $message = $message ?? "The method {$method_name} has the parameters {$parameter_names_string}.";

    $this->assertHelperMethodHasParametersSlice($parameters, '__construct', $message, 0);
  }

  /**
   * Asserts a method of the parsed class has the given parameters.
   *
   * Helper for assertMethodHasParameters() and other assertions.
   *
   * @param $parameters
   *   An array of parameters: keys are the parameter names, values are the
   *   typehint, with NULL for no typehint.
   * @param string $method_name
   *   The method name.
   * @param integer $offset
   *   (optional) The array slice offset in the actual parameters.
   * @param string $message
   *   (optional) The assertion message.
   */
  private function assertHelperMethodHasParametersSlice($parameters, $method_name, $message = NULL, $offset = 0) {
    $expected_parameter_names = array_keys($parameters);
    $expected_parameter_typehints = array_values($parameters);

    $parameter_names_string = implode(", ", $expected_parameter_names);
    $message = $message ?? "The method {$method_name} has the parameters {$parameter_names_string} in positions ... TODO.";

    //dump($this->parser_nodes['methods'][$method_name]);

    // Get the actual parameter names.
    $param_nodes_slice = array_slice($this->parser_nodes['methods'][$method_name]->params, $offset, count($parameters));

    $actual_parameter_names_slice = [];
    $actual_parameter_types_slice = [];
    foreach ($param_nodes_slice as $index => $param_node) {
      $actual_parameter_names_slice[] = $param_node->name;

      if (is_null($param_node->type)) {
        $actual_parameter_types_slice[] = NULL;
      }
      elseif (is_string($param_node->type)) {
        $actual_parameter_types_slice[] = $param_node->type;
      }
      else {
        // PHP CodeSniffer will have already caught a non-imported class, so
        // safe to assume there is only one part to the class name.
        $actual_parameter_types_slice[] = $param_node->type->parts[0];

        $expected_typehint_parts = explode('\\', $expected_parameter_typehints[$index]);

        // Check the full expected typehint is imported.
        $this->assertImportsClassLike($expected_typehint_parts, "The typehint for the {$index} parameter is imported.");

        // Replace the fully-qualified name with the short name in the
        // expectations array for comparison.
        $expected_parameter_typehints[$index] = end($expected_typehint_parts);
      }
    }

    $this->assertEquals($expected_parameter_names, $actual_parameter_names_slice, $message);

    $this->assertEquals($expected_parameter_typehints, $actual_parameter_types_slice, $message);
  }

  /**
   * Assert the parsed code contains the given methods.
   *
   * @param string[] $method_name
   *   An array of method names to check for.
   * @param string $message
   *   (optional) The assertion message.
   */
  function assertHasMethods($method_names, $message = NULL) {
    $method_names_string = implode(", ", $method_names);
    $message = $message ?? "The file contains the methods {$method_names_string}.";

    $this->assertArraySubset($method_names, array_keys($this->parser_nodes['methods']), $message);
  }

  /**
   * Assert the parsed code contains no methods.
   *
   * @param string $message
   *   (optional) The assertion message.
   */
  protected function assertHasNoMethods($message = NULL) {
    $message = $message ?? "The file contains no methods.";
    $this->assertArrayNotHasKey('methods', $this->parser_nodes, $message);
  }

  /**
   * Assert the parsed code implements the given hook.
   *
   * Also checks the hook implementation docblock has the correct text.
   *
   * @param string $hook_name
   *   The full name of the hook to check for, e.g. 'hook_help'.
   * @param string $message
   *   (optional) The assertion message.
   */
  function assertHasHookImplementation($hook_name, $module_name, $message = NULL) {
    $message = $message ?? "The code has a function that implements the hook $hook_name for module $module_name.";

    $hook_short_name = substr($hook_name, 5);
    $function_name = $module_name . '_' . $hook_short_name;

    $this->assertHasFunction($function_name, $message);

    // Use the older assertHookDocblock() assertion, but pass it just the
    // docblock contents rather than the whole file!
    $function_node = $this->parser_nodes['functions'][$function_name];
    $comments = $function_node->getAttribute('comments');

    // Workaround for issue with PHP Parser: if the function is the first in the
    // file, and there are no import statements, then the @file docblock will
    // be treated as one of the function's comments. Therefore, we need to take
    // the last comment in the array to be sure of having the actual function
    // docblock.
    // @see https://github.com/nikic/PHP-Parser/issues/445
    $function_docblock = end($comments);
    $docblock_text = $function_docblock->getReformattedText();
    $this->assertHookDocblock($hook_name, $docblock_text, "The module file contains the docblock for hook_menu().");
  }

}