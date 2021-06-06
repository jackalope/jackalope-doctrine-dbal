<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlParser;

use Jackalope\Factory;
use Jackalope\Test\TestCase;
use PHPCR\Util\ValueConverter;

class XmlToPropsParserTest extends TestCase
{
    /**
     * @var ValueConverter
     */
    private $valueConverter;

    protected function setUp(): void
    {
        $factory = new Factory();

        $this->valueConverter = $factory->get(ValueConverter::class);
    }

    public function testParseWithoutProps(): void
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<sv:node
	xmlns:mix="http://www.jcp.org/jcr/mix/1.0"
	xmlns:nt="http://www.jcp.org/jcr/nt/1.0"
	xmlns:xs="http://www.w3.org/2001/XMLSchema"
	xmlns:jcr="http://www.jcp.org/jcr/1.0"
	xmlns:sv="http://www.jcp.org/jcr/sv/1.0"
	xmlns:rep="internal">
	<sv:property sv:name="jcr:primaryType" sv:type="Name" sv:multi-valued="0">
		<sv:value length="15">nt:unstructured</sv:value>
	</sv:property>
	<sv:property sv:name="jcr:mixinTypes" sv:type="Name" sv:multi-valued="1">
		<sv:value length="9">sulu:page</sv:value>
	</sv:property>
	<sv:property sv:name="jcr:uuid" sv:type="String" sv:multi-valued="0">
		<sv:value length="36">0804f0c3-5250-4c2f-9d7e-7d0c99103026</sv:value>
	</sv:property>
	<sv:property sv:name="i18n:en-title" sv:type="String" sv:multi-valued="0">
		<sv:value length="8">My Title</sv:value>
	</sv:property>
</sv:node>
EOT;

        $xmlParser = $this->createXmlToPropsParser($xml);
        $data = $xmlParser->parse();

        $this->assertSame('nt:unstructured', $data->{'jcr:primaryType'});
        $this->assertSame(['sulu:page'], $data->{'jcr:mixinTypes'});
        $this->assertSame('0804f0c3-5250-4c2f-9d7e-7d0c99103026', $data->{'jcr:uuid'});
        $this->assertSame('My Title', $data->{'i18n:en-title'});
    }

    public function testParseWithProps(): void
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<sv:node
	xmlns:mix="http://www.jcp.org/jcr/mix/1.0"
	xmlns:nt="http://www.jcp.org/jcr/nt/1.0"
	xmlns:xs="http://www.w3.org/2001/XMLSchema"
	xmlns:jcr="http://www.jcp.org/jcr/1.0"
	xmlns:sv="http://www.jcp.org/jcr/sv/1.0"
	xmlns:rep="internal">
	<sv:property sv:name="jcr:primaryType" sv:type="Name" sv:multi-valued="0">
		<sv:value length="15">nt:unstructured</sv:value>
	</sv:property>
	<sv:property sv:name="jcr:mixinTypes" sv:type="Name" sv:multi-valued="1">
		<sv:value length="9">sulu:page</sv:value>
	</sv:property>
	<sv:property sv:name="jcr:uuid" sv:type="String" sv:multi-valued="0">
		<sv:value length="36">0804f0c3-5250-4c2f-9d7e-7d0c99103026</sv:value>
	</sv:property>
	<sv:property sv:name="i18n:en-title" sv:type="String" sv:multi-valued="0">
		<sv:value length="8">My Title</sv:value>
	</sv:property>
</sv:node>
EOT;

        $xmlParser = $this->createXmlToPropsParser($xml, ['jcr:uuid']);
        $data = $xmlParser->parse();

        $this->assertFalse(isset($data->{'jcr:primaryType'}));
        $this->assertFalse(isset($data->{'jcr:mixinTypes'}));
        $this->assertTrue(isset($data->{'jcr:uuid'}));
        $this->assertFalse(isset($data->{'i18n:de-title'}));
        $this->assertSame('0804f0c3-5250-4c2f-9d7e-7d0c99103026', $data->{'jcr:uuid'});
    }

    private function createXmlToPropsParser(string $xml, array $propNames = null): XmlToPropsParser
    {
        return new XmlToPropsParser(
            $xml,
            $this->valueConverter,
            $propNames
        );
    }
}
