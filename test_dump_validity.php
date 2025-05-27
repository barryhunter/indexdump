<?php

// test_dump_validity.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// --- Configuration ---
$manticore_host = getenv('MANTICORE_HOST') ?: '127.0.0.1';
$manticore_port = getenv('MANTICORE_PORT') ?: 9306;
$test_index_name = 'indexdump_test_rt'; // Must match test_setup.sql
$indexdump_script_path = __DIR__ . '/indexdump.php';
$setup_sql_path = __DIR__ . '/test_setup.sql';

// --- Error Handling ---
function fail(string $message, mysqli $mysqli = null) {
    echo "ERROR: $message\n";
    if ($mysqli && $mysqli->error) {
        echo "MySQLi Error: " . $mysqli->error . "\n";
    }
    // Attempt to cleanup before exiting
    if ($mysqli) {
        global $test_index_name;
        echo "Attempting cleanup...\n";
        execute_query($mysqli, "DROP TABLE IF EXISTS $test_index_name", true);
    }
    exit(1);
}

// --- Helper function `execute_query` ---
function execute_query(mysqli $mysqli, string $query, bool $ignore_errors = false) {
    $trimmed_query = trim($query);
    if (empty($trimmed_query)) {
        return true; // Skip empty queries
    }
    // echo "Executing query: " . substr($trimmed_query, 0, 100) . (strlen($trimmed_query) > 100 ? "..." : "") . "\n"; // Optional: for debugging
    $result = $mysqli->query($query);
    if (!$result && !$ignore_errors) {
        fail("Query failed: $query", $mysqli);
    }
    return $result ?: true; // Return result for SELECT, true for others
}

echo "Starting Manticore Search index dump validity test...\n";

// --- Main Logic ---

// 1. Connect to Manticore Search
echo "Connecting to Manticore Search ($manticore_host:$manticore_port)...\n";
$mysqli = new mysqli($manticore_host, '', '', '', (int)$manticore_port);
if ($mysqli->connect_error) {
    fail("Connection failed: " . $mysqli->connect_error);
}
echo "Connected successfully.\n";

// 2. Setup Test Index
echo "Setting up test index '$test_index_name' from '$setup_sql_path'...\n";
$setup_sql = file_get_contents($setup_sql_path);
if ($setup_sql === false) {
    fail("Failed to read setup SQL file: $setup_sql_path", $mysqli);
}

$sql_queries = explode(';', $setup_sql);
foreach ($sql_queries as $query) {
    $query = trim($query);
    if (!empty($query)) {
        // The DROP TABLE query should ignore errors as the table might not exist initially
        $ignore = (stripos($query, "DROP TABLE") === 0);
        execute_query($mysqli, $query, $ignore);
    }
}
echo "Test index setup complete.\n";

// 3. Execute indexdump.php
$dump_command = sprintf(
    "php %s -h%s -P%d %s %s",
    escapeshellarg($indexdump_script_path),
    escapeshellarg($manticore_host),
    (int)$manticore_port,
    escapeshellarg($test_index_name),
    escapeshellarg($test_index_name) // index_name and output_table_name
);
echo "Executing indexdump.php: $dump_command\n";
$dump_output = shell_exec($dump_command);

if ($dump_output === null || $dump_output === false || trim($dump_output) === '') {
    // Attempt to get more details if possible, e.g. from stderr, though shell_exec doesn't make this easy
    fail("indexdump.php execution failed or produced no output. Command: $dump_command", $mysqli);
}
echo "indexdump.php executed. Output length: " . strlen($dump_output) . " bytes.\n";

// 4. Validate Dump Output - SQL Syntax (Basic Check)
echo "Validating basic SQL syntax of the dump...\n";
if (strpos($dump_output, "CREATE TABLE $test_index_name") === false) {
    fail("Dump output does not contain 'CREATE TABLE $test_index_name'. Output:\n$dump_output", $mysqli);
}
if (strpos($dump_output, "INSERT INTO $test_index_name") === false) {
    fail("Dump output does not contain 'INSERT INTO $test_index_name'. Output:\n$dump_output", $mysqli);
}
echo "Basic SQL syntax check passed (CREATE TABLE and INSERT INTO found).\n";

// 5. Validate Dump Output - Data Integrity
echo "Validating data integrity of the dump...\n";

$expected_data = [
    ['id' => 1, 'title' => 'First document title', 'category_id' => 101],
    ['id' => 2, 'title' => 'Second document, another example', 'category_id' => 102],
    ['id' => 3, 'title' => 'Third item for testing', 'category_id' => 101],
    ['id' => 4, 'title' => 'The quick brown fox', 'category_id' => 103],
    ['id' => 5, 'title' => "O'Malley's Test", 'category_id' => 104],
];

// 5.1 Parse CREATE TABLE statement
echo "Parsing CREATE TABLE statement...\n";
$create_table_regex = "/CREATE TABLE\s+`?$test_index_name`?\s*\((.*?)\)/is";
if (preg_match($create_table_regex, $dump_output, $matches)) {
    $columns_sql = $matches[1];
    // Normalize whitespace and case for checks
    $normalized_columns_sql = preg_replace('/\s+/', ' ', strtolower(trim($columns_sql)));

    if (strpos($normalized_columns_sql, '`title` text') === false && strpos($normalized_columns_sql, 'title text') === false) {
        fail("Dumped CREATE TABLE statement does not contain 'title TEXT' (or similar). Found: $columns_sql", $mysqli);
    }
    if (strpos($normalized_columns_sql, '`category_id` integer') === false && strpos($normalized_columns_sql, 'category_id integer') === false) {
        fail("Dumped CREATE TABLE statement does not contain 'category_id INTEGER' (or similar). Found: $columns_sql", $mysqli);
    }
    // Note: 'id' column is BIGINT and often first, usually implicitly handled or explicitly by indexdump.
    // We are primarily concerned with the explicitly defined fields in test_setup.sql
    echo "CREATE TABLE statement schema check passed for 'title' and 'category_id'.\n";
} else {
    fail("Could not parse CREATE TABLE statement from dump. Output:\n$dump_output", $mysqli);
}

// 5.2 Parse INSERT INTO statements
echo "Parsing INSERT INTO statements...\n";
$dumped_data = [];
// Regex to capture id, title (string), category_id from VALUES (id, 'title', category_id)
// It needs to handle escaped quotes in the title string: 'O''Malley''s Test'
$insert_regex = "/INSERT INTO\s+`?$test_index_name`?\s*VALUES\s*\(\s*(\d+)\s*,\s*'((?:[^']|'')*)'\s*,\s*(\d+)\s*\)\s*;/is";

if (preg_match_all($insert_regex, $dump_output, $insert_matches, PREG_SET_ORDER)) {
    foreach ($insert_matches as $match) {
        // $match[0] is the full INSERT statement
        // $match[1] is the id (integer)
        // $match[2] is the title (string, with quotes escaped as '')
        // $match[3] is the category_id (integer)

        $id = (int)$match[1];
        // Unescape SQL string: replace '' with '
        $title = str_replace("''", "'", $match[2]);
        $category_id = (int)$match[3];

        $dumped_data[] = ['id' => $id, 'title' => $title, 'category_id' => $category_id];
    }
    echo "Parsed " . count($dumped_data) . " INSERT statements.\n";
} else {
    // It's possible no data was inserted if something went wrong, or if the regex is faulty
    // Check if expected data is also empty
    if (!empty($expected_data)) {
         fail("No INSERT statements found or regex failed to parse them. Dump output might be relevant:\n" . substr($dump_output, strpos($dump_output, "INSERT INTO"), 500), $mysqli);
    } else {
        echo "No INSERT statements found, which is expected as no data was anticipated.\n";
    }
}


// 5.3 Compare dumped data with expected data
echo "Comparing dumped data with expected data...\n";
// Sort both arrays by 'id' to ensure comparison is order-independent
usort($dumped_data, function ($a, $b) {
    return $a['id'] <=> $b['id'];
});
usort($expected_data, function ($a, $b) {
    return $a['id'] <=> $b['id'];
});

if (count($dumped_data) !== count($expected_data)) {
    fail(
        "Data integrity check failed: Mismatch in number of rows. Expected: " . count($expected_data) .
        ", Got: " . count($dumped_data) . "\nExpected:\n" . print_r($expected_data, true) .
        "\nGot:\n" . print_r($dumped_data, true),
        $mysqli
    );
}

for ($i = 0; $i < count($expected_data); $i++) {
    $expected_row = $expected_data[$i];
    $dumped_row = $dumped_data[$i];

    if ($expected_row['id'] !== $dumped_row['id']) {
        fail("Data integrity check failed: Mismatch in 'id' at row index $i (sorted by id). Expected: {$expected_row['id']}, Got: {$dumped_row['id']}", $mysqli);
    }
    if ($expected_row['title'] !== $dumped_row['title']) {
        fail("Data integrity check failed: Mismatch in 'title' for id {$expected_row['id']}. Expected: '{$expected_row['title']}', Got: '{$dumped_row['title']}'", $mysqli);
    }
    if ($expected_row['category_id'] !== $dumped_row['category_id']) {
        fail("Data integrity check failed: Mismatch in 'category_id' for id {$expected_row['id']}. Expected: {$expected_row['category_id']}, Got: {$dumped_row['category_id']}", $mysqli);
    }
}
echo "Data integrity confirmed. All rows match expected data.\n";

// 6. Cleanup
echo "Cleaning up test index '$test_index_name'...\n";
execute_query($mysqli, "DROP TABLE IF EXISTS $test_index_name", true); // ignore_errors = true
echo "Cleanup done.\n";

// 7. Success
echo "\nTest PASSED!\n";
$mysqli->close();
exit(0);

?>
