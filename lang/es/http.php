<?php

return [
    400 => ['title' => 'No pudimos procesar la solicitud', 'message' => 'Revisa los datos e inténtalo nuevamente.'],
    401 => ['title' => 'Necesitas iniciar sesión', 'message' => 'Tu sesión no está activa. Inicia sesión para continuar. Los cambios no se guardarán automáticamente.'],
    403 => ['title' => 'No tienes permiso para esta acción', 'message' => 'Solicita acceso a una persona autorizada o vuelve a una sección disponible.'],
    404 => ['title' => 'No encontramos esta página', 'message' => 'La dirección o el registro solicitado no está disponible. Revisa el enlace o vuelve al inicio.'],
    405 => ['title' => 'Esta acción no está disponible', 'message' => 'Vuelve a la página anterior y utiliza los controles del formulario.'],
    409 => ['title' => 'La información cambió', 'message' => 'Revisa el estado actual antes de volver a intentar la operación.'],
    413 => ['title' => 'El archivo es demasiado grande', 'message' => 'Selecciona un archivo más pequeño y vuelve a intentarlo.'],
    419 => ['title' => 'Tu sesión ha expirado', 'message' => 'Por seguridad, vuelve a cargar la página e inicia sesión si se solicita. Revisa los datos antes de guardar; no se enviarán automáticamente.'],
    422 => ['title' => 'Revisa los datos ingresados', 'message' => 'Hay datos que no se pueden procesar. Revisa el formulario e inténtalo nuevamente.'],
    429 => ['title' => 'Demasiados intentos', 'message' => 'Espera un momento antes de volver a intentarlo.'],
    500 => ['title' => 'Ocurrió un problema inesperado', 'message' => 'No pudimos completar la solicitud. Revisa su estado antes de repetirla. Si el problema continúa, contacta al administrador.'],
    503 => ['title' => 'El sistema no está disponible temporalmente', 'message' => 'Inténtalo más tarde. Si estabas registrando una operación, revisa su estado antes de repetirla.'],
];
