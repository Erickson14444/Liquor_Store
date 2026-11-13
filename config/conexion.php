<?php
/**
 * ============================================================================
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Módulo de Conexión a Base de Datos PostgreSQL
 * ============================================================================
 */

$host     = "localhost";
$port     = "5433";
$dbname   = "Liquor_Store";
$user     = "postgres";
$password = "postgre";

$pdo         = null;
$dbConnected = false;
$dbError     = null;

try {
    // Cadena de conexión PDO para PostgreSQL con schemas automáticos y UTF-8
    $searchPath = "public,identidad,seguridad,inventario,ventas,compras,promociones,tesoreria";
    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};options='--client_encoding=UTF8 -c search_path={$searchPath}'";

    $opciones = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 2, // Timeout corto para no congelar la carga si PG no está encendido
    ];

    $pdo = new PDO($dsn, $user, $password, $opciones);
    $dbConnected = true;

} catch (PDOException $e) {
    $pdo = null;
    $dbConnected = false;
    $dbError = $e->getMessage();
}

/**
 * Función auxiliar para obtener la conexión PDO
 * @return PDO|null
 */
function getConexion(): ?PDO {
    global $pdo;
    return $pdo;
}
