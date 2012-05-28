<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\DriverManager;

class ClientTest extends DoctrineDBALTestCase
{
    private $transport;
    /**
     * @var \Jackalope\Repository
     */
    private $repository;
    /**
     * @var \Jackalope\Session
     */
    private $session;

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $schema = RepositorySchema::create();

        foreach ($schema->toDropSql($conn->getDatabasePlatform()) as $statement) {
            try {
                $conn->exec($statement);
            } catch(\Exception $e) {

            }
        }

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $statement) {
            $conn->exec($statement);
        }

        $this->transport = new \Jackalope\Transport\DoctrineDBAL\Client(new \Jackalope\Factory(), $conn);
        $this->transport->createWorkspace('default');

        $this->repository = new \Jackalope\Repository(null, $this->transport);

        try {
            $this->transport->createWorkspace($GLOBALS['phpcr.workspace']);
        } catch (\PHPCR\RepositoryException $e) {
            if ($e->getMessage() != "Workspace '".$GLOBALS['phpcr.workspace']."' already exists") {
                // if the message is not that the workspace already exists, something went really wrong
                throw $e;
            }
        }
        $this->session = $this->repository->login(new \PHPCR\SimpleCredentials("user", "passwd"), $GLOBALS['phpcr.workspace']);
    }

    public function testQueryNodes()
    {
        $root = $this->session->getNode('/');
        $article = $root->addNode('article');
        $article->setProperty('foo', 'bar');
        $article->setProperty('bar', 'baz');

        $this->session->save();

        $qm = $this->session->getWorkspace()->getQueryManager();
        $query = $qm->createQuery('SELECT * FROM [nt:unstructured]', \PHPCR\Query\QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertEquals(2, count($result->getNodes()));

        $query = $qm->createQuery('SELECT * FROM [nt:unstructured] WHERE foo = "bar"', \PHPCR\Query\QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertEquals(1, count($result->getNodes()));
    }

    public function testAddNodeTypes()
    {
        $workspace = $this->session->getWorkspace();
        $ntm = $workspace->getNodeTypeManager();
        $template = $ntm->createNodeTypeTemplate();
        $template->setName('phpcr:article');

        $propertyDefs = $template->getPropertyDefinitionTemplates();
        $propertyTemplate = $ntm->createPropertyDefinitionTemplate();
        $propertyTemplate->setName('headline');
        $propertyTemplate->setRequiredType(\PHPCR\PropertyType::STRING);
        $propertyDefs[] = $propertyTemplate;

        $childDefs = $template->getNodeDefinitionTemplates();
        $nodeTemplate = $ntm->createNodeDefinitionTemplate();
        $nodeTemplate->setName('article_content');
        $nodeTemplate->setDefaultPrimaryTypeName('nt:unstructured');
        $nodeTemplate->setMandatory(true);
        $childDefs[] = $nodeTemplate;

        $ntm->registerNodeTypes(array($template), true);

        $def = $ntm->getNodeType('phpcr:article');
        $this->assertEquals("phpcr:article", $def->getName());
        $this->assertEquals(1, count($def->getDeclaredPropertyDefinitions()));
        $this->assertEquals(1, count($def->getDeclaredChildNodeDefinitions()));
    }

    public function testReorderNodes()
    {
        $root = $this->session->getNode('/');
        $topic = $root->addNode('topic');
        $topic->addNode('page1');
        $topic->addNode('page2');
        $topic->addNode('page3');
        $topic->addNode('page4');
        $topic->addNode('page5');                        

        $this->session->save();

        $topic->orderBefore('page3', 'page1');
        $topic->orderBefore('page4', NULL);

        $this->session->save();

        $conn = $this->getConnection();       
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name, sort_order')
           ->from('phpcr_nodes', 'n')
           ->where('n.local_name = :name')
           ->andWhere('n.parent = :parent')
           ->orderBy('n.sort_order', 'ASC');

        $query = $qb->getSql();
        $stmnt = $this->conn->prepare($query);

        $stmnt = $this->conn->executeQuery($query, array('name' => 'page3', 'parent' => '/topic'));        
        $row = $stmnt->fetch();
        $this->assertEquals(0, $row['sort_order']);   

        $stmnt = $this->conn->executeQuery($query, array('name' => 'page4', 'parent' => '/topic'));        

        $row = $stmnt->fetch();
        $this->assertEquals(4, $row['sort_order']);

        $retrieved = $this->session->getNode('/topic');
        foreach($retrieved as $name => $child) {
            $check[] = $name;
        }

        $this->assertEquals($check[0], 'page3');
        $this->assertEquals($check[4], 'page4');
    }
}
