<?php
/**
 * ============================================================================
 * Backend Controlador: Módulo de Auditoría Forense
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/procesar_auditoria.php
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/funciones.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $usuarioId = requerirSesionActiva(true);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Sesión expirada o no válida.']);
    exit;
}

if (!usuarioEsAdministrador() && !usuarioTienePermiso('VER_AUDITORIA_FORENSE')) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Se requieren permisos de Auditoría Forense.']);
    exit;
}

$accion = trim($_GET['accion'] ?? $_POST['accion'] ?? '');

try {
    switch ($accion) {

        // ====================================================================
        // 1. LISTAR REGISTROS DE AUDITORÍA CON FILTRADO AVANZADO
        // ====================================================================
        case 'listar_auditoria':
            $tabla       = trim($_GET['tabla'] ?? '');
            $tipoAccion  = trim($_GET['tipo_accion'] ?? '');
            $filtroUser  = trim($_GET['usuario_id'] ?? '');
            $soloOverrides = !empty($_GET['solo_overrides']);
            $fechaInicio = trim($_GET['fecha_inicio'] ?? '');
            $fechaFin    = trim($_GET['fecha_fin'] ?? '');
            $busqueda    = trim($_GET['busqueda'] ?? '');
            $limite      = min(200, max(10, intval($_GET['limite'] ?? 100)));
            $pagina      = max(1, intval($_GET['pagina'] ?? 1));
            $offset      = ($pagina - 1) * $limite;

            $where = ["1=1"];
            $params = [];

            if ($tabla !== '') {
                $where[] = "a.tabla_afectada = ?";
                $params[] = $tabla;
            }

            if ($tipoAccion !== '') {
                $where[] = "a.accion = ?";
                $params[] = $tipoAccion;
            }

            if ($filtroUser !== '') {
                $where[] = "(a.usuario_id = ? OR a.supervisor_id = ?)";
                $params[] = $filtroUser;
                $params[] = $filtroUser;
            }

            if ($soloOverrides) {
                $where[] = "a.supervisor_id IS NOT NULL";
            }

            if ($fechaInicio !== '') {
                $where[] = "a.fecha_hora >= ?::date";
                $params[] = $fechaInicio;
            }

            if ($fechaFin !== '') {
                $where[] = "a.fecha_hora <= (?::date + INTERVAL '1 day' - INTERVAL '1 second')";
                $params[] = $fechaFin;
            }

            if ($busqueda !== '') {
                // Búsqueda profunda en campos JSONB y nombres
                $where[] = "(
                    a.accion ILIKE ?
                    OR a.tabla_afectada ILIKE ?
                    OR u.username ILIKE ?
                    OR pu.nombre_razon_social ILIKE ?
                    OR sup.username ILIKE ?
                    OR a.datos_nuevos::text ILIKE ?
                    OR a.datos_anteriores::text ILIKE ?
                )";
                $q = "%{$busqueda}%";
                $params[] = $q;
                $params[] = $q;
                $params[] = $q;
                $params[] = $q;
                $params[] = $q;
                $params[] = $q;
                $params[] = $q;
            }

            $whereSql = implode(' AND ', $where);

            // Total de registros que coinciden
            $stmtCount = $pdo->prepare("
                SELECT COUNT(*)
                FROM seguridad.auditoria a
                LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
                LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
                LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
                WHERE {$whereSql}
            ");
            $stmtCount->execute($params);
            $totalRegistros = (int)$stmtCount->fetchColumn();

            // Consulta paginada
            $stmt = $pdo->prepare("
                SELECT a.auditoria_id,
                       a.usuario_id,
                       a.supervisor_id,
                       a.accion,
                       a.tabla_afectada,
                       a.datos_anteriores,
                       a.datos_nuevos,
                       TO_CHAR(a.fecha_hora, 'DD/MM/YYYY HH12:MI:SS AM') AS fecha_fmt,
                       a.fecha_hora,
                       u.username AS usuario_username,
                       COALESCE(pu.nombre_razon_social, u.username) AS usuario_nombre,
                       sup.username AS supervisor_username,
                       COALESCE(psup.nombre_razon_social, sup.username) AS supervisor_nombre,
                       (a.datos_anteriores IS NOT NULL) AS tiene_anteriores,
                       (a.supervisor_id IS NOT NULL) AS es_override
                FROM seguridad.auditoria a
                LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
                LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
                LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
                LEFT JOIN identidad.personas psup ON psup.persona_id = sup.persona_id
                WHERE {$whereSql}
                ORDER BY a.fecha_hora DESC, a.auditoria_id DESC
                LIMIT ? OFFSET ?
            ");
            $paramsWithLimit = array_merge($params, [$limite, $offset]);
            $stmt->execute($paramsWithLimit);
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decodificar JSONB para enviar estructurado
            foreach ($registros as &$r) {
                $r['datos_anteriores'] = $r['datos_anteriores'] ? json_decode($r['datos_anteriores'], true) : null;
                $r['datos_nuevos'] = $r['datos_nuevos'] ? json_decode($r['datos_nuevos'], true) : null;
            }

            echo json_encode([
                'status' => 'success',
                'data' => $registros,
                'total' => $totalRegistros,
                'pagina' => $pagina,
                'limite' => $limite,
                'total_paginas' => ceil($totalRegistros / $limite)
            ]);
            break;

        // ====================================================================
        // 2. DETALLE ESPECÍFICO E INSPECCIÓN DE DIFF
        // ====================================================================
        case 'obtener_detalle':
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception("ID de auditoría no válido.");
            }

            $stmt = $pdo->prepare("
                SELECT a.auditoria_id,
                       a.usuario_id,
                       a.supervisor_id,
                       a.accion,
                       a.tabla_afectada,
                       a.datos_anteriores,
                       a.datos_nuevos,
                       TO_CHAR(a.fecha_hora, 'DD/MM/YYYY HH12:MI:SS AM TZ') AS fecha_fmt,
                       a.fecha_hora,
                       u.username AS usuario_username,
                       COALESCE(pu.nombre_razon_social, u.username) AS usuario_nombre,
                       sup.username AS supervisor_username,
                       COALESCE(psup.nombre_razon_social, sup.username) AS supervisor_nombre
                FROM seguridad.auditoria a
                LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
                LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
                LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
                LEFT JOIN identidad.personas psup ON psup.persona_id = sup.persona_id
                WHERE a.auditoria_id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new Exception("Registro de auditoría #{$id} no encontrado.");
            }

            $ant = $row['datos_anteriores'] ? json_decode($row['datos_anteriores'], true) : null;
            $nue = $row['datos_nuevos'] ? json_decode($row['datos_nuevos'], true) : null;

            // Calcular diferencias clave
            $diffs = [];
            if (is_array($ant) && is_array($nue)) {
                $allKeys = array_unique(array_merge(array_keys($ant), array_keys($nue)));
                foreach ($allKeys as $k) {
                    $vAnt = $ant[$k] ?? null;
                    $vNue = $nue[$k] ?? null;
                    if ($vAnt !== $vNue) {
                        $diffs[] = [
                            'campo' => $k,
                            'antes' => $vAnt,
                            'despues' => $vNue
                        ];
                    }
                }
            }

            echo json_encode([
                'status' => 'success',
                'data' => [
                    'auditoria' => $row,
                    'datos_anteriores' => $ant,
                    'datos_nuevos' => $nue,
                    'diferencias' => $diffs
                ]
            ]);
            break;

        default:
            throw new Exception("Acción no válida: '{$accion}'.");
    }

} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
