<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../models/ChecadorModel.php';
require_once __DIR__ . '/../models/FotoModel.php';

class ChecadorController {
    private $checadorModel;
    private $fotoModel;

    public function __construct() {
        $this->checadorModel = new ChecadorModel();
        $this->fotoModel = new FotoModel();
    }

    public function manejarSolicitud() {
        try {
            $metodo = $_SERVER['REQUEST_METHOD'];
            $accion = $_GET['accion'] ?? '';

            switch ($metodo) {
                case 'POST':
                    $this->manejarPOST($accion);
                    break;
                case 'GET':
                    $this->manejarGET($accion);
                    break;
                default:
                    $this->responderError('Método no permitido', 405);
            }
        } catch (Exception $e) {
            $this->responderError('Error del servidor: ' . $e->getMessage(), 500);
        }
    }

    private function manejarPOST($accion) {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->responderError('JSON inválido', 400);
            return;
        }

        switch ($accion) {
            case 'validar_empleado':
                $this->validarEmpleado($input);
                break;
            case 'procesar_checador':
                $this->procesarChecador($input);
                break;
            case 'obtener_estado':
                $this->obtenerEstado($input);
                break;
            case 'registrar_empleado':
                $this->registrarEmpleado($input);
                break;
            default:
                $this->responderError('Acción no válida', 400);
        }
    }

    private function manejarGET($accion) {
        switch ($accion) {
            case 'historial':
                $numeroEmpleado = $_GET['numero_empleado'] ?? '';
                $fecha = $_GET['fecha'] ?? null;
                $this->obtenerHistorial($numeroEmpleado, $fecha);
                break;
            default:
                $this->responderError('Acción no válida', 400);
        }
    }

    private function validarEmpleado($data) {
        $numeroEmpleado = $data['numero_empleado'] ?? '';
        
        if (empty($numeroEmpleado)) {
            $this->responderError('Número de empleado requerido', 400);
            return;
        }

        $resultado = $this->checadorModel->validarEmpleado($numeroEmpleado);
        
        if ($resultado['success']) {
            // Empleado encontrado - obtener estado actual del empleado
            $estado = $this->checadorModel->obtenerEstadoEmpleado($numeroEmpleado);
            
            $this->responderExito([
                'empleado' => $resultado['empleado'],
                'estado' => $estado,
                'existe' => true
            ]);
        } else {
            // Verificar si es un error de "empleado no encontrado" vs error de formato
            if ($resultado['message'] === 'Empleado no encontrado o inactivo') {
                // Empleado no existe pero el formato es válido - permitir creación
                $this->responderExito([
                    'existe' => false,
                    'puede_crear' => true,
                    'numero_empleado' => $numeroEmpleado,
                    'mensaje' => 'Empleado no encontrado. Se puede registrar nuevo empleado.',
                    'requiere_registro' => true
                ], 202); // 202 Accepted - indica que la solicitud fue aceptada pero requiere acción adicional
            } else {
                // Error de validación (formato incorrecto, etc.)
                $this->responderError($resultado['message'], 422);
            }
        }
    }

    private function obtenerEstado($data) {
        $numeroEmpleado = $data['numero_empleado'] ?? '';
        $accion = $data['accion'] ?? '';
        
        if (empty($numeroEmpleado) || empty($accion)) {
            $this->responderError('Parámetros incompletos', 400);
            return;
        }

        try {
            $tipoRegistro = $this->checadorModel->determinarTipoRegistro($numeroEmpleado, $accion);
            
            if (isset($tipoRegistro['error'])) {
                $this->responderError($tipoRegistro['message'], 422);
            } else {
                $this->responderExito([
                    'tipo_registro' => $tipoRegistro['tipo'],
                    'nuevo_estado' => $tipoRegistro['nuevo_estado'],
                    'mensaje' => $this->obtenerMensajeTipoRegistro($tipoRegistro['tipo'])
                ]);
            }
        } catch (Exception $e) {
            $this->responderError('Error al obtener estado: ' . $e->getMessage(), 500);
        }
    }

    private function procesarChecador($data) {
        // Validar datos requeridos
        $camposRequeridos = ['numero_empleado', 'accion', 'foto_base64'];
        foreach ($camposRequeridos as $campo) {
            if (empty($data[$campo])) {
                $this->responderError("Campo requerido: {$campo}", 400);
                return;
            }
        }

        $numeroEmpleado = $data['numero_empleado'];
        $accion = $data['accion'];
        $fotoBase64 = $data['foto_base64'];

        try {
            // 1. Validar empleado
            $validacionEmpleado = $this->checadorModel->validarEmpleado($numeroEmpleado);
            if (!$validacionEmpleado['success']) {
                $this->responderError($validacionEmpleado['message'], 422);
                return;
            }

            // 2. Determinar tipo de registro
            $tipoRegistro = $this->checadorModel->determinarTipoRegistro($numeroEmpleado, $accion);
            if (isset($tipoRegistro['error'])) {
                $this->responderError($tipoRegistro['message'], 422);
                return;
            }

            // 3. Validar imagen
            $validacionFoto = $this->fotoModel->validarImagen($fotoBase64);
            if (!$validacionFoto['success']) {
                $this->responderError($validacionFoto['message'], 422);
                return;
            }

            // 4. Redimensionar imagen si es necesaria
            $fotoOptimizada = $this->fotoModel->redimensionarImagen($fotoBase64);

            // 5. Procesar y subir foto
            $resultadoFoto = $this->fotoModel->procesarFoto($fotoOptimizada, $numeroEmpleado);
            if (!$resultadoFoto['success']) {
                $this->responderError($resultadoFoto['message'], 500);
                return;
            }

            // 6. Registrar en base de datos
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $resultadoRegistro = $this->checadorModel->registrarChecador(
                $numeroEmpleado,
                $tipoRegistro['tipo'],
                $resultadoFoto['url'],
                $ipAddress,
                $userAgent
            );

            if ($resultadoRegistro['success']) {
                $this->responderExito([
                    'mensaje' => $this->obtenerMensajeTipoRegistro($tipoRegistro['tipo']),
                    'tipo_registro' => $tipoRegistro['tipo'],
                    'nuevo_estado' => $tipoRegistro['nuevo_estado'],
                    'foto_url' => $resultadoFoto['url'],
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->responderError($resultadoRegistro['message'], 500);
            }

        } catch (Exception $e) {
            $this->responderError('Error al procesar checador: ' . $e->getMessage(), 500);
        }
    }

    private function obtenerHistorial($numeroEmpleado, $fecha) {
        if (empty($numeroEmpleado)) {
            $this->responderError('Número de empleado requerido', 400);
            return;
        }

        try {
            $historial = $this->checadorModel->obtenerHistorialEmpleado($numeroEmpleado, $fecha);
            $this->responderExito(['historial' => $historial]);
        } catch (Exception $e) {
            $this->responderError('Error al obtener historial: ' . $e->getMessage(), 500);
        }
    }

    private function obtenerMensajeTipoRegistro($tipo) {
        $mensajes = [
            'check_in' => 'Entrada de jornada registrada exitosamente',
            'check_out' => 'Salida de jornada registrada exitosamente',
            'break_out' => 'Salida de break registrada exitosamente',
            'break_in' => 'Regreso de break registrado exitosamente'
        ];

        return $mensajes[$tipo] ?? 'Registro completado';
    }

    private function responderExito($data, $codigo = 200) {
        http_response_code($codigo);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    private function responderError($mensaje, $codigo = 400) {
        http_response_code($codigo);
        echo json_encode([
            'success' => false,
            'error' => $mensaje,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        exit;
    }
    private function registrarEmpleado($data) {
        $numeroEmpleado = $data['numero_empleado'] ?? '';
        $nombre = trim($data['nombre'] ?? '');
        
        if (empty($numeroEmpleado) || empty($nombre)) {
            $this->responderError('Número de empleado y nombre son requeridos', 400);
            return;
        }

        if (strlen($numeroEmpleado) !== 8 || !ctype_digit($numeroEmpleado)) {
            $this->responderError('El número de empleado debe tener exactamente 8 dígitos', 400);
            return;
        }

        if (strlen($nombre) < 2) {
            $this->responderError('El nombre debe tener al menos 2 caracteres', 400);
            return;
        }

        try {
            $resultado = $this->checadorModel->crearEmpleado($numeroEmpleado, $nombre);
            
            if ($resultado['success']) {
                // Obtener estado inicial del empleado recién creado
                $estado = $this->checadorModel->obtenerEstadoEmpleado($numeroEmpleado);
                
                $this->responderExito([
                    'empleado' => $resultado['empleado'],
                    'estado' => $estado
                ]);
            } else {
                $this->responderError($resultado['message'], 422);
            }
        } catch (Exception $e) {
            $this->responderError('Error al registrar empleado: ' . $e->getMessage(), 500);
        }
    }
}


// Ejecutar controlador
if (basename($_SERVER['SCRIPT_NAME']) === 'ChecadorController.php') {
    $controller = new ChecadorController();
    $controller->manejarSolicitud();
}

