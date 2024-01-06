<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlPropsRemover;

use PHPCR\PropertyType;

/**
 * @internal
 */
class XmlPropsRemover
{
    private string $xml;

    /**
     * @var string[]
     */
    private array $propertyNames;

    private bool $skipCurrentTag = false;

    private string $newXml = '';

    private string $newStartTag = '';

    /**
     * @var string[]
     */
    private array $weakReferences = [];

    /**
     * @var string[]
     */
    private array $binaries = [];

    /**
     * @var string[]
     */
    private array $references = [];

    public function __construct(string $xml, array $propertyNames)
    {
        $this->xml = $xml;
        $this->propertyNames = $propertyNames;
    }

    /**
     * @example [$xml, $references, $binaries] = $xmlPropsRemover->removeProps($xml, $propertiesToDelete);
     *
     * @return array{
     *     0: string,
     *     1: array{
     *         reference: string[],
     *         weakreference: string[],
     *     },
     *     2: string[]
     * } An array with the new xml (0) and the references (1) which requires to be removed
     */
    public function removeProps(): array
    {
        $this->newXml = '<?xml version="1.0" encoding="UTF-8"?>'.PHP_EOL;
        $this->references = [];
        $this->weakReferences = [];
        $this->binaries = [];
        $this->newStartTag = '';
        $this->skipCurrentTag = false;

        $parser = \xml_parser_create();

        \xml_set_element_handler(
            $parser,
            [$this, 'startHandler'],
            [$this, 'endHandler']
        );

        \xml_set_character_data_handler($parser, [$this, 'dataHandler']);

        \xml_parse($parser, $this->xml, true);
        \xml_parser_free($parser);
        // avoid memory leaks and unset the parser see: https://www.php.net/manual/de/function.xml-parser-free.php
        unset($parser);

        return [
            $this->newXml.PHP_EOL,
            [
                'reference' => $this->references,
                'weakreference' => $this->weakReferences,
            ],
            $this->binaries,
        ];
    }

    /**
     * @param mixed[] $attrs
     */
    private function startHandler(\XMLParser $parser, string $name, array $attrs): void
    {
        if ($this->skipCurrentTag) {
            return;
        }

        if ('SV:PROPERTY' === $name) {
            $svName = $attrs['SV:NAME'];

            if (\in_array((string) $svName, $this->propertyNames, true)) {
                $this->skipCurrentTag = true;
                // use PHPCR PropertyType to avoid encoding issues
                switch (PropertyType::valueFromName($attrs['SV:TYPE'])) {
                    case PropertyType::REFERENCE:
                        $this->references[] = $svName;
                        break;
                    case PropertyType::WEAKREFERENCE:
                        $this->weakReferences[] = $svName;
                        break;
                    case PropertyType::BINARY:
                        $this->binaries[] = $svName;
                        break;
                }

                return;
            }
        }

        $tag = '<'.\strtolower($name);
        foreach ($attrs as $key => $value) {
            $tag .= ' '.\strtolower($key) // there is no case key which requires escaping for performance reasons we avoid it so
                .'="'
                .\htmlspecialchars($value, ENT_COMPAT, 'UTF-8')
                .'"';
        }
        $tag .= '>';

        $this->newXml .= $this->newStartTag;
        $this->newStartTag = $tag; // handling self closing tags in endHandler
    }

    private function endHandler(\XMLParser $parser, string $name): void
    {
        if ('SV:PROPERTY' === $name && $this->skipCurrentTag) {
            $this->skipCurrentTag = false;

            return;
        }

        if ($this->skipCurrentTag) {
            return;
        }

        if ($this->newStartTag) {
            // if the tag is not rendered to newXml it can be a self-closing tag
            $this->newXml .= \substr($this->newStartTag, 0.0, -1).'/>';
            $this->newStartTag = '';

            return;
        }

        $this->newXml .= '</'.\strtolower($name).'>';
    }

    private function dataHandler(\XMLParser $parser, string $data): void
    {
        if ($this->skipCurrentTag) {
            return;
        }

        if ('' !== $data) {
            $this->newXml .= $this->newStartTag; // non-empty data means no self closing tag so render tag now
            $this->newStartTag = '';
            $this->newXml .= \htmlspecialchars($data, ENT_XML1, 'UTF-8');
        }
    }
}
