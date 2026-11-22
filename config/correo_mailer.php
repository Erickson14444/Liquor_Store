<?php
/**
 * ============================================================================
 * Cliente SMTP Ligero y Seguro en PHP Puro (Sin dependencias externas)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: config/correo_mailer.php
 * ============================================================================
 */

class CorreoMailer
{
    private array $config;

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $archivoConfig = __DIR__ . '/correo_config.php';
            $this->config = file_exists($archivoConfig) ? require $archivoConfig : [];
        } else {
            $this->config = $config;
        }
    }

    /**
     * Envía un correo con contenido HTML, texto alternativo y adjuntos opcionales
     * 
     * @param string $destinatarioEmail Correo del receptor (ej. proveedor)
     * @param string $destinatarioNombre Nombre del receptor
     * @param string $asunto Asunto del mensaje
     * @param string $cuerpoHtml Contenido en formato HTML
     * @param string|null $remitenteNombrePersonalizado Nombre opcional del remitente para este módulo (ej: "House & Ice • Departamento de Compras")
     * @param array $adjuntos Lista de archivos adjuntos: [['nombre' => 'Orden.pdf', 'contenido' => $pdfBinario, 'tipo' => 'application/pdf']]
     * @param string|null $cuerpoTextoPlano Texto plano opcional para máxima compatibilidad antispam
     * @return array ['ok' => bool, 'mensaje' => string]
     */
    public function enviarHtml(
        string $destinatarioEmail,
        string $destinatarioNombre,
        string $asunto,
        string $cuerpoHtml,
        ?string $remitenteNombrePersonalizado = null,
        array $adjuntos = [],
        ?string $cuerpoTextoPlano = null
    ): array
    {
        if (empty($this->config['activo'])) {
            return [
                'ok' => false,
                'mensaje' => 'El servicio de correo está desactivado en config/correo_config.php.'
            ];
        }

        $destinatarioEmail = trim($destinatarioEmail);
        if (!filter_var($destinatarioEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok' => false,
                'mensaje' => "La dirección de correo destinatario '{$destinatarioEmail}' no es válida."
            ];
        }

        $host    = (string)($this->config['smtp_host'] ?? 'smtp.gmail.com');
        $port    = (int)($this->config['smtp_port'] ?? 465);
        $secure  = strtolower((string)($this->config['smtp_secure'] ?? 'ssl'));
        $timeout = (int)($this->config['smtp_timeout'] ?? 12);
        $user    = (string)($this->config['smtp_usuario'] ?? '');
        $pass    = str_replace(' ', '', (string)($this->config['smtp_clave'] ?? ''));
        $from    = (string)($this->config['remitente_email'] ?? $user);
        $fromNom = !empty($remitenteNombrePersonalizado)
            ? (string)$remitenteNombrePersonalizado
            : (string)($this->config['remitente_nombre'] ?? 'House & Ice • Sistema Administrativo');

        if (empty($user) || empty($pass) || $pass === 'xxxxxxxxxxxxxxxx') {
            return [
                'ok' => false,
                'mensaje' => 'Faltan configurar las credenciales SMTP en config/correo_config.php.'
            ];
        }

        $prefijo = ($secure === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefijo . $host, $port, $errno, $errstr, $timeout);

        if (!$socket) {
            return [
                'ok' => false,
                'mensaje' => "No se pudo conectar al servidor SMTP {$host}:{$port} ({$errstr} - Código: {$errno})"
            ];
        }

        stream_set_timeout($socket, $timeout);

        try {
            $this->leerRespuesta($socket);

            $this->enviarComando($socket, "EHLO [127.0.0.1]");
            $this->leerRespuesta($socket);

            if ($secure === 'tls') {
                $this->enviarComando($socket, "STARTTLS");
                $this->leerRespuesta($socket);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception("Fallo en la negociación TLS con el servidor SMTP.");
                }
                $this->enviarComando($socket, "EHLO [127.0.0.1]");
                $this->leerRespuesta($socket);
            }

            // Autenticación
            $this->enviarComando($socket, "AUTH LOGIN");
            $this->leerRespuesta($socket);

            $this->enviarComando($socket, base64_encode($user));
            $this->leerRespuesta($socket);

            $this->enviarComando($socket, base64_encode($pass));
            $respAuth = $this->leerRespuesta($socket);
            if (substr($respAuth, 0, 3) !== '235') {
                throw new Exception("Error de autenticación SMTP. Verifique usuario o Contraseña de Aplicación de Google: {$respAuth}");
            }

            // Remitente y Destinatario
            $this->enviarComando($socket, "MAIL FROM:<{$from}>");
            $this->leerRespuesta($socket);

            $this->enviarComando($socket, "RCPT TO:<{$destinatarioEmail}>");
            $this->leerRespuesta($socket);

            // Contenido y Cabeceras Anti-Spam RFC 5322
            $this->enviarComando($socket, "DATA");
            $this->leerRespuesta($socket);

            // Generar texto plano humano y amigable si no se especificó para evitar la penalización de spam
            if (empty($cuerpoTextoPlano)) {
                $cuerpoTextoPlano = "Hola,\n\n"
                    . "Le saludamos cordialmente de parte de House & Ice.\n\n"
                    . "Le compartimos adjunto el documento oficial de la Orden de Compra en formato PDF con el detalle de los productos solicitados para entrega y recepción en nuestro almacén.\n\n"
                    . "Por favor confirmar la recepción de este pedido respondiendo a este mismo correo.\n\n"
                    . "Atentamente,\n"
                    . "House & Ice\n"
                    . "liquorstorehouseice@gmail.com";
            }

            $partesMail = explode('@', $from);
            $dominioMail = count($partesMail) > 1 ? $partesMail[1] : 'gmail.com';
            $messageId = sprintf("<%s.%s@%s>", bin2hex(random_bytes(10)), time(), $dominioMail);

            $boundaryMixed = "----=_Mixed_" . md5(uniqid((string)mt_rand(), true));
            $boundaryAlt   = "----=_Alt_" . md5(uniqid((string)mt_rand(), true));

            $cabeceras  = "MIME-Version: 1.0\r\n";
            $cabeceras .= "Message-ID: {$messageId}\r\n";
            $cabeceras .= "Date: " . date('r') . "\r\n";
            $cabeceras .= "From: =?UTF-8?B?" . base64_encode($fromNom) . "?= <{$from}>\r\n";
            $cabeceras .= "Reply-To: <" . ($this->config['responder_a'] ?? $from) . ">\r\n";
            $cabeceras .= "To: =?UTF-8?B?" . base64_encode($destinatarioNombre) . "?= <{$destinatarioEmail}>\r\n";
            $cabeceras .= "Subject: =?UTF-8?B?" . base64_encode($asunto) . "?=\r\n";

            if (!empty($adjuntos)) {
                // Mensaje con archivo adjunto (PDF)
                $cabeceras .= "Content-Type: multipart/mixed; boundary=\"{$boundaryMixed}\"\r\n\r\n";

                // Parte alternativa (Texto plano + HTML)
                $cuerpoMime  = "--{$boundaryMixed}\r\n";
                $cuerpoMime .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

                // Texto plano
                $cuerpoMime .= "--{$boundaryAlt}\r\n";
                $cuerpoMime .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $cuerpoMime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $cuerpoMime .= chunk_split(base64_encode($cuerpoTextoPlano)) . "\r\n";

                // HTML institucional
                $cuerpoMime .= "--{$boundaryAlt}\r\n";
                $cuerpoMime .= "Content-Type: text/html; charset=UTF-8\r\n";
                $cuerpoMime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $cuerpoMime .= chunk_split(base64_encode($cuerpoHtml)) . "\r\n";

                $cuerpoMime .= "--{$boundaryAlt}--\r\n";

                // Adjuntos (PDFs)
                foreach ($adjuntos as $adj) {
                    $nombreAdj = (string)($adj['nombre'] ?? 'documento.pdf');
                    $tipoMimeAdj = (string)($adj['tipo'] ?? 'application/pdf');
                    $contenidoAdj = (string)($adj['contenido'] ?? '');

                    $cuerpoMime .= "--{$boundaryMixed}\r\n";
                    $cuerpoMime .= "Content-Type: {$tipoMimeAdj}; name=\"=?UTF-8?B?" . base64_encode($nombreAdj) . "?=\"\r\n";
                    $cuerpoMime .= "Content-Transfer-Encoding: base64\r\n";
                    $cuerpoMime .= "Content-Disposition: attachment; filename=\"=?UTF-8?B?" . base64_encode($nombreAdj) . "?=\"\r\n\r\n";
                    $cuerpoMime .= chunk_split(base64_encode($contenidoAdj)) . "\r\n";
                }

                $cuerpoMime .= "--{$boundaryMixed}--\r\n";

            } else {
                // Mensaje sin adjuntos: multipart/alternative (Texto plano + HTML)
                $cabeceras .= "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n";

                // Texto plano
                $cuerpoMime  = "--{$boundaryAlt}\r\n";
                $cuerpoMime .= "Content-Type: text/plain; charset=UTF-8\r\n";
                $cuerpoMime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $cuerpoMime .= chunk_split(base64_encode($cuerpoTextoPlano)) . "\r\n";

                // HTML institucional
                $cuerpoMime .= "--{$boundaryAlt}\r\n";
                $cuerpoMime .= "Content-Type: text/html; charset=UTF-8\r\n";
                $cuerpoMime .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $cuerpoMime .= chunk_split(base64_encode($cuerpoHtml)) . "\r\n";

                $cuerpoMime .= "--{$boundaryAlt}--\r\n";
            }

            // Enviar mensaje terminado en CRLF.CRLF
            $mensajeCompleto = $cabeceras . $cuerpoMime . "\r\n.\r\n";
            fwrite($socket, $mensajeCompleto);
            $respData = $this->leerRespuesta($socket);

            if (substr($respData, 0, 3) !== '250') {
                throw new Exception("El servidor SMTP rechazó los datos del correo: {$respData}");
            }

            $this->enviarComando($socket, "QUIT");
            fclose($socket);

            return [
                'ok' => true,
                'mensaje' => "Correo enviado exitosamente a {$destinatarioEmail}."
            ];

        } catch (Throwable $e) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
            return [
                'ok' => false,
                'mensaje' => "Error al enviar correo: " . $e->getMessage()
            ];
        }
    }

    private function enviarComando($socket, string $cmd): void
    {
        fwrite($socket, $cmd . "\r\n");
    }

    private function leerRespuesta($socket): string
    {
        $respuesta = '';
        while (($linea = fgets($socket, 512)) !== false) {
            $respuesta .= $linea;
            // Si el cuarto caracter es espacio o CRLF corto, concluyó la respuesta multi-línea
            if (isset($linea[3]) && $linea[3] === ' ') {
                break;
            }
        }
        return trim($respuesta);
    }
}
