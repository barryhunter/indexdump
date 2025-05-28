#!/usr/bin/php
<?php
 /*
Works like 'mysqldump' but for indexes in Manticore/Sphinx

... useful in theory for a logical backup of a ManticoreSearch or SphinxSearch Index. Most useful for RT indexes, but can in theory dump stored fields and attributes from even plain indexes.

Known Limiations:
* Multibyte (UTF8 etc!) hasn't been tested!?
* doesn't deal propelly with locks, collations, timezones and version compatiblity etc
* it CAN issue lock/unlock commands (added in mantiore 5), but such locks are only safe for physical backup, they do NOT prevent writes to the index, so might still get 'dirty' data in a logical backup
* can only dump ONE index at a time!
* does not support either extended or complete inserts (like mysqldump does), nor 'replace into'
* creating fake a 'CREATE TABLE' command for the index is rudimentry. does not correctly deal with all combinations
* if specing a limit to only dump some rows, then must be <=1000 (max_matches!), larger values doesn't work yet
* NOT tested with percolate indexes (but I think should dump the 'data' ok, but possiblt not the schema!)

See also:
Node.js version of the same thing: https://www.npmjs.com/package/indexdump

***********************************************

 * This file copyright (C) 2022 Barry Hunter (github@barryhunter.co.uk)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

// Global script start time
$script_start_time = microtime(true);

// Function to escape TSV values
function escape_tsv($in) {
	return addcslashes(str_replace("\r",'',$in),"\\\n\t\0");
}

/**
 * Dumps a single Manticore Search index (table) to the specified output handle.
 *
 * This function handles the schema and data dumping for a given table,
 * applying options such as schema type, data inclusion, and locking.
 *
 * @param mysqli   $db_connection    The active MySQLi connection to the Manticore server.
 * @param string   $table_name_param The name of the index/table to be dumped.
 * @param resource $output_handle    The file handle where the SQL dump will be written 
 *                                   (e.g., STDOUT or a resource from fopen()).
 * @param array    $p_options        An associative array of options controlling the dump.
 *                                   Expected keys: 'schema' (true, false, 'mysql'), 'data' (true, false),
 *                                   'lock' (true, false), 'limit' (int or false).
 * @param string|null $tsv_output_path Optional: Path to the file where TSV data should be written.
 *                                     If null, TSV output is skipped.
 * @return bool    True on successful completion of the dump for this table, 
 *                 false on handled errors where an exception wasn't thrown (rare).
 * @throws Exception On critical errors during the dump process, such as failed SQL queries,
 *                   which prevent the dump from proceeding for this table.
 */
function dump_index($db_connection, $table_name_param, $output_handle, $p_options, $tsv_output_path = null) {
	$func_start_time = microtime(true);
	// $table_name_param is the specific table to dump in this function call.
	// $p_options for schema, data, lock, limit etc.

	fwrite($output_handle, "-- Dump for table `$table_name_param` started at: ".date('r')."\n\n");

	$mvas = array(); // Initialize MVAs array for this specific index dump

	// Construct the base select query for this table
	// The $p_options['select'] is primarily for single dump mode with a custom query.
	// For dumping a specific table, we construct a new select.
	$current_select_query = "select * from `{$table_name_param}`";


	######################################
	# fake schema for mysql
	if ($p_options['schema'] === 'mysql') {
		fwrite($output_handle, "-- dumping mysql compatible schema for `$table_name_param`\n\n");
		// For schema generation, limit is not essential, but original used 1000 to get field lengths.
		// We'll use the constructed query for the current table.
		$schema_query_postfix = " LIMIT 1000"; 
		$result = mysqli_query($db_connection, $current_select_query . $schema_query_postfix);
		if (!$result) {
			throw new Exception("Error running schema query for `$table_name_param`: " . mysqli_error($db_connection));
		}

		$fields = mysqli_fetch_fields($result);
		if (!$fields) {
			// Though mysqli_fetch_fields itself doesn't typically fail this way if query was successful
			throw new Exception("Error fetching fields for schema (mysql) for `$table_name_param`");
		}
		mysqli_free_result($result);

		fwrite($output_handle, "CREATE TABLE `{$table_name_param}` (\n"); $sep = '';
		foreach ($fields as $key => $obj) {
			fwrite($output_handle, $sep);
			switch($obj->type) {
				case MYSQLI_TYPE_INT24 :
					fwrite($output_handle, "\t`{$obj->name}` mediumint unsigned not null"); break;
				case MYSQLI_TYPE_LONG :
					fwrite($output_handle, "\t`{$obj->name}` int unsigned not null"); break;
				case MYSQLI_TYPE_LONGLONG :
					fwrite($output_handle, "\t`{$obj->name}` bigint not null");
					if ($obj->name == 'id')
						fwrite($output_handle, " primary key");
					break;
				case MYSQLI_TYPE_SHORT :
					fwrite($output_handle, "\t`{$obj->name}` smallint unsigned not null"); break;
				case MYSQLI_TYPE_TINY :
					fwrite($output_handle, "\t`{$obj->name}` tinyint unsigned not null"); break;
				case MYSQLI_TYPE_FLOAT :
					fwrite($output_handle, "\t`{$obj->name}` float not null"); break;
				case MYSQLI_TYPE_DOUBLE :
				case MYSQLI_TYPE_DECIMAL :
					fwrite($output_handle, "\t`{$obj->name}` double not null"); break;
				case MYSQLI_TYPE_STRING :
					if ($obj->max_length <= 1024) {
						fwrite($output_handle, "\t`{$obj->name}` varchar({$obj->max_length}) not null"); break;
					}
				default:
					fwrite($output_handle, "\t`{$obj->name}` text not null"); break;
			}
			$sep = ",\n";
		}
		fwrite($output_handle, "\n);\n\n");

	######################################
	# schema
	} elseif (!empty($p_options['schema'])) {
		fwrite($output_handle, "-- dumping schema (to create the table in RT mode) for `$table_name_param`\n\n");

		$result = mysqli_query($db_connection, "SHOW CREATE TABLE `{$table_name_param}`");
		$create_table_stmt = '';

		if ($result) {
			$row = mysqli_fetch_assoc($result);
			$create_table_stmt = $row['Create Table'];
			mysqli_free_result($result);
		} else {
			$create_table_stmt = "CREATE TABLE `{$table_name_param}` ("; $sep = "\n";
			$describe_result = mysqli_query($db_connection, "DESCRIBE `{$table_name_param}`");
			if (!$describe_result) {
				throw new Exception("Error describing table `$table_name_param` for schema generation: " . mysqli_error($db_connection));
			}

			$index_fields = array();
			while ($row = mysqli_fetch_assoc($describe_result)) {
				if ($row['Type'] == 'local') {
					// For distributed indexes, describe the agent might be needed, complex case.
					// Original script re-ran describe on agent, for now, keep it simple.
					// This part might need more robust handling for distributed setups.
					$agent_describe_result = mysqli_query($db_connection,"DESCRIBE `{$row['Agent']}`");
					if ($agent_describe_result) {
						$row = mysqli_fetch_assoc($agent_describe_result);
						mysqli_free_result($agent_describe_result);
					} else {
						fwrite(STDERR, "Warning: Could not describe agent `{$row['Agent']}` for distributed index `{$table_name_param}`.\n");
					}
				}

				if ($row['Field'] != 'id' && $row['Type'] != 'field') {
					$create_table_stmt .= "$sep  `{$row['Field']}` ";
					if ($row['Type'] == 'text' && $row['Properties'] == 'indexed stored')
						$create_table_stmt .= " text";
					elseif ($row['Type'] == 'text')
						$create_table_stmt .= " text {$row['Properties']}";
					elseif ($row['Type'] == 'uint')
						$create_table_stmt .= " integer";
					elseif ($row['Type'] == 'mva') {
						$create_table_stmt .= " multi";
						$mvas[$row['Field']] = 1; // Populate MVAs for this table
					} elseif ($row['Type'] == 'string') {
						$create_table_stmt .= " string";
						// Original had: if (isset($fields[$row['Field']])) which seems like a bug as $fields was from mysql schema part
						// Assuming it meant to check if it's an indexed string attribute.
						// This part is tricky without full context of original $fields variable.
						// For now, if it's string, it's string. Add 'indexed' if properties say so.
						if (strpos($row['Properties'], 'indexed') !== false) {
							 $create_table_stmt .= " indexed";
						}
					} else
						$create_table_stmt .= " {$row['Type']}";
					$sep = ",\n";
				} elseif ($row['Type'] == 'field') {
					$index_fields[$row['Field']] = 1;
				}
			}
			$create_table_stmt .= "\n)";
			mysqli_free_result($describe_result);
		}
		fwrite($output_handle, "$create_table_stmt;\n\n");

		if (!empty($index_fields)) {
			$warning_msg = "-- WARNING: the index `{$table_name_param}` contains the following fields that were NOT stored (only indexed), so not included in output:\n";
			$warning_msg .= "--  ".implode(', ',array_keys($index_fields))."\n";
			fwrite($output_handle, $warning_msg); // Write to dump as comment
			fwrite(STDERR, $warning_msg); // Also to STDERR for visibility
		}
	}

	######################################
	# data
	if (!empty($p_options['data'])) {
		$tsv_fh = null; // Initialize TSV file handle
		// Check if TSV output is requested and a valid path is provided
		if (is_string($tsv_output_path) && !empty($tsv_output_path)) {
			$tsv_fh = @fopen($tsv_output_path, 'w'); // Use @ to suppress default PHP warning, we'll handle it
			if (!$tsv_fh) {
				fwrite(STDERR, "Error: Could not open TSV file for writing: $tsv_output_path. TSV output will be skipped.\n");
				// $tsv_fh remains null, so TSV operations below will be skipped.
			}
		}

		if (!empty($p_options['lock'])) {
			if (!mysqli_query($db_connection, "LOCK TABLES `{$table_name_param}` READ")) {
				fwrite(STDERR, "Warning: Failed to lock table `{$table_name_param}`: " . mysqli_error($db_connection) . "\n");
				// Continue even if lock fails, as per original behavior
			}
		}

		$lastid = 0;
		$total_rows_dumped = 0;
		while(true) {
			$query_to_run = $current_select_query; // Use the base query for the current table

			// Handle $p_options['limit'] - primarily for single table mode.
			// If called from dump-all-indexes, limit is usually not desired per table.
			// This logic assumes limit is for the total rows from this specific table.
			$current_batch_limit = 1000; // Default batch size
			$postfix = '';

			if (!empty($p_options['limit'])) {
				$remaining_limit = $p_options['limit'] - $total_rows_dumped;
				if ($remaining_limit <= 0) break; // Limit reached
				if ($remaining_limit < $current_batch_limit) {
					$current_batch_limit = $remaining_limit;
				}
			}
			
			// Append WHERE/AND id > $lastid
			if (preg_match('/\bWHERE\b/i', $query_to_run)) {
				$postfix = ($lastid) ? " AND id > $lastid" : '';
			} else {
				$postfix = ($lastid) ? " WHERE id > $lastid" : '';
			}
			$postfix .= " ORDER BY id ASC LIMIT $current_batch_limit";

			$result = mysqli_query($db_connection, $query_to_run . $postfix);
			if (!$result) {
				$error_message = "Error running data query for `$table_name_param`" . $postfix . ": " . mysqli_error($db_connection);
				if (!empty($p_options['lock'])) {
					mysqli_query($db_connection, "UNLOCK TABLES"); // Attempt to unlock before throwing
				}
				throw new Exception($error_message);
			}

			$num_rows_in_batch = mysqli_num_rows($result);
			if (!$num_rows_in_batch) {
				mysqli_free_result($result);
				break; // No more rows
			}

			fwrite($output_handle, "-- dumping ".$num_rows_in_batch." rows from `$table_name_param` (total: ".($total_rows_dumped + $num_rows_in_batch).")\n\n");

			$names = array(); // For SQL INSERT statements (quoted)
			$plain_names = array(); // For TSV header (raw field names)
			$types = array();
			$result_fields = mysqli_fetch_fields($result);

			foreach ($result_fields as $key => $obj) {
				$names[] = "`{$obj->name}`"; 
				$plain_names[] = $obj->name; // Store raw name for TSV header
				switch($obj->type) {
					case MYSQLI_TYPE_INT24 :
					case MYSQLI_TYPE_LONG :
					case MYSQLI_TYPE_LONGLONG :
					case MYSQLI_TYPE_SHORT :
					case MYSQLI_TYPE_TINY :
						$types[] = 'int'; break;
					case MYSQLI_TYPE_FLOAT :
					case MYSQLI_TYPE_DOUBLE :
					case MYSQLI_TYPE_DECIMAL :
						$types[] = 'real'; break;
					default:
						// Check against $mvas populated for *this* table
						if (isset($mvas[$obj->name]) && $p_options['schema'] !== 'mysql') {
							$types[] = 'mva'; break;
						}
						$types[] = 'other'; break;
				}
			}
			// mysqli_free_result($result_fields); // This was incorrect, mysqli_fetch_fields returns array of objects

			// Write TSV header if TSV file is open and header not yet written
			if ($tsv_fh && $total_rows_dumped == 0) { // Only write header once
				$header_row_values = array_map('escape_tsv', $plain_names);
				if (@fwrite($tsv_fh, implode("\t", $header_row_values) . "\n") === false) {
					fwrite(STDERR, "Error writing TSV header to: $tsv_output_path. Further TSV output for this table will be skipped.\n");
					fclose($tsv_fh); // Close on error
					$tsv_fh = null;  // Stop further TSV attempts for this table
				}
			}
			
			// Process rows for SQL and TSV
			while($row = mysqli_fetch_row($result)) {
				// SQL Output (existing logic)
				fwrite($output_handle, "INSERT INTO `{$table_name_param}` (" . implode(",", $names) . ") VALUES (");
				$sep = '';
				foreach($row as $idx => $value) {
					if (is_null($value))
						$value_str = 'NULL';
					elseif ($types[$idx] == 'mva')
						$value_str = "(".mysqli_real_escape_string($db_connection, $value).")";
					elseif ($types[$idx] != 'int' && $types[$idx] != 'real')
						$value_str = "'".mysqli_real_escape_string($db_connection, $value)."'";
					else
						$value_str = $value; 
					fwrite($output_handle, $sep . $value_str);
					$sep = ',';
				}
				fwrite($output_handle, ");\n");

				// TSV Output
				if ($tsv_fh) { // Check if TSV file handle is still valid
					$tsv_row_values = array_map('escape_tsv', $row);
					if (@fwrite($tsv_fh, implode("\t", $tsv_row_values) . "\n") === false) {
						fwrite(STDERR, "Error writing TSV data row to: $tsv_output_path. Further TSV output for this table will be skipped.\n");
						fclose($tsv_fh); // Close on error
						$tsv_fh = null;  // Stop further TSV attempts
					}
				}
				
				$lastid = $row[0]; 
			}
			mysqli_free_result($result);
			$total_rows_dumped += $num_rows_in_batch;

			if ($num_rows_in_batch < $current_batch_limit) break; 
			if (!empty($p_options['limit']) && $total_rows_dumped >= $p_options['limit']) break; 
		}

		if (!empty($p_options['lock'])) mysqli_query($db_connection, "UNLOCK TABLES");
		
		// Close TSV file handle if it was opened and is still valid
		if ($tsv_fh) {
			fclose($tsv_fh);
		}
	}
	######################################
	$func_end_time = microtime(true);
	fwrite($output_handle, sprintf("\n-- Dump for table `%s` completed in %0.3f seconds\n\n", $table_name_param, $func_end_time - $func_start_time));
	return true; // Success
}


######################################
# defaults

$p = array(
	'schema'=>true, 
	'data'=>true,
	'lock'=>0,
	'P'=> 9306,
	'select' => null, // Will be populated if user provides a specific query
	'table' => null,  // Will be populated by arg parser or inferred
	'limit'=>false,
	'dump-all-indexes'=>false,
	'tsv'=>null,      // Path for TSV output, or true if flag for dump-all-indexes
);

######################################
# basic argument parser! (remains largely the same)
// This parser handles command-line arguments.
// - Options start with '-' or '--'.
// - Positional arguments are collected in $s and processed later.
if (count($argv) > 1) {
	$s=array(); // Array to hold non-option (positional) arguments
	for($i=1;$i<count($argv);$i++) {
		if (strpos($argv[$i],'-') === 0) {
			if (preg_match('/^-+(\w+)=(.+)/',$argv[$i],$m) || preg_match('/^-(\w)(.+)/',$argv[$i],$m)) {
				$key = $m[1];
				$value = $m[2];
			} else {
				$key = trim($argv[$i],' -');
				if ($key == 'dump-all-indexes') {
					$value = true;
				} elseif ($key == 'tsv') {
					$value = true; // Set as flag
				} else {
					// Default behavior: next argument is the value
					// Ensure $argv[$i+1] exists before assigning, to prevent error if option is last
					if (isset($argv[$i+1])) {
						$value = $argv[++$i];
					} else {
						// If option is last and expects a value, this could be an error or handled by specific option logic later
						// For now, assign true, consistent with boolean flags if no value given
						$value = true; 
					}
				}
			}
			$p[$key] = $value;
		} else {
			$s[] = $argv[$i];
		}
	}
	if (!empty($p['h']) || !empty($p['u'])) {
		$db = mysqli_connect($p['h'].':'.$p['P'],'','','') or die("unable to connect\n".mysqli_error($db)."\n");
	}
	if (!empty($s)) {
		$last_arg = end($s);
		if (is_numeric($last_arg)) { 
            		$p['limit'] = array_pop($s);
		}
		
		// If a query is provided as a non-option argument, it's $p['select']
		// If a table name is provided, it's $p['table']
		// If both, the last one usually wins for $p['select'] if it looks like a query.
		// This logic attempts to mimic the original's flexibility.
		$last_arg = end($s);
		if (count($s) == 1) {
			if (preg_match('/^\w+$/', $last_arg) && strpos(strtolower($last_arg), 'select ') === false) {
				$p['table'] = $last_arg;
			} else {
				$p['select'] = $last_arg;
			}
		} elseif (count($s) > 1) {
			$p['select'] = $s[0]; // First non-option is query
			$p['table'] = $s[1];  // Second non-option is table
		}
	}
} else { // No arguments, or only options like -h
	// Check if essential info is missing if not --dump-all-indexes
	if (empty($p['dump-all-indexes']) && empty($p['table']) && empty($p['select'])) {
		die("
Usage:
php indexdump.php [-hhost] [-Pport] [--schema=<0|1|mysql>] [--data=<0|1>] [--lock=<0|1>] [index_name] [limit]
php indexdump.php [-hhost] [-Pport] [--schema=<0|1|mysql>] [--data=<0|1>] [--lock=<0|1>] \"SELECT_query\" [table_name_for_create] [limit]
php indexdump.php --dump-all-indexes [-hhost] [-Pport] [--schema=<0|1|mysql>] [--data=<0|1>] [--lock=<0|1>] [ignored_args...]

Arguments (for single index dump):
  [index_name]         Name of the index to dump.
  [table_name_for_create] Optional: Name to use in CREATE TABLE if different from index_name or if using a custom query.
  [limit]              Optional: Limit the number of rows dumped.
  \"SELECT_query\"       Custom SELECT query to dump specific data. If used, [index_name] might be needed for CREATE TABLE.

Options:
  -h<host>             Connect to host.
  -P<port>             Port number to use for connection (default: 9306).
  --schema=<0|1|mysql> Dump schema. 0=no, 1=yes (Manticore RT format), 'mysql'=MySQL compatible (default: 1).
  --data=<0|1>         Dump data as INSERT statements. 0=no, 1=yes (default: 1).
  --lock=<0|1>         Lock table during data dump. 0=no, 1=yes (default: 0).
  --tsv[=filename.tsv] Output data in TSV format.
                       - For single index dump: `--tsv=filename.tsv` outputs data to `filename.tsv`.
                         If schema is also dumped, it goes to STDOUT or the .sql file as usual.
                       - With `--dump-all-indexes`: Use just `--tsv` (no filename). This creates an
                         `index_name.tsv` file for each dumped index in the dated directory,
                         alongside its `.sql` file. Any filename given (e.g., --tsv=file) is ignored.
  --dump-all-indexes   Dump all indexes found on the server.
                       - Creates a dated directory (e.g., index_dumps_YYYY-MM-DD).
                       - Each index is dumped into its own .sql (and optionally .tsv) file.
                       - Positional arguments like [index_name], [query], [limit] are IGNORED.
                       - Options like --schema, --data, --lock, --tsv (as flag) apply to each dump.
                       - A summary of total, successful, and failed dumps is printed at the end.
  --d                  Debug: print parsed parameters and exit.


Examples:
  php indexdump.php my_rt_index
    # Dumps schema and data for 'my_rt_index' to STDOUT.
  php indexdump.php my_rt_index 100
    # Dumps schema and 100 rows of data for 'my_rt_index'.
  php indexdump.php -P9308 \"SELECT * FROM my_other_index WHERE group_id=5\" my_other_index --tsv=custom.tsv
    # Runs a custom query, outputs SQL to STDOUT, and TSV data to 'custom.tsv'.
  php indexdump.php --dump-all-indexes -hdb.example.com --schema=mysql --data=0
    # Dumps only MySQL-compatible schemas for all indexes from db.example.com
    # into a directory like 'index_dumps_YYYY-MM-DD', no .tsv files.
  php indexdump.php --dump-all-indexes --tsv
    # Dumps all indexes as .sql and .tsv files into the dated directory.
");
	}	
}

// Argument processing:
// If a select query is given but no table name for CREATE TABLE, try to infer it.
if (!empty($p['select']) && empty($p['table']) && !$p['dump-all-indexes']) {
	if (preg_match('/\sfrom\s+(`?\w+`?\.)?`?(\w+)`?\s+/i', $p['select'], $m)) {
		$p['table'] = $m[2];
		fwrite(STDERR, "Inferred table name '{$p['table']}' from query for CREATE TABLE statement.\n");
	} else {
		// If we can't infer, and schema is requested, it's an issue.
		// Data dump might still work if the query is self-contained.
		if($p['schema']) {
			die("Error: Cannot infer table name from query '{$p['select']}' for schema dump. Please specify a table name.\n");
		}
	}
}

// If no arguments were given, and not dumping all, show help. (Handled by revised die() message)
// If only a table name is given, set default select query
if (!empty($p['table']) && empty($p['select']) && !$p['dump-all-indexes']) {
    $p['select'] = "select * from `{$p['table']}`";
}


if (!empty($p['d'])) { // Debug option
	print_r($p);
	exit;
}

######################################

if (empty($db)) {
	// Default connection if not already established via -h
	$host = isset($p['h']) ? $p['h'] : 'localhost';
	$port = $p['P'];
	$db = mysqli_connect("$host:$port",'','','');
}

// Ensure connection was successful
if (!$db) {
    die("Failed to connect to Manticore: " . mysqli_connect_error() . "\n");
}

if ($p['dump-all-indexes']) {
	print "-- Fetching all table names for --dump-all-indexes\n";
	$result = mysqli_query($db, "SHOW TABLES");
	if (!$result) {
		die("Error executing SHOW TABLES: " . mysqli_error($db) . "\n");
	}

	$all_tables = array(); // Array to store all table names fetched from the server.
	while ($row = mysqli_fetch_row($result)) {
		$all_tables[] = $row[0];
	}
	mysqli_free_result($result);

	if (empty($all_tables)) {
		fwrite(STDOUT, "No tables found on the server.\n"); // Changed to STDOUT for consistency
		exit; // Exit if no tables to process
	}

	// Initialize counters for the dump summary.
	$total_tables_processed = count($all_tables);
	$successful_dumps = 0;
	$failed_dumps = 0;
	// Prepare directory for storing all index dumps.
	$dump_dir = "index_dumps_" . date("Y-m-d"); // Dated directory for dumps.

	// Create the dump directory if it doesn't exist.
	if (!is_dir($dump_dir)) {
		if (mkdir($dump_dir, 0755, true)) { 
			fwrite(STDOUT, "Created directory: $dump_dir\n");
		} else {
			fwrite(STDERR, "Error: Could not create directory $dump_dir. Cannot proceed with dumping all indexes.\n");
			exit; // Exit if directory creation fails
		}
	} else {
		fwrite(STDOUT, "Using existing directory: $dump_dir\n");
	}

	// Loop through each table identified by SHOW TABLES.
	foreach ($all_tables as $table_name_loop) {
		// Sanitize table name to create a valid filename.
		$sanitized_table_name = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $table_name_loop);
		if (empty($sanitized_table_name)) {
			fwrite(STDERR, "Warning: Skipping table `{$table_name_loop}` due to problematic name after sanitization (resulted in empty string).\n");
			$failed_dumps++; // Increment failed counter.
			continue; // Skip to the next table.
		}
		// Construct the full path for the output .sql file.
		$output_file_path = $dump_dir . '/' . $sanitized_table_name . '.sql';

		fwrite(STDOUT, "Preparing to dump index `$table_name_loop` to `$output_file_path`...\n");

		$fh = null; // Initialize file handle for safety.
		try {
			// Attempt to open the specific .sql file for writing.
			$fh = fopen($output_file_path, 'w');
			if (!$fh) {
				fwrite(STDERR, "Error: Could not open file for writing: $output_file_path. Skipping this table.\n");
				$failed_dumps++; // Increment failed counter.
				continue; // Skip to the next table.
			}

			// Prepare options for this specific table, overriding table/select from global $p.
			$current_p_options = $p; // Copy global options (like --schema, --data, --lock).
			$current_p_options['table'] = $table_name_loop; // Set specific table for dump_index.
			$current_p_options['select'] = "select * from `{$table_name_loop}`"; // Base select for this table.
			// Note: $current_p_options['limit'] will use the global --limit if set.
			// If a global limit is not desired for --dump-all-indexes, set $current_p_options['limit'] = false here.
			
			// Determine TSV output path for --dump-all-indexes mode
			$current_tsv_path = null;
			if ($p['tsv'] === true) { // --tsv flag is active for dump-all-indexes
				// $sanitized_table_name is from the current loop iteration
				$current_tsv_path = $dump_dir . '/' . $sanitized_table_name . '.tsv';
			}
			// If $p['tsv'] is a string (filename specified by user), it's ignored in --dump-all-indexes mode, 
			// and $current_tsv_path will remain null unless $p['tsv'] was specifically 'true'.

			// Call the core dump_index function.
			if (dump_index($db, $table_name_loop, $fh, $current_p_options, $current_tsv_path)) {
				$success_message = "Successfully dumped SQL for `$table_name_loop` to `$output_file_path`";
                if ($current_tsv_path && file_exists($current_tsv_path) && filesize($current_tsv_path) > 0) {
                    $success_message .= " and TSV to `$current_tsv_path`";
                } elseif ($p['tsv'] === true) { // --tsv was flagged but file might be empty/not created
                     $success_message .= ". TSV output was requested";
                     if ($current_tsv_path) {
                        $success_message .= " to `$current_tsv_path`";
                     }
                     $success_message .= " (file may be empty if no data or if TSV writing failed)";
                }
                $success_message .= ".\n";
                fwrite(STDOUT, $success_message);
				$successful_dumps++; 
			} else {
				// This case might be less common if dump_index throws exceptions for most errors
				fwrite(STDERR, "Failed to dump SQL for `$table_name_loop` to `$output_file_path` (dump_index returned false).\n");
				$failed_dumps++; 
			}
		} catch (Exception $e) {
			// Catch any exceptions thrown by dump_index (e.g., SQL errors).
			fwrite(STDERR, "Error dumping index `$table_name_loop`: " . $e->getMessage() . "\n");
			$failed_dumps++; // Increment failed counter.
		} finally {
			// Ensure the file handle is closed if it was opened.
			if ($fh) {
				fclose($fh);
			}
		}
	}

	// Print the final summary of the dump operation.
	fwrite(STDOUT, "Finished processing all indexes.\n");
	fwrite(STDOUT, "Summary: Processed $total_tables_processed tables. $successful_dumps successful, $failed_dumps failed. Dumps are in directory `$dump_dir`.\n");
	exit; // Successfully exit after attempting to dump all indexes.
} else {
	// Single index dump mode (original behavior).
	// Wrapped in try-catch for consistency, as dump_index can throw exceptions.
	if (empty($p['table'])) {
		die("Error: No table name specified for single index dump. Use 'php indexdump.php <indexname>' or provide a query.\n");
	}

	try {
		// Determine the TSV output path for single dump mode.
		// $p['tsv'] holds the filename if --tsv=filename.tsv was used,
		// or true if --tsv was used as a flag, or null if not used.
		// dump_index expects a string path or null. If $p['tsv'] is true (flag mode),
		// we should pass null for single dump, as a filename is required.
		$single_tsv_path = is_string($p['tsv']) ? $p['tsv'] : null;

		if ($p['tsv'] === true && !is_string($single_tsv_path)) {
			fwrite(STDERR, "Notice: --tsv used as a flag in single dump mode. TSV output will be skipped as a filename is required (e.g., --tsv=output.tsv).\n");
		}

		if (dump_index($db, $p['table'], STDOUT, $p, $single_tsv_path)) {
			// Success message is part of dump_index for individual tables
			if ($single_tsv_path && file_exists($single_tsv_path) && filesize($single_tsv_path) > 0) {
				// Provide confirmation that TSV was also written, to STDERR for visibility alongside other messages.
				fwrite(STDERR, "TSV data for `{$p['table']}` also written to `{$single_tsv_path}`.\n");
			}
		} else {
			// This case might be less common if dump_index throws exceptions
			fwrite(STDERR, "An error occurred during the dump of table `{$p['table']}` (dump_index returned false).\n");
		}
	} catch (Exception $e) {
		fwrite(STDERR, "An error occurred during the dump of table `{$p['table']}`: " . $e->getMessage() . "\n");
	}
}

######################################
// Global script end time
$script_end_time = microtime(true);
printf("\n-- Total script execution time: %0.3f seconds\n\n", $script_end_time - $script_start_time);
