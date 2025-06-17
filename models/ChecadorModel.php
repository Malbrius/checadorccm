<?php
require_once __DIR__ . '/../config/db.php';

class ChecadorModel {
    private $conn;
    private $table_registros = 'registros_checador';
    private $table_estados = 'estados_empleados';
    private $table_empleados = 'empleados';

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function validarEmpleado($numeroEmpleado) {
        try {
            // Validar longitud
            if (strlen($numeroEmpleado) !== 8) {
                return [
                    'success' => false,
                    'message' => 'El número de empleado debe tener exactamente 8 caracteres'
                ];
            }

            // Validar que solo contenga números
            if (!ctype_digit($numeroEmpleado)) {
                return [
                    'success' => false,
                    'message' => 'El número de empleado solo debe contener números'
                ];
            }

            // Verificar si el empleado existe
            $query = "SELECT numero_empleado, nombre FROM " . $this->table_empleados . 
                    " WHERE numero_empleado = :numero_empleado AND activo = 1";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
                return [
                    'success' => true,
                    'empleado' => $empleado
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Empleado no encontrado o inactivo'
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al validar empleado: ' . $e->getMessage()
            ];
        }
    }

    public function obtenerEstadoEmpleado($numeroEmpleado) {
        try {
            $query = "SELECT * FROM " . $this->table_estados . 
                    " WHERE numero_empleado = :numero_empleado";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                // Crear registro inicial si no existe
                $this->crearEstadoInicial($numeroEmpleado);
                return [
                    'numero_empleado' => $numeroEmpleado,
                    'estado_actual' => 'fuera',
                    'ultimo_check_in' => null,
                    'ultimo_check_out' => null,
                    'ultimo_break_out' => null,
                    'ultimo_break_in' => null
                ];
            }
        } catch (Exception $e) {
            throw new Exception('Error al obtener estado del empleado: ' . $e->getMessage());
        }
    }

    private function crearEstadoInicial($numeroEmpleado) {
        try {
            $query = "INSERT INTO " . $this->table_estados . 
                    " (numero_empleado, estado_actual) VALUES (:numero_empleado, 'fuera')";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->execute();
        } catch (Exception $e) {
            throw new Exception('Error al crear estado inicial: ' . $e->getMessage());
        }
    }

    public function determinarTipoRegistro($numeroEmpleado, $accion) {
        try {
            $estado = $this->obtenerEstadoEmpleado($numeroEmpleado);
            $estadoActual = $estado['estado_actual'];

            if ($accion === 'entrada') {
                switch ($estadoActual) {
                    case 'fuera':
                        return ['tipo' => 'check_in', 'nuevo_estado' => 'trabajando'];
                    case 'en_break':
                        return ['tipo' => 'break_in', 'nuevo_estado' => 'trabajando'];
                    case 'trabajando':
                        return [
                            'error' => true,
                            'message' => 'El empleado ya está trabajando. Use salida para registrar break o fin de jornada.'
                        ];
                    default:
                        return ['error' => true, 'message' => 'Estado no válido'];
                }
            } elseif ($accion === 'salida') {
                switch ($estadoActual) {
                    case 'trabajando':
                        // Determinar si es break o check_out basado en la hora
                        $horaActual = date('H:i');
                        $horaLimiteJornada = '17:00'; // Ajustar según necesidades
                        
                        if ($horaActual < $horaLimiteJornada) {
                            return ['tipo' => 'break_out', 'nuevo_estado' => 'en_break'];
                        } else {
                            return ['tipo' => 'check_out', 'nuevo_estado' => 'fuera'];
                        }
                    case 'fuera':
                        return [
                            'error' => true,
                            'message' => 'El empleado no está en horario de trabajo'
                        ];
                    case 'en_break':
                        return [
                            'error' => true,
                            'message' => 'El empleado ya está en break. Use entrada para regresar al trabajo.'
                        ];
                    default:
                        return ['error' => true, 'message' => 'Estado no válido'];
                }
            }
        } catch (Exception $e) {
            return ['error' => true, 'message' => 'Error al determinar tipo de registro: ' . $e->getMessage()];
        }
    }

    public function registrarChecador($numeroEmpleado, $tipoRegistro, $fotoUrl, $ipAddress, $userAgent) {
        try {
            $this->conn->beginTransaction();

            // Insertar registro
            $query = "INSERT INTO " . $this->table_registros . 
                    " (numero_empleado, tipo_registro, foto_url, ip_address, user_agent) 
                     VALUES (:numero_empleado, :tipo_registro, :foto_url, :ip_address, :user_agent)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->bindParam(':tipo_registro', $tipoRegistro);
            $stmt->bindParam(':foto_url', $fotoUrl);
            $stmt->bindParam(':ip_address', $ipAddress);
            $stmt->bindParam(':user_agent', $userAgent);
            $stmt->execute();

            // Actualizar estado del empleado
            $this->actualizarEstadoEmpleado($numeroEmpleado, $tipoRegistro);

            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Registro guardado exitosamente',
                'registro_id' => $this->conn->lastInsertId()
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Error al registrar: ' . $e->getMessage()
            ];
        }
    }

    private function actualizarEstadoEmpleado($numeroEmpleado, $tipoRegistro) {
        try {
            $nuevoEstado = '';
            $campoFecha = '';
            
            switch ($tipoRegistro) {
                case 'check_in':
                    $nuevoEstado = 'trabajando';
                    $campoFecha = 'ultimo_check_in';
                    break;
                case 'check_out':
                    $nuevoEstado = 'fuera';
                    $campoFecha = 'ultimo_check_out';
                    break;
                case 'break_out':
                    $nuevoEstado = 'en_break';
                    $campoFecha = 'ultimo_break_out';
                    break;
                case 'break_in':
                    $nuevoEstado = 'trabajando';
                    $campoFecha = 'ultimo_break_in';
                    break;
            }

            $query = "UPDATE " . $this->table_estados . 
                    " SET estado_actual = :estado_actual, " . $campoFecha . " = NOW()
                     WHERE numero_empleado = :numero_empleado";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':estado_actual', $nuevoEstado);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->execute();
        } catch (Exception $e) {
            throw new Exception('Error al actualizar estado: ' . $e->getMessage());
        }
    }

    public function obtenerHistorialEmpleado($numeroEmpleado, $fecha = null) {
        try {
            $whereClause = "WHERE numero_empleado = :numero_empleado";
            $params = [':numero_empleado' => $numeroEmpleado];
            
            if ($fecha) {
                $whereClause .= " AND DATE(fecha_hora) = :fecha";
                $params[':fecha'] = $fecha;
            }

            $query = "SELECT * FROM " . $this->table_registros . " " . 
                    $whereClause . " ORDER BY fecha_hora DESC LIMIT 10";
            
            $stmt = $this->conn->prepare($query);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new Exception('Error al obtener historial: ' . $e->getMessage());
        }
    }
    public function crearEmpleado($numeroEmpleado, $nombre) {
        try {
            $this->conn->beginTransaction();

            // Verificar que no exista ya el empleado
            $query = "SELECT numero_empleado FROM " . $this->table_empleados . 
                    " WHERE numero_empleado = :numero_empleado";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $this->conn->rollback();
                return [
                    'success' => false,
                    'message' => 'El número de empleado ya existe'
                ];
            }

            // Insertar nuevo empleado
            $query = "INSERT INTO " . $this->table_empleados . 
                    " (numero_empleado, nombre) VALUES (:numero_empleado, :nombre)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':numero_empleado', $numeroEmpleado);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->execute();

            // Crear estado inicial
            $this->crearEstadoInicial($numeroEmpleado);

            $this->conn->commit();

            return [
                'success' => true,
                'empleado' => [
                    'numero_empleado' => $numeroEmpleado,
                    'nombre' => $nombre
                ]
            ];
        } catch (Exception $e) {
            $this->conn->rollback();
            return [
                'success' => false,
                'message' => 'Error al crear empleado: ' . $e->getMessage()
            ];
        }
    }
    
}