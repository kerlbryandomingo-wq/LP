-- Concurrency-safe sequence counters for quotation/order/job-order numbers
-- (spec section 7: "avoid generating duplicate ... numbers under
-- concurrent requests"). One row per (scope, year); incremented
-- atomically via `UPDATE ... SET value = LAST_INSERT_ID(value + 1)`
-- inside a transaction — see App\Services\NumberSequenceService.

CREATE TABLE number_sequences (
    scope VARCHAR(20) NOT NULL,
    year INT NOT NULL,
    value INT NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
