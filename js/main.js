class ChecadorApp {
    constructor() {
        this.apiUrl = '../controllers/ChecadorController.php';
        this.currentEmployee = null;
        this.currentAction = null;
        this.videoStream = null;
        this.capturedPhoto = null;
        
        this.initializeApp();
    }

    initializeApp() {
        this.setupEventListeners();
        this.updateDateTime();
        this.setDateTimeInterval();
    }

    setupEventListeners() {
        // Validación de empleado
        document.getElementById('validar-empleado').addEventListener('click', () => {
            this.validarEmpleado();
        });

        // Enter key en input de empleado
        document.getElementById('numero-empleado').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.validarEmpleado();
            }
        });

        // Botones de entrada y salida
        document.getElementById('btn-entrada').addEventListener('click', () => {
            this.iniciarProceso('entrada');
        });

        document.getElementById('btn-salida').addEventListener('click', () => {
            this.iniciarProceso('salida');
        });

        // Controles de cámara
        document.getElementById('capturar-foto').addEventListener('click', () => {
            this.capturarFoto();
        });

        document.getElementById('nueva-foto').addEventListener('click', () => {
            this.tomarNuevaFoto();
        });

        document.getElementById('confirmar-foto').addEventListener('click', () => {
            this.confirmarRegistro();
        });

        // Controles de navegación
        document.getElementById('nuevo-empleado').addEventListener('click', () => {
            this.nuevoEmpleado();
        });

        document.getElementById('cancelar-proceso').addEventListener('click', () => {
            this.cancelarProceso();
        });

        // Historial
        document.getElementById('toggle-historial').addEventListener('click', () => {
            this.toggleHistorial();
        });

        document.getElementById('aplicar-filtro').addEventListener('click', () => {
            this.aplicarFiltroHistorial();
        });

        // Validación en tiempo real del número de empleado
        document.getElementById('numero-empleado').addEventListener('input', (e) => {
            this.validarNumeroEmpleado(e.target.value);
        });
        // Modal de registro
        document.getElementById('cerrar-modal').addEventListener('click', () => {
            this.cerrarModalRegistro();
        });

        document.getElementById('cancelar-registro').addEventListener('click', () => {
            this.cerrarModalRegistro();
        });

        document.getElementById('confirmar-registro').addEventListener('click', () => {
            this.confirmarRegistroEmpleado();
        });

        // Validación en tiempo real del nombre
        document.getElementById('registro-nombre').addEventListener('input', (e) => {
            this.validarNombreEmpleado(e.target.value);
        });

        // Enter key en modal
        document.getElementById('registro-nombre').addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                this.confirmarRegistroEmpleado();
            }
        });
    }

    updateDateTime() {
        const now = new Date();
        const options = {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        };
        
        document.getElementById('datetime').textContent = 
            now.toLocaleDateString('es-ES', options);
    }

    setDateTimeInterval() {
        setInterval(() => {
            this.updateDateTime();
        }, 1000);
    }

    validarNumeroEmpleado(numero) {
        const feedback = document.getElementById('empleado-feedback');
        const input = document.getElementById('numero-empleado');

        // Limpiar caracteres no numéricos
        numero = numero.replace(/\D/g, '');
        input.value = numero;

        if (numero.length === 0) {
            feedback.textContent = '';
            feedback.className = 'input-feedback';
            return;
        }

        if (numero.length !== 8) {
            feedback.textContent = 'El Número de Empleado debe tener exactamente 8 dígitos';
            feedback.className = 'input-feedback error';
        } else if (!numero.startsWith('9')) { // <-- Opción agregada
            feedback.textContent = 'Formato de Empleado incorrecto';
            feedback.className = 'input-feedback error';
        } else {
            feedback.textContent = 'Formato correcto';
            feedback.className = 'input-feedback success';
        }
    }

    async validarEmpleado() {
        const numeroEmpleado = document.getElementById('numero-empleado').value.trim();
        
        if (!numeroEmpleado) {
            this.showNotification('Por favor ingrese un número de empleado', 'error');
            return;
        }

        if (numeroEmpleado.length !== 8) {
            this.showNotification('El número de empleado debe tener 8 dígitos', 'error');
            return;
        }

        this.showLoading('Validando empleado...');

        try {
            const response = await this.makeRequest('POST', 'validar_empleado', {
                numero_empleado: numeroEmpleado
            });

            if (response.success) {
                // Verificar si el empleado existe o necesita ser registrado
                if (response.data.existe === true) {
                    // Empleado encontrado
                    this.currentEmployee = response.data;
                    this.mostrarInfoEmpleado();
                    this.cargarHistorial(numeroEmpleado);
                } else if (response.data.requiere_registro === true) {
                    // Empleado no existe, mostrar modal para registrarlo
                    this.mostrarModalRegistro(numeroEmpleado);
                }
            } else {
                // Error en la validación (formato incorrecto, etc.)
                this.showNotification(response.error, 'error');
            }
        } catch (error) {
            this.showNotification('Error al validar empleado: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    mostrarInfoEmpleado() {
        const { empleado, estado } = this.currentEmployee;
        
        document.getElementById('empleado-nombre').textContent = empleado.nombre;
        document.getElementById('empleado-estado').textContent = this.formatearEstado(estado.estado_actual);
        document.getElementById('empleado-estado').className = `estado-badge ${estado.estado_actual}`;
        
        // Mostrar último registro
        const ultimoRegistro = this.obtenerUltimoRegistro(estado);
        document.getElementById('ultimo-registro').textContent = ultimoRegistro;
        
        // Actualizar textos de botones según estado
        this.actualizarBotonesAccion(estado.estado_actual);
        
        // Mostrar card de información
        document.getElementById('empleado-card').classList.add('hidden');
        document.getElementById('info-card').classList.remove('hidden');
    }

    formatearEstado(estado) {
        const estados = {
            'fuera': 'Fuera de Jornada',
            'trabajando': 'Trabajando',
            'en_break': 'En Break'
        };
        return estados[estado] || estado;
    }

    obtenerUltimoRegistro(estado) {
        const registros = [
            { tipo: 'check_in', fecha: estado.ultimo_check_in, texto: 'Entrada' },
            { tipo: 'check_out', fecha: estado.ultimo_check_out, texto: 'Salida' },
            { tipo: 'break_out', fecha: estado.ultimo_break_out, texto: 'Salida Break' },
            { tipo: 'break_in', fecha: estado.ultimo_break_in, texto: 'Entrada Break' }
        ];
        
        const ultimoRegistro = registros
            .filter(r => r.fecha)
            .sort((a, b) => new Date(b.fecha) - new Date(a.fecha))[0];
        
        if (ultimoRegistro) {
            const fecha = new Date(ultimoRegistro.fecha);
            return `${ultimoRegistro.texto} - ${fecha.toLocaleString('es-ES')}`;
        }
        
        return 'Sin registros';
    }

    actualizarBotonesAccion(estado) {
        const textoEntrada = document.getElementById('texto-entrada');
        const textoSalida = document.getElementById('texto-salida');
        const btnEntrada = document.getElementById('btn-entrada');
        const btnSalida = document.getElementById('btn-salida');
        
        switch (estado) {
            case 'fuera':
                textoEntrada.textContent = 'Entrada';
                textoSalida.textContent = 'Salida';
                btnEntrada.disabled = false;
                btnSalida.disabled = true;
                break;
            case 'trabajando':
                textoEntrada.textContent = 'Entrada';
                textoSalida.textContent = 'Salida / Break';
                btnEntrada.disabled = true;
                btnSalida.disabled = false;
                break;
            case 'en_break':
                textoEntrada.textContent = 'Regreso de Break';
                textoSalida.textContent = 'Salida';
                btnEntrada.disabled = false;
                btnSalida.disabled = true;
                break;
        }
    }

    async iniciarProceso(accion) {
        this.currentAction = accion;
        
        // Obtener información del tipo de registro
        this.showLoading('Preparando registro...');
        
        try {
            const response = await this.makeRequest('POST', 'obtener_estado', {
                numero_empleado: this.currentEmployee.empleado.numero_empleado,
                accion: accion
            });

            if (response.success) {
                this.showNotification(response.data.mensaje, 'info');
                this.iniciarCapturaCamara();
            } else {
                this.showNotification(response.error, 'error');
            }
        } catch (error) {
            this.showNotification('Error al procesar: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    async iniciarCapturaCamara() {
        try {
            // Solicitar acceso a la cámara
            this.videoStream = await navigator.mediaDevices.getUserMedia({
                video: {
                    width: { ideal: 640 },
                    height: { ideal: 480 },
                    facingMode: 'user'
                }
            });

            const video = document.getElementById('video');
            video.srcObject = this.videoStream;
            
            // Mostrar card de foto
            document.getElementById('info-card').classList.add('hidden');
            document.getElementById('foto-card').classList.remove('hidden');
            
        } catch (error) {
            this.showNotification('Error al acceder a la cámara: ' + error.message, 'error');
            this.cancelarProceso();
        }
    }

    capturarFoto() {
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const ctx = canvas.getContext('2d');
        
        // Configurar canvas con las dimensiones del video
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        
        // Dibujar frame actual del video en el canvas
        ctx.drawImage(video, 0, 0);
        
        // Convertir a base64
        this.capturedPhoto = canvas.toDataURL('image/jpeg', 0.8);
        
        // Mostrar preview
        const preview = document.getElementById('foto-preview');
        const img = document.getElementById('foto-img');
        img.src = this.capturedPhoto;
        
        // Ocultar video y mostrar preview
        video.classList.add('hidden');
        preview.classList.remove('hidden');
        
        // Actualizar controles
        document.getElementById('capturar-foto').classList.add('hidden');
        document.getElementById('nueva-foto').classList.remove('hidden');
        document.getElementById('confirmar-foto').classList.remove('hidden');
        
        // Detener stream de video
        if (this.videoStream) {
            this.videoStream.getTracks().forEach(track => track.stop());
        }
    }

    tomarNuevaFoto() {
        // Reiniciar proceso de captura
        document.getElementById('foto-preview').classList.add('hidden');
        document.getElementById('video').classList.remove('hidden');
        document.getElementById('capturar-foto').classList.remove('hidden');
        document.getElementById('nueva-foto').classList.add('hidden');
        document.getElementById('confirmar-foto').classList.add('hidden');
        
        this.capturedPhoto = null;
        this.iniciarCapturaCamara();
    }

    async confirmarRegistro() {
        if (!this.capturedPhoto) {
            this.showNotification('No hay foto capturada', 'error');
            return;
        }

        this.showLoading('Procesando registro...');

        try {
            const response = await this.makeRequest('POST', 'procesar_checador', {
                numero_empleado: this.currentEmployee.empleado.numero_empleado,
                accion: this.currentAction,
                foto_base64: this.capturedPhoto
            });

            if (response.success) {
                this.showNotification(response.data.mensaje, 'success');
                this.procesarExitoso(response.data);
            } else {
                this.showNotification(response.error, 'error');
            }
        } catch (error) {
            this.showNotification('Error al confirmar registro: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }

    procesarExitoso(data) {
        // Actualizar información del empleado
        this.currentEmployee.estado.estado_actual = data.nuevo_estado;
        
        // Actualizar interfaz
        this.mostrarInfoEmpleado();
        
        // Recargar historial
        this.cargarHistorial(this.currentEmployee.empleado.numero_empleado);
        
        // Limpiar proceso
        this.limpiarProceso();
        
        // Volver a la vista de información
        setTimeout(() => {
            document.getElementById('foto-card').classList.add('hidden');
            document.getElementById('info-card').classList.remove('hidden');
        }, 2000);
    }

    cancelarProceso() {
        this.limpiarProceso();
        document.getElementById('foto-card').classList.add('hidden');
        document.getElementById('info-card').classList.remove('hidden');
    }

    limpiarProceso() {
        // Detener stream de video
        if (this.videoStream) {
            this.videoStream.getTracks().forEach(track => track.stop());
            this.videoStream = null;
        }
        
        // Limpiar foto capturada
        this.capturedPhoto = null;
        this.currentAction = null;
        
        // Resetear controles de cámara
        document.getElementById('video').classList.remove('hidden');
        document.getElementById('foto-preview').classList.add('hidden');
        document.getElementById('capturar-foto').classList.remove('hidden');
        document.getElementById('nueva-foto').classList.add('hidden');
        document.getElementById('confirmar-foto').classList.add('hidden');
    }

    nuevoEmpleado() {
        this.currentEmployee = null;
        this.limpiarProceso();
        
        // Limpiar input
        document.getElementById('numero-empleado').value = '';
        document.getElementById('empleado-feedback').textContent = '';
        document.getElementById('empleado-feedback').className = 'input-feedback';
        
        // Mostrar card de empleado
        document.getElementById('info-card').classList.add('hidden');
        document.getElementById('foto-card').classList.add('hidden');
        document.getElementById('empleado-card').classList.remove('hidden');
        
        // Foco en input
        document.getElementById('numero-empleado').focus();
    }

    async cargarHistorial(numeroEmpleado, fecha = null) {
        try {
            let url = `${this.apiUrl}?accion=historial&numero_empleado=${numeroEmpleado}`;
            if (fecha) {
                url += `&fecha=${fecha}`;
            }
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.success) {
                this.mostrarHistorial(data.data.historial);
            }
        } catch (error) {
            console.error('Error al cargar historial:', error);
        }
    }

    mostrarHistorial(registros) {
        const lista = document.getElementById('historial-lista');
        
        if (!registros || registros.length === 0) {
            lista.innerHTML = '<div class="no-registros">No hay registros para mostrar</div>';
            return;
        }
        
        const html = registros.map(registro => {
            const fecha = new Date(registro.fecha_hora);
            const tipoTexto = this.obtenerTextoTipoRegistro(registro.tipo_registro);
            
            return `
                <div class="historial-item ${registro.tipo_registro}">
                    <div class="historial-info">
                        <div class="historial-tipo">${tipoTexto}</div>
                        <div class="historial-fecha">${fecha.toLocaleString('es-ES')}</div>
                    </div>
                    <div class="historial-actions">
                        ${registro.foto_url ? `<a href="${registro.foto_url}" target="_blank" class="btn-foto"><i class="fas fa-image"></i></a>` : ''}
                    </div>
                </div>
            `;
        }).join('');
        
        lista.innerHTML = html;
    }

    obtenerTextoTipoRegistro(tipo) {
        const tipos = {
            'check_in': 'Entrada de Jornada',
            'check_out': 'Salida de Jornada',
            'break_out': 'Salida de Break',
            'break_in': 'Regreso de Break'
        };
        return tipos[tipo] || tipo;
    }

    toggleHistorial() {
        const body = document.getElementById('historial-body');
        const toggle = document.getElementById('toggle-historial');
        const icon = toggle.querySelector('i');
        
        if (body.classList.contains('hidden')) {
            body.classList.remove('hidden');
            icon.classList.replace('fa-chevron-down', 'fa-chevron-up');
        } else {
            body.classList.add('hidden');
            icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        }
    }

    aplicarFiltroHistorial() {
        if (!this.currentEmployee) return;
        
        const fecha = document.getElementById('filtro-fecha').value;
        this.cargarHistorial(this.currentEmployee.empleado.numero_empleado, fecha);
    }

    async makeRequest(method, action, data = null) {
        const url = `${this.apiUrl}?accion=${action}`;
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
            }
        };
        
        if (data) {
            options.body = JSON.stringify(data);
        }
        
        const response = await fetch(url, options);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return await response.json();
    }

    showLoading(text = 'Cargando...') {
        document.getElementById('loading-text').textContent = text;
        document.getElementById('loading').classList.remove('hidden');
    }

    hideLoading() {
        document.getElementById('loading').classList.add('hidden');
    }

    showNotification(message, type = 'info') {
        const container = document.getElementById('notifications');
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        
        const icon = this.getNotificationIcon(type);
        notification.innerHTML = `
            <i class="${icon}"></i>
            <span>${message}</span>
            <button class="notification-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }

    getNotificationIcon(type) {
        const icons = {
            'success': 'fas fa-check-circle',
            'error': 'fas fa-exclamation-circle',
            'warning': 'fas fa-exclamation-triangle',
            'info': 'fas fa-info-circle'
        };
        return icons[type] || icons.info;
    }

    mostrarModalRegistro(numeroEmpleado) {
        document.getElementById('registro-numero').value = numeroEmpleado;
        document.getElementById('registro-nombre').value = '';
        document.getElementById('nombre-feedback').textContent = '';
        document.getElementById('nombre-feedback').className = 'input-feedback';
        document.getElementById('modal-registro').classList.remove('hidden');
        document.getElementById('registro-nombre').focus();
    }

    cerrarModalRegistro() {
        document.getElementById('modal-registro').classList.add('hidden');
    }

    validarNombreEmpleado(nombre) {
        const feedback = document.getElementById('nombre-feedback');
        nombre = nombre.trim();
        
        if (nombre.length === 0) {
            feedback.textContent = '';
            feedback.className = 'input-feedback';
            return false;
        }
        
        if (nombre.length < 2) {
            feedback.textContent = 'El nombre debe tener al menos 2 caracteres';
            feedback.className = 'input-feedback error';
            return false;
        }
        
        if (nombre.length > 100) {
            feedback.textContent = 'El nombre no puede exceder 100 caracteres';
            feedback.className = 'input-feedback error';
            return false;
        }
        
        // Validar que solo contenga letras, espacios y acentos
        if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/.test(nombre)) {
            feedback.textContent = 'El nombre solo puede contener letras y espacios';
            feedback.className = 'input-feedback error';
            return false;
        }
        
        feedback.textContent = 'Nombre válido';
        feedback.className = 'input-feedback success';
        return true;
    }

    async confirmarRegistroEmpleado() {
        const numeroEmpleado = document.getElementById('registro-numero').value;
        const nombre = document.getElementById('registro-nombre').value.trim();
        
        if (!this.validarNombreEmpleado(nombre)) {
            this.showNotification('Por favor corrija los errores en el formulario', 'error');
            return;
        }
        
        this.showLoading('Registrando empleado...');
        
        try {
            const response = await this.makeRequest('POST', 'registrar_empleado', {
                numero_empleado: numeroEmpleado,
                nombre: nombre
            });
            
            if (response.success) {
                this.showNotification('Empleado registrado exitosamente', 'success');
                
                // Configurar el empleado recién creado como el empleado actual
                this.currentEmployee = {
                    empleado: response.data.empleado,
                    estado: response.data.estado,
                    existe: true
                };
                
                // Cerrar modal y mostrar información del empleado
                this.cerrarModalRegistro();
                this.mostrarInfoEmpleado();
                this.cargarHistorial(numeroEmpleado);
                
            } else {
                this.showNotification(response.error, 'error');
            }
        } catch (error) {
            this.showNotification('Error al registrar empleado: ' + error.message, 'error');
        } finally {
            this.hideLoading();
        }
    }
}

// Inicializar aplicación cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    new ChecadorApp();
});