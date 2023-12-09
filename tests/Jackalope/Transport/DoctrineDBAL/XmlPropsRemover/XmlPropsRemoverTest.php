<?php

namespace Jackalope\Transport\DoctrineDBAL\XmlPropsRemover;

use Jackalope\Factory;
use Jackalope\Test\TestCase;
use Jackalope\Transport\DoctrineDBAL\XmlParser\XmlToPropsParser;
use PHPCR\Util\ValueConverter;

class XmlPropsRemoverTest extends TestCase
{
    public function testRemoveProps(): void
    {
        $xml = <<<EOT
<?xml version="1.0" encoding="UTF-8"?>
<sv:node xmlns:mix="http://www.jcp.org/jcr/mix/1.0" xmlns:nt="http://www.jcp.org/jcr/nt/1.0" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:jcr="http://www.jcp.org/jcr/1.0" xmlns:sv="http://www.jcp.org/jcr/sv/1.0" xmlns:rep="internal">
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
	<sv:property sv:name="ampersand" sv:type="String" sv:multi-valued="0"><sv:value length="13">foo &amp; bar&amp;baz</sv:value></sv:property>
	<sv:property sv:name="äüö?ß&lt;&gt;''&quot;=&quot;test" sv:type="String" sv:multi-valued="0"><sv:value length="15">&lt;&gt;:&amp;|öäü"?"ß'='</sv:value></sv:property>
	<sv:property sv:name="block_1_ref" sv:type="reference" sv:multi-valued="0">1922ec03-b5ed-40cf-856c-ecfb8eac12e2</sv:property>
	<sv:property sv:name="block_2_ref" sv:type="reference" sv:multi-valued="0">94c9aefe-faaa-4896-816b-5bfc575681f0</sv:property>
	<sv:property sv:name="block_3_ref" sv:type="weakreference" sv:multi-valued="0">a8ae4420-095b-4045-8775-b731cbae2fe1</sv:property>
	<sv:property sv:name="external_reference" sv:type="reference" sv:multi-valued="0">
		<sv:value length="36">842e61c0-09ab-42a9-87c0-308ccc90e6f6</sv:value>
	</sv:property>
</sv:node>
EOT;

        $xmlPropsRemover = $this->createXmlPropsRemover($xml, [
            'i18n:en-title',
            'block_2_ref',
            'block_3_ref',
            'external_reference',
        ]);
        [$xml, $references] = $xmlPropsRemover->removeProps();

        $this->assertStringContainsString('äüö?ß&lt;&gt;\'\'&quot;=&quot;test', $xml, 'Not correctly escaped special chars property name, after removing props.');
        $this->assertStringContainsString('&lt;&gt;:&amp;|öäü"?"ß\'=\'', $xml, 'Not correctly escaped special chars property value, after removing props.');

        $xmlParser = $this->createXmlToPropsParser($xml);
        $data = $xmlParser->parse();
        $this->assertSame([
            'jcr:primaryType' => 'nt:unstructured',
            ':jcr:primaryType' => 7,
            'jcr:mixinTypes' => ['sulu:page'],
            ':jcr:mixinTypes' => 7,
            'jcr:uuid' => '0804f0c3-5250-4c2f-9d7e-7d0c99103026',
            ':jcr:uuid' => 1,
            'ampersand' => 'foo & bar&baz',
            ':ampersand' => 1,
            'äüö?ß<>\'\'"="test' => '<>:&|öäü"?"ß\'=\'',
            ':äüö?ß<>\'\'"="test' => 1,
            'block_1_ref' => '1922ec03-b5ed-40cf-856c-ecfb8eac12e2',
            ':block_1_ref' => 9,
        ], (array) $data);
        $this->assertSame([
            'reference' => [
                'block_2_ref',
                'external_reference',
            ],
            'weakreference' => [
                'block_3_ref',
            ],
        ], $references);
    }

    private function createXmlPropsRemover(string $xml, array $propNames = null): XmlPropsRemover
    {
        return new XmlPropsRemover(
            $xml,
            $propNames
        );
    }

    private function createXmlToPropsParser(string $xml, array $propNames = null): XmlToPropsParser
    {
        $factory = new Factory();

        $valueConverter = $factory->get(ValueConverter::class);

        return new XmlToPropsParser(
            $xml,
            $valueConverter,
            $propNames
        );
    }
}
