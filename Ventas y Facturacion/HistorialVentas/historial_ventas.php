<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Reporte: Historial de Ventas (Vista Principal)
 * Ubicación: Ventas y Facturacion/HistorialVentas/historial_ventas.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

if (function_exists('usuarioPuedeAccederFacturacion')) {
    requerirAccesoModulo(
        usuarioPuedeAccederFacturacion(),
        false,
        'Acceso restringido: no posees permisos para consultar el Historial de Ventas.'
    );
}

$vendedores = [];
$metodosPago = [];
$supervisoresAutorizados = [];

if ($pdo) {
    try {
        $vendedores = $pdo->query("
            SELECT DISTINCT u.usuario_id,
                   COALESCE(NULLIF(TRIM(p.nombre_razon_social), ''), u.username) AS nombre_completo,
                   u.username
            FROM ventas.facturas f
            JOIN seguridad.usuarios u ON u.usuario_id = f.vendedor_id
            LEFT JOIN identidad.personas p ON p.persona_id = u.persona_id
            ORDER BY nombre_completo ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $metodosPago = $pdo->query("
            SELECT id, codigo, descripcion
            FROM ventas.metodos_pago
            WHERE activo = TRUE
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
    }

    try {
        $supervisoresAutorizados = obtenerSupervisoresAutorizados($pdo);
    } catch (Throwable $e) {
    }
}

$hoy = date('Y-m-d');
$inicioDeMes = date('Y-m-01');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Historial de Ventas</title>
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

        .hv-filters-card {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 18px;
        }

        .hv-presets-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e7dcc8;
        }

        .hv-preset-group {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .hv-preset-btn {
            border: 1px solid #d4c4b0;
            background: #faf6f0;
            color: #5a3818;
            padding: 4px 10px;
            border-radius: 99px;
            font-size: 0.76rem;
            font-weight: 700;
            cursor: pointer;
        }

        .hv-preset-btn:hover,
        .hv-preset-btn.active {
            background: #a66e2e;
            color: #fff;
            border-color: #a66e2e;
        }

        .hv-filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .hv-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }

        .hv-kpi-card {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 14px 16px;
        }

        .hv-kpi-title {
            font-size: 0.72rem;
            font-weight: 800;
            color: #7a6b5d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .hv-kpi-val {
            font-size: 1.4rem;
            font-weight: 900;
            font-family: monospace;
            color: #211813;
            margin-top: 4px;
        }

        .hv-kpi-sub {
            font-size: 0.74rem;
            color: #8a7b70;
            margin-top: 3px;
        }

        .hv-tax-pill {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 99px;
            background: #f3ece1;
            color: #5a3818;
            margin: 1px 2px;
        }

        .hv-badge-pago {
            display: inline-block;
            font-size: 0.7rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 6px;
            background: #f5f2ed;
            color: #4a3b32;
            border: 1px solid #e0d6c8;
            font-family: monospace;
        }

        @media print {

            .topbar,
            .sidebar,
            .sidebar-overlay,
            .hv-filters-card,
            .no-print {
                display: none !important;
            }

            .app-container,
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            /* Modo impresión de comprobante individual */
            body.imprimiendo-comprobante * {
                visibility: hidden;
            }

            body.imprimiendo-comprobante #areaImpresionFactura,
            body.imprimiendo-comprobante #areaImpresionFactura * {
                visibility: visible;
            }

            body.imprimiendo-comprobante #areaImpresionFactura {
                position: fixed;
                left: 0;
                top: 0;
                width: 100%;
                background: #fff;
                padding: 24px;
                box-sizing: border-box;
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
                <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials) ?></div>
                <div class="user-info-col">
                    <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto) ?></div>
                    <div class="user-sub-line">
                        <span class="user-role-text"><?= htmlspecialchars($sesionRol) ?></span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar')): ?>
                            <?= renderizarPillCajaTopbar() ?>
                        <?php else: ?>
                            <span class="user-caja-pill open"><span class="pill-dot green"></span> Caja
                                0<?= htmlspecialchars((string) $sesionCajaId) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <?php include __DIR__ . '/../../sidebar.php'; ?>

        <main class="main-content">

            <!-- ENCABEZADO -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Historial de Ventas y Comprobantes</h1>
                    <p class="page-subtitle">Consulta histórica de facturas, auditoría fiscal DGII, reimpresión de
                        comprobantes, exportación a Excel y anulaciones con PIN</p>
                </div>
                <div class="no-print" style="display: flex; gap: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn btn-outline" onclick="imprimirReporteGeneral()">
                        🖨️ Imprimir Reporte
                    </button>
                    <button type="button" class="btn btn-gold" onclick="exportarHistorialCsv()">
                        📥 Exportar CSV (Excel)
                    </button>
                </div>
            </div>

            <!-- PANEL DE FILTROS -->
            <div class="hv-filters-card">
                <div class="hv-presets-row">
                    <div style="font-size: 0.82rem; font-weight: 800; color: #5a3818;">🔎 Filtros del Reporte</div>
                    <div class="hv-preset-group">
                        <button type="button" class="hv-preset-btn" data-preset="hoy"
                            onclick="aplicarPresetFecha('hoy')">Hoy</button>
                        <button type="button" class="hv-preset-btn" data-preset="7d"
                            onclick="aplicarPresetFecha('7d')">Últimos 7 días</button>
                        <button type="button" class="hv-preset-btn active" data-preset="mes"
                            onclick="aplicarPresetFecha('mes')">Este mes</button>
                        <button type="button" class="hv-preset-btn" data-preset="todo"
                            onclick="aplicarPresetFecha('todo')">Todo el historial</button>
                        <button type="button" class="hv-preset-btn" onclick="limpiarFiltrosHistorial()"
                            style="background: #fff; color: #8a7b70;">↺ Limpiar</button>
                    </div>
                </div>

                <div class="hv-filters-grid">
                    <div class="form-group" style="margin-bottom: 0; grid-column: span 2;">
                        <label for="hv_q" class="form-label">Buscar (Factura, NCF, Cliente, RNC, Cajero o SKU)</label>
                        <input type="text" id="hv_q" class="form-control"
                            placeholder="Ej. FAC-..., B02..., Juan, RON-BRU..." oninput="programarCargaHistorial()" />
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_fecha_desde" class="form-label">Desde</label>
                        <input type="date" id="hv_fecha_desde" class="form-control"
                            value="<?= htmlspecialchars($inicioDeMes) ?>" onchange="alCambiarFechaManual()" />
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_fecha_hasta" class="form-label">Hasta</label>
                        <input type="date" id="hv_fecha_hasta" class="form-control"
                            value="<?= htmlspecialchars($hoy) ?>" onchange="alCambiarFechaManual()" />
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_tipo_ncf" class="form-label">Tipo NCF</label>
                        <select id="hv_tipo_ncf" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="">Todos</option>
                            <option value="B02">B02 • Consumo Final</option>
                            <option value="B01">B01 • Crédito Fiscal</option>
                            <option value="CONSUMO_INTERNO">Consumo Interno</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_condicion" class="form-label">Condición</label>
                        <select id="hv_condicion" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="">Todas</option>
                            <option value="CONTADO">Contado</option>
                            <option value="CREDITO">Crédito (CxC)</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_origen" class="form-label">Canal / Origen</label>
                        <select id="hv_origen" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="">Todos</option>
                            <option value="MOSTRADOR">Mostrador (POS)</option>
                            <option value="DELIVERY">Delivery</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_estado" class="form-label">Estado</label>
                        <select id="hv_estado" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="">Todos</option>
                            <option value="PAGADA">PAGADA</option>
                            <option value="EMITIDA">EMITIDA</option>
                            <option value="EN_TRANSITO">EN TRÁNSITO</option>
                            <option value="ENTREGADA">ENTREGADA</option>
                            <option value="ANULADA">ANULADA</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_metodo" class="form-label">Método de Pago</label>
                        <select id="hv_metodo" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="0">Todos</option>
                            <?php foreach ($metodosPago as $mp): ?>
                                <option value="<?= (int) $mp['id'] ?>"><?= htmlspecialchars($mp['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="hv_vendedor" class="form-label">Cajero / Vendedor</label>
                        <select id="hv_vendedor" class="form-control" onchange="cargarHistorialVentas()">
                            <option value="">Todos</option>
                            <?php foreach ($vendedores as $v): ?>
                                <option value="<?= htmlspecialchars($v['usuario_id']) ?>">
                                    <?= htmlspecialchars($v['nombre_completo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- TARJETAS KPI EN VIVO -->
            <div class="hv-kpi-grid">
                <div class="hv-kpi-card" style="border-left: 4px solid #2e7d32;">
                    <div class="hv-kpi-title" style="color: #2e7d32;">Ventas Netas (DOP)</div>
                    <div class="hv-kpi-val" id="kpiVentasNetas" style="color: #1b5e20;">RD$ 0.00</div>
                    <div class="hv-kpi-sub" id="kpiVentasSub">0 facturas válidas • Ticket prom.: RD$ 0.00</div>
                </div>

                <div class="hv-kpi-card" style="border-left: 4px solid #a66e2e;">
                    <div class="hv-kpi-title" style="color: #794a15;">Impuestos DGII / Descuentos</div>
                    <div class="hv-kpi-val" id="kpiImpuestos" style="color: #794a15;">RD$ 0.00</div>
                    <div class="hv-kpi-sub" id="kpiDescuentosSub">Descuentos y cupones: RD$ 0.00</div>
                </div>

                <div class="hv-kpi-card" style="border-left: 4px solid #1565c0;">
                    <div class="hv-kpi-title" style="color: #1565c0;">Contado vs. Crédito</div>
                    <div class="hv-kpi-val" id="kpiContado" style="color: #0d47a1;">RD$ 0.00</div>
                    <div class="hv-kpi-sub" id="kpiCreditoSub">Crédito (CxC): RD$ 0.00</div>
                </div>

                <div class="hv-kpi-card" style="border-left: 4px solid #c62828;">
                    <div class="hv-kpi-title" style="color: #c62828;">Devoluciones (B04) y Anuladas</div>
                    <div class="hv-kpi-val" id="kpiDevoluciones" style="color: #c62828;">RD$ 0.00</div>
                    <div class="hv-kpi-sub" id="kpiAnuladasSub">0 anuladas (RD$ 0.00)</div>
                </div>
            </div>

            <!-- TABLA DE FACTURAS -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Listado de Facturas Emitidas</h2>
                        <span class="badge-count" id="hvBadgeTotal">0 facturas</span>
                    </div>
                    <div id="hvMargenBanner"
                        style="display: none; font-size: 0.8rem; font-weight: 700; color: #2e7d32; background: #edf7ed; border: 1px solid #c8e6c9; padding: 4px 10px; border-radius: 99px;">
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Factura / Fecha</th>
                                <th>Comprobante (NCF)</th>
                                <th>Cliente</th>
                                <th>Cajero / Terminal</th>
                                <th>Condición / Pago</th>
                                <th class="text-right">Total / Imp.</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right no-print" style="padding-right: 18px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="hvTablaBody">
                            <tr>
                                <td colspan="8" class="text-center" style="padding: 32px; color: #888;">
                                    Cargando historial de ventas...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="table-card-footer">
                    <span id="hvFooterInfo">Mostrando 0 facturas</span>
                    <span>House &amp; Ice • Reporte Operativo y Fiscal DGII</span>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 1: VER / REIMPRIMIR COMPROBANTE DE FACTURA                         -->
    <!-- ======================================================================== -->
    <div id="modalComprobanteVenta" class="modal-overlay"
        onclick="if(event.target.id==='modalComprobanteVenta') cerrarModal('modalComprobanteVenta')">
        <div class="modal-box modal-lg" style="max-width: 760px;">
            <div class="modal-header no-print">
                <h3 class="modal-title" id="detFacTitulo">Detalle de Comprobante</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalComprobanteVenta')">&times;</button>
            </div>
            <div class="modal-body" id="areaImpresionFactura" style="max-height: 74vh; overflow-y: auto;">
                <p style="color: #888;">Consultando factura...</p>
            </div>
            <div class="modal-footer no-print">
                <button type="button" class="btn btn-outline" onclick="imprimirComprobanteIndividual()">🖨️ Reimprimir /
                    PDF</button>
                <button type="button" class="btn btn-secondary"
                    onclick="cerrarModal('modalComprobanteVenta')">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 2: ANULAR FACTURA CON PIN DE SUPERVISOR                            -->
    <!-- ======================================================================== -->
    <div id="modalAnularHistorial" class="modal-overlay" style="z-index: 1250;">
        <div class="modal-box" style="max-width: 480px;">
            <div class="modal-header">
                <h3 class="modal-title">Anular Factura (Requiere PIN)</h3>
                <button type="button" class="btn-close" onclick="cerrarModal('modalAnularHistorial')">&times;</button>
            </div>
            <form id="formAnularHistorial" onsubmit="confirmarAnulacionHistorial(event)">
                <input type="hidden" id="anularHistFacturaId" value="" />
                <div class="modal-body">
                    <div
                        style="background: #ffebee; border: 1px solid #ef9a9a; color: #b71c1c; padding: 10px 12px; border-radius: 8px; font-size: 0.84rem; margin-bottom: 12px;">
                        Vas a anular <strong id="anularHistNumero"></strong>. Se devolverán las unidades a sus lotes
                        originales y se reversarán caja, CxC, saldo B04 y cupones.
                    </div>
                    <div class="form-group">
                        <label for="anularHistJustificacion" class="form-label">Motivo de Anulación *</label>
                        <input type="text" id="anularHistJustificacion" class="form-control" required minlength="5"
                            maxlength="255" placeholder="Ej. Error en selección de comprobante B01" />
                    </div>
                    <div class="form-group">
                        <label for="anularHistSupervisor" class="form-label">Supervisor que Autoriza *</label>
                        <select id="anularHistSupervisor" class="form-control" required>
                            <option value="">-- Seleccionar Supervisor --</option>
                            <?php foreach ($supervisoresAutorizados as $sup): ?>
                                <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                                    <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="anularHistPin" class="form-label">PIN de Supervisor *</label>
                        <input type="password" id="anularHistPin" class="form-control" required inputmode="numeric"
                            maxlength="6" placeholder="••••••"
                            style="font-family: monospace; letter-spacing: 4px; font-weight: 700; text-align: center;"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary"
                        onclick="cerrarModal('modalAnularHistorial')">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="btnConfirmarAnularHist">Confirmar
                        Anulación</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script src="historial_ventas.js?v=<?= time() ?>"></script>
</body>

</html>