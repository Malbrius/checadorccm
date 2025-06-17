<?php
class Database {
    private $host = '127.0.0.1';
    private $db_name = 'checador_db';
    private $username = 'root';
    private $password = 'oE0aIbr]x1(.LH1o';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Error de conexión: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// Configuración FTP/SFTP
class FTPConfig {
    const FTP_SERVER = '127.0.0.1';
    const FTP_USERNAME = 'user';
    const FTP_PASSWORD = '1';
    const FTP_PORT = 21;
    const SFTP_PORT = 22;
    const REMOTE_PATH = '/fotos_checador/';
    const LOCAL_TEMP_PATH = __DIR__ . '/../temp/';
    const BASE_URL = 'https://127.0.0.1/fotos_checador/';
}

// SQL para crear las tablas necesarias
/*
CREATE DATABASE IF NOT EXISTS checador_db;
USE checador_db;

CREATE TABLE IF NOT EXISTS empleados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_empleado VARCHAR(8) UNIQUE NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS registros_checador (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero_empleado VARCHAR(8) NOT NULL,
    tipo_registro ENUM('check_in', 'check_out', 'break_out', 'break_in') NOT NULL,
    fecha_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    foto_url VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    INDEX idx_empleado_fecha (numero_empleado, fecha_hora),
    FOREIGN KEY (numero_empleado) REFERENCES empleados(numero_empleado)
);

CREATE TABLE IF NOT EXISTS estados_empleados (
    numero_empleado VARCHAR(8) PRIMARY KEY,
    estado_actual ENUM('fuera', 'trabajando', 'en_break') DEFAULT 'fuera',
    ultimo_check_in TIMESTAMP NULL,
    ultimo_check_out TIMESTAMP NULL,
    ultimo_break_out TIMESTAMP NULL,
    ultimo_break_in TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (numero_empleado) REFERENCES empleados(numero_empleado)
);
*/
?>