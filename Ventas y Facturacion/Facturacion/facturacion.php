<?php
/**
 * ============================================================================
 * Módulo 4: Ventas • Proceso 3: Facturación (Punto de Venta POS)
 * Ubicación: Ventas y Facturacion/Facturacion/facturacion.php
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/../../config/auditoria_helper.php';

requerirSesionActiva(false, '../../Login y Menu/login.php');

$metodosPago = [];
$secuenciasFiscales = [];
$almacenes = [];
$monedas = [];
$cuentasBancarias = [];
$categorias = [];
$marcas = [];
$cotizacionesPend = [];
$zonasDelivery = [];
$facturasRecientes = [];
$supervisoresAutorizados = [];
$sesionCajaActiva = null;

if ($pdo) {
  try {
    // 1. Métodos de pago activos (Split Universal)
    $metodosPago = $pdo->query("
            SELECT id, codigo, descripcion, requiere_referencia
            FROM ventas.metodos_pago
            WHERE activo = TRUE
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 2. Almacenes físicos activos (prioriza Mostrador como almacén POS por defecto)
    $almacenes = $pdo->query("
            SELECT id, nombre, ubicacion
            FROM inventario.almacenes
            WHERE activo = TRUE
            ORDER BY CASE WHEN LOWER(nombre) LIKE '%mostrador%' THEN 0 ELSE 1 END, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 3. Monedas activas
    $monedas = $pdo->query("
            SELECT id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base
            FROM tesoreria.monedas
            WHERE activa = TRUE
            ORDER BY CASE WHEN es_moneda_base = TRUE THEN 0 ELSE 1 END, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 4. Directorio de cuentas bancarias activas (para Transferencias y Cheques)
    $cuentasBancarias = $pdo->query("
            SELECT cb.id, cb.nombre_banco, cb.numero_cuenta, cb.tipo_cuenta,
                   m.codigo_iso, m.simbolo
            FROM tesoreria.cuentas_bancarias cb
            LEFT JOIN tesoreria.monedas m ON m.id = cb.moneda_id
            WHERE cb.activa = TRUE
            ORDER BY cb.nombre_banco ASC, cb.numero_cuenta ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 5. Categorías y marcas para filtros del buscador de catálogo
    $categorias = $pdo->query("
            SELECT id, nombre FROM inventario.categoria_producto WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $marcas = $pdo->query("
            SELECT id, nombre FROM inventario.marca WHERE id > 0 AND estado = 'ACTIVO' ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 6. Secuencias fiscales DGII activas (B01 y B02)
    $secuenciasFiscales = $pdo->query("
            SELECT id, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual,
                   TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vencimiento_fmt
            FROM ventas.secuencias_fiscales_dgii
            WHERE estado = 'ACTIVO'
              AND fecha_vencimiento >= CURRENT_DATE
              AND secuencia_actual <= secuencia_hasta
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 7. Cotizaciones pendientes vigentes
    $cotizacionesPend = $pdo->query("
            SELECT cot.id,
                   cot.numero_cotizacion,
                   cot.cliente_id,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_doc,
                   TO_CHAR(cot.fecha_emision, 'DD/MM/YYYY') AS fecha_emision_fmt,
                   TO_CHAR(cot.fecha_vencimiento, 'DD/MM/YYYY') AS fecha_vencimiento_fmt,
                   cot.monto_total
            FROM ventas.cotizaciones cot
            INNER JOIN identidad.clientes c ON cot.cliente_id = c.persona_id
            INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
            WHERE cot.estado = 'PENDIENTE'
              AND cot.fecha_vencimiento >= CURRENT_DATE
            ORDER BY cot.fecha_emision DESC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 8. Zonas de delivery activas
    $zonasDelivery = $pdo->query("
            SELECT id, nombre, tarifa_envio, tiempo_promedio_min
            FROM ventas.zonas_delivery
            WHERE activa = TRUE
            ORDER BY nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 9. Historial reciente de facturas emitidas
    $facturasRecientes = $pdo->query("
            SELECT f.id,
                   f.numero_factura,
                   f.tipo_ncf,
                   COALESCE(f.ncf, 'S/C') AS ncf,
                   f.condicion_pago,
                   f.origen_venta,
                   f.monto_total,
                   f.estado,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   COALESCE(NULLIF(f.razon_social_cliente, ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(f.rnc_cedula_cliente, ''), p.documento_identidad, '') AS cliente_doc
            FROM ventas.facturas f
            LEFT JOIN identidad.clientes c ON f.cliente_id = c.persona_id
            LEFT JOIN identidad.personas p ON c.persona_id = p.persona_id
            ORDER BY f.fecha_emision DESC, f.id DESC
            LIMIT 40
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
  }

  try {
    // 10. Sesión de caja asignada al cajero autenticado (Fase 5: Control de Caja)
    $cajeroUsuarioId = $_SESSION['usuario']['usuario_id'] ?? null;
    $stmtSc = $pdo->prepare("
            SELECT sc.id, sc.caja_id, sc.cajero_id, sc.monto_inicial_efectivo, sc.estado,
                   TO_CHAR(sc.fecha_apertura, 'DD/MM/YYYY HH12:MI AM') AS fecha_apertura_fmt,
                   cp.nombre_equipo, cp.ubicacion, p.nombre_razon_social AS cajero_nombre
            FROM ventas.sesiones_caja sc
            JOIN ventas.cajas_pos cp ON cp.id = sc.caja_id
            JOIN seguridad.usuarios u ON sc.cajero_id = u.usuario_id
            JOIN identidad.personas p ON u.persona_id = p.persona_id
            WHERE sc.cajero_id = :usuario_id
              AND sc.estado IN ('ABIERTA', 'EN_CUADRE')
            ORDER BY sc.fecha_apertura DESC
            LIMIT 1
        ");
    $stmtSc->execute([':usuario_id' => $cajeroUsuarioId]);
    $sesionCajaActiva = $stmtSc->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($sesionCajaActiva) {
      $sesionCajaId = $sesionCajaActiva['caja_id'];
    }
  } catch (Throwable $e) {
    $sesionCajaActiva = null;
  }

  try {
    $supervisoresAutorizados = obtenerSupervisoresAutorizados($pdo);
  } catch (Throwable $e) {
  }
}

$estadoSesion = strtoupper((string) ($sesionCajaActiva['estado'] ?? ''));
$puedeFacturar = ($sesionCajaActiva !== null && $estadoSesion === 'ABIERTA');
$enCuadreCaja = ($sesionCajaActiva !== null && $estadoSesion === 'EN_CUADRE');

$ncfDisponibles = ['B02' => null, 'B01' => null];
foreach ($secuenciasFiscales as $sec) {
  $tipo = strtoupper(trim($sec['tipo_comprobante']));
  if (!isset($ncfDisponibles[$tipo]) || $ncfDisponibles[$tipo] === null) {
    $ncfDisponibles[$tipo] = [
      'id' => (int) $sec['id'],
      'ncf_proximo' => $tipo . str_pad((string) $sec['secuencia_actual'], 8, '0', STR_PAD_LEFT),
      'restantes' => (int) $sec['secuencia_hasta'] - (int) $sec['secuencia_actual'] + 1,
      'vencimiento' => $sec['vencimiento_fmt'],
    ];
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>House &amp; Ice • Facturación (POS)</title>
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

    /* Buscador en vivo de clientes */
    .pos-client-picker-wrap {
      position: relative;
      flex: 1;
      min-width: 0;
    }

    .pos-client-pill {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
      min-height: 38px;
      padding: 4px 10px;
      background: #fdf8ee;
      border: 1.5px solid #d4a359;
      border-radius: 6px;
      font-size: 0.84rem;
      cursor: pointer;
    }

    .pos-client-pill:hover {
      background: #fbf1df;
    }

    .pos-client-dropdown {
      display: none;
      position: absolute;
      top: calc(100% + 4px);
      left: 0;
      right: 0;
      min-width: 310px;
      max-height: 260px;
      overflow-y: auto;
      background: #fff;
      border: 1px solid #d4a359;
      border-radius: 8px;
      box-shadow: 0 12px 28px rgba(33, 24, 19, 0.2);
      z-index: 1500;
    }

    .pos-top-grid,
    .pos-config-card,
    .pos-config-row-clean,
    .pos-field-group,
    .pos-client-input-wrap {
      overflow: visible !important;
    }

    .pos-config-card {
      position: relative;
      z-index: 30;
    }

    .pos-client-dropdown {
      z-index: 2000;
    }

    .pos-client-opt {
      padding: 8px 12px;
      cursor: pointer;
      border-bottom: 1px solid #f2ebe1;
      font-size: 0.83rem;
    }

    .pos-client-opt:last-child {
      border-bottom: none;
    }

    .pos-client-opt:hover {
      background: #fdf5ea;
    }

    .pos-scanner-strip {
      display: grid;
      grid-template-columns: 3.5fr 1.1fr 1.1fr auto;
      gap: 12px;
      align-items: end;
      background: #fff;
      border: 1px solid #e7dcc8;
      border-radius: 10px;
      padding: 10px 14px;
      margin-bottom: 12px;
    }

    @media (max-width: 1200px) {
      .pos-scanner-strip {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 700px) {
      .pos-scanner-strip {
        grid-template-columns: 1fr;
      }
    }

    /* Coordinación de tamaños uniformes (35px) sincronizados con los textboxes */
    .pos-btn-sync {
      height: 35px !important;
      min-height: 35px !important;
      padding: 0 11px !important;
      font-size: 0.81rem !important;
      font-weight: 600 !important;
      border-radius: var(--radius-sm, 6px) !important;
      display: inline-flex;
      align-items: center !important;
      justify-content: center !important;
      gap: 5px !important;
      white-space: nowrap !important;
      box-sizing: border-box !important;
      transition: all 0.15s ease !important;
    }

    .pos-btn-sync:hover {
      transform: translateY(-1px);
    }

    /* Botón Finalizar/Empezar Turno: más grande que el resto (38px) */
    .pos-btn-turno {
      height: 38px !important;
      min-height: 38px !important;
      padding: 0 14px !important;
      font-size: 0.85rem !important;
      font-weight: 700 !important;
      border-radius: var(--radius-sm, 6px) !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 6px !important;
      white-space: nowrap !important;
      box-sizing: border-box !important;
      transition: all 0.15s ease !important;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.12) !important;
    }

    .pos-btn-turno:hover {
      transform: translateY(-1px);
      box-shadow: 0 3px 7px rgba(0, 0, 0, 0.18) !important;
    }

    /* Botón Emitir Factura: el más grande de todos (42px) y destacado al final */
    .pos-btn-emitir {
      height: 42px !important;
      min-height: 42px !important;
      padding: 0 22px !important;
      font-size: 0.94rem !important;
      font-weight: 800 !important;
      background: #241911 !important;
      color: #ffffff !important;
      border: 1px solid #241911 !important;
      border-radius: var(--radius-sm, 6px) !important;
      box-shadow: 0 3px 8px rgba(0, 0, 0, 0.25) !important;
      display: inline-flex !important;
      align-items: center !important;
      justify-content: center !important;
      gap: 7px !important;
      white-space: nowrap !important;
      cursor: pointer !important;
      transition: all 0.15s ease !important;
      margin-left: 4px !important;
      letter-spacing: 0.3px;
    }

    .pos-btn-emitir:hover:not(:disabled) {
      background: #3c2a1e !important;
      border-color: #3c2a1e !important;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.35) !important;
      transform: translateY(-1px);
    }

    .pos-btn-emitir:disabled {
      opacity: 0.55 !important;
      cursor: not-allowed !important;
      background: #6c757d !important;
      border-color: #6c757d !important;
      box-shadow: none !important;
    }

    .pos-cupon-strip {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      background: #fdfaf4;
      border: 1px dashed #d8b888;
      border-radius: 8px;
      padding: 10px 14px;
      margin-bottom: 14px;
    }

    .pos-tax-pill {
      display: inline-block;
      font-size: 0.72rem;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 99px;
      background: #f3ece1;
      color: #5a3818;
      margin: 1px 2px;
    }

    .pos-cashback-banner {
      background: #edf7ed;
      border: 1px solid #b7dfb9;
      color: #1b5e20;
      padding: 9px 14px;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      margin-bottom: 12px;
      display: none;
    }

    /* Notificaciones inteligentes de promociones elegibles */
    .pos-promo-alerts-wrap {
      display: none;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 12px;
    }

    .pos-promo-alert-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
      padding: 9px 14px;
      border-radius: 8px;
      font-size: 0.84rem;
      line-height: 1.35;
    }

    .pos-promo-alert-item.tipo-cross {
      background: #fff8eb;
      border: 1px solid #e6c280;
      border-left: 4px solid #a66e2e;
      color: #5a3818;
    }

    .pos-promo-alert-item.tipo-upsell {
      background: #eef6ff;
      border: 1px solid #b6d4fe;
      border-left: 4px solid #1565c0;
      color: #0d47a1;
    }

    .pos-promo-alert-item.tipo-cashback {
      background: #f5f0ff;
      border: 1px solid #d4c4fb;
      border-left: 4px solid #6b21a8;
      color: #4c1d95;
    }

    .pos-promo-alert-body {
      display: flex;
      align-items: center;
      gap: 9px;
      flex: 1;
      min-width: 240px;
    }

    .pos-promo-alert-icon {
      font-size: 1.1rem;
      flex-shrink: 0;
    }

    .pos-promo-alert-badge {
      display: inline-block;
      font-size: 0.68rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      padding: 1px 6px;
      border-radius: 4px;
      margin-right: 6px;
      background: rgba(0, 0, 0, 0.08);
    }

    .pos-promo-action-btn {
      padding: 5px 12px;
      font-size: 0.78rem;
      font-weight: 700;
      border-radius: 6px;
      border: 1px solid transparent;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.15s ease;
    }

    .pos-promo-alert-item.tipo-cross .pos-promo-action-btn {
      background: #a66e2e;
      color: #ffffff;
      border-color: #8f5c23;
    }

    .pos-promo-alert-item.tipo-cross .pos-promo-action-btn:hover {
      background: #8f5c23;
      transform: translateY(-1px);
    }

    .pos-promo-alert-item.tipo-upsell .pos-promo-action-btn {
      background: #1565c0;
      color: #ffffff;
      border-color: #0d47a1;
    }

    .pos-promo-alert-item.tipo-upsell .pos-promo-action-btn:hover {
      background: #0d47a1;
      transform: translateY(-1px);
    }

    .pos-promo-stock-warn {
      font-size: 0.75rem;
      font-weight: 700;
      color: #b71c1c;
      background: #ffebee;
      border: 1px solid #ef9a9a;
      padding: 3px 8px;
      border-radius: 5px;
      white-space: nowrap;
    }

    .pos-stock-alert-banner {
      background: #ffebee;
      border: 1px solid #ef9a9a;
      color: #b71c1c;
      padding: 9px 14px;
      border-radius: 8px;
      font-size: 0.84rem;
      font-weight: 600;
      margin-bottom: 12px;
      display: none;
    }

    .split-pay-summary {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      background: #faf7f2;
      border: 1px solid #e7dcc8;
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 14px;
    }

    .split-pay-box {
      text-align: center;
    }

    .split-pay-box small {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      color: #8a7b70;
      text-transform: uppercase;
    }

    .split-pay-box strong {
      font-size: 1.15rem;
      font-family: monospace;
      color: #211813;
    }

    .split-subtipo-panel {
      background: #fdfaf4;
      border: 1px solid #eadecd;
      border-radius: 8px;
      padding: 12px;
      margin-top: 10px;
    }

    /* Estilos Modal Arqueo Ciego */
    .modal-arqueo-premium {
      max-width: 680px !important;
      border-radius: 14px !important;
      overflow: hidden;
    }

    .arqueo-header-pills {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
      margin-top: 6px;
    }

    .arqueo-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 0.74rem;
      font-weight: 700;
    }

    .arqueo-pill-sesion {
      background: #fdf5ea;
      color: #8c4c15;
      border: 1px solid #e8caa0;
    }

    .arqueo-pill-terminal {
      background: #f0ebe4;
      color: #3d3229;
      border: 1px solid #d4c7b8;
    }

    .arqueo-pill-cajero {
      background: #eef5ee;
      color: #27672b;
      border: 1px solid #c3dec4;
    }

    .arqueo-notice-card {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      background: #fdf8ee;
      border: 1px solid #edd5b2;
      border-left: 4px solid #b25e09;
      border-radius: 10px;
      padding: 12px 14px;
    }

    .arqueo-section-card {
      background: #fff;
      border: 1px solid #e8decb;
      border-radius: 10px;
      padding: 14px;
    }

    .arqueo-section-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
    }

    .arqueo-section-label {
      font-size: 0.78rem;
      font-weight: 800;
      color: #4a3c31;
      text-transform: uppercase;
    }

    .arqueo-total-badge {
      background: #fdf5ea;
      color: #8c4c15;
      border: 1px solid #eccca2;
      padding: 5px 12px;
      border-radius: 20px;
      font-weight: 900;
      font-size: 1.05rem;
      font-family: monospace;
    }

    .pos-denoms-container {
      max-height: 220px;
      overflow-y: auto;
      border: 1px solid #ede4d6;
      border-radius: 8px;
      background: #faf7f2;
      padding: 4px;
    }

    .denom-item-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #fff;
      padding: 6px 12px;
      margin-bottom: 3px;
      border-radius: 6px;
      border: 1px solid #f0e6d6;
    }

    .denom-badge-type {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 3px 8px;
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.82rem;
      min-width: 135px;
    }

    .denom-type-billete {
      background: #edf7ec;
      color: #236528;
      border: 1px solid #c7e5c6;
    }

    .denom-type-moneda {
      background: #f2f3f5;
      color: #4e555d;
      border: 1px solid #dcdfe3;
    }

    .denom-qty-input {
      width: 76px;
      text-align: center;
      padding: 5px 6px;
      border: 1.5px solid #d4c7b8;
      border-radius: 6px;
      font-family: monospace;
      font-size: 0.92rem;
      font-weight: 800;
    }

    .denom-subtotal-text {
      font-family: monospace;
      font-size: 0.88rem;
      font-weight: 800;
      width: 108px;
      text-align: right;
      color: #8c4c15;
    }

    .arqueo-input-control {
      font-family: monospace;
      font-weight: 700;
      font-size: 0.94rem;
      background: #fff;
      border: 1.5px solid #d4c7b8;
      border-radius: 6px;
      padding: 8px 10px;
      width: 100%;
      box-sizing: border-box;
    }

    @media (max-width: 900px) {
      .pos-scanner-strip {
        grid-template-columns: 1fr;
      }

      .split-pay-summary {
        grid-template-columns: 1fr;
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
            <?php if ($puedeFacturar): ?>
              <span class="user-caja-pill open"
                title="Turno activo en <?= htmlspecialchars($sesionCajaActiva['nombre_equipo']) ?>">
                <span class="pill-dot green"></span>
                <?= htmlspecialchars($sesionCajaActiva['nombre_equipo']) ?>
              </span>
            <?php elseif ($enCuadreCaja): ?>
              <span class="user-caja-pill" style="background: #fff3e0; color: #b25e09; border: 1px solid #ffe082;">
                <span class="pill-dot" style="background: #b25e09;"></span>
                En Arqueo (<?= htmlspecialchars($sesionCajaActiva['nombre_equipo']) ?>)
              </span>
            <?php else: ?>
              <span class="user-caja-pill" style="background: #fdf5ea; color: #b25e09; border: 1px solid #eccca2;">
                <span class="pill-dot" style="background: #b25e09;"></span>
                Sin Caja Asignada
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

      <!-- ENCABEZADO -->
      <div class="page-header" style="align-items: center;">
        <div>
          <h1 class="page-title">Facturación (Punto de Venta POS)</h1>
          <p class="page-subtitle">Motor fiscal DGII, promociones automáticas, cupones, descarga FEFO y Split Universal
            de pagos</p>
        </div>
        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
          <!-- 1. CAJA CHICA -->
          <?php if ($puedeFacturar): ?>
            <button type="button" class="btn btn-outline pos-btn-sync" onclick="abrirModalCajaChicaFacturacion()"
              style="border-color: #d8b888; color: #794a15; background: #fffcf8;" title="Entrada / Salida de caja chica">
              💵 Caja Chica
            </button>
          <?php endif; ?>

          <!-- 2. IMPORTAR COTIZACIÓN -->
          <button type="button" class="btn btn-outline pos-btn-sync" onclick="abrirModalCotizaciones()"
            title="Importar cotización pendiente">
            📄 Importar Cotización
            <?php if (count($cotizacionesPend) > 0): ?>
              <span class="pos-inline-count"><?= count($cotizacionesPend) ?></span>
            <?php endif; ?>
          </button>

          <!-- 3. FACTURAS EMITIDAS -->
          <button type="button" class="btn btn-outline pos-btn-sync" onclick="abrirModalHistorial()"
            title="Ver facturas emitidas">
            🧾 Facturas Emitidas (<?= count($facturasRecientes) ?>)
          </button>

          <!-- 4. PEDIDO A DOMICILIO (SIN PARÉNTESIS) -->
          <button type="button" class="btn btn-outline pos-btn-sync" id="btnAbrirPedidoDelivery"
            onclick="abrirModalPedidoDelivery()" title="Configurar pedido a domicilio">
            🛵 Pedido a Domicilio
          </button>

          <!-- 5. FINALIZAR / EMPEZAR TURNO (MÁS GRANDE QUE LOS ANTERIORES) -->
          <?php if (!empty($sesionCajaActiva)): ?>
            <button type="button" class="btn btn-gold pos-btn-turno" onclick="abrirModalArqueoFacturacion()"
              style="background: #965318; border-color: #965318; color: #fff;" title="Finalizar turno y arqueo">
              🔒 Finalizar Turno
            </button>
          <?php elseif ($enCuadreCaja): ?>
            <button type="button" class="btn btn-gold pos-btn-turno" onclick="abrirModalArqueoFacturacion()"
              style="background: #b25e09; border-color: #b25e09; color: #fff;" title="Concluir conteo físico">
              ⏳ Concluir Arqueo
            </button>
          <?php else: ?>
            <a href="../ControlCaja/control_caja.php" class="btn btn-primary pos-btn-turno"
              style="background: #2e7d52; border-color: #2e7d52; color: #fff; text-decoration: none;"
              title="Abrir turno de caja">
              🟢 Iniciar Turno
            </a>
          <?php endif; ?>

          <!-- 6. EMITIR FACTURA (EL MÁS GRANDE DE TODOS AL FINAL DE LA LÍNEA) -->
          <?php if (!$puedeFacturar): ?>
            <button type="button" class="pos-btn-emitir" id="btnGuardarFactura" disabled title="Caja cerrada o en arqueo">
              🔒 <?= $enCuadreCaja ? 'En Arqueo' : 'Sin Turno' ?>
            </button>
          <?php else: ?>
            <button type="button" class="pos-btn-emitir" id="btnGuardarFactura" onclick="abrirModalCobro()"
              title="Emitir Factura">
              💳 Emitir Factura
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- AVISOS DE FASE 5 (CONTROL DE CAJA) -->
      <?php if (!$puedeFacturar && !$enCuadreCaja): ?>
        <div
          style="background: #fff8eb; border: 1px solid #f0d8a8; color: #7a5216; padding: 12px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 0.88rem;">
          ⚠️ <strong>Turno No Iniciado / Sin Caja Asignada:</strong> No puedes facturar ni registrar cobros. Solicita a tu
          supervisor que te asigne una terminal y fondo inicial en <strong>Control de Caja</strong>.
        </div>
      <?php elseif ($enCuadreCaja): ?>
        <div
          style="background: #fff3e0; border: 1px solid #ffe082; color: #b25e09; padding: 12px 18px; border-radius: 8px; margin-bottom: 16px; font-size: 0.88rem;">
          ⏳ <strong>Terminal en Arqueo Ciego (EN_CUADRE):</strong> La facturación está bloqueada temporalmente. Presiona
          <strong>🔒 Finalizar Turno</strong> para concluir tu conteo físico.
        </div>
      <?php endif; ?>

      <!-- BLOQUE SUPERIOR: CABECERA FISCAL/CLIENTE + TOTAL EN VIVO -->
      <div class="pos-top-grid">
        <div class="pos-config-card">
          <div class="pos-config-row-clean">
            <!-- CLIENTE / RNC (BUSCADOR EN VIVO) -->
            <div class="pos-field-group">
              <label for="inputBuscarClientePos" class="form-label">CLIENTE / RNC:</label>
              <div class="pos-client-input-wrap">
                <div class="pos-client-picker-wrap" id="posClientPickerWrap">
                  <input type="hidden" id="selectCliente" value="CONSUMIDOR_FINAL" />
                  <div id="posClientePill" class="pos-client-pill" onclick="abrirBuscadorClientePos()"
                    title="Clic para buscar o cambiar cliente">
                    <div id="posClientePillTexto"
                      style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                      <strong>Consumidor Final</strong> <span
                        style="font-size: 0.74rem; color: #7a6b5d;">(Mostrador)</span>
                    </div>
                    <span style="font-size: 0.76rem; color: #a66e2e; font-weight: 700; flex-shrink: 0;">🔍</span>
                  </div>
                  <input type="text" id="inputBuscarClientePos" class="form-control" style="display: none;"
                    autocomplete="off" placeholder="Nombre, cédula, RNC, código o teléfono..."
                    oninput="programarBusquedaClientesPos()" onkeydown="teclaBusquedaClientesPos(event)" />
                  <div id="posClienteDropdown" class="pos-client-dropdown"></div>
                </div>
                <button type="button" class="btn btn-beige pos-btn-add-client" onclick="abrirModalNuevoCliente()">+
                  Cliente</button>
              </div>
            </div>

            <!-- COMPROBANTE FISCAL (NCF) -->
            <div class="pos-field-group">
              <label for="selectTipoNcf" class="form-label">COMPROBANTE (NCF):</label>
              <select id="selectTipoNcf" class="form-control" onchange="actualizarInfoNcf()">
                <option value="B02">B02 • Consumo
                  Final<?= $ncfDisponibles['B02'] ? ' (' . $ncfDisponibles['B02']['ncf_proximo'] . ')' : ' [Sin bloque]' ?>
                </option>
                <option value="B01">B01 • Crédito
                  Fiscal<?= $ncfDisponibles['B01'] ? ' (' . $ncfDisponibles['B01']['ncf_proximo'] . ')' : ' [Sin bloque]' ?>
                </option>
                <option value="CONSUMO_INTERNO">Consumo Interno (Sin NCF)</option>
              </select>
            </div>

            <!-- CONDICIÓN DE VENTA Y MONEDA -->
            <div class="pos-field-group">
              <label for="selectCondicionVenta" class="form-label">CONDICIÓN DE VENTA:</label>
              <div style="display: flex; gap: 6px; align-items: center;">
                <select id="selectCondicionVenta" class="form-control" style="flex: 1; min-width: 95px;"
                  onchange="alCambiarCondicionVenta()">
                  <option value="CONTADO">Contado</option>
                  <option value="CREDITO">Crédito (30d)</option>
                </select>
                <select id="selectMoneda" class="form-control"
                  style="width: 70px; max-width: 70px; padding: 0 4px; font-size: 0.82rem; text-align: center; font-weight: 700; flex-shrink: 0;"
                  onchange="alCambiarMoneda()">
                  <?php foreach ($monedas as $mon): ?>
                    <option value="<?= (int) $mon['id'] ?>" data-iso="<?= htmlspecialchars($mon['codigo_iso']) ?>"
                      data-simbolo="<?= htmlspecialchars($mon['simbolo']) ?>"
                      data-tasa="<?= htmlspecialchars((string) $mon['tasa_cambio_referencia']) ?>">
                      <?= htmlspecialchars($mon['codigo_iso']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- TARJETA DERECHA: TOTAL FACTURA CON DESGLOSE DINÁMICO DE IMPUESTOS -->
        <div class="pos-total-card">
          <div class="pos-total-header-row">
            <span class="pos-total-label">TOTAL FACTURA</span>
            <span class="pos-ncf-pill" id="badgeNcfActivo">NCF: B02</span>
          </div>

          <div class="pos-total-amount" id="displayTotalFactura">RD$ 0.00</div>
          <div class="pos-total-divider"></div>

          <div class="pos-total-breakdown" style="flex-wrap: wrap; gap: 8px;">
            <span>Bruto: <strong id="displaySubtotalBruto">RD$ 0.00</strong></span>
            <span id="spanDescuentoResumen" style="display: none; color: #2e7d52;">
              Desc.: <strong id="displayDescuentoTotal">-RD$ 0.00</strong>
            </span>
            <span id="spanDeliveryResumen" style="display: none; color: #794a15;">
              Envío: <strong id="displayTarifaDelivery">+RD$ 0.00</strong>
            </span>
            <span>Impuestos: <strong id="displayImpuestosTotal">RD$ 0.00</strong></span>
          </div>
          <div id="wrapPillsImpuestos" style="margin-top: 6px;"></div>
        </div>
      </div>

      <!-- BARRA DE ESCÁNER + ALMACÉN POS + CUPÓN + CATÁLOGO -->
      <div class="pos-scanner-strip">
        <div class="form-group" style="margin-bottom: 0; min-width: 0;">
          <label for="inputScannerPos" class="form-label">ESCÁNER RÁPIDO (CÓDIGO DE BARRAS O SKU)</label>
          <div style="display: flex; gap: 6px; width: 100%;">
            <input type="text" id="inputScannerPos" class="form-control" style="flex: 1; min-width: 0; width: 100%;"
              placeholder="Escanea botella, six-pack o caja y presiona Enter..." autocomplete="off"
              onkeydown="if(event.key==='Enter'){ event.preventDefault(); escanearCodigoPos(); }" />
            <button type="button" class="btn btn-secondary pos-btn-sync" onclick="escanearCodigoPos()">Agregar</button>
          </div>
        </div>

        <div class="form-group" style="margin-bottom: 0; min-width: 0;">
          <label for="selectAlmacenPos" class="form-label">ALMACÉN DE DESPACHO (FEFO)</label>
          <select id="selectAlmacenPos" class="form-control" style="width: 100%;"
            onchange="recalcularCarritoServidor()">
            <?php foreach ($almacenes as $alm): ?>
              <option value="<?= (int) $alm['id'] ?>"><?= htmlspecialchars($alm['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group" style="margin-bottom: 0; min-width: 0;">
          <label for="inputCodigoCupon" class="form-label">CUPÓN PROMOCIONAL</label>
          <div style="display: flex; gap: 6px; width: 100%;">
            <input type="text" id="inputCodigoCupon" class="form-control"
              style="flex: 1; min-width: 0; width: 100%; text-transform: uppercase; font-family: monospace; font-weight: 700;"
              placeholder="LQR-A1B2C3"
              onkeydown="if(event.key==='Enter'){ event.preventDefault(); aplicarCuponPos(); }" />
            <button type="button" class="btn btn-secondary pos-btn-sync" onclick="aplicarCuponPos()">Aplicar</button>
            <button type="button" class="btn btn-outline pos-btn-sync" id="btnQuitarCupon"
              style="display: none; padding: 0 8px !important; color: #b71c1c;" onclick="quitarCuponPos()"
              title="Quitar cupón">✕</button>
          </div>
        </div>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
          <button type="button" class="btn btn-gold pos-btn-sync" onclick="abrirModalCatalogo()">
            🔍 Catálogo y Presentaciones
          </button>
        </div>
      </div>

      <div id="estadoCuponTexto"
        style="font-size: 0.8rem; color: #7a6b5d; margin-top: -6px; margin-bottom: 12px; padding: 0 4px;">
        Si el cliente presenta una <strong>Nota de Crédito B04</strong>, se aplica como medio de pago en el modal de
        Cobro.
      </div>

      <!-- BANNERS DINÁMICOS (DELIVERY, COTIZACIÓN, CASHBACK, SUGERENCIAS PROMO, ALERTAS STOCK) -->
      <div id="bannerPedidoDeliveryActivo" class="pos-delivery-summary-bar" style="display: none;">
        <div class="pos-delivery-summary-info">
          <span class="pos-delivery-badge-icon">🛵</span>
          <div>
            <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">Pedido a Domicilio (Delivery)
            </div>
            <div id="textoResumenDelivery" style="font-size: 0.8rem; color: var(--text-body); margin-top: 2px;"></div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <button type="button" class="btn-sm btn-outline" onclick="abrirModalPedidoDelivery()">Editar</button>
          <button type="button" class="btn-sm btn-danger" onclick="quitarConfiguracionDelivery()">Cambiar a
            Mostrador</button>
        </div>
      </div>

      <div id="bannerCotizacionActiva" class="pos-quote-banner" style="display: none;">
        <div><strong>📄 Cotización Importada:</strong> <span id="textoCotizacionActiva"></span></div>
        <button type="button" class="btn-sm btn-outline" onclick="desvincularCotizacion()">Quitar Vínculo</button>
      </div>

      <div id="bannerCashbackPreview" class="pos-cashback-banner"></div>
      <div id="bannerSugerenciasPromos" class="pos-promo-alerts-wrap"></div>
      <div id="bannerAlertasStock" class="pos-stock-alert-banner"></div>

      <!-- TABLA PRINCIPAL: DETALLE DE PARTIDAS EN CARRITO -->
      <div class="table-card pos-cart-card">
        <div class="table-card-top" style="display: flex; align-items: center; justify-content: space-between;">
          <div class="table-card-info" style="display: flex; align-items: center; gap: 10px;">
            <h2 class="table-card-title">Detalle de Partidas en Carrito</h2>
            <span class="badge-count" id="badgeArticulosCarrito">0 Artículos</span>
          </div>
          <button type="button" class="btn btn-outline pos-btn-sync" id="btnLimpiarCarrito"
            onclick="limpiarCarritoConConfirmacion()" style="display: none; color: #b71c1c; border-color: #f0c5c1;"
            title="Vaciar todos los artículos del carrito">
            🗑️ Vaciar Carrito
          </button>
        </div>

        <div class="table-wrapper">
          <table class="data-table pos-cart-table">
            <thead>
              <tr>
                <th style="width: 12%;">SKU</th>
                <th style="width: 28%;">PRODUCTO / PROMOCIÓN</th>
                <th class="text-center" style="width: 14%;">CANTIDAD</th>
                <th class="text-right" style="width: 11%;">PRECIO UNIT.</th>
                <th class="text-right" style="width: 13%;">DESCUENTO</th>
                <th class="text-right" style="width: 10%;">IMPUESTOS</th>
                <th class="text-right" style="width: 8%;">TOTAL</th>
                <th class="text-right" style="width: 4%;"></th>
              </tr>
            </thead>
            <tbody id="tbodyCarrito">
              <tr>
                <td colspan="8" class="text-center" style="padding: 34px 20px; color: var(--text-muted);">
                  Escanea un producto o presiona <strong>🔍 Catálogo y Presentaciones</strong> para comenzar a facturar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerResumenPartidas">0 líneas registradas en la factura actual</span>
          <span>House &amp; Ice • Motor Fiscal DGII y FEFO en Vivo</span>
        </div>
      </div>

    </main>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 1: COBRO CON SPLIT UNIVERSAL DE PAGOS (CONTADO / CRÉDITO)      -->
  <!-- ==================================================================== -->
  <div id="modalCobroSplit" class="modal-overlay">
    <div class="modal-box modal-xl" style="max-width: 780px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title" id="tituloModalCobro">Cobro y Emisión de Factura</h3>
          <p class="modal-subtitle" id="subtituloModalCobro">Split Universal de Pagos (Efectivo, Tarjeta, Transferencia,
            Cheque y Saldo a Favor B04)</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalCobroSplit')">&times;</button>
      </div>

      <div class="modal-body" style="max-height: 74vh; overflow-y: auto;">
        <!-- VERIFICACIÓN DE MAYORÍA DE EDAD (ALCOHOL A CONSUMIDOR FINAL) -->
        <div id="bloqueVerificacionEdad"
          style="display: none; background: #fff3e0; border: 1px solid #ffb74d; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px;">
          <label
            style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 700; color: #7a4200; font-size: 0.86rem;">
            <input type="checkbox" id="chkConfirmoMayoriaEdad"
              style="width: 18px; height: 18px; accent-color: #a66e2e;" />
            <span>🔞 Confirmo que el comprador presentó identificación o aparenta mayoría de edad (&ge; 18 años) para
              compra de bebidas alcohólicas.</span>
          </label>
        </div>

        <!-- RESUMEN FINANCIERO -->
        <div class="split-pay-summary">
          <div class="split-pay-box">
            <small>Total a Cobrar</small>
            <strong id="splitTotalFactura">RD$ 0.00</strong>
          </div>
          <div class="split-pay-box">
            <small>Cubierto / Aplicado</small>
            <strong id="splitTotalCubierto" style="color: #2e7d32;">RD$ 0.00</strong>
          </div>
          <div class="split-pay-box">
            <small id="splitLblPendienteCambio">Saldo Pendiente</small>
            <strong id="splitPendienteCambio" style="color: #c62828;">RD$ 0.00</strong>
          </div>
        </div>

        <!-- SECCIÓN CONTADO: CONSTRUCTOR DE PAGOS MIXTOS -->
        <div id="panelCobroContado">
          <div
            style="background: #fff; border: 1px solid #e7dcc8; border-radius: 10px; padding: 14px; margin-bottom: 14px;">
            <div class="form-row-2" style="align-items: end;">
              <div class="form-group" style="margin-bottom: 0;">
                <label for="splitMetodoPago" class="form-label">MÉTODO DE PAGO *</label>
                <select id="splitMetodoPago" class="form-control" onchange="alCambiarMetodoSplit()">
                  <?php foreach ($metodosPago as $mp): ?>
                    <option value="<?= (int) $mp['id'] ?>"
                      data-codigo="<?= htmlspecialchars(strtoupper($mp['codigo'])) ?>">
                      <?= htmlspecialchars($mp['descripcion']) ?> (<?= htmlspecialchars($mp['codigo']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label for="splitMontoPago" class="form-label">MONTO A RECIBIR / APLICAR *</label>
                <div style="display: flex; gap: 6px;">
                  <input type="number" step="0.01" min="0.01" id="splitMontoPago" class="form-control"
                    style="font-family: monospace; font-weight: 800; font-size: 1.05rem;" />
                  <button type="button" class="btn btn-gold" onclick="agregarPagoSplit()" style="white-space: nowrap;">+
                    Agregar Pago</button>
                </div>
              </div>
            </div>

            <!-- SUBTIPO 1: TARJETA -->
            <div id="subtipoTarjeta" class="split-subtipo-panel" style="display: none;">
              <div style="font-size: 0.78rem; font-weight: 800; color: #794a15; margin-bottom: 8px;">💳 Datos de
                Verifone (tesoreria.pago_tarjeta)</div>
              <div class="form-row-3">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Tipo *</label>
                  <select id="tjTipo" class="form-control">
                    <option value="DEBITO">DÉBITO</option>
                    <option value="CREDITO">CRÉDITO</option>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Marca *</label>
                  <select id="tjMarca" class="form-control">
                    <option value="VISA">VISA</option>
                    <option value="MASTERCARD">MASTERCARD</option>
                    <option value="AMEX">AMEX</option>
                    <option value="OTRA">OTRA</option>
                  </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Últimos 4 Dígitos *</label>
                  <input type="text" id="tjUlt4" class="form-control" maxlength="4" placeholder="1234"
                    oninput="this.value=this.value.replace(/\D/g,'').slice(0,4);" />
                </div>
              </div>
              <div class="form-row-2" style="margin-top: 8px;">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">N° Autorización *</label>
                  <input type="text" id="tjAutorizacion" class="form-control" maxlength="50" placeholder="Ej. 849201" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">N° Lote (Opcional)</label>
                  <input type="text" id="tjLote" class="form-control" maxlength="30" placeholder="Ej. 0042" />
                </div>
              </div>
            </div>

            <!-- SUBTIPO 2: TRANSFERENCIA -->
            <div id="subtipoTransferencia" class="split-subtipo-panel" style="display: none;">
              <div style="font-size: 0.78rem; font-weight: 800; color: #794a15; margin-bottom: 8px;">🏦 Transferencia
                Bancaria (no entra al efectivo del Arqueo Ciego)</div>
              <div class="form-row-2">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Banco de Origen *</label>
                  <input type="text" id="trBancoOrigen" class="form-control" maxlength="60"
                    placeholder="Ej. Banco Popular / BHD" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Cuenta Bancaria Receptora *</label>
                  <select id="trCuentaId" class="form-control">
                    <option value="">-- Seleccionar Cuenta --</option>
                    <?php foreach ($cuentasBancarias as $cb): ?>
                      <option value="<?= (int) $cb['id'] ?>">
                        <?= htmlspecialchars($cb['nombre_banco'] . ' • ' . $cb['numero_cuenta'] . ' (' . ($cb['codigo_iso'] ?: 'DOP') . ')') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="form-row-3" style="margin-top: 8px;">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">N° Referencia *</label>
                  <input type="text" id="trReferencia" class="form-control" maxlength="100"
                    placeholder="Ej. TRF-998812" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Fecha *</label>
                  <input type="date" id="trFecha" class="form-control" value="<?= date('Y-m-d') ?>" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Titular (Opcional)</label>
                  <input type="text" id="trTitular" class="form-control" maxlength="120" />
                </div>
              </div>
            </div>

            <!-- SUBTIPO 3: CHEQUE -->
            <div id="subtipoCheque" class="split-subtipo-panel" style="display: none;">
              <div style="font-size: 0.78rem; font-weight: 800; color: #794a15; margin-bottom: 8px;">📝 Datos del Cheque
                (tesoreria.pago_cheque)</div>
              <div class="form-row-3">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">N° Cheque *</label>
                  <input type="text" id="chNumero" class="form-control" maxlength="30" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Banco Emisor *</label>
                  <input type="text" id="chBanco" class="form-control" maxlength="60" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Fecha Emisión *</label>
                  <input type="date" id="chFecha" class="form-control" value="<?= date('Y-m-d') ?>" />
                </div>
              </div>
              <div class="form-row-2" style="margin-top: 8px;">
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Titular del Cheque *</label>
                  <input type="text" id="chTitular" class="form-control" maxlength="120" />
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                  <label class="form-label">Cuenta Destino (Opcional)</label>
                  <select id="chCuentaId" class="form-control">
                    <option value="">-- En custodia / Sin depositar --</option>
                    <?php foreach ($cuentasBancarias as $cb): ?>
                      <option value="<?= (int) $cb['id'] ?>">
                        <?= htmlspecialchars($cb['nombre_banco'] . ' • ' . $cb['numero_cuenta']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>

            <!-- SUBTIPO 4: SALDO A FAVOR (NOTA DE CRÉDITO B04 VIGENTE 15 DÍAS) -->
            <div id="subtipoSaldoFavor" class="split-subtipo-panel" style="display: none;">
              <div style="font-size: 0.78rem; font-weight: 800; color: #794a15; margin-bottom: 8px;">♻️ Nota de Crédito
                B04 • Saldo a Favor (Medio de Pago, vigencia 15 días)</div>
              <div style="display: flex; gap: 8px; align-items: center;">
                <input type="text" id="sfCodigoCredito" class="form-control"
                  placeholder="Escanea o digita Código de Crédito o NCF B04..."
                  style="text-transform: uppercase; font-family: monospace; font-weight: 700;" />
                <button type="button" class="btn btn-secondary" onclick="validarCreditoB04EnCobro()"
                  style="white-space: nowrap;">Verificar Saldo</button>
              </div>
              <div id="sfInfoResultado" style="font-size: 0.8rem; margin-top: 6px; color: #5a3818;"></div>
            </div>
          </div>

          <!-- LISTA DE PAGOS AGREGADOS AL SPLIT -->
          <div class="carrito-reglas-wrapper">
            <table class="carrito-reglas-table">
              <thead>
                <tr>
                  <th>Método</th>
                  <th>Referencia / Detalle</th>
                  <th style="text-align: right;">Recibido</th>
                  <th style="text-align: right;">Aplicado</th>
                  <th style="text-align: right;">Devuelta</th>
                  <th style="text-align: right;">Acción</th>
                </tr>
              </thead>
              <tbody id="tbodyPagosSplit">
                <tr>
                  <td colspan="6" style="text-align: center; padding: 14px; color: #888;">Agrega uno o varios pagos
                    arriba para cubrir el total de la factura.</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- SECCIÓN CRÉDITO COMERCIAL -->
        <div id="panelCobroCredito"
          style="display: none; background: #eef5ff; border: 1px solid #bbdefb; border-radius: 10px; padding: 16px; color: #0d47a1;">
          <div style="font-weight: 800; font-size: 0.96rem; margin-bottom: 6px;">📋 Facturación a Crédito Comercial
            (Cuentas por Cobrar)</div>
          <div id="textoResumenCredito" style="font-size: 0.86rem; line-height: 1.5;"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCobroSplit')">Cancelar</button>
        <button type="button" class="btn btn-gold" id="btnConfirmarEmisionFinal" onclick="confirmarYGuardarFactura()">
          ✅ Confirmar y Emitir Factura
        </button>
      </div>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 2: BUSCADOR FILTRADO DE CATÁLOGO Y PRESENTACIONES              -->
  <!-- ==================================================================== -->
  <div id="modalCatalogoProductos" class="modal-overlay">
    <div class="modal-box modal-xl">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Catálogo de Productos y Presentaciones</h3>
          <p class="modal-subtitle" id="subtituloCatalogoPos">Stock disponible en el almacén POS seleccionado</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalCatalogoProductos')">&times;</button>
      </div>

      <div class="modal-body">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 10px; margin-bottom: 10px;">
          <div class="form-group" style="margin-bottom: 0;">
            <label for="catFiltroTexto" class="form-label">Nombre, SKU, Código de Barras o Empaque</label>
            <input type="text" id="catFiltroTexto" class="form-control"
              placeholder="Ej. Brugal, Presidente, Six-Pack..." oninput="programarBusquedaCatalogoPos()" />
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label for="catFiltroCategoria" class="form-label">Categoría</label>
            <select id="catFiltroCategoria" class="form-control" onchange="buscarEnCatalogoPos()">
              <option value="0">Todas las categorías</option>
              <?php foreach ($categorias as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label for="catFiltroMarca" class="form-label">Marca</label>
            <select id="catFiltroMarca" class="form-control" onchange="buscarEnCatalogoPos()">
              <option value="0">Todas las marcas</option>
              <?php foreach ($marcas as $mar): ?>
                <option value="<?= (int) $mar['id'] ?>"><?= htmlspecialchars($mar['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
          <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.84rem; cursor: pointer;">
            <input type="checkbox" id="catFiltroSoloStock" checked onchange="buscarEnCatalogoPos()"
              style="width: 15px; height: 15px; accent-color: #a66e2e;" />
            <span>Mostrar solo productos con stock disponible en este almacén</span>
          </label>
          <span class="badge-count" id="catContadorResultados">0 productos</span>
        </div>

        <div class="carrito-reglas-wrapper" style="max-height: 380px; overflow-y: auto;">
          <table class="carrito-reglas-table">
            <thead>
              <tr>
                <th style="width: 26%;">Producto / SKU</th>
                <th style="width: 18%;">Categoría / Marca</th>
                <th style="width: 12%; text-align: center;">Stock Disp.</th>
                <th>Presentaciones (clic para añadir al carrito)</th>
              </tr>
            </thead>
            <tbody id="catTablaResultados"></tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCatalogoProductos')">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 3: PEDIDO A DOMICILIO (DELIVERY CON TRIPLE FILTRO EN VIVO)     -->
  <!-- ==================================================================== -->
  <div id="modalPedidoDelivery" class="modal-overlay">
    <div class="modal-box" style="max-width: 640px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Datos del Pedido a Domicilio (Delivery)</h3>
          <p class="modal-subtitle">Triple Filtro: Zona asignada + Turno horario activo + Capacidad disponible</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalPedidoDelivery')">&times;</button>
      </div>

      <form id="formPedidoDelivery" onsubmit="confirmarPedidoDeliveryModal(event)">
        <div class="modal-body">
          <div class="form-group">
            <label for="selectZonaDelivery" class="form-label">ZONA DE ENTREGA (SUMA TARIFA A LA FACTURA) *:</label>
            <select id="selectZonaDelivery" class="form-control" required
              onchange="cargarRepartidoresZonaTripleFiltro()">
              <option value="" data-nombre="" data-tarifa="0">-- Seleccione la Zona --</option>
              <?php foreach ($zonasDelivery as $z): ?>
                <option value="<?= (int) $z['id'] ?>" data-nombre="<?= htmlspecialchars($z['nombre']) ?>"
                  data-tarifa="<?= htmlspecialchars((string) $z['tarifa_envio']) ?>">
                  <?= htmlspecialchars($z['nombre']) ?> • Tarifa: RD$ <?= number_format((float) $z['tarifa_envio'], 2) ?>
                  (~<?= (int) $z['tiempo_promedio_min'] ?> min)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
              <label class="form-label" style="margin: 0;">REPARTIDORES EVALUADOS POR TRIPLE FILTRO *:</label>
              <span id="badgeCountRepZona" class="badge-count" style="font-size: 0.72rem;">Seleccione una zona</span>
            </div>
            <input type="hidden" id="inputRepartidorSeleccionado" value="" />
            <div id="listaRepartidoresZonaModal" class="pos-rep-cards-container">
              <div class="pos-rep-empty-msg">Selecciona una zona arriba para evaluar repartidores en turno y con
                capacidad libre.</div>
            </div>
          </div>

          <div class="form-group" style="margin-bottom: 0;">
            <label for="inputDireccionDelivery" class="form-label">DIRECCIÓN EXACTA DEL PEDIDO *:</label>
            <textarea id="inputDireccionDelivery" class="form-control" rows="3" required maxlength="250"
              placeholder="Calle, número, residencial, apartamento y referencia..."></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalPedidoDelivery')">Cancelar</button>
          <button type="submit" class="btn btn-gold">Confirmar Pedido Delivery</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 4: IMPORTAR COTIZACIÓN PENDIENTE                               -->
  <!-- ==================================================================== -->
  <div id="modalImportarCotizacion" class="modal-overlay">
    <div class="modal-box" style="max-width: 720px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Importar Cotización Pendiente</h3>
          <p class="modal-subtitle">Valida vigencia y existencias actuales antes de cargar al carrito</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalImportarCotizacion')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="table-wrapper" style="max-height: 340px; overflow-y: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Cotización</th>
                <th>Cliente</th>
                <th>Emisión / Vence</th>
                <th class="text-right">Total</th>
                <th class="text-right">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($cotizacionesPend)): ?>
                <tr>
                  <td colspan="5" class="text-center" style="padding: 28px; color: var(--text-muted);">No hay cotizaciones
                    vigentes pendientes.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($cotizacionesPend as $cot): ?>
                  <tr>
                    <td><strong
                        style="font-family: monospace; color: var(--accent-gold);"><?= htmlspecialchars($cot['numero_cotizacion']) ?></strong>
                    </td>
                    <td>
                      <div style="font-weight: 600;"><?= htmlspecialchars($cot['cliente_nombre']) ?></div>
                      <div style="font-size: 0.74rem; color: #8a7b70;"><?= htmlspecialchars($cot['cliente_doc']) ?></div>
                    </td>
                    <td style="font-size: 0.78rem;">
                      <div>Emitida: <?= htmlspecialchars($cot['fecha_emision_fmt']) ?></div>
                      <div>Vence: <?= htmlspecialchars($cot['fecha_vencimiento_fmt']) ?></div>
                    </td>
                    <td class="text-right" style="font-family: monospace; font-weight: 700;">RD$
                      <?= number_format((float) $cot['monto_total'], 2) ?>
                    </td>
                    <td class="text-right">
                      <button type="button" class="btn-sm btn-gold"
                        onclick="cargarCotizacionEnCarrito(<?= (int) $cot['id'] ?>)">Cargar</button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 5: REGISTRO RÁPIDO DE CLIENTE (+ CLIENTE)                      -->
  <!-- ==================================================================== -->
  <div id="modalNuevoClienteRapido" class="modal-overlay">
    <div class="modal-box" style="max-width: 540px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Registrar Nuevo Cliente</h3>
          <p class="modal-subtitle">Alta rápida vinculada a identidad.personas (&ge; 18 años en persona física)</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalNuevoClienteRapido')">&times;</button>
      </div>
      <form id="formClienteRapido" onsubmit="guardarClienteRapido(event)">
        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="cliTipoDoc" class="form-label">Tipo de Documento *</label>
              <select id="cliTipoDoc" class="form-control" onchange="aplicarMascaraDocClienteRapido()">
                <option value="RNC">RNC (Jurídico)</option>
                <option value="CEDULA" selected>Cédula de Identidad</option>
                <option value="PASAPORTE">Pasaporte</option>
              </select>
            </div>
            <div class="form-group">
              <label for="cliDocumento" class="form-label">Número de Documento *</label>
              <input type="text" id="cliDocumento" class="form-control" required placeholder="001-1234567-8"
                maxlength="13" oninput="aplicarMascaraDocClienteRapido()" />
            </div>
          </div>
          <div class="form-group">
            <label for="cliNombre" class="form-label">Nombre Completo o Razón Social *</label>
            <input type="text" id="cliNombre" class="form-control" required maxlength="150"
              placeholder="Ej. Grupo Hotelero del Norte SRL" />
          </div>
          <div class="form-row-3">
            <div class="form-group">
              <label for="cliTelefono" class="form-label">Teléfono</label>
              <input type="text" id="cliTelefono" class="form-control" placeholder="(809) 555-0101" maxlength="14"
                oninput="formatearTelefonoRapido(this)" />
            </div>
            <div class="form-group">
              <label for="cliFechaNac" class="form-label">F. Nacimiento (&ge;18)</label>
              <input type="date" id="cliFechaNac" class="form-control"
                max="<?= date('Y-m-d', strtotime('-18 years')) ?>" />
            </div>
            <div class="form-group">
              <label for="cliCategoria" class="form-label">Categoría</label>
              <select id="cliCategoria" class="form-control">
                <option value="REGULAR">Regular</option>
                <option value="VIP">VIP</option>
                <option value="MAYORISTA">Mayorista</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
            onclick="cerrarModal('modalNuevoClienteRapido')">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnSubmitClienteRapido">Guardar y Seleccionar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 6: HISTORIAL DE FACTURAS EMITIDAS (+ DETALLE Y ANULACIÓN PIN)  -->
  <!-- ==================================================================== -->
  <div id="modalHistorialFacturas" class="modal-overlay">
    <div class="modal-box modal-xl">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Facturas Emitidas Recientemente</h3>
          <p class="modal-subtitle">Consulta de detalle de pagos/cupones y anulación auditada con PIN de supervisor</p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalHistorialFacturas')">&times;</button>
      </div>
      <div class="modal-body">
        <div class="table-wrapper" style="max-height: 400px; overflow-y: auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Factura</th>
                <th>NCF</th>
                <th>Cliente</th>
                <th>Condición / Origen</th>
                <th>Fecha</th>
                <th class="text-right">Total</th>
                <th class="text-center">Estado</th>
                <th class="text-right">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($facturasRecientes)): ?>
                <tr>
                  <td colspan="8" class="text-center" style="padding: 28px; color: var(--text-muted);">Aún no se han
                    emitido facturas.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($facturasRecientes as $f):
                  $estF = strtoupper((string) $f['estado']);
                  $puedeAnular = !in_array($estF, ['ANULADA', 'EN_TRANSITO'], true);
                  ?>
                  <tr>
                    <td><strong
                        style="font-family: monospace; color: var(--accent-gold);"><?= htmlspecialchars($f['numero_factura']) ?></strong>
                    </td>
                    <td><span class="badge-count" style="font-size: 0.74rem;"><?= htmlspecialchars($f['ncf']) ?></span></td>
                    <td>
                      <div style="font-weight: 600;"><?= htmlspecialchars($f['cliente_nombre']) ?></div>
                      <?php if (!empty($f['cliente_doc'])): ?>
                        <div style="font-size: 0.72rem; color: #8a7b70;"><?= htmlspecialchars($f['cliente_doc']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 0.78rem;">
                      <strong><?= htmlspecialchars($f['condicion_pago']) ?></strong>
                      <div style="color: #8a7b70;"><?= htmlspecialchars($f['origen_venta']) ?></div>
                    </td>
                    <td style="font-size: 0.77rem; font-family: monospace;"><?= htmlspecialchars($f['fecha_fmt']) ?></td>
                    <td class="text-right" style="font-family: monospace; font-weight: 700;">RD$
                      <?= number_format((float) $f['monto_total'], 2) ?>
                    </td>
                    <td class="text-center">
                      <span
                        class="status-badge <?= $estF === 'ANULADA' ? 'inactive' : 'active' ?>"><?= htmlspecialchars($estF) ?></span>
                    </td>
                    <td class="text-right" style="white-space: nowrap;">
                      <button type="button" class="btn-sm btn-outline"
                        onclick="verDetalleFactura(<?= (int) $f['id'] ?>)">Ver</button>
                      <?php if ($puedeAnular): ?>
                        <button type="button" class="btn-sm btn-danger"
                          onclick="abrirModalAnularFactura(<?= (int) $f['id'] ?>, '<?= htmlspecialchars($f['numero_factura'], ENT_QUOTES) ?>')">Anular</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL 6B: DETALLE DE FACTURA -->
  <div id="modalDetalleFactura" class="modal-overlay" style="z-index: 1200;">
    <div class="modal-box modal-lg">
      <div class="modal-header">
        <h3 class="modal-title" id="detFacTitulo">Detalle de Factura</h3>
        <button type="button" class="btn-close" onclick="cerrarModal('modalDetalleFactura')">&times;</button>
      </div>
      <div class="modal-body" id="detFacBody" style="max-height: 70vh; overflow-y: auto;"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalDetalleFactura')">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- MODAL 6C: ANULAR FACTURA CON PIN DE SUPERVISOR -->
  <div id="modalAnularFactura" class="modal-overlay" style="z-index: 1250;">
    <div class="modal-box" style="max-width: 480px;">
      <div class="modal-header">
        <h3 class="modal-title">Anular Factura (Requiere PIN)</h3>
        <button type="button" class="btn-close" onclick="cerrarModal('modalAnularFactura')">&times;</button>
      </div>
      <form id="formAnularFactura" onsubmit="confirmarAnulacionFactura(event)">
        <input type="hidden" id="anularFacturaId" value="" />
        <div class="modal-body">
          <div
            style="background: #ffebee; border: 1px solid #ef9a9a; color: #b71c1c; padding: 10px 12px; border-radius: 8px; font-size: 0.84rem; margin-bottom: 12px;">
            Vas a anular <strong id="anularFacturaNumero"></strong>. Se devolverán las botellas a sus lotes originales y
            se reversarán caja/CxC/cupones.
          </div>
          <div class="form-group">
            <label for="anularJustificacion" class="form-label">Motivo de Anulación *</label>
            <input type="text" id="anularJustificacion" class="form-control" required minlength="5" maxlength="255"
              placeholder="Ej. Error en selección de cliente B01" />
          </div>
          <div class="form-group">
            <label for="anularSupervisorId" class="form-label">Supervisor que Autoriza *</label>
            <select id="anularSupervisorId" class="form-control" required>
              <option value="">-- Seleccionar Supervisor --</option>
              <?php foreach ($supervisoresAutorizados as $sup): ?>
                <option value="<?= htmlspecialchars($sup['usuario_id']) ?>">
                  <?= htmlspecialchars($sup['nombre_completo'] . ' (' . $sup['username'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" style="margin-bottom: 0;">
            <label for="anularPin" class="form-label">PIN de Supervisor *</label>
            <input type="password" id="anularPin" class="form-control" required inputmode="numeric" maxlength="6"
              placeholder="••••••" style="font-family: monospace; letter-spacing: 4px; font-weight: 700;"
              oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalAnularFactura')">Cancelar</button>
          <button type="submit" class="btn btn-danger" id="btnConfirmarAnulacion">Confirmar Anulación</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 7: ARQUEO CIEGO (FIN DE TURNO — FASE 5 CONTROL DE CAJA)        -->
  <!-- ==================================================================== -->
  <div id="modalArqueoCiegoFacturacion" class="modal-overlay">
    <div class="modal-box modal-arqueo-premium">
      <div class="modal-header" style="border-bottom: 1px solid #ede3d5; padding: 16px 22px; background: #faf7f2;">
        <div>
          <h3 class="modal-title" style="margin: 0;">🔒 Arqueo Ciego de Caja (Fin de Turno)</h3>
          <div class="arqueo-header-pills">
            <?php if (!empty($sesionCajaActiva)): ?>
              <span class="arqueo-pill arqueo-pill-sesion">📌 Sesión #<?= (int) $sesionCajaActiva['id'] ?></span>
              <span class="arqueo-pill arqueo-pill-terminal">🖥️
                <?= htmlspecialchars($sesionCajaActiva['nombre_equipo']) ?></span>
              <span class="arqueo-pill arqueo-pill-cajero">👤
                <?= htmlspecialchars($sesionCajaActiva['cajero_nombre']) ?></span>
            <?php endif; ?>
          </div>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalArqueoCiegoFacturacion')">&times;</button>
      </div>

      <form id="formArqueoFacturacion" onsubmit="enviarArqueoFacturacion(event)">
        <input type="hidden" name="accion" value="procesar_arqueo" />
        <input type="hidden" name="sesion_id" value="<?= $sesionCajaActiva['id'] ?? '' ?>" />
        <input type="hidden" id="pos_arq_efectivo_declarado" name="efectivo_declarado" value="0.00" />

        <div class="modal-body"
          style="display: flex; flex-direction: column; gap: 14px; max-height: 68vh; overflow-y: auto;">
          <div class="arqueo-notice-card">
            <span>🛡️</span>
            <div style="font-size: 0.78rem; color: #794a15;">
              <strong>Regla Oficial de Arqueo Ciego:</strong> Declare únicamente el conteo físico de billetes, monedas y
              vouchers en gaveta. El sistema compara contra los saldos contables al procesar el cierre.
            </div>
          </div>

          <div class="arqueo-section-card">
            <div class="arqueo-section-head">
              <span class="arqueo-section-label">💵 Conteo de Efectivo Físico (DOP)</span>
              <span class="arqueo-total-badge" id="posTotalEfectivoLbl">RD$ 0.00</span>
            </div>
            <div id="posDenominacionesWrap" class="pos-denoms-container"></div>
          </div>

          <div class="arqueo-section-card">
            <div class="form-row-2" style="margin-bottom: 0;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="pos_tarjeta_declarada">TOTAL EN VOUCHERS (RD$) *</label>
                <input type="number" step="0.01" min="0" id="pos_tarjeta_declarada" name="tarjeta_declarada"
                  class="arqueo-input-control" value="0.00" required />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="pos_cantidad_vouchers">CANTIDAD DE VOUCHERS *</label>
                <input type="number" min="0" step="1" id="pos_cantidad_vouchers" name="cantidad_vouchers"
                  class="arqueo-input-control" value="0" required />
              </div>
            </div>
          </div>

          <div class="arqueo-section-card">
            <div class="form-row-2" style="margin-bottom: 0;">
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label" for="pos_monto_retenido">FONDO DEJADO EN GAVETA (RD$)</label>
                <input type="number" step="0.01" min="0" id="pos_monto_retenido" name="monto_retenido_fondo"
                  class="arqueo-input-control"
                  value="<?= htmlspecialchars((string) ($sesionCajaActiva['monto_inicial_efectivo'] ?? '2500.00')) ?>"
                  oninput="recalcularEfectivoFacturacion()" />
              </div>
              <div class="form-group" style="margin-bottom: 0;">
                <label class="form-label">NETO A BÓVEDA (PARA TESORERÍA)</label>
                <div id="posLblNetoBoveda" class="arqueo-input-control"
                  style="background: #fdfaf4; border-color: #d8b888; color: #794a15; font-weight: 800; font-family: monospace; display: flex; align-items: center;">
                  RD$ 0.00
                </div>
              </div>
            </div>
          </div>

          <div id="posVeredictoBox"
            style="display: none; padding: 14px; border-radius: 10px; font-weight: 700; text-align: center;"></div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
            onclick="cerrarModal('modalArqueoCiegoFacturacion')">Volver</button>
          <button type="submit" id="posBtnSubmitArqueo" class="btn btn-gold"
            style="background: #8c4c15; border-color: #8c4c15; color: #fff;">
            🔒 Finalizar Turno y Cuadrar
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 8: CAJA CHICA EN FACTURACIÓN (CON PIN DE SUPERVISOR)           -->
  <!-- ==================================================================== -->
  <div id="modalCajaChicaFacturacion" class="modal-overlay">
    <div class="modal-box" style="max-width: 480px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">💵 Movimiento de Caja Chica</h3>
          <p class="modal-subtitle">
            <?= !empty($sesionCajaActiva) ? 'Sesión #' . (int) $sesionCajaActiva['id'] . ' • ' . htmlspecialchars($sesionCajaActiva['nombre_equipo']) : '' ?>
          </p>
        </div>
        <button type="button" class="btn-close" onclick="cerrarModal('modalCajaChicaFacturacion')">&times;</button>
      </div>
      <form id="formCajaChicaFacturacion" onsubmit="enviarCajaChicaFacturacion(event)">
        <input type="hidden" name="sesion_id" value="<?= $sesionCajaActiva['id'] ?? '' ?>" />
        <div class="modal-body" style="display: flex; flex-direction: column; gap: 12px;">
          <div class="form-group" style="margin: 0;">
            <label class="form-label" for="pos_mov_tipo">TIPO DE MOVIMIENTO *</label>
            <select id="pos_mov_tipo" name="tipo_movimiento" class="form-control" required>
              <option value="EGRESO">EGRESO (Compra de hielo, insumos)</option>
              <option value="INGRESO">INGRESO (Refuerzo de menudo)</option>
            </select>
          </div>
          <div class="form-group" style="margin: 0;">
            <label class="form-label" for="pos_mov_monto">MONTO (RD$) *</label>
            <input type="number" step="0.01" min="0.01" id="pos_mov_monto" name="monto" class="form-control" required
              style="font-family: monospace; font-weight: 800;" />
          </div>
          <div class="form-group" style="margin: 0;">
            <label class="form-label" for="pos_mov_concepto">CONCEPTO / JUSTIFICACIÓN *</label>
            <input type="text" id="pos_mov_concepto" name="concepto" class="form-control" required
              placeholder="Ej. Compra de 10 fundas de hielo" />
          </div>
          <div style="background: #fff8e1; border: 1.5px dashed #ffe082; padding: 12px; border-radius: 8px;">
            <div style="font-weight: 700; color: #794a15; margin-bottom: 8px; font-size: 0.82rem;">🔐 AUTORIZACIÓN DEL
              SUPERVISOR</div>
            <div class="form-group" style="margin-bottom: 8px;">
              <label class="form-label" for="pos_mov_usuario_supervisor">USUARIO DEL SUPERVISOR *</label>
              <input type="text" id="pos_mov_usuario_supervisor" name="usuario_supervisor" class="form-control" required
                autocomplete="off" placeholder="Ej. admin" />
            </div>
            <div class="form-group" style="margin: 0;">
              <label class="form-label" for="pos_mov_pin">PIN DE SEGURIDAD *</label>
              <input type="password" maxlength="6" id="pos_mov_pin" name="pin_supervisor" class="form-control" required
                placeholder="••••" style="text-align: center; font-size: 1.25rem; letter-spacing: 5px;"
                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6);" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary"
            onclick="cerrarModal('modalCajaChicaFacturacion')">Cancelar</button>
          <button type="submit" id="posBtnSubmitCajaChica" class="btn btn-gold">Registrar con PIN</button>
        </div>
      </form>
    </div>
  </div>

  <div id="toast" class="toast"></div>

  <script>
    window.POS_PUEDE_FACTURAR = <?= $puedeFacturar ? 'true' : 'false' ?>;
    window.POS_NCF_INFO = <?= json_encode($ncfDisponibles, JSON_UNESCAPED_UNICODE) ?>;
    window.POS_METODOS_PAGO = <?= json_encode($metodosPago, JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="facturacion.js?v=<?= time() ?>"></script>
</body>

</html>