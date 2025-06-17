<?php
require_once __DIR__ . '/../config/db.php';

class FotoModel {
    private $tempPath;
    private $ftpConfig;
    
    public function __construct() {
        $this->tempPath = FTPConfig::LOCAL_TEMP_PATH;
        $this->ftpConfig = new FTPConfig();
        
        // Crear directorio temporal si no existe
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0755, true);
        }
    }

    public function procesarFoto($fotoBase64, $numeroEmpleado) {
        try {
            // Verificar y redimensionar imagen si es necesario
            $fotoOptimizada = $this->redimensionarImagen($fotoBase64);
            
            // Generar nombre único para la foto
            $timestamp = date('YmdHis');
            $nombreArchivo = "checador_{$numeroEmpleado}_{$timestamp}.jpg";
            $rutaLocal = $this->tempPath . $nombreArchivo;
            
            // Decodificar y guardar foto localmente
            $fotoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoOptimizada));
            
            if ($fotoData === false) {
                throw new Exception('Error al decodificar la imagen');
            }
            
            if (file_put_contents($rutaLocal, $fotoData) === false) {
                throw new Exception('Error al guardar la imagen localmente');
            }
            
            // Subir por FTP/SFTP
            $urlRemota = $this->subirPorFTP($rutaLocal, $nombreArchivo);
            
            if (!$urlRemota) {
                // Si falla FTP, intentar con SFTP
                $urlRemota = $this->subirPorSFTP($rutaLocal, $nombreArchivo);
            }
            
            // Limpiar archivo temporal
            if (file_exists($rutaLocal)) {
                unlink($rutaLocal);
            }
            
            if ($urlRemota) {
                return [
                    'success' => true,
                    'url' => $urlRemota,
                    'filename' => $nombreArchivo
                ];
            } else {
                throw new Exception('Error al subir la imagen al servidor remoto');
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al procesar foto: ' . $e->getMessage()
            ];
        }
    }

    private function subirPorFTP($rutaLocal, $nombreArchivo) {
        try {
            $conexionFTP = ftp_connect(FTPConfig::FTP_SERVER, FTPConfig::FTP_PORT, 30);
            
            if (!$conexionFTP) {
                throw new Exception('No se pudo conectar al servidor FTP');
            }
            
            $login = ftp_login($conexionFTP, FTPConfig::FTP_USERNAME, FTPConfig::FTP_PASSWORD);
            
            if (!$login) {
                ftp_close($conexionFTP);
                throw new Exception('Error de autenticación FTP');
            }
            
            // Activar modo pasivo
            ftp_pasv($conexionFTP, true);
            
            // Crear directorio remoto si no existe
            $rutaRemota = FTPConfig::REMOTE_PATH . $nombreArchivo;
            
            // Subir archivo
            $subida = ftp_put($conexionFTP, $rutaRemota, $rutaLocal, FTP_BINARY);
            
            ftp_close($conexionFTP);
            
            if ($subida) {
                return FTPConfig::BASE_URL . $nombreArchivo;
            } else {
                throw new Exception('Error al subir archivo por FTP');
            }
        } catch (Exception $e) {
            error_log('Error FTP: ' . $e->getMessage());
            return false;
        }
    }

    private function subirPorSFTP($rutaLocal, $nombreArchivo) {
        try {
            // Verificar si la extensión SSH2 está disponible
            if (!extension_loaded('ssh2')) {
                throw new Exception('Extensión SSH2 no disponible');
            }
            
            $conexion = ssh2_connect(FTPConfig::FTP_SERVER, FTPConfig::SFTP_PORT);
            
            if (!$conexion) {
                throw new Exception('No se pudo conectar al servidor SFTP');
            }
            
            $auth = ssh2_auth_password($conexion, FTPConfig::FTP_USERNAME, FTPConfig::FTP_PASSWORD);
            
            if (!$auth) {
                throw new Exception('Error de autenticación SFTP');
            }
            
            $sftp = ssh2_sftp($conexion);
            
            if (!$sftp) {
                throw new Exception('Error al inicializar SFTP');
            }
            
            $rutaRemota = 'ssh2.sftp://' . intval($sftp) . FTPConfig::REMOTE_PATH . $nombreArchivo;
            
            $resultado = copy($rutaLocal, $rutaRemota);
            
            if ($resultado) {
                return FTPConfig::BASE_URL . $nombreArchivo;
            } else {
                throw new Exception('Error al subir archivo por SFTP');
            }
        } catch (Exception $e) {
            error_log('Error SFTP: ' . $e->getMessage());
            return false;
        }
    }

    public function validarImagen($fotoBase64) {
        try {
            // Verificar que sea una imagen válida
            $fotoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoBase64));
            
            if ($fotoData === false) {
                return [
                    'success' => false,
                    'message' => 'Formato de imagen inválido'
                ];
            }
            
            // Verificar tamaño (máximo 5MB)
            $tamaño = strlen($fotoData);
            $maxSize = 5 * 1024 * 1024; // 5MB
            
            if ($tamaño > $maxSize) {
                return [
                    'success' => false,
                    'message' => 'La imagen es demasiado grande (máximo 5MB)'
                ];
            }
            
            // Verificar que sea una imagen válida usando getimagesizefromstring
            $infoImagen = getimagesizefromstring($fotoData);
            
            if ($infoImagen === false) {
                return [
                    'success' => false,
                    'message' => 'El archivo no es una imagen válida'
                ];
            }
            
            // Verificar tipo MIME
            $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png'];
            
            if (!in_array($infoImagen['mime'], $tiposPermitidos)) {
                return [
                    'success' => false,
                    'message' => 'Tipo de imagen no permitido. Solo JPG y PNG'
                ];
            }
            
            return [
                'success' => true,
                'width' => $infoImagen[0],
                'height' => $infoImagen[1],
                'mime' => $infoImagen['mime'],
                'size' => $tamaño
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al validar imagen: ' . $e->getMessage()
            ];
        }
    }

    // Alternative implementation without GD extension
    public function redimensionarImagen($fotoBase64, $maxAncho = 800, $maxAlto = 600, $calidad = 85) {
        try {
            // Check if GD extension is available
            if (!extension_loaded('gd')) {
                error_log('Extension GD no disponible , retornando imagen original');
                return $fotoBase64; // Return original if GD is not available
            }
            
            $fotoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoBase64));
            $imagen = imagecreatefromstring($fotoData);
            
            if ($imagen === false) {
                throw new Exception('Error al crear imagen desde string');
            }
            
            $anchoOriginal = imagesx($imagen);
            $altoOriginal = imagesy($imagen);
            
            // Calcular nuevas dimensiones manteniendo proporción
            $ratio = min($maxAncho / $anchoOriginal, $maxAlto / $altoOriginal);
            
            if ($ratio < 1) {
                $nuevoAncho = intval($anchoOriginal * $ratio);
                $nuevoAlto = intval($altoOriginal * $ratio);
                
                $imagenRedimensionada = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
                
                imagecopyresampled(
                    $imagenRedimensionada, $imagen,
                    0, 0, 0, 0,
                    $nuevoAncho, $nuevoAlto,
                    $anchoOriginal, $altoOriginal
                );
                
                // Convertir a base64
                ob_start();
                imagejpeg($imagenRedimensionada, null, $calidad);
                $imagenData = ob_get_contents();
                ob_end_clean();
                
                imagedestroy($imagen);
                imagedestroy($imagenRedimensionada);
                
                return 'data:image/jpeg;base64,' . base64_encode($imagenData);
            }
            
            imagedestroy($imagen);
            return $fotoBase64; // No necesita redimensionar
            
        } catch (Exception $e) {
            error_log('Error al redimensionar imagen: ' . $e->getMessage());
            return $fotoBase64; // Devolver original en caso de error
        }
    }

    // Alternative method for basic size validation without GD
    public function validarTamañoImagen($fotoBase64, $maxAncho = 1920, $maxAlto = 1080) {
        try {
            $fotoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $fotoBase64));
            $infoImagen = getimagesizefromstring($fotoData);
            
            if ($infoImagen === false) {
                return [
                    'success' => false,
                    'message' => 'No se puede obtener información de la imagen'
                ];
            }
            
            if ($infoImagen[0] > $maxAncho || $infoImagen[1] > $maxAlto) {
                return [
                    'success' => false,
                    'message' => "Imagen demasiado grande. Máximo: {$maxAncho}x{$maxAlto}px"
                ];
            }
            
            return [
                'success' => true,
                'width' => $infoImagen[0],
                'height' => $infoImagen[1]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al validar tamaño: ' . $e->getMessage()
            ];
        }
    }
}