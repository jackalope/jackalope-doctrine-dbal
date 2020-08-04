Upgrade
=======

1.5
---

- The following change needs to be made to your database: 

  `ALTER TABLE phpcr_type_childs ADD COLUMN `id` INT(11) UNSIGNED PRIMARY KEY AUTO_INCREMENT FIRST;` 
  

1.2
---

- The following change needs to be made to your database: 

  `ALTER TABLE phpcr_nodes ADD numerical_props LONGTEXT DEFAULT NULL;` 
  
  The Jackalope implementation integrates with the doctrine schema updater
  which means you may also [migrate your database that
  way](http://symfony.com/doc/current/book/doctrine.html#generating-getters-and-setters).
