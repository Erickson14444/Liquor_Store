<?php
/**
 * ============================================================================
 * Backend Controlador: Módulo de Auditoría Forense
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Ubicación: Seguridad y Control/AuditoriaForense/procesar_auditoria.php
 * ============================================================================
 */

ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/conexion.php';
if (file_exists(__DIR__ . '/../../config/funciones.php')) {
    require_once __DIR__ . '/../../config/funciones.php';
}
require_once __DIR__ . '/../../config/auditoria_helper.php';

try {
    $usuarioId = requerirSesionActiva(true);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Sesión expirada o no válida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!usuarioEsAdministrador() && !usuarioTienePermiso('VER_AUDITORIA_FORENSE')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: Se requieren permisos de Auditoría Forense.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$accion = trim((string) ($_GET['accion'] ?? $_POST['accion'] ?? ''));

/**
 * Construye dinámicamente el WHERE y parámetros compartidos entre listar_auditoria y exportar_csv.
 */
function construirFiltrosAuditoria(array $src): array
{
    $tabla = trim((string) ($src['tabla'] ?? ''));
    $tipoAccion = trim((string) ($src['tipo_accion'] ?? ''));
    $categoria = strtoupper(trim((string) ($src['categoria_evento'] ?? '')));
    $filtroUser = trim((string) ($src['usuario_id'] ?? ''));
    $filtroSup = trim((string) ($src['supervisor_id'] ?? ''));
    $soloOverrides = !empty($src['solo_overrides']) && $src['solo_overrides'] !== '0';
    $soloCambios = !empty($src['solo_cambios']) && $src['solo_cambios'] !== '0';
    $fechaInicio = trim((string) ($src['fecha_inicio'] ?? ''));
    $fechaFin = trim((string) ($src['fecha_fin'] ?? ''));
    $busqueda = trim((string) ($src['busqueda'] ?? ''));

    $where = ['1=1'];
    $params = [];

    if ($tabla !== '') {
        $where[] = '(a.tabla_afectada = ? OR a.tabla_afectada LIKE ?)';
        $params[] = $tabla;
        $params[] = '%.' . $tabla;
    }

    if ($tipoAccion !== '') {
        $where[] = 'a.accion = ?';
        $params[] = $tipoAccion;
    }

    if ($categoria !== '') {
        if ($categoria === 'CREACION') {
            $where[] = "(a.accion ~* '(CREAR|INSERT|REGISTRAR|EMITIR|APERTURA|NUEV)' AND a.datos_anteriores IS NULL)";
        } elseif ($categoria === 'MODIFICACION') {
            $where[] = "(a.datos_anteriores IS NOT NULL OR a.accion ~* '(ACTUALIZ|EDIT|MODIF|AJUSTE)')";
        } elseif ($categoria === 'ANULACION') {
            $where[] = "a.accion ~* '(ANUL|CANCEL|ELIMIN|DELETE|INCOBRABLE|RECHAZ)'";
        } elseif ($categoria === 'FISCAL') {
            $where[] = "(a.accion ~* '(FACTUR|B01|B02|B03|B04|DGII|RETENC|NCF|DEVOLUC)' OR a.tabla_afectada ~* '(facturas|notas_debito|devoluciones|retenciones)')";
        } elseif ($categoria === 'CAJA_TESORERIA') {
            $where[] = "(a.tabla_afectada ~* '(caja|egreso|recibo|banco|deposito|cuentas_por|tesoreria)' OR a.accion ~* '(CAJA|ARQUEO|DEPOSITO|EGRESO|RECIBO|LIQUIDAR|CXP|CXC)')";
        } elseif ($categoria === 'INVENTARIO') {
            $where[] = "(a.tabla_afectada ~* '(inventario|stock|lote|traslado|recepcion|compra|producto|consignacion)' OR a.accion ~* '(STOCK|INVENTARIO|TRASLADO|RECEPCION|COMPRA|CONSIGNACION)')";
        }
    }

    if ($filtroUser !== '') {
        $where[] = 'a.usuario_id = ?';
        $params[] = $filtroUser;
    }

    if ($filtroSup !== '') {
        $where[] = 'a.supervisor_id = ?';
        $params[] = $filtroSup;
    }

    if ($soloOverrides) {
        $where[] = 'a.supervisor_id IS NOT NULL';
    }

    if ($soloCambios) {
        $where[] = 'a.datos_anteriores IS NOT NULL';
    }

    if ($fechaInicio !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaInicio)) {
        $where[] = 'a.fecha_hora >= ?::date';
        $params[] = $fechaInicio;
    }

    if ($fechaFin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaFin)) {
        $where[] = "a.fecha_hora < (?::date + INTERVAL '1 day')";
        $params[] = $fechaFin;
    }

    if ($busqueda !== '') {
        $where[] = "(
            a.accion ILIKE ?
            OR a.tabla_afectada ILIKE ?
            OR COALESCE(u.username, '') ILIKE ?
            OR COALESCE(pu.nombre_razon_social, '') ILIKE ?
            OR COALESCE(sup.username, '') ILIKE ?
            OR COALESCE(psup.nombre_razon_social, '') ILIKE ?
            OR COALESCE(a.datos_nuevos::text, '') ILIKE ?
            OR COALESCE(a.datos_anteriores::text, '') ILIKE ?
        )";
        $q = "%{$busqueda}%";
        array_push($params, $q, $q, $q, $q, $q, $q, $q, $q);
    }

    return [implode(' AND ', $where), $params];
}

try {
    if (!$pdo) {
        throw new Exception('No hay conexión activa con PostgreSQL.');
    }

    switch ($accion) {

        // ====================================================================
        // 1. LISTAR REGISTROS DE AUDITORÍA CON FILTRADO AVANZADO Y PAGINACIÓN
        // ====================================================================
        case 'listar_auditoria':
            header('Content-Type: application/json; charset=utf-8');

            [$whereSql, $params] = construirFiltrosAuditoria($_GET);

            $limite = min(200, max(10, intval($_GET['limite'] ?? 50)));
            $pagina = max(1, intval($_GET['pagina'] ?? 1));
            $orden = trim((string) ($_GET['orden'] ?? 'fecha_desc'));
            $orderSql = ($orden === 'fecha_asc')
                ? 'a.fecha_hora ASC, a.auditoria_id ASC'
                : 'a.fecha_hora DESC, a.auditoria_id DESC';

            // Total y resumen del filtro actual
            $stmtCount = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_registros,
                    COUNT(CASE WHEN a.supervisor_id IS NOT NULL THEN 1 END) AS total_overrides,
                    COUNT(CASE WHEN a.datos_anteriores IS NOT NULL THEN 1 END) AS total_cambios
                FROM seguridad.auditoria a
                LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
                LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
                LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
                LEFT JOIN identidad.personas psup ON psup.persona_id = sup.persona_id
                WHERE {$whereSql}
            ");
            $stmtCount->execute($params);
            $resCount = $stmtCount->fetch(PDO::FETCH_ASSOC) ?: [];
            $totalRegistros = (int) ($resCount['total_registros'] ?? 0);
            $totalPaginas = max(1, (int) ceil($totalRegistros / $limite));
            if ($pagina > $totalPaginas) {
                $pagina = $totalPaginas;
            }
            $offset = ($pagina - 1) * $limite;

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
                       COALESCE(u.username, 'sistema') AS usuario_username,
                       COALESCE(pu.nombre_razon_social, u.username, 'Sistema') AS usuario_nombre,
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
                ORDER BY {$orderSql}
                LIMIT ? OFFSET ?
            ");
            $stmt->execute(array_merge($params, [$limite, $offset]));
            $registros = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($registros as &$r) {
                $t = (string) ($r['tabla_afectada'] ?? '');
                $r['tabla_limpia'] = strpos($t, '.') !== false ? substr($t, strrpos($t, '.') + 1) : $t;
                $r['es_override'] = ($r['es_override'] === true || $r['es_override'] === 't' || $r['es_override'] == 1);
                $r['tiene_anteriores'] = ($r['tiene_anteriores'] === true || $r['tiene_anteriores'] === 't' || $r['tiene_anteriores'] == 1);
                $r['datos_anteriores'] = $r['datos_anteriores'] ? json_decode($r['datos_anteriores'], true) : null;
                $r['datos_nuevos'] = $r['datos_nuevos'] ? json_decode($r['datos_nuevos'], true) : null;
            }
            unset($r);

            echo json_encode([
                'status' => 'success',
                'data' => $registros,
                'total' => $totalRegistros,
                'total_overrides' => (int) ($resCount['total_overrides'] ?? 0),
                'total_cambios' => (int) ($resCount['total_cambios'] ?? 0),
                'pagina' => $pagina,
                'limite' => $limite,
                'total_paginas' => $totalPaginas,
            ], JSON_UNESCAPED_UNICODE);
            break;

        // ====================================================================
        // 2. EXPORTAR VISTA FILTRADA A CSV (COMPATIBLE CON EXCEL)
        // ====================================================================
        case 'exportar_csv':
            [$whereSql, $params] = construirFiltrosAuditoria($_GET);
            $orden = trim((string) ($_GET['orden'] ?? 'fecha_desc'));
            $orderSql = ($orden === 'fecha_asc')
                ? 'a.fecha_hora ASC, a.auditoria_id ASC'
                : 'a.fecha_hora DESC, a.auditoria_id DESC';

            $stmt = $pdo->prepare("
                SELECT a.auditoria_id,
                       TO_CHAR(a.fecha_hora, 'YYYY-MM-DD HH24:MI:SS') AS fecha_iso,
                       a.tabla_afectada,
                       a.accion,
                       COALESCE(u.username, 'sistema') AS usuario_username,
                       COALESCE(pu.nombre_razon_social, u.username, 'Sistema') AS usuario_nombre,
                       COALESCE(sup.username, '') AS supervisor_username,
                       COALESCE(a.datos_anteriores::text, '') AS datos_anteriores_txt,
                       COALESCE(a.datos_nuevos::text, '') AS datos_nuevos_txt
                FROM seguridad.auditoria a
                LEFT JOIN seguridad.usuarios u ON u.usuario_id = a.usuario_id
                LEFT JOIN identidad.personas pu ON pu.persona_id = u.persona_id
                LEFT JOIN seguridad.usuarios sup ON sup.usuario_id = a.supervisor_id
                LEFT JOIN identidad.personas psup ON psup.persona_id = sup.persona_id
                WHERE {$whereSql}
                ORDER BY {$orderSql}
                LIMIT 5000
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $filename = 'auditoria_house_ice_' . date('Ymd_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            // BOM UTF-8 para tildes en Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Fecha y Hora', 'Tabla', 'Accion', 'Usuario', 'Nombre Operador', 'Supervisor PIN', 'Datos Anteriores (JSON)', 'Datos Nuevos (JSON)']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['auditoria_id'],
                    $row['fecha_iso'],
                    $row['tabla_afectada'],
                    $row['accion'],
                    $row['usuario_username'],
                    $row['usuario_nombre'],
                    $row['supervisor_username'],
                    $row['datos_anteriores_txt'],
                    $row['datos_nuevos_txt'],
                ]);
            }
            fclose($out);
            exit;

        // ====================================================================
        // 3. DETALLE ESPECÍFICO E INSPECCIÓN DE DIFF
        // ====================================================================
        case 'obtener_detalle':
            header('Content-Type: application/json; charset=utf-8');
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('ID de auditoría no válido.');
            }

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
                       COALESCE(u.username, 'sistema') AS usuario_username,
                       COALESCE(pu.nombre_razon_social, u.username, 'Sistema') AS usuario_nombre,
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

            $diffs = [];
            if (is_array($ant) && is_array($nue)) {
                $allKeys = array_unique(array_merge(array_keys($ant), array_keys($nue)));
                foreach ($allKeys as $k) {
                    $vAnt = $ant[$k] ?? null;
                    $vNue = $nue[$k] ?? null;
                    if (json_encode($vAnt) !== json_encode($vNue)) {
                        $diffs[] = [
                            'campo' => $k,
                            'antes' => $vAnt,
                            'despues' => $vNue,
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
                    'diferencias' => $diffs,
                ],
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida: '{$accion}'.");
    }

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}