CREATE DATABASE IF NOT EXISTS trading_dashboard;
USE trading_dashboard;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(120) DEFAULT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    verification_token VARCHAR(64) DEFAULT NULL,
    is_verified TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    trade_no VARCHAR(50) NOT NULL,
    trade_date DATE NOT NULL,
    day VARCHAR(20),
    no_trades INT,
    opening_bal DECIMAL(15,2),
    closing_bal DECIMAL(15,2),
    profit DECIMAL(15,2),
    loss DECIMAL(15,2),
    setup_type VARCHAR(100),
    entry_reason VARCHAR(255),
    rule_followed VARCHAR(50),
    emotion VARCHAR(100),
    strategy_tags TEXT,
    mistake_tags TEXT,
    notes TEXT,
    screenshot_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS strategy_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS playbooks (
    user_id INT PRIMARY KEY,
    content MEDIUMTEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (username, email, password, is_verified)
VALUES ('admin', 'admin@example.com', '123456', 1)
ON DUPLICATE KEY UPDATE username = username;
