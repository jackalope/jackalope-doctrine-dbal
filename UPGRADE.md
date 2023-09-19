Upgrade
=======

2.0
---

- The `CachedClient` now expects caches implementing PSR-16 `Psr\SimpleCache\CacheInterface` 
  rather than `Doctrine\Common\Cache\Cache` from the deprecated Doctrine cache library. Adjust your
  boostrap code accordingly. 

- `cli-config.php.dist` has been renamed to `cli-config.dist.php` - if you automate usage of the cli
  config file, you will need to adjust.

- We changed the database schema to cascade delete the binary data when a node is deleted. To
  upgrade the schema, delete all dangling references and add the foreign key to the database:

  `DELETE FROM phpcr_binarydata where node_id NOT IN (SELECT id FROM phpcr_nodes)`
  `ALTER TABLE phpcr_binarydata ADD CONSTRAINT fk_nodes FOREIGN KEY (node_id) REFERENCES phpcr_nodes(id) ON DELETE CASCADE`

1.5
---

- If you want to start using Percona in strict mode, or use the Percona cluster, add a primary key 
  to the table `phpcr_type_childs`:
  
  `ALTER TABLE phpcr_type_childs ADD COLUMN `id` INT(11) UNSIGNED PRIMARY KEY AUTO_INCREMENT FIRST;` 

1.2
---

- The following change needs to be made to your database: 

  `ALTER TABLE phpcr_nodes ADD numerical_props LONGTEXT DEFAULT NULL;` 
  
  The Jackalope implementation integrates with the doctrine schema updater
  which means you may also [migrate your database that
  way](http://symfony.com/doc/current/book/doctrine.html#generating-getters-and-setters).
