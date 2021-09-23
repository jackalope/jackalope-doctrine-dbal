<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlParser;

use PHPCR\PropertyType;
use PHPCR\Util\ValueConverter;

/**
 * @internal
 */
final class XmlToPropsParser
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
    private $valueConverter;

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
    private $currentValueData = '';

    /**
     * @var mixed
     */
    private $currentPropData = '';

    /**
     * @var \stdClass
     */
    private $data;

    /**
     * @param string[] $propertyNames
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
     * @param string     $name
     * @param mixed[]    $attrs
     */
    private function startHandler($parser, $name, $attrs): void
    {
        $this->currentTag = $name;

        if ('SV:PROPERTY' !== $name) {
            return;
        }

        if (null !== $this->propertyNames && !\in_array($attrs['SV:NAME'], $this->propertyNames)) {
            return;
        }

        $this->lastPropertyName = $attrs['SV:NAME'];
        $this->lastPropertyType = PropertyType::valueFromName($attrs['SV:TYPE']);
        $this->lastPropertyMultiValued = $attrs['SV:MULTI-VALUED'];
    }

    private function endHandler($parser, $name): void
    {
        $this->currentTag = null;

        if (!$this->lastPropertyName) {
            return;
        }

        if ('SV:VALUE' === $name) {
            $this->addCurrentValue($this->currentValueData);
            $this->currentValueData = '';
        }

        // it could be that there exist a sv:property node without a sv:value
        // in this case the value is set on the property data
        if ('SV:PROPERTY' === $name && empty($this->currentValues) && !$this->lastPropertyMultiValued) {
            $this->addCurrentValue($this->currentPropData);
            $this->currentPropData = '';
        }

        if ('SV:PROPERTY' !== $name) {
            return;
        }

        switch ($this->lastPropertyType) {
            case PropertyType::BINARY:
                $this->data->{':'.$this->lastPropertyName} = $this->lastPropertyMultiValued ? $this->currentValues : $this->currentValues[0];
                break;
            default:
                $this->data->{$this->lastPropertyName} = $this->lastPropertyMultiValued ? $this->currentValues : $this->currentValues[0];
                $this->data->{':'.$this->lastPropertyName} = $this->lastPropertyType;
                break;
        }

        $this->currentValues = [];
        $this->currentValueData = '';
        $this->currentPropData = '';
        $this->lastPropertyName = null;
        $this->lastPropertyType = null;
        $this->lastPropertyMultiValued = null;
    }

    private function dataHandler($parser, $data): void
    {
        if (!$this->lastPropertyName) {
            return;
        }

        if ('SV:VALUE' === $this->currentTag) {
            $this->currentValueData .= $data;

            return;
        }

        if ('SV:PROPERTY' === $this->currentTag) {
            $this->currentPropData .= $data;

            return;
        }
    }

    private function addCurrentValue($value): void
    {
        switch ($this->lastPropertyType) {
            case PropertyType::NAME:
            case PropertyType::URI:
            case PropertyType::WEAKREFERENCE:
            case PropertyType::REFERENCE:
            case PropertyType::PATH:
            case PropertyType::DECIMAL:
            case PropertyType::STRING:
                $this->currentValues[] = (string) $value;
                break;
            case PropertyType::BOOLEAN:
                $this->currentValues[] = (bool) $value;
                break;
            case PropertyType::LONG:
                $this->currentValues[] = (int) $value;
                break;
            case PropertyType::BINARY:
                $this->currentValues[] = (int) $value;
                break;
            case PropertyType::DATE:
                $date = $value;
                if ($date) {
                    $date = new \DateTime($date);
                    $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    // Jackalope expects a string, might make sense to refactor to allow DateTime instances too
                    $date = $this->valueConverter->convertType($date, PropertyType::STRING);
                }
                $this->currentValues[] = $date;
                break;
            case PropertyType::DOUBLE:
                $this->currentValues[] = (float) $value;
                break;
            default:
                throw new \InvalidArgumentException("Type with constant $this->lastPropertyType not found.");
        }
    }
}
