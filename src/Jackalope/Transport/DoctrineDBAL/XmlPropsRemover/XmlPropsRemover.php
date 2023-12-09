<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlPropsRemover;

/**
 * @internal
 */
class XmlPropsRemover
{
    /**
     * @var string
     */
    private $xml;

    /**
     * @var string[]
     */
    private $propertyNames;

    /**
     * @var bool
     */
    private $skipCurrentTag = false;

    /**
     * @var string
     */
    private $newXml = '';

    /**
     * @var string
     */
    private $newStartTag = '';

    private $weakReferences = [];

    private $references = [];

    public function __construct(string $xml, array $propertyNames)
    {
        $this->xml = $xml;
        $this->propertyNames = $propertyNames;
    }

    /**
     * @return array{
     *     0: string,
     *     1: array{
     *         reference: string[],
     *         weakreference: string[],
     *     },
     * }
     */
    public function removeProps(): array
    {
        $this->newXml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $this->references = [];
        $this->weakReferences = [];
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
            $this->newXml . PHP_EOL,
            [
                'reference' => $this->references,
                'weakreference' => $this->weakReferences,
            ],
        ];
    }

    /**
     * @param \XmlParser $parser
     * @param string $name
     * @param mixed[] $attrs
     */
    private function startHandler($parser, $name, $attrs): void
    {
        if ($this->skipCurrentTag) {
            return;
        }

        if ($name === 'SV:PROPERTY') {
            $svName = $attrs['SV:NAME'];

            if (\in_array($svName, $this->propertyNames)) {
                $this->skipCurrentTag = true;

                if (\in_array($svName, $this->propertyNames)) {
                    $this->skipCurrentTag = true;
                    $svType = $attrs['SV:TYPE'];

                    if ($svType === 'reference') {
                        $this->references[] = $svName;
                    } elseif ($svType === 'weakreference') {
                        $this->weakReferences[] = $svName;
                    }

                    return;
                }

                return;
            }
        }

        $tag = '<' . \strtolower($name);
        foreach ($attrs as $key => $value) {
            $tag .= ' ' . \strtolower($key) // there is no case key which requires escaping for performance reasons we avoid it so
                . '="'
                . \htmlspecialchars($value, ENT_COMPAT, 'UTF-8')
                . '"';
        }
        $tag .= '>';

        $this->newXml .= $this->newStartTag;
        $this->newStartTag = $tag; // handling self closing tags in endHandler
    }

    private function endHandler($parser, $name): void
    {
        if ($name === 'SV:PROPERTY' && $this->skipCurrentTag) {
            $this->skipCurrentTag = false;

            return;
        }

        if ($this->skipCurrentTag) {
            return;
        }

        if ($this->newStartTag) {
            // if the tag is not rendered to newXml it can be a self closing tag
            $this->newXml .= \substr($this->newStartTag, 0.0, -1) . '/>';
            $this->newStartTag = '';

            return;
        }

        $this->newXml .= '</' . \strtolower($name) . '>';
    }

    private function dataHandler($parser, $data): void
    {
        if ($this->skipCurrentTag) {
            return;
        }

        if ($data !== '') {
            $this->newXml .= $this->newStartTag; // none empty data means no self closing tag so render tag now
            $this->newStartTag = '';
            $this->newXml .= htmlspecialchars($data, ENT_XML1, 'UTF-8');
        }
    }
}
