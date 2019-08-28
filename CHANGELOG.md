Changelog
=========

1.3.4
-----

* Use `defaultTableOptions` to detect collate of database connection, if available

1.3.3
-----

* Use platform expression for concatenating
* Fix return type of CachedClient::getNamespaces

1.3.2
-----

* Detect utf8mb4 encoding and use shorter fields so MySQL can handle indexes on 4 byte UTF-8.

1.3.1
-----

* Support PHP 7.2
* Allow Symfony 4 components

1.3.0
-----

* upgraded to PHP 5.6 / 7
* bugfix #339 fix datetime comparison with different timezones
* bugfix #337 Added auto-detection of binary collation for MySQL, so that not always utf8_bin is used.
  A new `setCaseSensitiveEncoding` method has been introduced, which can be used to override
  the auto detected value.
* performance #336 better custom node type loading
* bugfix #333 keep sort order when clone/copying a node

1.2
---

* Validate namespaces in node paths and throws NamespaceException on unknown prefixes.
* Throw Exception when user tries to select either jcr:path or jcr:score
* The jackalope:init:dbal command now only really executes when the --force
  parameter is given.
* Fixed Property::getNode() can return the same node multiple times if that
  node was added to the property multiple times. This has the side effect that
  the array returned by this method is not indexed by uuid anymore. That index
  was never advertised but might have been used.
* RepositoryFactoryDoctrineDbal::getRepository now throws a PHPCR\ConfigurationException
  instead of silently returning null on invalid parameters or missing required
  parameters.

1.1.0
-----

Maintenance release with lots of new features and cleanups.

1.1.0-RC1
---------

* Various bugfixes have been merged.

* **2014-01-08**: The fetch depth performance optimization is now fully
  supported.

* **2014-01-08**: You now get a RepositoryException if you try to overwrite one
  of the built-in node types. In 1.0, you got no exception but the custom type
  with the same name as a built-in type was simply ignored.

* **2014-01-07**: We now properly support LENGTH in queries. This required a
  change to the stored data. To update existing installations, you can use this
  migration: https://github.com/wjzijderveld/jackalope-doctrine-dbal-length-migration

* **2014-01-05**: Allowing to set a closure for a custom uuid generator. Set
  the parameter `jackalope.uuid_generator` to a function returning a UUID
  string and pass it as parameter to the repository factory.

* **2014-01-04**: mix:lastModified is now handled automatically. To disable,
  set the option jackalope.auto_lastmodified to `false`.

* **2013-12-26**: cleanup of phpcr-utils lead to adjust cli-config.php.dist.
  If you use the console, you need to sync your cli-config.php file with the
  dist file.

* **2013-12-14**: Added support for logging PHPCR database queries.

1.0.0
-----

* **2013-06-07**: [#75] split the references into 2 tables
 * This splits the phpcr_nodes_foreignkeys into two separate tables
 * Improves performance and allows using native deferred FK capabilities in the future
 * Migration steps
   * run ``bin/jackalope jackalope:init:dbal --dump-sql``
   * Copy and execute all tables, indexes etc related to ``phpcr_nodes_references`` and ``phpcr_nodes_weakreferences``.
   * Run the following SQL statements:
     * INSERT INTO phpcr_nodes_references ( source_id, source_property_name, target_id )
       SELECT source_id, source_property_name, target_id FROM phpcr_nodes_foreignkeys WHERE type = 9;
     * INSERT INTO phpcr_nodes_weakreferences ( source_id, source_property_name, target_id )
       SELECT source_id, source_property_name, target_id FROM phpcr_nodes_foreignkeys WHERE type = 10;
     * DROP TABLE phpcr_nodes_foreignkeys;

* **2013-06-01**: [#109] ensure data is stored as UTC and returned in the default TZ
 * This enables consistent search query behavior regardless of the timezone used
 * Any existing stored nodes need to be modified, so that they are stored again
   to benefit from this change.
