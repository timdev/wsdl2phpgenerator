<?php

/**
 * @package Wsdl2PhpGenerator
 */

/**
 * Very stupid datatype to use instead of array
 *
 * @package Wsdl2PhpGenerator
 * @author Fredrik Wallgren <fredrik.wallgren@gmail.com>
 *  @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
class DocumentationManager {

  /**
   *
   * @var string The documentation for the service
   */
  private $serviceDescription;

  /**
   * The key is the function name
   *
   * @var array An array with strings with function descriptions
   */
  private $functionDescriptions;

  /**
   * @var array
   */
  private $complexTypeDescriptions;

  public function __construct() {
    $this->serviceDescription = '';
    $this->functionDescriptions = array();
  }

  /**
   * Loads all documentation into the instance
   *
   * @param DOMDocument $dom The wsdl file dom document
   */
  public function loadDocumentation(DOMDocument $dom) {
    $docList = $dom->getElementsByTagName('documentation');

    foreach ($docList as $item) {
      if ($item->parentNode->localName == 'service') {
        $this->serviceDescription = trim($item->parentNode->nodeValue);
      } else if ($item->parentNode->localName == 'operation') {
        $name = $item->parentNode->getAttribute('name');
        $this->setFunctionDescription($name, trim($item->nodeValue));
      } elseif ($item->parentNode->parentNode->localName == 'complexType') {
        /**
         * @var DOMElement
         */
        $complexType = $item->parentNode->parentNode;

        //get main dox for this complexType itself.
        $children = $complexType->childNodes;


        $this->complexTypeDescriptions[$complexType->getAttribute('name')]['__MAIN__'] = '';
        for ($i = 0; $i < $children->length; $i++) {
          if (($el = $children->item($i)) instanceof DOMElement) {
            for ($i = 0; $i < $el->childNodes->length; $i++) {
              if ($el->childNodes->item($i) instanceof DOMElement && $el->childNodes->item($i)->localName == 'documentation') {
                $this->complexTypeDescriptions[$complexType->getAttribute('name')]['__MAIN__'] = $el->childNodes->item($i)->nodeValue;
              }
            }
          }
        }

        $seq = $complexType->getElementsByTagName('sequence')->item(0);
        if ($seq) {
          $els = $seq->getElementsByTagName('element');
          for ($i = 0; $i < $els->length; $i++) {
            if ($els->item($i)->getElementsByTagName('documentation')->length) {
              $this->complexTypeDescriptions[$complexType->getAttribute('name')][$els->item($i)->getAttribute('name')]
                      = trim(
                      $els->item($i)
                              ->getElementsByTagName('documentation')
                              ->item(0)->nodeValue
              );
            } else {
              $this->complexTypeDescriptions[$complexType->getAttribute('name')][$els->item($i)->getAttribute('name')] = '';
            }
          }
        }
      }
    }
  }

  public function getComplexTypeDescriptions($type) {
    if (empty($this->complexTypeDescriptions[$type])) return null;
    return $this->complexTypeDescriptions[$type];
  }

  /**
   *
   * @return string The documentation for the service
   */
  public function getServiceDescription() {
    return $this->serviceDescription;
  }

  /**
   *
   * @param string $serviceDescription The new documentation
   */
  public function setServiceDescription($serviceDescription) {
    $this->serviceDescription = $serviceDescription;
  }

  /**
   *
   * @param string $function The name of the function
   * @param string $description The documentation
   */
  public function setFunctionDescription($function, $description) {
    $this->functionDescriptions[$function] = $description;
  }

  /**
   *
   * @param string $function
   * @return string The description
   */
  public function getFunctionDescription($function) {
    $ret = '';
    $ret = @$this->functionDescriptions[$function];

    return $ret;
  }

}

