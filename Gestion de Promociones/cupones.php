<?php
/**
 * ============================================================================
 * Módulo 6: Gestión de Promociones • Cupones & Descuentos
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Gestion de Promociones\cupones.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * Tablas: promociones.cupones_descuento, promociones.promociones_campanas, ventas.facturas
 * ============================================================================
 */

require_once __DIR__ . '/../config/conexion.php';

$cupones = [];
$campanas = [];
$descuentosEnCurso = [];

if ($pdo) {
  try {
    // 1. Auto-mantenimiento preventivo de estados vencidos
    $pdo->exec("UPDATE promociones.cupones_descuento SET estado = 'VENCIDO' WHERE estado = 'DISPONIBLE' AND fecha_vencimiento < CURRENT_DATE");

    // 2. Consulta maestra de cupones con trazabilidad forense
    $stmtCupones = $pdo->query("
            SELECT c.id,
                   c.codigo_cupon,
                   c.promocion_id,
                   c.factura_origen_id,
                   c.factura_canje_id,
                   c.cliente_id,
                   c.monto_credito,
                   c.fecha_vencimiento,
                   c.estado,
                   TO_CHAR(c.fecha_vencimiento, 'DD/MM/YYYY') AS fecha_vencimiento_fmt,
                   (c.fecha_vencimiento - CURRENT_DATE) AS dias_restantes,
                   COALESCE(camp.nombre_promocion, 'Campaña Promocional') AS campana_nombre,
                   camp.tipo_promocion,
                   fo.numero_factura AS factura_origen_numero,
                   fo.ncf AS factura_origen_ncf,
                   TO_CHAR(fo.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS factura_origen_fecha,
                   fc.numero_factura AS factura_canje_numero,
                   fc.ncf AS factura_canje_ncf,
                   TO_CHAR(fc.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS factura_canje_fecha,
                   cl.codigo_cliente,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_documento,
                   p.telefono_principal AS cliente_telefono,
                   p.email AS cliente_email
            FROM promociones.cupones_descuento c
            LEFT JOIN promociones.promociones_campanas camp ON c.promocion_id = camp.id
            LEFT JOIN ventas.facturas fo ON c.factura_origen_id = fo.id
            LEFT JOIN ventas.facturas fc ON c.factura_canje_id = fc.id
            LEFT JOIN identidad.clientes cl ON c.cliente_id = cl.persona_id
            LEFT JOIN identidad.personas p ON cl.persona_id = p.persona_id
            ORDER BY c.fecha_vencimiento ASC, c.id DESC
        ");
    $cupones = $stmtCupones->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 3. Catálogo de campañas para select de filtros
    $campanas = $pdo->query("
            SELECT id, nombre_promocion, tipo_promocion, estado
            FROM promociones.promociones_campanas
            ORDER BY (CASE WHEN estado = 'ACTIVA' THEN 1 ELSE 2 END), id DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 4. Auditoría de Descuentos & Promociones en curso
    $stmtDescuentos = $pdo->query("
            SELECT pc.id,
                   pc.nombre_promocion,
                   pc.tipo_promocion,
                   pc.fecha_inicio,
                   pc.fecha_fin,
                   pc.monto_minimo_factura,
                   pc.estado,
                   TO_CHAR(pc.fecha_inicio, 'DD/MM/YYYY') AS fecha_inicio_fmt,
                   TO_CHAR(pc.fecha_fin, 'DD/MM/YYYY') AS fecha_fin_fmt,
                   COALESCE((
                       SELECT STRING_AGG(DISTINCT
                           CASE
                               WHEN pd.beneficio_porcentaje > 0 THEN pd.beneficio_porcentaje::text || '% desc.'
                               WHEN pd.beneficio_monto_fijo > 0 THEN 'RD$ ' || pd.beneficio_monto_fijo::text || ' desc.'
                               ELSE 'Combo / Regalo'
                           END, ', ')
                       FROM promociones.promociones_detalles pd
                       WHERE pd.promocion_id = pc.id
                   ), 'Descuento general') AS regla_beneficio
            FROM promociones.promociones_campanas pc
            ORDER BY (CASE WHEN pc.estado = 'ACTIVA' THEN 1 ELSE 2 END), pc.fecha_inicio DESC, pc.id DESC
        ");
    $descuentosEnCurso = $stmtDescuentos->fetchAll(PDO::FETCH_ASSOC) ?: [];

  } catch (Throwable $e) {
    $errorCarga = $e->getMessage();
  }
}

$msg = $_GET['msg'] ?? '';
$tipoMsg = $_GET['tipo'] ?? 'exito';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Gestión de Cupones &amp; Descuentos</title>
  <link rel="stylesheet" href="styles.css?v=20" />
  <style>
    /* ESTILOS ESPECÍFICOS DE CUPONES Y DESCUENTOS */
    .kpi-grid-cupones {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
      margin-bottom: 20px;
    }

    .kpi-card-cupon {
      background: #ffffff;
      border: 1px solid #eadecd;
      border-radius: 10px;
      padding: 14px 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      box-shadow: 0 2px 6px rgba(33, 24, 19, 0.03);
      transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
      cursor: pointer;
      user-select: none;
    }

    .kpi-card-cupon:hover {
      transform: translateY(-2px);
      border-color: #c89d5c;
      box-shadow: 0 4px 12px rgba(33, 24, 19, 0.07);
    }

    .kpi-card-cupon.active-kpi {
      border: 2px solid #a66e2e;
      background: #fdfaf4;
      box-shadow: 0 2px 8px rgba(166, 110, 46, 0.15);
    }

    .kpi-lbl-cupon {
      font-size: 0.74rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      color: #8a7b70;
    }

    .kpi-val-cupon {
      font-size: 1.45rem;
      font-weight: 800;
      color: #211813;
      font-family: monospace;
      margin-top: 2px;
      line-height: 1.1;
    }

    .kpi-sub-cupon {
      font-size: 0.74rem;
      color: #8a7b70;
      margin-top: 3px;
    }

    /* BARRA DE HERRAMIENTAS Y FILTROS */
    .cupon-toolbar {
      background: #ffffff;
      border: 1px solid #eadecd;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 16px;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }

    /* PILL DE CÓDIGO DE CUPÓN */
    .cupon-code-pill {
      font-family: monospace;
      font-weight: 800;
      font-size: 0.88rem;
      letter-spacing: 0.8px;
      background: #fdf5ea;
      color: #794a15;
      border: 1px dashed #d8b888;
      padding: 4px 9px;
      border-radius: 6px;
      display: inline-block;
    }

    /* BADGES DE ESTADO */
    .badge-status {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.3px;
      text-transform: uppercase;
    }

    .status-disponible {
      background: #e8f5e9;
      color: #2e7d32;
      border: 1px solid #a5d6a7;
    }

    .status-usado {
      background: #e3f2fd;
      color: #1565c0;
      border: 1px solid #90caf9;
    }

    .status-vencido {
      background: #ffebee;
      color: #c62828;
      border: 1px solid #ef9a9a;
    }

    .status-anulado {
      background: #eeeeee;
      color: #616161;
      border: 1px solid #e0e0e0;
    }

    /* BOTONES DE ACCIÓN */
    .btn-action-cupon {
      padding: 5px 9px;
      border-radius: 6px;
      font-size: 0.75rem;
      font-weight: 600;
      cursor: pointer;
      border: 1px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: 3px;
      transition: all 0.15s ease;
      background: #fff;
    }

    .btn-action-cupon.btn-view {
      border-color: #d8cbbb;
      color: #4a3b32;
    }

    .btn-action-cupon.btn-view:hover {
      background: #f5ede1;
      border-color: #a66e2e;
    }

    .btn-action-cupon.btn-print {
      border-color: #bbdefb;
      color: #1565c0;
      background: #f4f9ff;
    }

    .btn-action-cupon.btn-print:hover {
      background: #e3f2fd;
      border-color: #1e88e5;
    }

    .btn-action-cupon.btn-cancel {
      border-color: #ffcdd2;
      color: #c62828;
      background: #fffafa;
    }

    .btn-action-cupon.btn-cancel:hover {
      background: #ffebee;
      border-color: #e53935;
    }

    .btn-icon-tiny {
      background: transparent;
      border: 1px solid #e0d7cb;
      border-radius: 4px;
      padding: 2px 6px;
      font-size: 0.72rem;
      cursor: pointer;
      transition: all 0.15s ease;
    }

    .btn-icon-tiny:hover {
      background: #f0e6d6;
      border-color: #a66e2e;
    }

    /* FILA HOVER */
    .fila-cupon {
      transition: background 0.12s ease;
    }

    .fila-cupon:hover {
      background: #fdfaf4 !important;
    }

    /* TICKET TÉRMICO PREVIEW (80mm) */
    .thermal-receipt-container {
      width: 320px;
      margin: 0 auto;
      background: #fff;
      color: #000;
      font-family: 'Courier New', Courier, monospace;
      padding: 20px 16px;
      border: 1px solid #ccc;
      box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
      border-radius: 4px;
      font-size: 13px;
      line-height: 1.35;
    }

    .receipt-header {
      text-align: center;
      border-bottom: 1px dashed #000;
      padding-bottom: 10px;
      margin-bottom: 10px;
    }

    .receipt-title {
      font-size: 16px;
      font-weight: bold;
      letter-spacing: 1px;
    }

    .receipt-box-code {
      border: 2px dashed #000;
      padding: 10px;
      margin: 12px 0;
      text-align: center;
      background: #fafafa;
    }

    .receipt-code-val {
      font-size: 20px;
      font-weight: 900;
      letter-spacing: 2px;
      font-family: monospace;
    }

    .receipt-amount-val {
      font-size: 22px;
      font-weight: 900;
      margin: 4px 0;
    }

    .receipt-barcode {
      font-family: 'Libre Barcode 39', monospace, sans-serif;
      font-size: 32px;
      text-align: center;
      letter-spacing: 4px;
      margin-top: 8px;
    }

    .receipt-footer {
      font-size: 10.5px;
      text-align: center;
      border-top: 1px dashed #000;
      padding-top: 8px;
      margin-top: 10px;
      color: #333;
    }

    /* ESTILOS DE IMPRESIÓN */
    @media print {
      body * {
        visibility: hidden !important;
      }

      #modalTicket,
      #modalTicket * {
        visibility: visible !important;
      }

      #modalTicket {
        position: fixed !important;
        left: 0 !important;
        top: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        background: #fff !important;
        display: block !important;
        padding: 0 !important;
        margin: 0 !important;
      }

      .modal-content {
        box-shadow: none !important;
        border: none !important;
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
      }

      .modal-header,
      .modal-actions-print {
        display: none !important;
      }

      .thermal-receipt-container {
        border: none !important;
        box-shadow: none !important;
        width: 100% !important;
        max-width: 80mm !important;
        margin: 0 auto !important;
        padding: 0 !important;
      }
    }

    /* MODALES FLOTANTES CENTRADOS AL MEDIO DE LA PANTALLA */
    .modal-backdrop,
    .modal-overlay {
      position: fixed !important;
      top: 0 !important;
      left: 0 !important;
      right: 0 !important;
      bottom: 0 !important;
      width: 100vw !important;
      height: 100vh !important;
      background-color: rgba(33, 24, 19, 0.65) !important;
      display: none;
      align-items: center !important;
      justify-content: center !important;
      z-index: 99999 !important;
      padding: 16px !important;
      box-sizing: border-box !important;
    }

    .modal-backdrop.open,
    .modal-overlay.open {
      display: flex !important;
    }

    .modal-content,
    .modal-box {
      background-color: #ffffff !important;
      border: 1px solid #dfcaa4 !important;
      border-radius: 12px !important;
      box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35) !important;
      max-height: calc(96vh - 24px) !important;
      overflow-y: auto !important;
      display: flex !important;
      flex-direction: column !important;
      animation: modalFadeIn 0.2s ease-out !important;
      margin: auto !important;
      position: relative !important;
      width: 100% !important;
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
        transform: translateY(-16px) scale(0.97);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .btn-close {
      background: none;
      border: none;
      font-size: 1.6rem;
      line-height: 1;
      color: #8c7f70;
      cursor: pointer;
      padding: 2px 8px;
      border-radius: 4px;
      transition: background 0.15s, color 0.15s;
    }

    .btn-close:hover {
      color: #211813;
      background: #f0e6d6;
    }
  </style>
</head>

<body>

  <!-- BARRA SUPERIOR -->
  <header class="topbar">
    <div style="display: flex; align-items: center; gap: 10px;">
      <button type="button" class="mobile-toggle-btn" onclick="toggleMobileSidebar()" aria-label="Abrir Menú">
        <span></span>
        <span></span>
        <span></span>
      </button>
      <a href="../Login y Menu/menu.php" class="topbar-brand" style="text-decoration: none;">
        <span class="brand-title">HOUSE &amp; ICE</span>
        <span class="brand-dot">•</span>
        <span class="brand-subtitle">Sistema Administrativo</span>
      </a>
    </div>
    <div class="topbar-actions">
      <div class="topbar-user">
        <div class="user-avatar"><?= htmlspecialchars($sesionAvatarInitials ?? 'AD') ?></div>
        <div class="user-info-col">
          <div class="user-name"><?= htmlspecialchars($sesionNombreCompleto ?? 'Administrador') ?></div>
          <div class="user-sub-line">
            <span class="user-role-text"><?= htmlspecialchars($sesionRol ?? 'Administrador') ?></span>
            <span class="user-bullet">•</span>
            <?= renderizarPillCajaTopbar() ?>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <!-- MENÚ LATERAL UNIFICADO -->
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- MENSAJE DE RETROALIMENTACIÓN -->
      <?php if (!empty($msg)): ?>
        <div
          style="margin-bottom: 16px; padding: 12px 18px; border-radius: 8px; font-weight: 500; font-size: 0.9rem; <?= ($tipoMsg === 'exito') ? 'background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7;' : 'background: #ffebee; color: #c62828; border: 1px solid #ef9a9a;' ?>">
          <?= htmlspecialchars($msg) ?>
        </div>
      <?php endif; ?>

      <!-- ENCABEZADO DE PÁGINA -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Gestión de Cupones &amp; Descuentos</h1>
          <p class="page-subtitle">Consulta, auditoría, trazabilidad y control de vigencia de cupones promocionales
            generados en facturación (Módulo 6: Promociones)</p>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
          <a href="promociones.php" class="btn btn-gold" style="text-decoration: none;">
            🎁 Reglas y Campañas
          </a>
        </div>
      </div>

      <!-- TARJETAS DINÁMICAS KPI (CLICKEABLES PARA FILTRAR) -->
      <div class="kpi-grid-cupones">
        <div class="kpi-card-cupon active-kpi" id="kpiCardTodos" onclick="seleccionarFiltroKpi('TODOS')">
          <div>
            <div class="kpi-lbl-cupon">Total Cupones</div>
            <div class="kpi-val-cupon" id="kpiValTotal">0</div>
            <div class="kpi-sub-cupon" id="kpiSubTotal">RD$ 0.00 emitidos</div>
          </div>
          <span style="font-size: 1.7rem;">🏷️</span>
        </div>

        <div class="kpi-card-cupon" id="kpiCardDisponibles" onclick="seleccionarFiltroKpi('DISPONIBLE')">
          <div>
            <div class="kpi-lbl-cupon" style="color: #2e7d32;">Disponibles (Activos)</div>
            <div class="kpi-val-cupon" style="color: #2e7d32;" id="kpiValDisponibles">0</div>
            <div class="kpi-sub-cupon" id="kpiSubDisponibles">RD$ 0.00 en crédito</div>
          </div>
          <span style="font-size: 1.7rem;">🟢</span>
        </div>

        <div class="kpi-card-cupon" id="kpiCardPorVencer" onclick="seleccionarFiltroKpi('POR_VENCER')">
          <div>
            <div class="kpi-lbl-cupon" style="color: #b25e09;">Alerta Por Vencer (&le; 7d)</div>
            <div class="kpi-val-cupon" style="color: #b25e09;" id="kpiValPorVencer">0</div>
            <div class="kpi-sub-cupon" id="kpiSubPorVencer">RD$ 0.00 por caducar</div>
          </div>
          <span style="font-size: 1.7rem;">🟠</span>
        </div>

        <div class="kpi-card-cupon" id="kpiCardUsados" onclick="seleccionarFiltroKpi('USADO')">
          <div>
            <div class="kpi-lbl-cupon" style="color: #1565c0;">Canjeados (Usados)</div>
            <div class="kpi-val-cupon" style="color: #1565c0;" id="kpiValUsados">0</div>
            <div class="kpi-sub-cupon" id="kpiSubUsados">RD$ 0.00 redimidos</div>
          </div>
          <span style="font-size: 1.7rem;">🔵</span>
        </div>

        <div class="kpi-card-cupon" id="kpiCardVencidos" onclick="seleccionarFiltroKpi('VENCIDO_ANULADO')">
          <div>
            <div class="kpi-lbl-cupon" style="color: #c62828;">Vencidos / Anulados</div>
            <div class="kpi-val-cupon" style="color: #c62828;" id="kpiValVencidos">0</div>
            <div class="kpi-sub-cupon" id="kpiSubVencidos">Caducados o cancelados</div>
          </div>
          <span style="font-size: 1.7rem;">🔴</span>
        </div>
      </div>

      <!-- BARRA DE FILTROS EN TIEMPO REAL -->
      <div class="cupon-toolbar">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; flex: 1;">
          <div class="search-box" style="min-width: 250px; flex: 1;">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscadorCupones"
              placeholder="Buscar por código (CUP-...), cliente, factura o campaña..." oninput="renderizarCupones()" />
          </div>

          <select id="filtroEstado" class="form-control" style="width: 170px; height: 38px;"
            onchange="cambioSelectEstado()">
            <option value="">📋 Todos los Estados</option>
            <option value="DISPONIBLE">🟢 Disponibles</option>
            <option value="POR_VENCER">🟠 Por Vencer (&le; 7 días)</option>
            <option value="USADO">🔵 Canjeados (Usados)</option>
            <option value="VENCIDO">🔴 Vencidos</option>
            <option value="ANULADO">⚪ Anulados</option>
          </select>

          <select id="filtroCampana" class="form-control" style="width: 200px; height: 38px;"
            onchange="renderizarCupones()">
            <option value="">🎁 Todas las Campañas</option>
            <?php foreach ($campanas as $camp): ?>
              <option value="<?= htmlspecialchars((string) $camp['id']) ?>">
                <?= htmlspecialchars($camp['nombre_promocion']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <select id="ordenarPor" class="form-control" style="width: 180px; height: 38px;"
            onchange="renderizarCupones()">
            <option value="vencimiento_asc">⏳ Vencimiento: Próximos</option>
            <option value="vencimiento_desc">⌛ Vencimiento: Lejanos</option>
            <option value="monto_desc">💰 Monto: Mayor a Menor</option>
            <option value="monto_asc">💵 Monto: Menor a Mayor</option>
            <option value="id_desc">🆕 Registro: Más Recientes</option>
          </select>
        </div>

        <button type="button" class="btn btn-beige" onclick="restablecerFiltros()"
          title="Limpiar todos los filtros aplicados">
          🔄 Restablecer
        </button>
      </div>

      <!-- PESTAÑAS DE VISTA Y AUDITORÍA (2 PESTAÑAS PRINCIPALES) -->
      <div
        style="display: flex; gap: 8px; margin-bottom: 14px; border-bottom: 2px solid #e7dcc8; padding-bottom: 2px; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary" id="tabBtnAuditCupones"
          onclick="cambiarVistaAuditoria('CUPONES', this)"
          style="padding: 8px 18px; font-weight: 700; border-radius: 8px 8px 0 0; background: #ffffff; color: var(--text-dark); border: 1.5px solid #d4c1a7; border-bottom: none; cursor: pointer;">
          <span>🎟️</span> Cupones de Descuento
        </button>
        <button type="button" class="btn btn-secondary" id="tabBtnAuditDescuentos"
          onclick="cambiarVistaAuditoria('DESCUENTOS', this)"
          style="padding: 8px 18px; font-weight: 700; border-radius: 8px 8px 0 0; background: #fbf9f5; color: var(--text-muted); border: 1px solid var(--border-light); border-bottom: none; cursor: pointer;">
          <span>🏷️</span> Promociones &amp; Descuentos en Curso (<?= count($descuentosEnCurso) ?>)
        </button>
      </div>

      <!-- TABLA DE AUDITORÍA Y TRAZABILIDAD (CUPONES) -->
      <div class="table-card" id="contenedorTablaCupones">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title" id="tituloTablaAuditoria">Matriz de Cupones Emitidos</h2>
            <span class="badge-count" id="badgeContadorCupones"><?= count($cupones) ?> registros</span>
          </div>
          <div style="font-size: 0.8rem; color: #8a7b70;" id="subtituloTablaAuditoria">
            Mostrando trazabilidad de emisión automática y canje en facturación
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th style="min-width: 140px;">Código / Campaña</th>
                <th style="min-width: 170px;">Cliente Titular</th>
                <th style="min-width: 110px;">Crédito</th>
                <th style="min-width: 120px;">Factura Origen</th>
                <th style="min-width: 130px;">Vigencia / Plazo</th>
                <th class="text-center" style="min-width: 110px;">Estado</th>
                <th style="min-width: 120px;">Canje en Caja</th>
                <th class="text-right" style="min-width: 120px; padding-right: 18px;">Acciones</th>
              </tr>
            </thead>
            <tbody id="tablaCuponesBody">
              <!-- Renderizado dinámico vía JavaScript -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- TABLA DE AUDITORÍA DE DESCUENTOS Y PROMOCIONES EN CURSO -->
      <div class="table-card" id="contenedorTablaDescuentos" style="display: none;">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Auditoría de Descuentos &amp; Promociones Activas</h2>
            <span class="badge-count"><?= count($descuentosEnCurso) ?> campañas registradas</span>
          </div>
          <div style="font-size: 0.8rem; color: #8a7b70;">
            Políticas comerciales vigentes aplicables en facturación POS y ventas
          </div>
        </div>

        <div class="table-wrapper">
          <table class="data-table">
            <thead>
              <tr>
                <th style="min-width: 200px;">Nombre de la Campaña / Promoción</th>
                <th style="min-width: 140px;">Tipo de Descuento</th>
                <th style="min-width: 180px;">Regla / Beneficio Aplicado</th>
                <th class="text-right" style="min-width: 130px;">Monto Mínimo</th>
                <th class="text-center" style="min-width: 180px;">Período de Vigencia</th>
                <th class="text-center" style="min-width: 100px;">Estado</th>
                <th class="text-center" style="min-width: 110px;">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($descuentosEnCurso)): ?>
                <tr>
                  <td colspan="7" class="text-center" style="padding: 30px; color: var(--text-muted);">
                    No se han configurado promociones o campañas en curso.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($descuentosEnCurso as $desc): ?>
                  <tr>
                    <td>
                      <strong
                        style="color: var(--text-dark); font-size: 0.90rem;"><?= htmlspecialchars($desc['nombre_promocion']) ?></strong>
                    </td>
                    <td>
                      <span class="badge-status status-usado" style="font-size: 0.72rem;">
                        <?= htmlspecialchars($desc['tipo_promocion']) ?>
                      </span>
                    </td>
                    <td style="font-size: 0.84rem; color: #5a4b3d;">
                      <?= htmlspecialchars($desc['regla_beneficio']) ?>
                    </td>
                    <td class="text-right" style="font-family: monospace; font-weight: 700;">
                      RD$ <?= number_format((float) $desc['monto_minimo_factura'], 2) ?>
                    </td>
                    <td class="text-center" style="font-size: 0.82rem;">
                      <span style="color: #2e7d52;"><?= htmlspecialchars($desc['fecha_inicio_fmt']) ?></span>
                      al
                      <span style="color: #b78103;"><?= htmlspecialchars($desc['fecha_fin_fmt'] ?: 'Sin término') ?></span>
                    </td>
                    <td class="text-center">
                      <?php if ($desc['estado'] === 'ACTIVA'): ?>
                        <span class="badge-status status-disponible">● ACTIVA</span>
                      <?php else: ?>
                        <span class="badge-status status-anulado">INACTIVA</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <a href="promociones.php" class="btn btn-action-cupon btn-view" style="text-decoration: none;">
                        ⚙️ Reglas
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </main>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 1: DETALLE Y TRAZABILIDAD COMPLETA DEL CUPÓN                      -->
  <!-- ======================================================================== -->
  <div id="modalDetalle" class="modal-backdrop" style="display: none;">
    <div class="modal-content" style="max-width: 540px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Ficha Técnica del Cupón</h3>
          <p class="modal-subtitle">Detalle forense de origen, cliente y canje en facturación</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModalDetalle()">&times;</button>
      </div>

      <div class="modal-body" style="display: flex; flex-direction: column; gap: 14px;">
        <div
          style="background: #fcf9f3; border: 1px dashed #d8b888; border-radius: 8px; padding: 14px; text-align: center;">
          <div style="font-size: 0.76rem; font-weight: 700; color: #8a7b70; text-transform: uppercase;">Código de Cupón
          </div>
          <div id="detCodigoCupon"
            style="font-size: 1.6rem; font-weight: 900; font-family: monospace; color: #794a15; margin: 4px 0;">—</div>
          <div id="detMontoCredito" style="font-size: 1.3rem; font-weight: 800; color: #211813;">RD$ 0.00</div>
          <div style="margin-top: 6px;">
            <span id="detEstado" class="badge-status status-disponible">DISPONIBLE</span>
          </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.85rem;">
          <div>
            <div style="font-size: 0.74rem; color: #8a7b70; font-weight: 700;">CAMPAÑA ORIGEN</div>
            <div id="detCampana" style="font-weight: 600; color: #211813; margin-top: 2px;">—</div>
          </div>
          <div>
            <div style="font-size: 0.74rem; color: #8a7b70; font-weight: 700;">FECHA VENCIMIENTO</div>
            <div id="detVencimiento" style="font-weight: 600; color: #211813; margin-top: 2px;">—</div>
            <div id="detDiasRestantes" style="font-size: 0.74rem; color: #a66e2e;">—</div>
          </div>
          <div style="grid-column: span 2;">
            <div style="font-size: 0.74rem; color: #8a7b70; font-weight: 700;">CLIENTE ASIGNADO</div>
            <div id="detCliente" style="font-weight: 600; color: #211813; margin-top: 2px;">—</div>
            <div id="detClienteTel" style="font-size: 0.74rem; color: #8a7b70;">—</div>
          </div>
          <div style="grid-column: span 2;">
            <div style="font-size: 0.74rem; color: #8a7b70; font-weight: 700;">FACTURA DE EMISIÓN (ORIGEN)</div>
            <div id="detFacturaOrigen" style="font-weight: 600; color: #211813; margin-top: 2px;">—</div>
          </div>
          <div style="grid-column: span 2;">
            <div style="font-size: 0.74rem; color: #8a7b70; font-weight: 700;">FACTURA DE CANJE (USO EN CAJA)</div>
            <div id="detFacturaCanje" style="font-weight: 600; color: #1565c0; margin-top: 2px;">—</div>
          </div>
        </div>
      </div>

      <div class="modal-footer"
        style="display: flex; justify-content: space-between; align-items: center; margin-top: 14px;">
        <button type="button" id="btnImprimirDesdeDetalle" class="btn btn-beige">
          🖨️ Imprimir Ticket
        </button>
        <button type="button" class="btn btn-gold" onclick="cerrarModalDetalle()">
          Cerrar
        </button>
      </div>
    </div>
  </div>

  <!-- ======================================================================== -->
  <!-- MODAL 2: TICKET TÉRMICO PARA IMPRESIÓN (80mm)                           -->
  <!-- ======================================================================== -->
  <div id="modalTicket" class="modal-backdrop" style="display: none;">
    <div class="modal-content" style="max-width: 400px; padding: 18px;">
      <div class="modal-header modal-actions-print" style="margin-bottom: 12px;">
        <h3 class="modal-title" style="font-size: 1rem;">Vista Previa de Ticket</h3>
        <button type="button" class="btn-close" onclick="cerrarModalTicket()">&times;</button>
      </div>

      <div class="thermal-receipt-container">
        <div class="receipt-header">
          <div class="receipt-title">HOUSE &amp; ICE</div>
          <div style="font-size: 11px;">LICORERÍA &amp; BEBIDAS EXCLUSIVAS</div>
          <div style="font-size: 10px; margin-top: 3px;">RNC: 1-32-45678-9 • Santo Domingo, R.D.</div>
          <div style="font-weight: bold; margin-top: 6px;">*** CUPÓN DE DESCUENTO ***</div>
        </div>

        <div class="receipt-box-code">
          <div style="font-size: 11px; text-transform: uppercase;">Código para Canje</div>
          <div class="receipt-code-val" id="tktCodigo">CUP-000000</div>
          <div style="font-size: 11px; margin-top: 4px;">Valor de Crédito</div>
          <div class="receipt-amount-val" id="tktMonto">RD$ 0.00</div>
        </div>

        <div style="font-size: 11px; margin-bottom: 6px;">
          <div><strong>Beneficiario:</strong> <span id="tktCliente">AL PORTADOR</span></div>
          <div><strong>Campaña:</strong> <span id="tktCampana">PROMOCIÓN</span></div>
          <div><strong>Válido hasta:</strong> <span id="tktVencimiento">31/12/2026</span></div>
        </div>

        <div style="text-align: center; border: 1px solid #ddd; padding: 6px; margin: 8px 0; background: #fafafa;">
          <div style="font-family: monospace; font-size: 18px; letter-spacing: 4px; font-weight: bold;" id="tktBarcode">
            *CUP-000000*
          </div>
          <div style="font-size: 9px; color: #555;">Presentar código de barras en caja</div>
        </div>

        <div class="receipt-footer">
          <div>Canjeable en su próxima compra en House &amp; Ice.</div>
          <div>No transferible por efectivo ni acumulable salvo políticas vigentes.</div>
          <div style="margin-top: 4px; font-weight: bold;">¡Gracias por su preferencia!</div>
        </div>
      </div>

      <div class="modal-actions-print" style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px;">
        <button type="button" class="btn btn-beige" onclick="cerrarModalTicket()">Cerrar</button>
        <button type="button" class="btn btn-gold" onclick="ejecutarImpresionTicket()">
          🖨️ Imprimir Ticket (80mm)
        </button>
      </div>
    </div>
  </div>

  <!-- SERIALIZACIÓN DE DATOS PARA JAVASCRIPT -->
  <script>
    window.CUPONES_DATA = <?= json_encode($cupones, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    window.DESCUENTOS_DATA = <?= json_encode($descuentosEnCurso, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="cupones.js?v=22"></script>
</body>

</html>