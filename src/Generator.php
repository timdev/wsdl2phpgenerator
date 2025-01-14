<?php
/**
 * @package Wsdl2PhpGenerator
 */

/**
 * Include the needed files
 */
require_once dirname(__FILE__).'/Config.php';
require_once dirname(__FILE__).'/Validator.php';
require_once dirname(__FILE__).'/Variable.php';
require_once dirname(__FILE__).'/Enum.php';
require_once dirname(__FILE__).'/ComplexType.php';
require_once dirname(__FILE__).'/Pattern.php';
require_once dirname(__FILE__).'/DocumentationManager.php';
require_once dirname(__FILE__).'/Service.php';
require_once dirname(__FILE__).'/OutputManager.php';

// Php code classes
require_once dirname(__FILE__).'/../lib/phpSource/PhpFile.php';

/**
 * Class that contains functionality for generating classes from a wsdl file
 *
 * @package Wsdl2PhpGenerator
 * @author Fredrik Wallgren <fredrik.wallgren@gmail.com>
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class Generator
{
  /**
   * A SoapClient for loading the WSDL
   * @var SoapClient
   * @access private
   */
  private $client = null;

  /**
   * DOM document used to load and parse the wsdl
   * @var DOMDocument
   * @access private
   */
  private $dom = null;

  /**
   *
   *
   * @var Service The service class
   */
  private $service;

  /**
   * An array of Type objects that represents the types in the service
   *
   * @var array Array of Type objects
   * @see Type
   */
  private $types;

  /**
   * This is the object that holds the current config
   *
   * @var Config
   * @access private
   */
  private $config;

  /**
   *
   * @var DocumentationManager A manager for the documentation
   */
  private $documentation;

  /**
   *
   * @var Generator The infamous singleton instance
   */
  private static $instance;

  /**
   * Construct the generator
   */
  public function __construct()
  {
    $this->service = null;
    $this->types = array();
    $this->enums = array();
    $this->simple = array();
    $this->documentation = new DocumentationManager();

    if (self::$instance != null)
    {
      throw new Exception('wsdl2phpGenerator is only supposed to be constructed once. Check your code!');
    }

    self::$instance = $this;
  }

  public function getDocumentationManager(){
    return $this->documentation;
  }
  /**
   * Generates php source code from a wsdl file
   *
   * @see Config
   * @param Config $config The config to use for generation
   * @access public
   */
  public function generate(Config $config)
  {
    $this->config = $config;

    $this->log(_('Starting generation'));

    $wsdl = $this->config->getInputFile();
    
      
    if (is_array($wsdl)){
      foreach($wsdl as $ws){        
        $this->load($ws);
      }
    }else{
      $this->load($wsdl);      
    }
    
    $this->savePhp();

    $this->log(_('Generation complete'));
  }

  /**
   * Load the wsdl file into php
   */
  private function load($wsdl)
  {
    try
    {
      $this->log(_('Loading the wsdl'));
      $this->client = new SoapClient($wsdl, array('cache_wsdl' => WSDL_CACHE_NONE));
    }
    catch(SoapFault $e)
    {
      throw new Exception('Error connectiong to to the wsdl. Error: '.$e->getMessage());
    }

    $this->log(_('Loading the DOM'));
    $this->dom = DOMDocument::load($wsdl);

    $this->documentation->loadDocumentation($this->dom);
   
    $this->loadTypes();
    $this->loadService();
  }

  /**
   * Loads the service class
   *
   * @access private
   */
  private function loadService()
  {
    $name = $this->dom->getElementsByTagNameNS('*', 'service')->item(0)->getAttribute('name');

    $this->log(_('Starting to load service ').$name);

    $config = $this->getConfig();
    
    $ns = $config->getNamespaceName();
    
    $this->service = new Service($name, $this->types, $this->documentation->getServiceDescription(), $ns);

    $functions = $this->client->__getFunctions();
    foreach($functions as $function)
    {
      $matches = array();
      if(preg_match('/^(\w[\w\d_]*) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $function, $matches))
      {
        $returns = $matches[1];
        $function = $matches[2];
        $params = $matches[3];
      }
      else if(preg_match('/^(list\([\w\$\d,_ ]*\)) (\w[\w\d_]*)\(([\w\$\d,_ ]*)\)$/', $function, $matches))
      {
        $returns = $matches[1];
        $function = $matches[2];
        $params = $matches[3];
      }
      else
      {
        // invalid function call
        throw new Exception('Invalid function call: '.$function);
      }

      $this->log(_('Loading function ').$function);

      $this->service->addOperation($function, $params, $this->documentation->getFunctionDescription($function));
    }

    $this->log(_('Done loading service ').$name);
  }

  /**
   * Loads all type classes
   *
   * @access private
   */
  private function loadTypes()
  {
    $this->log(_('Loading types'));

    $types = $this->client->__getTypes();
   

    foreach($types as $typeStr)
    {
      $parts = explode(PHP_EOL, $typeStr);
      $tArr = explode(" ", $parts[0]);
      $restriction = $tArr[0];
      $className = $tArr[1];

      if(substr($className, -2, 2) == '[]' || substr($className, 0, 7) == 'ArrayOf')
      {
        // skip arrays
        continue;
      }
      
      // used below to look for documentation string.
      $documentation = null;
      $dxp = new \DOMXPath($this->dom);
      $dxp->registerNamespace('xs','http://www.w3.org/2001/XMLSchema');
     
      $type = null;
      $numParts = count($parts);
      
      // ComplexType
      if ($numParts > 1)
      {
     
        //look for dox.
        $foo = $dxp->query("//xs:complexType[@name='$className']/xs:annotation/xs:documentation");
        if ($foo->length >= 1) 
        $documentation = $foo->item(0)->nodeValue;
        
#        echo str_pad($className,40) . ': ' . $documentation ."\n\n";

        $type = new ComplexType($className);
        
        #var_dump($this->documentation);
        #var_dump($type->getPhpIdentifier());
        #die('BORK');
#        $type->setDocumentation($documentation->getComplexTypeDescriptions($type->getName()));
        $type->setDocumentation($documentation);
        $this->log(_('Loading type ').$type->getPhpIdentifier());

        for($i = 1; $i < $numParts - 1; $i++)
        {
          $parts[$i] = trim($parts[$i]);
          list($typename, $name) = explode(" ", substr($parts[$i], 0, strlen($parts[$i])-1) );

          $name = $this->cleanNamespace($name);

          $type->addMember($typename, $name);
        }
      }
      else // Enum or Pattern
      {
        $typenode = $this->findTypenode($className);

        if ($typenode)
        {
          // If enum
          $enumerationList = $typenode->getElementsByTagName('enumeration');
          $patternList = $typenode->getElementsByTagName('pattern');
          if ($enumerationList->length > 0)
          {
            $type = new Enum($className, $restriction);
            $this->log(_('Loading enum ').$type->getPhpIdentifier());
            foreach ($enumerationList as $enum)
            {
              $type->addValue($enum->attributes->getNamedItem('value')->nodeValue);
            }
          }
          else if ($patternList->length > 0)// If pattern
          {
            $type = new Pattern($className, $restriction);
            $this->log(_('Loading pattern ').$type->getPhpIdentifier());
            $type->setValue($patternList->item(0)->attributes->getNamedItem('value')->nodeValue);
          }
          else
          {
            continue; // Don't load the type if we don't know what it is
          }
        }
      }

      if ($type != null)
      {
	$already_registered = FALSE;
	if ($this->config->getSharedTypes())
	  foreach ($this->types as $registered_types) {
	    if ($registered_types->getIdentifier() == $type->getIdentifier()) {
	      $already_registered = TRUE;
	      break;
	    }
	  }
	if (!$already_registered)
        $this->types[] = $type;
      }
    }

    $this->log(_('Done loading types'));
  }

  /**
   * Save all the loaded classes to the configured output dir
   *
   * @throws Exception If no service is loaded
   *
   * @access private
   */
  private function savePhp()
  {
    $service = $this->service->getClass();

    if ($service == null)
    {
      throw new Exception('No service loaded');
    }

    $output = new OutputManager($this->config);

    // Generate all type classes
    $types = array();
    foreach ($this->types as $type)
    {
      $class = $type->getClass();
      if ($class != null)
      {
        $types[] = $class;

        if ($this->config->getOneFile() == false)
        {
          $service->addDependency($class->getIdentifier().'.php');
        }
      }
    }

    $output->save($service, $types);
  }

  /**
   * Logs a message to the standard output
   *
   * @param string $message The message to log
   */
  private function log($message)
  {
    if ($this->config->getVerbose() == true)
    {
      print $message.PHP_EOL;
    }
  }

  /**
   * Returns the singleton of the generator class. This may be changed to a "better" solution but I don't know any of the top of my head
   * Used by different classes to get the loaded config
   *
   * @return Generator The dreaded singleton instance
   */
  public static function getInstance()
  {
    return self::$instance;
  }

  /**
   * Returns the loaded config
   *
   * @return Config The loaded config
   */
  public function getConfig()
  {
    return $this->config;
  }

  /**
   * Takes a string and removes the xml namespace if any
   *
   * @param string $str
   * @return string The string without namespace
   */
  private function cleanNamespace($str)
  {
    if(strpos($str, ':'))
    {
      $arr = explode(':', $str);
      $str = $arr[1];
    }

    return $str;
  }

  /**
   * Parses the type schema for a type with the name $name
   *
   * @param string $name
   * @return DOMElement|null Returns the typenode with the name $name if it finds it. Null otherwise
   */
  private function findTypenode($name)
  {
    $typenode = null;

    $types = $this->dom->getElementsByTagName('types');
    if ($types->length > 0)
    {
      $schemaList = $types->item(0)->getElementsByTagName('schema');
      $schemaList = $this->dom->getElementsByTagName('types')->item(0)->getElementsByTagName('schema');

      foreach ($schemaList as $schema)
      {
        foreach ($schema->childNodes as $node)
        {
          if($node instanceof DOMElement)
          {
            if ($node->hasAttributes())
            {
              $t = $node->attributes->getNamedItem('name');
              if ($t)
              {
                if($t->nodeValue == $name)
                {
                  $typenode = $node;
                }
              }
            }
          }
        }
      }
    }

    return $typenode;
  }
}

