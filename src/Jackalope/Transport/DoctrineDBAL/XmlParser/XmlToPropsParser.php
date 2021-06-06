<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlParser;

use PHPCR\PropertyType;
use PHPCR\Util\ValueConverter;

/**
 * @internal
 */
class XmlToPropsParser
{
    /**
     * @var string
     */
    private $xml;

    /**
     * @var string[]|null
     */
    private $propertyNames;

    /**
     * @var ValueConverter
     */
    protected $valueConverter;

    /**
     * @var string|null
     */
    private $lastPropertyName = null;

    /**
     * @var string|null
     */
    private $lastPropertyType = null;

    /**
     * @var string|null
     */
    private $lastPropertyMultiValued = null;

    /**
     * @var string|null
     */
    private $currentTag = null;

    /**
     * @var mixed[]
     */
    private $currentValues = [];

    /**
     * @var mixed
     */
    private $currentValue = null;

    /**
     * @var \stdClass
     */
    private $data;

    /**
     * @param string $xml
     * @param string[] $columnNames
     */
    public function __construct(
        string $xml,
        ValueConverter $valueConverter,
        array $propertyNames = null
    ) {
        $this->xml = $xml;
        $this->propertyNames = $propertyNames;
        $this->valueConverter = $valueConverter;
    }

    /**
     * @return \stdClass
     */
    public function parse(): \stdClass
    {
        $this->data = new \stdClass();

        $parser = xml_parser_create();

        xml_set_element_handler(
            $parser,
            [$this, 'startHandler'],
            [$this, 'endHandler']
        );

        xml_set_character_data_handler($parser, [$this, 'dataHandler']);

        xml_parse($parser, $this->xml, true);
        xml_parser_free($parser);
        // avoid memory leaks and unset the parser see: https://www.php.net/manual/de/function.xml-parser-free.php
        unset($parser);

        return $this->data;
    }

    /**
     * @param \XmlParser $parser
     * @param string $name
     * @param mixed[] $attrs
     */
    private function startHandler($parser, $name, $attrs): void
    {
        $this->currentTag = $name;

        if ($name !== 'SV:PROPERTY') {
            return;
        }

        if ($this->propertyNames !== null && !\in_array($attrs['SV:NAME'], $this->propertyNames)) {
            return;
        }

        $this->lastPropertyName = $attrs['SV:NAME'];
        $this->lastPropertyType = PropertyType::valueFromName($attrs['SV:TYPE']);
        $this->lastPropertyMultiValued = $attrs['SV:MULTI-VALUED'];
    }

    private function endHandler($parser, $name): void
    {
        $this->currentTag = null;

        if ($name === 'SV:PROPERTY' && $this->lastPropertyName) {
            switch ($this->lastPropertyType) {
                case PropertyType::BINARY:
                    $this->data->{':' . $this->lastPropertyName} = $this->lastPropertyMultiValued ? $this->currentValues : $this->currentValues[0];
                    break;
                default:
                    $this->data->{$this->lastPropertyName} = $this->lastPropertyMultiValued ? $this->currentValues : $this->currentValues[0];
                    $this->data->{':' . $this->lastPropertyName} = $this->lastPropertyType;
                    break;
            }

            $this->currentValues = [];
            $this->lastPropertyName = null;
            $this->lastPropertyType = null;
            $this->lastPropertyMultiValued = null;

            return;
        }

        if ($name === 'SV:VALUE' && $this->lastPropertyName) {
            switch ($this->lastPropertyType) {
                case PropertyType::NAME:
                case PropertyType::URI:
                case PropertyType::WEAKREFERENCE:
                case PropertyType::REFERENCE:
                case PropertyType::PATH:
                case PropertyType::DECIMAL:
                case PropertyType::STRING:
                    $this->currentValues[] = $this->currentValue;
                    break;
                case PropertyType::BOOLEAN:
                    $this->currentValues[] = (bool)$this->currentValue;
                    break;
                case PropertyType::LONG:
                    $this->currentValues[] = (int)$this->currentValue;
                    break;
                case PropertyType::BINARY:
                    $this->currentValues[] = (int)$this->currentValue;
                    break;
                case PropertyType::DATE:
                    $date = $this->currentValue;
                    if ($date) {
                        $date = new \DateTime($date);
                        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                        // Jackalope expects a string, might make sense to refactor to allow DateTime instances too
                        $date = $this->valueConverter->convertType($date, PropertyType::STRING);
                    }
                    $this->currentValues[] = $date;
                    break;
                case PropertyType::DOUBLE:
                    $this->currentValues[] = (double)$this->currentValue;
                    break;
                default:
                    throw new \InvalidArgumentException("Type with constant $this->lastPropertyType not found.");
            }
        }
    }

    private function dataHandler($parser, $data): void
    {
        if ($this->currentTag === 'SV:VALUE' && $this->lastPropertyName) {
            $this->currentValue = $data;
        }
    }
}
