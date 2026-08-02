-- Database Setup
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    starting_capital NUMERIC(12, 2) DEFAULT 10000.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS strategies (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS trades (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    symbol VARCHAR(50) NOT NULL,
    instrument_type VARCHAR(20) NOT NULL,
    expiry_date DATE,
    entry_price NUMERIC(10, 2) NOT NULL,
    exit_price NUMERIC(10, 2) NOT NULL,
    quantity INT NOT NULL,
    pnl NUMERIC(12, 2) NOT NULL,
    strategy_id INT REFERENCES strategies(id) ON DELETE SET NULL,
    trade_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Default strategies
INSERT INTO strategies (user_id, name) VALUES 
(NULL, 'Order Block / Demand Zone'),
(NULL, 'Breakout / Retest'),
(NULL, 'Moving Average Crossover')
ON CONFLICT DO NOTHING;