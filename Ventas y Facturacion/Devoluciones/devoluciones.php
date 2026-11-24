<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 6: Devoluciones y Notas de Crédito (B04)
 * Ubicación: Ventas y Facturacion/Devoluciones/devoluciones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (function_exists('usuarioPuedeAccederDevoluciones')) {
    requerirAccesoModulo(
        usuarioPuedeAccederDevoluciones(),
        false,
        'Acceso restringido: no posees permisos para emitir Devoluciones / Notas de Crédito (B04).'
    );
}

$devoluciones = [];
$supervisoresAutorizados = [];
$infoB04 = null;

$kpiTotal = 0;
$kpiMontoTotal = 0.00;
$kpiSaldoActivo = 0.00;
$kpiCantB04Disp = 0;

if ($pdo) {
    try {
        // Vencer automáticamente créditos B04 con más de 15 días
        $pdo->exec("
            UPDATE ventas.devoluciones
            SET estado_saldo = 'VENCIDO'
            WHERE metodo_reembolso = 'SALDO_FAVOR'
              AND estado_saldo IN ('DISPONIBLE', 'USADO_PARCIAL')
              AND fecha_vencimiento_saldo < CURRENT_DATE
        ");

        $supervisoresAutorizados = obtenerSupervisoresAutorizados($pdo);

        // Próximo NCF B04 disponible
        $rowB04 = $pdo->query("
            SELECT id, secuencia_actual, secuencia_hasta,
                   TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vencimiento_fmt
            FROM ventas.secuencias_fiscales_dgii
            WHERE tipo_comprobante = 'B04'
              AND estado = 'ACTIVO'
              AND fecha_vencimiento >= CURRENT_DATE
              AND secuencia_actual <= secuencia_hasta
            ORDER BY id ASC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if ($rowB04) {
            $infoB04 = [
                'proximo' => 'B04' . str_pad((string) $rowB04['secuencia_actual'], 8, '0', STR_PAD_LEFT),
                'restantes' => intval($rowB04['secuencia_hasta']) - intval($rowB04['secuencia_actual']) + 1,
                'vencimiento' => $rowB04['vencimiento_fmt'],
            ];
        }

        // Listado histórico de devoluciones B04
        $devoluciones = $pdo->query("
            SELECT d.id,
                   d.ncf_nota_credito,
                   d.ncf_factura_modificada,
                   d.monto_total,
                   d.metodo_reembolso,
                   d.justificacion_motivo,
                   d.codigo_credito,
                   d.saldo_disponible,
                   d.estado_saldo,
                   TO_CHAR(d.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_emision_fmt,
                   TO_CHAR(d.fecha_vencimiento_saldo, 'DD/MM/YYYY')  AS fecha_vencimiento_fmt,
                   COALESCE(m.simbolo, 'RD$') AS simbolo,
                   f.numero_factura,
                   COALESCE(NULLIF(TRIM(f.razon_social_cliente), ''), pc.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(TRIM(f.rnc_cedula_cliente), ''), pc.documento_identidad, '') AS cliente_doc,
                   COALESCE(NULLIF(TRIM(ps.nombre_razon_social), ''), us.username, 'Supervisor') AS supervisor_nombre,
                   COALESCE((SELECT SUM(dd.cantidad) FROM ventas.devoluciones_detalles dd WHERE dd.nota_credito_id = d.id), 0) AS total_unidades
            FROM ventas.devoluciones d
            JOIN ventas.facturas f          ON f.id = d.factura_id
            LEFT JOIN identidad.personas pc ON pc.persona_id = f.cliente_id
            LEFT JOIN tesoreria.monedas m   ON m.id = d.moneda_id
            LEFT JOIN seguridad.usuarios us ON us.usuario_id = d.supervisor_id
            LEFT JOIN identidad.personas ps ON ps.persona_id = us.persona_id
            ORDER BY d.fecha_emision DESC, d.id DESC
            LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kpiTotal = count($devoluciones);
        foreach ($devoluciones as $dv) {
            $kpiMontoTotal += floatval($dv['monto_total'] ?? 0);
            if (in_array(strtoupper((string) ($dv['estado_saldo'] ?? '')), ['DISPONIBLE', 'USADO_PARCIAL'], true)) {
                $kpiSaldoActivo += floatval($dv['saldo_disponible'] ?? 0);
                $kpiCantB04Disp++;
            }
        }
    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
    }
}

$badgesMetodo = [
    'SALDO_FAVOR' => ['label' => 'SALDO A FAVOR (15d)', 'bg' => '#e8f5e9', 'color' => '#2e7d32'],
    'EFECTIVO' => ['label' => 'EFECTIVO (CAJA)', 'bg' => '#fff3e0', 'color' => '#e65100'],
    'TARJETA' => ['label' => 'TARJETA (POS)', 'bg' => '#e3f2fd', 'color' => '#1565c0'],
    'ABONO_CXC' => ['label' => 'ABONO A CXC', 'bg' => '#ede7f6', 'color' => '#5e35b1'],
    'CREDITO_CXC' => ['label' => 'ABONO A CXC', 'bg' => '#ede7f6', 'color' => '#5e35b1'],
    'CREDITO' => ['label' => 'ABONO A CXC', 'bg' => '#ede7f6', 'color' => '#5e35b1'],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Devoluciones y Notas de Crédito (B04)</title>
    <link rel="stylesheet" href="../styles.css?v=24" />
    <style>
        .toast {
            z-index: 999999 !important;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.55rem;
            line-height: 1;
            color: #8a7b70;
            cursor: pointer;
            padding: 2px 8px;
            border-radius: 6px;
        }

        .btn-close:hover {
            color: #211813;
            background: rgba(0, 0, 0, 0.06);
        }

        #modalNuevaDevolucion .promo-block-card {
            overflow: visible !important;
        }

        .fac-picker-wrap {
            position: relative;
        }

        .fac-selected-pill {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            background: #fdf8ee;
            border: 1.5px solid #d4a359;
            border-radius: 8px;
            font-size: 0.86rem;
        }

        .fac-dropdown {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            max-height: 270px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #d4a359;
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(33, 24, 19, 0.2);
            z-index: 1600;
        }

        .fac-opt-item {
            padding: 9px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f2ebe1;
            font-size: 0.84rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .fac-opt-item:last-child {
            border-bottom: none;
        }

        .fac-opt-item:hover {
            background: #fdf5ea;
        }

        .dev-qty-control {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .dev-qty-btn {
            width: 26px;
            height: 28px;
            border: 1px solid #d4c4b0;
            background: #faf6f0;
            color: #211813;
            border-radius: 6px;
            font-weight: 800;
            cursor: pointer;
        }

        .dev-qty-btn:hover {
            background: #eadecd;
            border-color: #a66e2e;
        }

        .dev-qty-input {
            width: 54px;
            height: 28px;
            text-align: center;
            font-weight: 700;
            border: 1px solid #d4c4b0;
            border-radius: 6px;
            font-family: monospace;
        }

        .dev-total-strip {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #faf7f2;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .pin-auth-box {
            background: #fff8e1;
            border: 1.5px dashed #ffe082;
            border-radius: 10px;
            padding: 14px;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #areaImpresionB04,
            #areaImpresionB04 * {
                visibility: visible;
            }

            #areaImpresionB04 {
                position: fixed;
                left: 0;
                top: 0;
                width: 100%;
                background: #fff;
                padding: 24px;
                box-sizing: border-box;
            }

            .no-print {
                display: none !important;
            }
        }
    </style>
</head>

<body>

    <!-- BARRA SUPERIOR -->
    <header class="topbar">
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
                <span></span><span></span><span></span>
            </button>
            <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
                <span class="brand-title">HOUSE &amp; ICE</span>
                <span class="brand-dot">•</span>
                <span class="brand-subtitle">Sistema Administrativo</span>
            </a>
        </div>
        <div class="topbar-actions">
            <div class="topbar-user">
                <div class="user-avatar">
                    <?= htmlspecialchars($sesionAvatarInitials) ?>
                </div>
                <div class="user-info-col">
                    <div class="user-name">
                        <?= htmlspecialchars($sesionNombreCompleto) ?>
                    </div>
                    <div class="user-sub-line">
                        <span class="user-role-text">
                            <?= htmlspecialchars($sesionRol) ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar')): ?>
                                <?= renderizarPillCajaTopbar() ?>
                        <?php else: ?>
                                <span class="user-caja-pill open"><span class="pill-dot green"></span> Caja 0
                                    <?= htmlspecialchars((string) $sesionCajaId) ?>
                                </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <main class="main-content">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Devoluciones y Notas de Crédito (B04)</h1>
                    <p class="page-subtitle">Emisión fiscal DGII B04 autorizada con PIN de supervisor, reingreso por
                        lote exacto y gestión de Saldo a Favor (15 días)</p>
                </div>
                <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <?php if ($infoB04): ?>
                            <span class="badge-count"
                                style="background: #fdf5ea; color: #794a15; border: 1px solid #d4a359; font-family: monospace;">
                                Próximo NCF:
                                <?= htmlspecialchars($infoB04['proximo']) ?> (
                                <?= $infoB04['restantes'] ?> disp.)
                            </span>
                    <?php else: ?>
                            <span class="badge-count" style="background: #ffebee; color: #c62828; border: 1px solid #ef9a9a;">
                                ⚠️ Sin secuencia B04 activa
                            </span>
                    <?php endif; ?>

                    <button type="button" class="btn btn-gold" onclick="abrirModalNuevaDevolucion()" <?= !$infoB04 ? 'disabled' : '' ?>>
                        + Nueva Devolución (B04)
                    </button>
                </div>
            </div>

            <?php if (!empty($errorCarga)): ?>
                    <div
                        style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                        <strong>Aviso:</strong>
                        <?= htmlspecialchars($errorCarga) ?>
                    </div>
            <?php endif; ?>

            <!-- KPIs -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: 12px; margin-bottom: 18px;">
                <div style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #7a6b5d; font-weight: 700;">NOTAS DE CRÉDITO (B04)</div>
                    <div style="font-size: 1.45rem; font-weight: 800; color: #211813; margin-top: 4px;">
                        <?= $kpiTotal ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #ffe0b2; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #e65100; font-weight: 700;">TOTAL REEMBOLSADO</div>
                    <div
                        style="font-size: 1.45rem; font-weight: 800; color: #e65100; margin-top: 4px; font-family: monospace;">
                        RD$
                        <?= number_format($kpiMontoTotal, 2) ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #c8e6c9; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #2e7d32; font-weight: 700;">SALDO A FAVOR VIGENTE</div>
                    <div
                        style="font-size: 1.45rem; font-weight: 800; color: #2e7d32; margin-top: 4px; font-family: monospace;">
                        RD$
                        <?= number_format($kpiSaldoActivo, 2) ?>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #bbdefb; border-radius: 10px; padding: 14px 16px;">
                    <div style="font-size: 0.74rem; color: #1565c0; font-weight: 700;">CRÉDITOS B04 CANJEABLES</div>
                    <div style="font-size: 1.45rem; font-weight: 800; color: #1565c0; margin-top: 4px;">
                        <?= $kpiCantB04Disp ?>
                    </div>
                </div>
            </div>

            <!-- TABLA HISTÓRICA DE NOTAS DE CRÉDITO B04 -->
            <div class="table-card">
                <div class="table-card-top" style="flex-wrap: wrap; gap: 10px;">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Registro de Devoluciones y Notas de Crédito B04</h2>
                        <span class="badge-count" id="badgeCountDev">
                            <?= count($devoluciones) ?> registros
                        </span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <select id="filtroMetodoDev" class="form-control" style="width: 190px; height: 38px;"
                            onchange="filtrarTablaDevoluciones()">
                            <option value="">Todos los métodos</option>
                            <option value="SALDO_FAVOR">Saldo a Favor (15d)</option>
                            <option value="EFECTIVO">Efectivo</option>
                            <option value="TARJETA">Tarjeta</option>
                            <option value="ABONO_CXC">Abono a CxC</option>
                        </select>

                        <div class="search-box" style="width: 280px;">
                            <span class="search-icon">🔍</span>
                            <input type="text" id="buscadorDev"
                                placeholder="Buscar por B04, factura, cliente o código CRD..."
                                oninput="filtrarTablaDevoluciones()" />
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>NCF B04 / Fecha</th>
                                <th>Factura Modificada</th>
                                <th>Cliente</th>
                                <th>Método / Crédito</th>
                                <th class="text-right">Monto B04</th>
                                <th>Autorizó (PIN)</th>
                                <th class="text-right" style="padding-right: 20px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($devoluciones)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center" style="padding: 32px; color: #888;">
                                            Aún no se han emitido devoluciones. Haz clic en <strong>+ Nueva Devolución
                                                (B04)</strong> para procesar una Nota de Crédito.
                                        </td>
                                    </tr>
                            <?php else: ?>
                                    <?php foreach ($devoluciones as $dv):
                                        $metKey = strtoupper((string) $dv['metodo_reembolso']);
                                        $bm = $badgesMetodo[$metKey] ?? ['label' => $metKey, 'bg' => '#eadecd', 'color' => '#5a3818'];
                                        $sim = $dv['simbolo'] ?: 'RD$';
                                        $estSal = strtoupper((string) ($dv['estado_saldo'] ?? ''));
                                        $filtroMetNorm = in_array($metKey, ['ABONO_CXC', 'CREDITO_CXC', 'CREDITO', 'NOTA_CREDITO'], true) ? 'ABONO_CXC' : $metKey;
                                        $textoF = strtolower(
                                            ($dv['ncf_nota_credito'] ?? '') . ' ' .
                                            ($dv['numero_factura'] ?? '') . ' ' .
                                            ($dv['ncf_factura_modificada'] ?? '') . ' ' .
                                            ($dv['cliente_nombre'] ?? '') . ' ' .
                                            ($dv['codigo_credito'] ?? '') . ' ' .
                                            ($dv['supervisor_nombre'] ?? '')
                                        );
                                        ?>
                                            <tr class="fila-devolucion" data-metodo="<?= htmlspecialchars($filtroMetNorm) ?>"
                                                data-texto="<?= htmlspecialchars($textoF) ?>">
                                                <td style="white-space: nowrap;">
                                                    <strong style="color: #a66e2e; font-family: monospace; font-size: 0.94rem;">
                                                        <?= htmlspecialchars($dv['ncf_nota_credito']) ?>
                                                    </strong>
                                                    <div style="font-size: 0.74rem; color: #8a7b70;">
                                                        <?= htmlspecialchars($dv['fecha_emision_fmt']) ?>
                                                    </div>
                                                </td>
                                                <td style="white-space: nowrap;">
                                                    <div style="font-weight: 700; font-family: monospace; color: #211813;">
                                                        <?= htmlspecialchars($dv['numero_factura']) ?>
                                                    </div>
                                                    <div style="font-size: 0.74rem; color: #8a7b70;">NCF:
                                                        <?= htmlspecialchars($dv['ncf_factura_modificada']) ?> •
                                                        <?= intval($dv['total_unidades']) ?> ud(s).
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 700; color: #211813;">
                                                        <?= htmlspecialchars($dv['cliente_nombre']) ?>
                                                    </div>
                                                    <div style="font-size: 0.74rem; color: #8a7b70; font-family: monospace;">
                                                        <?= htmlspecialchars($dv['cliente_doc']) ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge"
                                                        style="background: <?= $bm['bg'] ?>; color: <?= $bm['color'] ?>; font-weight: 700; font-size: 0.72rem;">
                                                        <?= htmlspecialchars($bm['label']) ?>
                                                    </span>
                                                    <?php if (!empty($dv['codigo_credito'])): ?>
                                                            <div
                                                                style="font-size: 0.74rem; font-family: monospace; margin-top: 3px; color: <?= in_array($estSal, ['DISPONIBLE', 'USADO_PARCIAL'], true) ? '#2e7d32' : '#8a7b70' ?>;">
                                                                <strong>
                                                                    <?= htmlspecialchars($dv['codigo_credito']) ?>
                                                                </strong>
                                                                • Disp:
                                                                <?= htmlspecialchars($sim) ?>
                                                                <?= number_format((float) $dv['saldo_disponible'], 2) ?>
                                                                (
                                                                <?= htmlspecialchars($estSal) ?>)
                                                            </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right"
                                                    style="white-space: nowrap; font-family: monospace; font-weight: 800; color: #c62828;">
                                                    -
                                                    <?= htmlspecialchars($sim) ?>
                                                    <?= number_format((float) $dv['monto_total'], 2) ?>
                                                </td>
                                                <td style="font-size: 0.82rem; color: #4a3b32;">
                                                    <?= htmlspecialchars($dv['supervisor_nombre']) ?>
                                                </td>
                                                <td class="text-right" style="padding-right: 20px; white-space: nowrap;">
                                                    <button type="button" class="btn-sm btn-outline"
                                                        onclick="verDetalleDevolucion(<?= (int) $dv['id'] ?>)">Ver / Imprimir
                                                        B04</button>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-card-footer">
                    <span id="footerInfoDev">Total:
                        <?= count($devoluciones) ?> devoluciones emitidas
                    </span>
                    <span>House &amp; Ice • Control Fiscal DGII B04 y Reingreso por Lote</span>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 1: NUEVA DEVOLUCIÓN / NOTA DE CRÉDITO B04 (PATRÓN 3 BLOQUES)       -->
    <!-- ======================================================================== -->
    <div id="modalNuevaDevolucion" class="modal-overlay">
        <div class="modal-box modal-xl">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Emitir Devolución y Nota de Crédito (B04)</h2>
                    <p class="modal-subtitle" style="margin: 0; font-size: 0.8rem; color: #7a6b5d;">Selecciona la
                        factura original, indica qué unidades regresan y autoriza con PIN de supervisor</p>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModalDevolucion()">&times;</button>
            </div>

            <form id="formNuevaDevolucion" onsubmit="guardarDevolucion(event)">
                <div class="modal-body" style="max-height: 74vh; overflow-y: auto;">

                    <!-- BLOQUE 1: BUSCAR Y SELECCIONAR FACTURA ORIGINAL -->
                    <div class="promo-block-card">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">1</span>
                                <span>Factura Original a Modificar</span>
                            </div>
                            <span class="promo-type-hint">Solo facturas PAGADA, EMITIDA o ENTREGADA</span>
                        </div>

                        <div class="fac-picker-wrap" id="facPickerWrap">
                            <input type="hidden" id="dev_factura_id" value="0" />
                            <div id="dev_fac_pill" class="fac-selected-pill">
                                <div id="dev_fac_pill_texto"></div>
                                <button type="button" class="btn-sm btn-outline" onclick="limpiarFacturaDev(true)"
                                    style="flex-shrink: 0;">Cambiar Factura</button>
                            </div>
                            <input type="text" id="dev_fac_buscar" class="form-control" autocomplete="off"
                                placeholder="Buscar por N° Factura (FAC-...), NCF (B02/B01), cliente o cédula/RNC..."
                                onfocus="buscarFacturasDev()" oninput="programarBusquedaFacturasDev()" />
                            <div id="dev_fac_dropdown" class="fac-dropdown"></div>
                        </div>

                        <div id="devAlerta30Dias"
                            style="display: none; margin-top: 10px; background: #fff3e0; border: 1px solid #ffb74d; color: #8d4500; padding: 8px 12px; border-radius: 6px; font-size: 0.82rem;">
                        </div>
                    </div>

                    <!-- BLOQUE 2: SELECCIÓN DE ARTÍCULOS, CANTIDADES Y ESTADO FÍSICO -->
                    <div class="promo-block-card rules-cart">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">2</span>
                                <span>Productos a Devolver y Destino Físico (Reingreso vs. Dañado)</span>
                            </div>
                            <button type="button" class="btn-sm btn-outline" id="btnDevolverTodo" style="display: none;"
                                onclick="marcarDevolverTodo()">
                                Devolver Todo lo Disponible
                            </button>
                        </div>

                        <div class="carrito-reglas-wrapper">
                            <table class="carrito-reglas-table">
                                <thead>
                                    <tr>
                                        <th>SKU / Producto / Lote Origen</th>
                                        <th style="text-align: center; width: 105px;">Fact. / Disp.</th>
                                        <th style="text-align: center; width: 135px;">Cant. Devolver</th>
                                        <th style="text-align: center; width: 185px;">Condición Física</th>
                                        <th style="text-align: right; width: 115px;">Valor Unit.</th>
                                        <th style="text-align: right; width: 120px;">Subtotal B04</th>
                                    </tr>
                                </thead>
                                <tbody id="devTablaLineasBody">
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 22px; color: #888;">
                                            Busca y selecciona una factura arriba para ver los productos disponibles
                                            para devolución.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="dev-total-strip">
                            <div id="devResumenUnidades" style="font-size: 0.84rem; color: #5a3818;">
                                0 unidad(es) seleccionada(s) para devolución
                            </div>
                            <div style="text-align: right;">
                                <small
                                    style="display: block; font-size: 0.7rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">TOTAL
                                    NOTA DE CRÉDITO (B04)</small>
                                <strong id="devTotalReembolso"
                                    style="font-family: monospace; font-size: 1.25rem; color: #c62828;">RD$
                                    0.00</strong>
                            </div>
                        </div>
                    </div>

                    <!-- BLOQUE 3: MÉTODO DE REEMBOLSO, MOTIVO Y AUTORIZACIÓN PIN -->
                    <div class="promo-block-card">
                        <div class="promo-block-header">
                            <div class="promo-block-title">
                                <span class="promo-step-badge">3</span>
                                <span>Reembolso y Autorización de Supervisor (PIN)</span>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 12px; margin-bottom: 12px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="dev_metodo_reembolso" class="form-label">Método de Reembolso *</label>
                                <select id="dev_metodo_reembolso" class="form-control" required
                                    onchange="actualizarNotaMetodoDev()">
                                    <option value="SALDO_FAVOR">♻️ Saldo a Favor B04 (Crédito 15 días en tienda)
                                    </option>
                                    <option value="EFECTIVO">💵 Efectivo (Egreso de Caja Chica en turno activo)</option>
                                    <option value="TARJETA">💳 Reverso a Tarjeta (Rebaja vouchers del turno)</option>
                                    <option value="ABONO_CXC" id="optMetodoCxc" disabled>📋 Abono a Cuenta por Cobrar
                                        (CxC)</option>
                                </select>
                                <div id="devNotaMetodo" style="font-size: 0.75rem; color: #7a6b5d; margin-top: 4px;">
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="dev_justificacion" class="form-label">Motivo / Justificación Obligatoria
                                    *</label>
                                <input type="text" id="dev_justificacion" class="form-control" required minlength="5"
                                    maxlength="255"
                                    placeholder="Ej. Cliente cambió presentación / Botella sellada sobrante de evento / Corcho defectuoso" />
                            </div>
                        </div>

                        <div class="pin-auth-box">
                            <div style="font-weight: 800; color: #794a15; font-size: 0.82rem; margin-bottom: 8px;">
                                🔐 FIRMA OBLIGATORIA DEL SUPERVISOR (PERMISO AUTORIZAR_CON_PIN)
                            </div>
                            <div class="form-row-2">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="dev_supervisor_id" class="form-label">Supervisor que Autoriza *</label>
                                    <select id="dev_supervisor_id" class="form-control" required
                                        style="background: #fff;">
                                        <option value="">-- Seleccionar Supervisor --</option>
                                        <?php foreach ($supervisoresAutorizados as $sup): ?>
                                                <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                                    <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ')') ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="dev_pin" class="form-label">PIN de Supervisor (4 a 6 dígitos) *</label>
                                    <input type="password" id="dev_pin" class="form-control" required
                                        inputmode="numeric" maxlength="6" placeholder="••••••"
                                        style="background: #fff; font-family: monospace; letter-spacing: 5px; font-weight: 800; text-align: center;"
                                        oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModalDevolucion()">Cancelar</button>
                    <button type="submit" class="btn btn-gold" id="btnEmitirB04">✅ Emitir Nota de Crédito (B04)</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 2: VER / IMPRIMIR NOTA DE CRÉDITO B04                              -->
    <!-- ======================================================================== -->
    <div id="modalDetalleB04" class="modal-overlay"
        onclick="if(event.target.id==='modalDetalleB04') cerrarModal('modalDetalleB04')">
        <div class="modal-box modal-lg">
            <div class="modal-header no-print">
                <h3 class="modal-title" id="detB04Titulo">Nota de Crédito B04</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalDetalleB04')">&times;</button>
            </div>
            <div class="modal-body" id="areaImpresionB04" style="max-height: 72vh; overflow-y: auto;">
                <p style="color: #888;">Consultando Nota de Crédito...</p>
            </div>
            <div class="modal-footer no-print">
                <button type="button" class="btn btn-outline" onclick="window.print()">🖨️ Imprimir / PDF</button>
                <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleB04')">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script src="devoluciones.js?v=<?= time() ?>"></script>
</body>

</html>