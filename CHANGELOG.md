Changelog
=========

* **2013-06-01**: [#109] ensure data is stored as UTC and returned in the default TZ
 * This enables consistent search query behavior regardless of the timezone used
 * Any existing stored nodes need to be modified, so that they are stored again
   to benefit from this change.
