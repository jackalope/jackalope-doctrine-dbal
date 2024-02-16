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
	<sv:property sv:name="i18n:nl-published" sv:type="Date" sv:multi-valued="0"><sv:value length="29">2020-04-16T08:57:07.256+00:00</sv:value></sv:property>
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

    public function testParseWithoutSvValueNode(): void
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<sv:node
	xmlns:mix="http://www.jcp.org/jcr/mix/1.0"
	xmlns:nt="http://www.jcp.org/jcr/nt/1.0"
	xmlns:xs="http://www.w3.org/2001/XMLSchema"
	xmlns:jcr="http://www.jcp.org/jcr/1.0"
	xmlns:sv="http://www.jcp.org/jcr/sv/1.0"
	xmlns:phpcr="http://www.jcp.org/jcr/phpcr/1.0"
	xmlns:rep="internal">
	<sv:property sv:name="jcr:primaryType" sv:type="name" sv:multi-valued="0">
		<sv:value length="15">nt:unstructured</sv:value>
	</sv:property>
	<sv:property sv:name="block_1_ref" sv:type="reference" sv:multi-valued="0">1922ec03-b5ed-40cf-856c-ecfb8eac12e2</sv:property>
	<sv:property sv:name="block_2_ref" sv:type="Reference" sv:multi-valued="0">94c9aefe-faaa-4896-816b-5bfc575681f0</sv:property>
	<sv:property sv:name="block_3_ref" sv:type="weakreference" sv:multi-valued="0">a8ae4420-095b-4045-8775-b731cbae2fe1</sv:property>
	<sv:property sv:name="external_reference" sv:type="reference" sv:multi-valued="0">
		<sv:value length="36">842e61c0-09ab-42a9-87c0-308ccc90e6f6</sv:value>
	</sv:property>
</sv:node>
EOT;

        $xmlParser = $this->createXmlToPropsParser($xml, ['block_1_ref', 'block_2_ref', 'block_3_ref', 'external_reference']);
        $data = $xmlParser->parse();

        $this->assertSame('1922ec03-b5ed-40cf-856c-ecfb8eac12e2', $data->{'block_1_ref'});
        $this->assertSame('94c9aefe-faaa-4896-816b-5bfc575681f0', $data->{'block_2_ref'});
        $this->assertSame('a8ae4420-095b-4045-8775-b731cbae2fe1', $data->{'block_3_ref'});
        $this->assertSame('842e61c0-09ab-42a9-87c0-308ccc90e6f6', $data->{'external_reference'});
    }

    public function testParseEncoding(): void
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<sv:node
	xmlns:mix="http://www.jcp.org/jcr/mix/1.0"
	xmlns:nt="http://www.jcp.org/jcr/nt/1.0"
	xmlns:xs="http://www.w3.org/2001/XMLSchema"
	xmlns:jcr="http://www.jcp.org/jcr/1.0"
	xmlns:sv="http://www.jcp.org/jcr/sv/1.0"
	xmlns:phpcr="http://www.jcp.org/jcr/phpcr/1.0"
	xmlns:rep="internal">
	<sv:property sv:name="ampersand" sv:type="String" sv:multi-valued="0"><sv:value length="13">foo &amp; bar&amp;baz</sv:value></sv:property>
</sv:node>
EOT;

        $xmlParser = $this->createXmlToPropsParser($xml, ['ampersand']);
        $data = $xmlParser->parse();

        $this->assertSame('foo & bar&baz', $data->{'ampersand'});
    }

    private function createXmlToPropsParser(string $xml, ?array $propNames = null): XmlToPropsParser
    {
        return new XmlToPropsParser(
            $xml,
            $this->valueConverter,
            $propNames
        );
    }
}
