CREATE DATABASE IF NOT EXISTS customer_system;
USE customer_system;
CREATE TABLE records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_name VARCHAR(255),
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending','approved') DEFAULT 'pending',
    excel_file VARCHAR(255),
    invoice_pdf VARCHAR(255),
    pbe_pdf VARCHAR(255)
);