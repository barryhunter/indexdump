-- Test setup script for indexdump.php validation

-- Drop the test index if it already exists (optional, but good for idempotency)
DROP TABLE IF EXISTS indexdump_test_rt;

-- Create a new Real-Time index for testing
-- It includes an 'id' column (implicitly created by Manticore for RT indexes and used by indexdump.php for iteration),
-- a text field, and an integer attribute.
CREATE TABLE indexdump_test_rt (
    title TEXT,
    category_id INTEGER
);

-- Insert some sample data
INSERT INTO indexdump_test_rt (id, title, category_id) VALUES (1, 'First document title', 101);
INSERT INTO indexdump_test_rt (id, title, category_id) VALUES (2, 'Second document, another example', 102);
INSERT INTO indexdump_test_rt (id, title, category_id) VALUES (3, 'Third item for testing', 101);
INSERT INTO indexdump_test_rt (id, title, category_id) VALUES (4, 'The quick brown fox', 103);
-- Add a row with a potentially problematic character like a single quote
INSERT INTO indexdump_test_rt (id, title, category_id) VALUES (5, 'O''Malley''s Test', 104);
