Upgrade
=======

2.0
---

- The `CachedClient` now expects caches implementing PSR-16 `Psr\SimpleCache\CacheInterface` 
  rather than `Doctrine\Common\Cache\Cache` from the deprecated Doctrine cache library. Adjust your
  boostrap code accordingly. 

- `cli-config.php.dist` has been renamed to `cli-config.dist.php` - if you automate usage of the cli
  config file, you will need to adjust.

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
