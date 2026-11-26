<?php
/**
 * ============================================================================
 * Módulo 6: Tesorería • Movimientos Bancarios y Depósitos de Arqueos
 * Ubicación: Tesoreria/MovimientosBancarios/movimientos_bancarios.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeRegistrarBancos = $esAdmin
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('REGISTRAR_MOVIMIENTOS_BANCARIOS'));
$puedeVerBancos = $puedeRegistrarBancos
    || (function_exists('usuarioTienePermiso') && usuarioTienePermiso('VER_REPORTES_FINANCIEROS'));

if (!$puedeVerBancos) {
    header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para acceder a Tesorería / Movimientos Bancarios."));
    exit;
}

$cuentasTodas = [];
$cuentasDop = [];

if ($pdo) {
    try {
        $cuentasTodas = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta,
                   COALESCE(m.codigo_iso, 'DOP') AS codigo_iso,
                   COALESCE(m.simbolo, 'RD$')    AS simbolo,
                   COALESCE(m.es_moneda_base, TRUE) AS es_moneda_base
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
            ORDER BY m.es_moneda_base DESC, cb.nombre_banco ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($cuentasTodas as $ct) {
            $esBase = ($ct['es_moneda_base'] === true || $ct['es_moneda_base'] === 't' || $ct['es_moneda_base'] == 1);
            if ($esBase || strtoupper((string) $ct['codigo_iso']) === 'DOP') {
                $cuentasDop[] = $ct;
            }
        }
    } catch (Throwable $e) {
        $errorCarga = $e->getMessage();
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
    <title>House &amp; Ice • Tesorería y Depósitos de Arqueos</title>
    <link rel="stylesheet" href="../../Panel de configuracion/styles.css?v=21" />
    <style>
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999999;
            padding: 12px 18px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.88rem;
            color: #fff;
            background: #211813;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.28);
            opacity: 0;
            pointer-events: none;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            max-width: 440px;
        }

        .toast.show {
            opacity: 1;
            pointer-events: auto;
            transform: translateY(0);
        }

        .toast-success {
            background: #1b5e20;
        }

        .toast-error {
            background: #b71c1c;
        }

        .mb-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }

        .mb-kpi-card {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .mb-kpi-label {
            font-size: 0.72rem;
            font-weight: 800;
            color: #7a6b5d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .mb-kpi-value {
            font-size: 1.45rem;
            font-weight: 900;
            font-family: monospace;
            color: #211813;
            margin-top: 4px;
        }

        .mb-kpi-sub {
            font-size: 0.74rem;
            color: #8a7b70;
            margin-top: 2px;
        }

        .boveda-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            background: #fdf8ee;
            border: 1px solid #dfcaa4;
            border-radius: 10px;
            padding: 12px 16px;
            margin-top: 12px;
        }

        .mb-filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 10px;
            align-items: end;
            padding: 14px 18px;
            border-bottom: 1px solid #efe7da;
            background: #fcfaf6;
        }

        .badge-mov {
            display: inline-block;
            font-size: 0.71rem;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 5px;
            letter-spacing: 0.2px;
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(20, 14, 10, 0.65);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }

        .modal-content {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 48px rgba(0, 0, 0, 0.35);
            border: 1px solid #d8b888;
            width: 100%;
            overflow: hidden;
        }

        .modal-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eadecd;
            background: #fdfaf4;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .modal-title {
            font-size: 1.12rem;
            font-weight: 800;
            color: #211813;
            margin: 0 0 3px 0;
        }

        .modal-subtitle {
            font-size: 0.8rem;
            color: #8a7b70;
            margin: 0;
        }

        .modal-body {
            padding: 18px 20px;
            max-height: 74vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 13px 20px;
            border-top: 1px solid #eadecd;
            background: #fdfaf4;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn-close {
            background: none;
            border: none;
            font-size: 1.55rem;
            line-height: 1;
            color: #8a7b70;
            cursor: pointer;
            padding: 0 4px;
        }

        .btn-close:hover {
            color: #211813;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #areaImpresionVolante,
            #areaImpresionVolante * {
                visibility: visible;
            }

            #areaImpresionVolante {
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
                    <?= htmlspecialchars($sesionAvatarInitials ?? 'AD') ?>
                </div>
                <div class="user-info-col">
                    <div class="user-name">
                        <?= htmlspecialchars($sesionNombreCompleto ?? 'Usuario') ?>
                    </div>
                    <div class="user-sub-line">
                        <span class="user-role-text">
                            <?= htmlspecialchars($sesionRol ?? 'Tesorería') ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar')): ?>
                            <?= renderizarPillCajaTopbar() ?>
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
                    <h1 class="page-title">Tesorería • Movimientos Bancarios y Depósitos de Caja</h1>
                    <p class="page-subtitle">Consolidación de arqueos de efectivo en bóveda hacia cuentas bancarias y
                        control del libro auxiliar de bancos</p>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <a href="../../Ventas y Facturacion/ControlCaja/control_caja.php" class="btn btn-beige"
                        style="text-decoration: none;">
                        💻 Ir a Control de Caja
                    </a>
                </div>
            </div>

            <?php if (!empty($errorCarga)): ?>
                <div
                    style="background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px;">
                    <strong>Aviso:</strong>
                    <?= htmlspecialchars($errorCarga) ?>
                </div>
            <?php endif; ?>

            <!-- KPIs DE TESORERÍA -->
            <div class="mb-kpi-grid">
                <div class="mb-kpi-card" style="border-left: 4px solid #b25e09;">
                    <div class="mb-kpi-label" style="color: #b25e09;">Pendiente en Bóveda (DOP)</div>
                    <div class="mb-kpi-value" id="kpiBovedaDop" style="color: #b25e09;">RD$ 0.00</div>
                    <div class="mb-kpi-sub" id="kpiBovedaSub">0 arqueo(s) por depositar</div>
                </div>

                <div class="mb-kpi-card" style="border-left: 4px solid #2e7d32;">
                    <div class="mb-kpi-label" style="color: #2e7d32;">Depósitos de Caja (Periodo)</div>
                    <div class="mb-kpi-value" id="kpiDepositosCaja" style="color: #1b5e20;">RD$ 0.00</div>
                    <div class="mb-kpi-sub" id="kpiDepositosSub">0 volante(s) registrado(s)</div>
                </div>

                <div class="mb-kpi-card" style="border-left: 4px solid #1565c0;">
                    <div class="mb-kpi-label" style="color: #1565c0;">Entradas Bancarias Totales</div>
                    <div class="mb-kpi-value" id="kpiEntradasDop" style="color: #0d47a1;">RD$ 0.00</div>
                    <div class="mb-kpi-sub">Depósitos, transferencias y cheques</div>
                </div>

                <div class="mb-kpi-card" style="border-left: 4px solid #c62828;">
                    <div class="mb-kpi-label" style="color: #c62828;">Salidas Bancarias Totales</div>
                    <div class="mb-kpi-value" id="kpiSalidasDop" style="color: #c62828;">RD$ 0.00</div>
                    <div class="mb-kpi-sub" id="kpiFlujoNetoSub">Flujo neto: RD$ 0.00</div>
                </div>
            </div>

            <!-- SECCIÓN 1: BANDEJA DE ARQUEOS PENDIENTES DE DEPÓSITO (BÓVEDA) -->
            <div class="table-card" style="margin-bottom: 24px;">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">⏳ Arqueos de Efectivo Pendientes de Depósito (En Bóveda)</h2>
                        <span class="badge-count" id="badgePendientesCount">0 pendientes</span>
                    </div>
                    <div style="font-size: 0.8rem; color: #8a7b70;">
                        Marca uno o varios turnos cerrados para consolidarlos en un solo volante bancario
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th style="width: 42px; text-align: center;">
                                    <?php if ($puedeRegistrarBancos): ?>
                                        <input type="checkbox" id="chkSeleccionarTodosArqueos"
                                            onchange="toggleTodosArqueos(this.checked)" title="Seleccionar todos" />
                                    <?php endif; ?>
                                </th>
                                <th>SESIÓN / TERMINAL</th>
                                <th>CAJERO</th>
                                <th>CIERRE DEL TURNO</th>
                                <th style="text-align: right;">DECLARADO</th>
                                <th style="text-align: right;">MENUDO RETENIDO</th>
                                <th style="text-align: center;">DESCUADRE</th>
                                <th style="text-align: right; padding-right: 18px;">NETO A DEPOSITAR</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyArqueosPendientes">
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 24px; color: #8a7b70;">Consultando
                                    arqueos pendientes en bóveda...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <?php if ($puedeRegistrarBancos): ?>
                    <div style="padding: 0 18px 16px;">
                        <div class="boveda-bar">
                            <div>
                                <div
                                    style="font-size: 0.74rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">
                                    Selección Actual para Volante Bancario</div>
                                <div style="font-size: 0.92rem; color: #211813; margin-top: 2px;">
                                    <strong id="lblCantSeleccionados">0</strong> turno(s) seleccionado(s) • Total a
                                    depositar:
                                    <strong id="lblTotalSeleccionado"
                                        style="font-family: monospace; font-size: 1.12rem; color: #1b5e20;">RD$
                                        0.00</strong>
                                </div>
                            </div>
                            <button type="button" id="btnAbrirModalDeposito" class="btn btn-gold" disabled
                                onclick="abrirModalDepositoBancario()">
                                🏦 Registrar Depósito a Banco
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECCIÓN 2: LIBRO DE MOVIMIENTOS BANCARIOS -->
            <div class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">🏦 Libro de Movimientos Bancarios</h2>
                        <span class="badge-count" id="badgeMovsCount">0 movimientos</span>
                    </div>
                    <button type="button" class="btn btn-beige btn-sm" onclick="limpiarFiltrosBancos()">↺ Limpiar
                        Filtros</button>
                </div>

                <div class="mb-filters-row">
                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="filCuenta">Cuenta Bancaria</label>
                        <select id="filCuenta" class="form-control" onchange="cargarDatosTesoreria()">
                            <option value="0">Todas las cuentas</option>
                            <?php foreach ($cuentasTodas as $ct): ?>
                                <option value="<?= (int) $ct['id'] ?>">
                                    <?= htmlspecialchars($ct['nombre_banco'] . ' • ' . $ct['numero_cuenta'] . ' (' . $ct['codigo_iso'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="filTipo">Tipo de Movimiento</label>
                        <select id="filTipo" class="form-control" onchange="cargarDatosTesoreria()">
                            <option value="">Todos</option>
                            <option value="DEPOSITO_CAJA">Depósito de Caja</option>
                            <option value="TRANSFERENCIA_ENTRADA">Transferencia Entrada</option>
                            <option value="TRANSFERENCIA_SALIDA">Transferencia Salida</option>
                            <option value="CHEQUE_ENTRADA">Cheque Entrada</option>
                            <option value="CHEQUE_SALIDA">Cheque Salida</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="filDesde">Desde</label>
                        <input type="date" id="filDesde" class="form-control"
                            value="<?= htmlspecialchars($inicioDeMes) ?>" onchange="cargarDatosTesoreria()" />
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="filHasta">Hasta</label>
                        <input type="date" id="filHasta" class="form-control" value="<?= htmlspecialchars($hoy) ?>"
                            onchange="cargarDatosTesoreria()" />
                    </div>

                    <div class="form-group" style="margin: 0;">
                        <label class="form-label" for="filBuscar">Buscar Volante / Concepto</label>
                        <input type="text" id="filBuscar" class="form-control"
                            placeholder="Referencia, banco, sesión..." oninput="programarCargaTesoreria()" />
                    </div>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>FECHA / ID</th>
                                <th>CUENTA BANCARIA</th>
                                <th>TIPO</th>
                                <th>VOLANTE / REFERENCIA</th>
                                <th>CONCEPTO</th>
                                <th>REGISTRADO POR</th>
                                <th style="text-align: right;">MONTO</th>
                                <th style="text-align: right; padding-right: 18px;">ACCIONES</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyMovimientosBancarios">
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 26px; color: #8a7b70;">Cargando
                                    movimientos bancarios...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>
    </div>

    <!-- ======================================================================== -->
    <!-- MODAL 1: REGISTRAR DEPÓSITO DE ARQUEOS A BANCO                           -->
    <!-- ======================================================================== -->
    <?php if ($puedeRegistrarBancos): ?>
        <div id="modalRegistrarDeposito" class="modal-backdrop">
            <div class="modal-content" style="max-width: 560px;">
                <div class="modal-header">
                    <div>
                        <h3 class="modal-title">🏦 Confirmar Depósito de Caja a Banco</h3>
                        <p class="modal-subtitle">Asienta el volante bancario y marca los arqueos seleccionados como
                            DEPOSITADO</p>
                    </div>
                    <button type="button" class="btn-close" onclick="cerrarModal('modalRegistrarDeposito')">&times;</button>
                </div>

                <form id="formRegistrarDeposito" onsubmit="confirmarDepositoBancario(event)">
                    <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">

                        <div
                            style="background: #eef7ee; border: 1.5px solid #a5d6a7; border-radius: 10px; padding: 12px 16px; display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                            <div>
                                <div
                                    style="font-size: 0.72rem; font-weight: 800; color: #2e7d32; text-transform: uppercase;">
                                    Total del Volante a Depositar</div>
                                <div id="depResumenSesiones" style="font-size: 0.8rem; color: #3d332a; margin-top: 2px;">—
                                </div>
                            </div>
                            <div id="depMontoTotalModal"
                                style="font-size: 1.35rem; font-weight: 900; font-family: monospace; color: #1b5e20;">RD$
                                0.00</div>
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="dep_cuenta_id">CUENTA BANCARIA DESTINO (DOP) *</label>
                            <select id="dep_cuenta_id" class="form-control" required>
                                <?php if (empty($cuentasDop)): ?>
                                    <option value="">⚠️ No hay cuentas bancarias activas en DOP</option>
                                <?php else: ?>
                                    <option value="">-- Seleccionar Cuenta Bancaria --</option>
                                    <?php foreach ($cuentasDop as $cd): ?>
                                        <option value="<?= (int) $cd['id'] ?>">
                                            <?= htmlspecialchars($cd['nombre_banco'] . ' — ' . $cd['numero_cuenta'] . ' (' . $cd['tipo_cuenta'] . ' DOP)') ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div style="display: grid; grid-template-columns: 1.3fr 1fr; gap: 12px;">
                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="dep_referencia">N° DE VOLANTE / REFERENCIA BANCARIA *</label>
                                <input type="text" id="dep_referencia" class="form-control" required minlength="3"
                                    maxlength="100" placeholder="Ej. DEP-20261125-001"
                                    style="font-family: monospace; font-weight: 700; text-transform: uppercase;" />
                            </div>

                            <div class="form-group" style="margin: 0;">
                                <label class="form-label" for="dep_fecha">FECHA DEL DEPÓSITO *</label>
                                <input type="date" id="dep_fecha" class="form-control" required
                                    max="<?= htmlspecialchars($hoy) ?>" value="<?= htmlspecialchars($hoy) ?>" />
                            </div>
                        </div>

                        <div class="form-group" style="margin: 0;">
                            <label class="form-label" for="dep_observacion">OBSERVACIÓN (OPCIONAL)</label>
                            <input type="text" id="dep_observacion" class="form-control" maxlength="160"
                                placeholder="Ej. Depositado en sucursal Av. Juan Pablo Duarte por mensajería" />
                        </div>

                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-beige"
                            onclick="cerrarModal('modalRegistrarDeposito')">Cancelar</button>
                        <button type="submit" id="btnConfirmarDeposito" class="btn btn-gold" <?= empty($cuentasDop) ? 'disabled' : '' ?>>
                            ✅ Confirmar y Asentar Depósito
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- ======================================================================== -->
    <!-- MODAL 2: VER / IMPRIMIR DETALLE DEL MOVIMIENTO / VOLANTE                 -->
    <!-- ======================================================================== -->
    <div id="modalDetalleMovimiento" class="modal-backdrop"
        onclick="if(event.target.id==='modalDetalleMovimiento') cerrarModal('modalDetalleMovimiento')">
        <div class="modal-content" style="max-width: 720px;">
            <div class="modal-header no-print">
                <div>
                    <h3 class="modal-title" id="detMovTitulo">Detalle de Movimiento Bancario</h3>
                    <p class="modal-subtitle">Comprobante de tesorería y arqueos de caja respaldados</p>
                </div>
                <button type="button" class="btn-close" onclick="cerrarModal('modalDetalleMovimiento')">&times;</button>
            </div>
            <div class="modal-body" id="areaImpresionVolante">
                <p style="color: #8a7b70;">Cargando detalle...</p>
            </div>
            <div class="modal-footer no-print">
                <button type="button" class="btn btn-beige" onclick="window.print()">🖨️ Imprimir / PDF</button>
                <button type="button" class="btn btn-gold"
                    onclick="cerrarModal('modalDetalleMovimiento')">Cerrar</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        window.TESORERIA_PUEDE_REGISTRAR = <?= $puedeRegistrarBancos ? 'true' : 'false' ?>;
    </script>
    <script src="movimientos_bancarios.js?v=<?= time() ?>"></script>
</body>

</html>