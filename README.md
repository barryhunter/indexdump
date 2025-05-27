# indexdump

A tool for dumping ManticoreSearch or SphinxSearch indexes. Useful for running a **logical backup of a index** from a running searchd server. It 
should be able to dump just about any index, both RT and 'plain' indexes. But in general it most useful for RT indexes, as it can save the data that 
was inserted into the index directly. Note however it will only include 'fields' that where 'stored' (either as a string atttribute, or in the 
docstore). If the field is not stored, then it can't be dumped.

modelled after 'mysqldump' - but downloads data from the search server not the database. 

As well as dumping a 'SQL' dump file (on stdout) it can also dump the data to a .tsv file.

** Note script is creating a logical backup, of a single index. While usable for backups, its probably recommended to create a 'physical' backup of the index files from the server. 

In general a physical backup of the index files, is quicker (both to take copy and to restore a copy!), but requires access to the filesystem on the server. 
These 'dumps' only read access to the sphinxQL/manticoreQL port, but can be quite slow particully for large indexes. 


* bonus feature: it can create a fake mysql schema for the index (actully what inspired me to create this) - so can recreate a mysql database table using data from a RT index in the search server. 


## Known Limitations

* It does NOT dump fields that are not stored (limition of searchd, not this script) 

* Multibyte (UTF8 etc!) hasn't been tested!?
* it CAN issue lock/unlock commands (added in manticore 5), but such locks are only safe for physical backup, they do NOT prevent writes to the index, so might still get 'dirty' data in dump
* can only dump ONE index at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* creating fake a 'CREATE TABLE' command for the index is rudimentry. does not correctly deal with all combinations. (RT and Percolate indexes in a v3.6+ should have proper create table schema from the server, its onlt older versions or for plain indexes, need to create a 'fake' schema. )
* if specing a limit to only dump some rows, then must be <=1000 (max_matches!), larger values doesn't work yet
* NOT tested with percolate indexes (but I think should dump the 'data' ok)

* Currently no real support for taking 'incremental' backups, but could sort of fake it, as can specify 'WHERE' to only download certain rows. 


## Usage

    php indexdump.php [-hhost] [-Pport] [query] [table] [limit] [--data=0] [--schema=0] [--lock=1] [--tsv=dump.tsv.gz] > dump.sql

Examples:

    php indexdump.php index 100 > dump.sql

    # 100 rows from index

    php indexdump.php -hmaster.domain.com \"select * from table where title != 'Other'\" > dump.sql

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

* [--schema=mysql] creates a fake mysql compatible CREATE TABLE schema in the dump. In theory to be used to dump FROM searchd, and create a mysql table of the same data!

* [--lock=1] when dumping, adds 'LOCK index' to lock the index in searchd. 
    Note that the LOCK command in manticoresearch, is a physical lock (prevents writes to teh file) it does NOT prevent other threads doing INSERT/DELETE/UPDATE etc) - so no garentee of index consistency in the dump

* [--tsv=<filename>] creates a GZ compressed Tab Seperated Value dump of the index data (in addition, not instead of the SQL dump output on stdout)

... normally you would pipe the output direct to a file. To compress, pipe it via gzip

    indexdump.php index | gzip > index.sql.gz

## Restoring

In theory the dump file should be directly importable into searchd (eg using the mysql client) 

    cat dump.sql | mysql -P9306 --protocol=tcp

... but the `CREATE TABLE` would only work if the server is in RT-mode. Also the create table might not recreate the index exactly.
You instead create the index manually (either with CREATE TABLE or directly in searchd.conf), and just use the INSERT cmmands from the file


## Requirements

* PHP - 5+ probably, but tested with 7.3

* php-mysqli - for connecting to the searchd server

* A running searchd instance, either Sphinx or Manticore. In general should work with anything 2.x or above, but will work best with 3.x+ where the 
fields are stored with docstore.
... tested mostly with Manticore 3.6

## See other

A node.js version is at https://github.com/webigorkiev/indexdump
... in particular has a --all option to dump all indexes. 

This script was originally based on code from https://github.com/barryhunter/fakedump
... which works in similar way just against a mysql database server.

Also if looking for a physical backup, see the node.js tool, https://github.com/webigorkiev/indexbackup
... manticoresearch are also working on their own physical backup script. 

## Test Script for Dump File Validation

The `test_dump_validity.php` script is designed to test the `indexdump.php` utility. It automates the process of creating a test index in a Manticore Search server, using `indexdump.php` to generate a dump of this index, and then validating the contents of the dump file for correctness.

### Prerequisites

To run the test script, you will need:
*   A PHP installation (e.g., PHP 7.3 or newer). This is required for both `test_dump_validity.php` and the `indexdump.php` script itself.
*   A running Manticore Search server.
*   The `mysqli` PHP extension must be installed and enabled in your PHP configuration.

### Configuration

*   The test script attempts to connect to Manticore Search at `127.0.0.1:9306` by default.
*   You can override these defaults by setting the following environment variables before running the script:
    *   `MANTICORE_HOST`: The hostname or IP address of your Manticore Search server.
    *   `MANTICORE_PORT`: The Manticore Search SQL port (usually 9306).

### How to Run

Execute the script from the command line in the repository's root directory:
```bash
php test_dump_validity.php
```

### What it Does

The script performs the following actions:
1.  **Sets up a test index:** It creates a Real-Time (RT) index named `indexdump_test_rt` using the definitions in `test_setup.sql`. This includes a specific schema and sample data.
2.  **Runs `indexdump.php`:** It executes `indexdump.php` to dump the `indexdump_test_rt` index.
3.  **Validates SQL Syntax:** It performs a basic check on the generated dump to ensure it contains `CREATE TABLE` and `INSERT INTO` statements for the test index.
4.  **Verifies Data Integrity:** It parses the `CREATE TABLE` statement to check for correct column definitions and parses all `INSERT INTO` statements to compare the dumped data row-by-row against the original sample data. This includes checking for correct handling of special characters in strings.
5.  **Cleans Up:** After the validation, it drops the `indexdump_test_rt` index from the Manticore Search server.
6.  **Reports Results:** The script will output "Test PASSED!" if all checks are successful. If any issues are found, it will print detailed error messages and exit with a non-zero status code.
