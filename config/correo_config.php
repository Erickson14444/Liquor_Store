<?php
/**
 * ============================================================================
 * Configuración del Servicio de Correo SMTP
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: config/correo_config.php
 * ============================================================================
 * 
 * GUÍA RÁPIDA PARA ACTIVAR EL CORREO:
 * 1. Cambia 'activo' => true.
 * 2. Coloca tu cuenta de Gmail en 'smtp_usuario' y 'remitente_email'.
 * 3. En 'smtp_clave', ingresa tu "Contraseña de Aplicación" de 16 caracteres
 *    generada en tu cuenta de Google: https://myaccount.google.com/apppasswords
 *    (Nota: NO es tu clave normal de entrar a Gmail).
 * 4. Al enviar desde smtp.gmail.com con SSL, Google firma los correos y NUNCA caen en spam.
 */

return [
    // Activar el envío automático de correos (true = habilitado, false = desactivado)
    'activo'           => true,

    // Servidor SMTP (Gmail / Google Workspace por defecto)
    'smtp_host'        => 'smtp.gmail.com',
    'smtp_port'        => 465, // Puerto SSL recomendado para Gmail
    'smtp_secure'      => 'ssl', // 'ssl' (puerto 465) o 'tls' (puerto 587)
    'smtp_timeout'     => 12,

    // Credenciales SMTP
    'smtp_usuario'     => 'liquorstorehouseice@gmail.com',
    'smtp_clave'       => 'ohdrhqbllgnrkhke',

    // Datos del Remitente General del Sistema
    'remitente_email'  => 'liquorstorehouseice@gmail.com',
    'remitente_nombre' => 'House & Ice',
    'responder_a'      => 'liquorstorehouseice@gmail.com',
];
