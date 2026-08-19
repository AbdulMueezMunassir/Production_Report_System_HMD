-- sql/database.sql
-- HAMEEDIA HOURLY PRODUCTION SYSTEM

CREATE DATABASE IF NOT EXISTS hameedia_hourly_production;
USE hameedia_hourly_production;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Date Settings (One config per date)
CREATE TABLE report_date_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE UNIQUE NOT NULL,
    working_hours INT NOT NULL DEFAULT 10,
    target_efficiency DECIMAL(5,4) NOT NULL DEFAULT 0.9000,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Devotion Types
CREATE TABLE devotion_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) UNIQUE NOT NULL,
    type ENUM('garment', 'assembly') DEFAULT 'garment',
    status TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Devotion Components
CREATE TABLE devotion_components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    devotion_id INT NOT NULL,
    component_name VARCHAR(100) NOT NULL,
    component_code VARCHAR(50),
    is_match_out TINYINT(1) DEFAULT 0,
    is_dhu TINYINT(1) DEFAULT 0,
    sort_order INT DEFAULT 0,
    status TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (devotion_id) REFERENCES devotion_types(id) ON DELETE CASCADE
);

-- Reports Table
CREATE TABLE reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL,
    devotion_id INT NOT NULL,
    created_by INT NOT NULL,
    status ENUM('draft', 'finalized') DEFAULT 'draft',
    reporting_hours INT NOT NULL,
    target_efficiency DECIMAL(5,4) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    finalized_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_date (report_date),
    INDEX idx_devotion (devotion_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_draft (report_date, devotion_id, status) 
        WHERE status = 'draft',
    FOREIGN KEY (devotion_id) REFERENCES devotion_types(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Report Rows Table
CREATE TABLE report_rows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    component_id INT NOT NULL,
    row_type ENUM('component', 'match_out', 'dhu', 'total') DEFAULT 'component',
    ttl_sam_pc DECIMAL(10,4) DEFAULT 0.0000,
    unit_smv DECIMAL(10,4) DEFAULT 0.0000,
    day_forecast DECIMAL(10,2) DEFAULT 0.00,
    unit_carder INT DEFAULT 0,
    plan_hours DECIMAL(5,2) DEFAULT 0.00,
    worked_hours DECIMAL(5,2) DEFAULT 0.00,
    available_minutes DECIMAL(10,2) DEFAULT 0.00,
    plan_minutes DECIMAL(10,2) DEFAULT 0.00,
    plan_efficiency DECIMAL(5,4) DEFAULT 0.0000,
    target_100 DECIMAL(10,2) DEFAULT 0.00,
    day_total DECIMAL(10,2) DEFAULT 0.00,
    earned_minutes DECIMAL(10,2) DEFAULT 0.00,
    achieved_efficiency DECIMAL(5,4) DEFAULT 0.0000,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report (report_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
    FOREIGN KEY (component_id) REFERENCES devotion_components(id)
);

-- Hourly Production Table (Dynamic hours)
CREATE TABLE hourly_production (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_row_id INT NOT NULL,
    hour_number INT NOT NULL,
    quantity DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_row_hour (report_row_id, hour_number),
    FOREIGN KEY (report_row_id) REFERENCES report_rows(id) ON DELETE CASCADE
);

-- DHU Records Table
CREATE TABLE dhu_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    report_row_id INT,
    hour_number INT,
    dhu_quantity DECIMAL(10,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report (report_id),
    FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
);