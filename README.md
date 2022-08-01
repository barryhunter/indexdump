# indexdump

A tool for dumping ManticoreSearch or SphinxSearch indexes. Useful for running a **logical backup of a index** from a running searchd server. It 
should be able to dump just about any index, both RT and 'plain' indexes. But in general it most useful for RT indexes, as it can save the data that 
was inserted into the index directly. Note however it will only include 'fields' that where 'stored' (either as a string atttribute, or in the 
docstore). If the field is not stored, then it can't be dumped.

modelled after 'mysqldump' - but downloads data from the search server not the database. 

As well as dumping a 'SQL' dump file (on stdout) it can also dump the data to a .tsv file.

**

## Known Limitations

* It does NOT dump fields that are not stored (limition of searchd, not this script) 

* Multibyte (UTF8 etc!) hasn't been tested!?
* doesn't deal propelly with locks, collations, timezones and version compatiblity etc
* it CAN issue lock/unlock commands (added in mantiore 5), but such locks are only safe for physical backup, they do NOT prevent writes to the index, so might still get 'dirty' dat$
* can only dump ONE index at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* creating fake a 'CREATE TABLE' command for the index is rudimentry. does not correctly deal with all combinations
* if specing a limit to only dump some rows, then must be <=1000 (max_matches!), larger values doesn't work yet
* NOT tested with percolate indexes (but I think should dump the 'data' ok, but possiblt not the schema!)


## Usage

    php indexdump.php [-hhost] [-Pport] [query] [table] [limit] [--data=0] [--schema=0] [--lock=1] > dump.sql

Examples:

    php indexdump.php index 100
    # 100 rows from index

    php indexdump.php -hmaster.domain.com \"select * from table where title != 'Other'\"
    # runs the full query against a specific index - dumping all rows. - you CAN use MATCH(..)
    # NOTE: should NOT add ORDER BY nor LIMIT to the query, as this script needs to add them to dump all rows (because of max_matches)


* [-hhost] -- specify a host to connect to (defaults to localhost)

* [-Pport] -- specify a port to use (defaults to 9306 - the default sphinxQL/manticoreQL port)

* [query] is the main workhorse, specify a full sphinx query like "SELECT * FROM index" - but importantly its a full query, so can select what columns, rows etc to include.
    NOTE: should NOT add ORDER BY nor LIMIT to the query, as this script needs to add them to dump all rows (because of max_matches)

* [table] is the index to dump (using a default "SELECT * FROM index" query) - specify a query OR a bare index name, not both.

* [limit] number of rows to dump (otherwise will dump all rows)
    NOTE:  then must be <=1000 (max_matches!), larger values doesn't work yet

* [--data=0] [--schema=0] can optionally turn off dumping of data and/or schema seperatly

* [--lock=1] when dumping, adds 'LOCK index' to lock the index in searchd. Note that the LOCK command in manticoresearch, is a physical lock (prevents writes to teh file) it does NOT prevent other threads doing INSERT/DELETE/UPDATE etc) - so no garentee of index onconsistency in the dump


## Restoring

In theory the dump file should be directly importable into searchd (eg using the mysql client) 

    cat dump.sql | mysql -P9306 --protocol=tcp

... but the `CREATE TABLE` would only work if the server is in RT-mode. Also the create table might not recreate the index exactly.
You instead create the index manually (either with CREATE TABLE or directly in searchd.conf), and just use the INSERT cmmands from the file


## Requirements

PHP - 5+ probably, but tested with 7.3
php-mysqli - for connecting to the searchd server

A running searchd instance, either Sphinx or Manticore. In general should work with anything 2.x or above, but will work best with 3.x+ where the 
fields are stored with docstore.

## See other

A node.js version is at https://github.com/webigorkiev/indexdump/
