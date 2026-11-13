<?php
/**
 * Script de prueba de conexión a PostgreSQL
 * House & Ice • Sistema Administrativo
 */

require_once __DIR__ . '/config/conexion.php';

$pgVersion = 'No conectado';
$todasTablas = [];
$tablasPorEsquema = [];
$totalTablas = 0;

if ($pdo) {
    try {
        $pgVersion = $pdo->query("SELECT version()")->fetchColumn();

        // Consultar todas las tablas agrupadas por los 7 esquemas del ERP
        $stmt = $pdo->query("
            SELECT table_schema, table_name 
            FROM information_schema.tables 
            WHERE table_schema IN ('compras', 'identidad', 'inventario', 'promociones', 'seguridad', 'tesoreria', 'ventas', 'public')
              AND table_type = 'BASE TABLE'
            ORDER BY table_schema ASC, table_name ASC
        ");
        $todasTablas = $stmt->fetchAll();

        foreach ($todasTablas as $row) {
            $tablasPorEsquema[$row['table_schema']][] = $row['table_name'];
        }
        $totalTablas = count($todasTablas);
    } catch (Throwable $e) {
        $dbConnected = false;
        $dbError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prueba de Conexión • PostgreSQL &amp; Apache</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f5ef;
            color: #251c16;
            margin: 0;
            padding: 40px 20px;
            display: flex;
            justify-content: center;
        }
        .card {
            background: #ffffff;
            border: 1px solid #eae3d5;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(37, 28, 22, 0.08);
            max-width: 820px;
            width: 100%;
            padding: 32px;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #a66e2e;
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.4rem;
            color: #211813;
        }
        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .status-success {
            background: #eef8f2;
            border: 1px solid #c2e5d0;
            color: #2e7d52;
        }
        .status-warning {
            background: #fff8e6;
            border: 1px solid #f0d080;
            color: #946200;
        }
        .btn-link {
            display: inline-block;
            background: #a66e2e;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
            margin-right: 10px;
            transition: background 0.2s;
        }
        .btn-link:hover {
            background: #87551f;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed #eae3d5;
            font-size: 0.9rem;
        }
        .detail-label {
            color: #8c8173;
            font-weight: 500;
        }
        .detail-val {
            font-family: 'Consolas', monospace;
            font-weight: bold;
            color: #211813;
        }
        .schema-section {
            margin-top: 20px;
            border-top: 1px solid #eae3d5;
            padding-top: 16px;
        }
        .schema-title {
            font-size: 1rem;
            font-weight: bold;
            color: #a66e2e;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 6px;
            margin-bottom: 12px;
        }
        .table-item {
            font-family: 'Consolas', monospace;
            font-size: 0.8rem;
            background: #fdfcf9;
            border: 1px solid #e2dacb;
            padding: 6px 10px;
            border-radius: 4px;
            color: #3d332a;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div>
                <h1>House &amp; Ice</h1>
                <div style="font-size: 0.85rem; color: #a66e2e; font-weight: 600;">Diagnóstico de Servidor y Base de Datos</div>
            </div>
            <?php if ($dbConnected && $pdo): ?>
                <div class="status-badge status-success">
                    <span>●</span> Conectado a PostgreSQL
                </div>
            <?php else: ?>
                <div class="status-badge status-warning">
                    <span>▲</span> Modo Demostración Activo (Sin PostgreSQL)
                </div>
            <?php endif; ?>
        </div>

        <div class="detail-row">
            <span class="detail-label">Servidor / Host:</span>
            <span class="detail-val"><?= htmlspecialchars($host ?? 'localhost') ?>:<?= htmlspecialchars($port ?? '5433') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Base de Datos:</span>
            <span class="detail-val"><?= htmlspecialchars($dbname ?? 'Liquor_Store') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Usuario Conectado:</span>
            <span class="detail-val"><?= htmlspecialchars($user ?? 'postgres') ?></span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Estado de Conexión:</span>
            <span class="detail-val" style="color: <?= ($dbConnected && $pdo) ? '#2e7d52' : '#d97706' ?>;">
                <?= ($dbConnected && $pdo) ? 'En línea (Base de Datos Operativa)' : 'PostgreSQL Desconectado / Usando Demo' ?>
            </span>
        </div>
        <?php if (!empty($dbError)): ?>
        <div class="detail-row" style="background: #fff8f8; padding: 10px; border-radius: 6px;">
            <span class="detail-label" style="color: #c53030;">Aviso de Conexión:</span>
            <span class="detail-val" style="color: #c53030; font-size: 0.8rem; max-width: 500px;"><?= htmlspecialchars($dbError) ?></span>
        </div>
        <?php endif; ?>
        <div class="detail-row">
            <span class="detail-label">Total de Tablas Detectadas:</span>
            <span class="detail-val" style="color: #2e7d52;"><?= $totalTablas ?> tablas activas</span>
        </div>
        <div class="detail-row">
            <span class="detail-label">Versión del Servidor:</span>
            <span class="detail-val" style="font-size: 0.76rem; max-width: 440px; text-align: right;"><?= htmlspecialchars($pgVersion) ?></span>
        </div>

        <div style="margin-top: 24px;">
            <a href="Login%20y%20Menu/menu.php" class="btn-link">Ir al Menú Principal →</a>
            <a href="Login%20y%20Menu/login.php" class="btn-link" style="background: #4a3b32;">Ir al Login →</a>
            <a href="Panel%20de%20configuracion/catalogo/productos.php" class="btn-link" style="background: #251c16;">Panel Productos →</a>
        </div>

        <?php if ($totalTablas > 0): ?>
            <?php foreach ($tablasPorEsquema as $schema => $tbls): ?>
                <div class="schema-section">
                    <div class="schema-title">
                        <span>📁 Esquema: <u><?= strtoupper($schema) ?></u></span>
                        <span style="font-size: 0.78rem; font-weight: normal; color: #8c8173;"><?= count($tbls) ?> tablas</span>
                    </div>
                    <div class="table-grid">
                        <?php foreach ($tbls as $t): ?>
                            <div class="table-item">📦 <?= htmlspecialchars($t) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
