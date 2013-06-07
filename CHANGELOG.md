Changelog
=========

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
