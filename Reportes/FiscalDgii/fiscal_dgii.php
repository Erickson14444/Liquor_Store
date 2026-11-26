<?php
require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$esAdmin = function_exists('usuarioEsAdministrador') && usuarioEsAdministrador();
$puedeFiscal = $esAdmin
    || (function_exists('usuarioTienePermiso') && (
        usuarioTienePermiso('EJECUTAR_CIERRES_FISCALES_PERIODO') || usuarioTienePermiso('VER_REPORTES_FINANCIEROS')
    ));

if (!$puedeFiscal) {
    header("Location: ../../Login y Menu/menu.php?error=" . urlencode("Acceso restringido: no posees permisos para Reportes Fiscales DGII."));
    exit;
}

$periodoActual = date('Y-m');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>House &amp; Ice • Reportes Fiscales DGII (606 / 607 / 608)</title>
    <link rel="stylesheet" href="../../Panel de configuracion/styles.css?v=21" />
    <style>
        .dg-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .dg-kpi-card {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 10px;
            padding: 14px 18px;
        }

        .dg-kpi-label {
            font-size: 0.72rem;
            font-weight: 800;
            color: #7a6b5d;
            text-transform: uppercase;
        }

        .dg-kpi-value {
            font-size: 1.4rem;
            font-weight: 900;
            font-family: monospace;
            color: #211813;
            margin-top: 4px;
        }

        .dg-kpi-sub {
            font-size: 0.74rem;
            color: #8a7b70;
            margin-top: 3px;
        }

        .dg-toolbar {
            background: #fff;
            border: 1px solid #e7dcc8;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .dg-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            border-bottom: 2px solid #e7dcc8;
            padding-bottom: 10px;
        }

        .dg-tab-btn {
            padding: 9px 16px;
            border-radius: 8px;
            border: 1px solid #d8c8b4;
            background: #faf6f0;
            color: #5a3818;
            font-weight: 700;
            font-size: 0.84rem;
            cursor: pointer;
        }

        .dg-tab-btn.active {
            background: #211813;
            color: #f6e7cf;
            border-color: #211813;
        }

        .dg-pill {
            display: inline-block;
            font-size: 0.71rem;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 5px;
            font-family: monospace;
        }

        @media print {

            .topbar,
            .sidebar,
            .sidebar-overlay,
            .dg-toolbar,
            .dg-tabs,
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
        }
    </style>
</head>

<body>
    <header class="topbar">
        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()"
                aria-label="Abrir Menú"><span></span><span></span><span></span></button>
            <a href="../../Login y Menu/menu.php" class="topbar-brand" style="text-decoration:none;">
                <span class="brand-title">HOUSE &amp; ICE</span><span class="brand-dot">•</span><span
                    class="brand-subtitle">Sistema Administrativo</span>
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
                            <?= htmlspecialchars($sesionRol ?? 'Usuario') ?>
                        </span>
                        <span class="user-bullet">•</span>
                        <?php if (function_exists('renderizarPillCajaTopbar'))
                            echo renderizarPillCajaTopbar(); ?>
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
                    <h1 class="page-title">Reportes Fiscales DGII (607 • 606 • 608)</h1>
                    <p class="page-subtitle">Envío mensual de Ventas, Compras, Retenciones e ITBIS/ISC en DOP conforme a
                        Norma General 07-18</p>
                </div>
                <div class="no-print" style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-beige" onclick="window.print()">🖨️ Imprimir</button>
                    <button type="button" class="btn btn-beige" onclick="descargarArchivoDgii('exportar_txt_dgii')">📄
                        TXT DGII (|)</button>
                    <button type="button" class="btn btn-gold" onclick="descargarArchivoDgii('exportar_csv')">📥
                        Exportar Excel (CSV)</button>
                </div>
            </div>

            <div class="dg-toolbar">
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="dgPeriodo">Periodo Fiscal (Mes / Año)</label>
                    <input type="month" id="dgPeriodo" class="form-control"
                        value="<?= htmlspecialchars($periodoActual) ?>" onchange="cargarFiscalDgii()" />
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="dgTipoNcf">Tipo NCF (607)</label>
                    <select id="dgTipoNcf" class="form-control" onchange="cargarFiscalDgii()">
                        <option value="">Todos (B01, B02, B03, B04)</option>
                        <option value="B01">B01 • Crédito Fiscal</option>
                        <option value="B02">B02 • Consumo Final</option>
                        <option value="B03">B03 • Nota de Débito</option>
                        <option value="B04">B04 • Nota de Crédito</option>
                    </select>
                </div>
                <div class="form-group" style="margin:0;">
                    <label class="form-label" for="dgRncEmpresa">RNC Emisor (TXT DGII)</label>
                    <input type="text" id="dgRncEmpresa" class="form-control" value="131000000" maxlength="11"
                        style="font-family:monospace; font-weight:700;" />
                </div>
                <div class="form-group" style="margin:0; grid-column:span 2;">
                    <label class="form-label" for="dgBuscar">Buscar NCF, RNC/Cédula, Cliente o Proveedor</label>
                    <input type="text" id="dgBuscar" class="form-control" placeholder="Ej. B01, B04, Cervecería..."
                        oninput="programarCargaDgii()" />
                </div>
            </div>

            <div class="dg-kpi-grid">
                <div class="dg-kpi-card" style="border-left:4px solid #2e7d32;">
                    <div class="dg-kpi-label" style="color:#2e7d32;">607 • Ventas Netas (Base DOP)</div>
                    <div class="dg-kpi-value" id="kpi607Base" style="color:#1b5e20;">RD$ 0.00</div>
                    <div class="dg-kpi-sub" id="kpi607Sub">0 comprobantes</div>
                </div>
                <div class="dg-kpi-card" style="border-left:4px solid #794a15;">
                    <div class="dg-kpi-label" style="color:#794a15;">607 • ITBIS + ISC Facturado</div>
                    <div class="dg-kpi-value" id="kpi607Imp" style="color:#794a15;">RD$ 0.00</div>
                    <div class="dg-kpi-sub" id="kpi607ImpSub">ITBIS: RD$ 0.00 • ISC: RD$ 0.00</div>
                </div>
                <div class="dg-kpi-card" style="border-left:4px solid #1565c0;">
                    <div class="dg-kpi-label" style="color:#1565c0;">606 • Compras Reportables</div>
                    <div class="dg-kpi-value" id="kpi606Total" style="color:#0d47a1;">RD$ 0.00</div>
                    <div class="dg-kpi-sub" id="kpi606Sub">0 comprobantes</div>
                </div>
                <div class="dg-kpi-card" style="border-left:4px solid #c62828;">
                    <div class="dg-kpi-label" style="color:#c62828;">606 Retenciones / 608 Anulados</div>
                    <div class="dg-kpi-value" id="kpi606Ret" style="color:#c62828;">RD$ 0.00</div>
                    <div class="dg-kpi-sub" id="kpi608Sub">0 anulados</div>
                </div>
            </div>

            <div class="dg-tabs">
                <button type="button" class="dg-tab-btn active" data-fmt="607" onclick="cambiarFormatoDgii('607')">📈
                    Formato 607 • Ventas (<span id="tabCnt607">0</span>)</button>
                <button type="button" class="dg-tab-btn" data-fmt="606" onclick="cambiarFormatoDgii('606')">📥 Formato
                    606 • Compras (<span id="tabCnt606">0</span>)</button>
                <button type="button" class="dg-tab-btn" data-fmt="608" onclick="cambiarFormatoDgii('608')">🚫 Formato
                    608 • Anulados (<span id="tabCnt608">0</span>)</button>
            </div>

            <section id="panelFmt_607" class="table-card">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Formato 607 • Ventas de Bienes y Servicios (DOP)</h2><span
                            class="badge-count" id="badge607">0</span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>NCF / MODIFICADO</th>
                                <th>FECHA</th>
                                <th>RNC / CÉDULA</th>
                                <th>CLIENTE / DOC.</th>
                                <th style="text-align:right;">BASE NETA</th>
                                <th style="text-align:right;">ITBIS</th>
                                <th style="text-align:right;">ISC</th>
                                <th style="text-align:right;">TOTAL DOP</th>
                                <th>FORMA PAGO</th>
                            </tr>
                        </thead>
                        <tbody id="tbody607">
                            <tr>
                                <td colspan="9" style="text-align:center; padding:26px; color:#8a7b70;">Cargando Formato
                                    607...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="panelFmt_606" class="table-card" style="display:none;">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Formato 606 • Compras, Servicios y Retenciones (DOP)</h2><span
                            class="badge-count" id="badge606">0</span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>NCF / MODIFICADO</th>
                                <th>FECHA COMP. / PAGO</th>
                                <th>PROVEEDOR / RNC</th>
                                <th style="text-align:center;">TIPO</th>
                                <th style="text-align:right;">BIENES / SERV.</th>
                                <th style="text-align:right;">TOTAL DOP</th>
                                <th style="text-align:right;">RET. ITBIS</th>
                                <th style="text-align:right;">RET. ISR</th>
                                <th>FORMA PAGO</th>
                            </tr>
                        </thead>
                        <tbody id="tbody606">
                            <tr>
                                <td colspan="9" style="text-align:center; padding:26px; color:#8a7b70;">Cargando Formato
                                    606...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="panelFmt_608" class="table-card" style="display:none;">
                <div class="table-card-top">
                    <div class="table-card-info">
                        <h2 class="table-card-title">Formato 608 • Comprobantes Fiscales Anulados</h2><span
                            class="badge-count" id="badge608">0</span>
                    </div>
                </div>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>NCF ANULADO</th>
                                <th>FECHA</th>
                                <th>TIPO ANULACIÓN DGII</th>
                                <th>FACTURA</th>
                                <th>CLIENTE</th>
                                <th>AUTORIZÓ (PIN)</th>
                                <th style="text-align:right; padding-right:18px;">MONTO ORIGINAL</th>
                            </tr>
                        </thead>
                        <tbody id="tbody608">
                            <tr>
                                <td colspan="7" style="text-align:center; padding:26px; color:#8a7b70;">Cargando Formato
                                    608...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script src="fiscal_dgii.js?v=<?= time() ?>"></script>
</body>

</html>