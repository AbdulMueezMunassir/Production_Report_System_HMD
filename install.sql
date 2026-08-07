-- install.sql - Complete database setup
-- Create database if not exists
CREATE DATABASE IF NOT EXISTS production_db;
USE production_db;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default admin
INSERT INTO users (username, password, full_name, role) VALUES 
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin')
ON DUPLICATE KEY UPDATE username = username;

-- Divisions table
CREATE TABLE IF NOT EXISTS divisions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    code VARCHAR(20) NOT NULL,
    type ENUM('shirt', 'trouser', 'coat', 'shirt_mtm', 'trouser_mtm', 'coat_mtm', 'assembly') DEFAULT 'shirt',
    display_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert divisions
INSERT INTO divisions (name, code, type, display_order) VALUES
('Shirt', 'SH', 'shirt', 1),
('Trouser', 'TR', 'trouser', 2),
('Coat', 'CT', 'coat', 3),
('Shirt MTM', 'SHM', 'shirt_mtm', 4),
('Trouser MTM', 'TRM', 'trouser_mtm', 5),
('Coat MTM', 'CTM', 'coat_mtm', 6),
('Shirt Assembly', 'SHA', 'assembly', 7),
('Trouser Assembly', 'TRA', 'assembly', 8),
('Coat Assembly', 'CTA', 'assembly', 9)
ON DUPLICATE KEY UPDATE name = name;

-- Components table
CREATE TABLE IF NOT EXISTS components (
    id INT PRIMARY KEY AUTO_INCREMENT,
    division_id INT,
    name VARCHAR(50) NOT NULL,
    is_match_out BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE CASCADE
);

-- Insert components for Shirt
INSERT INTO components (division_id, name, display_order) VALUES
(1, 'Front', 1), (1, 'Back', 2), (1, 'Sleeve', 3), (1, 'Collar', 4), (1, 'Cuff', 5);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(1, 'Match Out', TRUE, 6);

-- Insert components for Trouser
INSERT INTO components (division_id, name, display_order) VALUES
(2, 'Front', 1), (2, 'Back', 2), (2, 'Band', 3);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(2, 'Match Out', TRUE, 4);

-- Insert components for Coat
INSERT INTO components (division_id, name, display_order) VALUES
(3, 'Front', 1), (3, 'Back', 2), (3, 'Sleeve', 3), (3, 'Collar', 4);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(3, 'Match Out', TRUE, 5);

-- Insert components for Shirt MTM
INSERT INTO components (division_id, name, display_order) VALUES
(4, 'Front', 1), (4, 'Back', 2), (4, 'Sleeve', 3), (4, 'Collar', 4), (4, 'Cuff', 5);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(4, 'Match Out', TRUE, 6);

-- Insert components for Trouser MTM
INSERT INTO components (division_id, name, display_order) VALUES
(5, 'Front', 1), (5, 'Back', 2), (5, 'Band', 3);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(5, 'Match Out', TRUE, 4);

-- Insert components for Coat MTM
INSERT INTO components (division_id, name, display_order) VALUES
(6, 'Front', 1), (6, 'Back', 2), (6, 'Sleeve', 3), (6, 'Collar', 4);
INSERT INTO components (division_id, name, is_match_out, display_order) VALUES
(6, 'Match Out', TRUE, 5);

-- Insert components for Assembly
INSERT INTO components (division_id, name, display_order) VALUES
(7, 'SHIRT', 1), (8, 'TROUSER', 1), (9, 'COAT', 1);

-- Production reports table
CREATE TABLE IF NOT EXISTS production_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_date DATE NOT NULL,
    division_id INT,
    component_id INT,
    ttl_sam_pc DECIMAL(10,2) DEFAULT 0,
    unit_smv DECIMAL(10,2) DEFAULT 0,
    day_forecast DECIMAL(10,2) DEFAULT 0,
    unit_carder INT DEFAULT 0,
    plan_hours DECIMAL(5,2) DEFAULT 0,
    worked_hours DECIMAL(5,2) DEFAULT 0,
    available_minutes DECIMAL(10,2) DEFAULT 0,
    plan_minutes DECIMAL(10,2) DEFAULT 0,
    plan_eff DECIMAL(5,2) DEFAULT 0,
    target_100 DECIMAL(10,2) DEFAULT 0,
    hour_1 DECIMAL(10,2) DEFAULT 0,
    hour_2 DECIMAL(10,2) DEFAULT 0,
    hour_3 DECIMAL(10,2) DEFAULT 0,
    hour_4 DECIMAL(10,2) DEFAULT 0,
    hour_5 DECIMAL(10,2) DEFAULT 0,
    hour_6 DECIMAL(10,2) DEFAULT 0,
    hour_7 DECIMAL(10,2) DEFAULT 0,
    hour_8 DECIMAL(10,2) DEFAULT 0,
    hour_9 DECIMAL(10,2) DEFAULT 0,
    hour_10 DECIMAL(10,2) DEFAULT 0,
    hour_11 DECIMAL(10,2) DEFAULT 0,
    day_total DECIMAL(10,2) DEFAULT 0,
    acvd_eff DECIMAL(5,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (report_date),
    INDEX idx_division (division_id),
    INDEX idx_component (component_id),
    FOREIGN KEY (division_id) REFERENCES divisions(id),
    FOREIGN KEY (component_id) REFERENCES components(id)
);