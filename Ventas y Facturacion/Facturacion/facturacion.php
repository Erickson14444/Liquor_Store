<?php
/**
 * Proceso de Facturación (Punto de Venta)
 * Ubicación: c:\xampp\htdocs\Liquor_Store\Ventas y Facturacion\Facturacion\facturacion.php
 * House & Ice • Sistema Administrativo
 */

require_once __DIR__ . '/../../config/conexion.php';

$clientes            = [];
$metodosPago         = [];
$secuenciasFiscales  = [];
$catalogoProductos   = [];
$cotizacionesPend    = [];
$zonasDelivery       = [];
$repartidoresPorZona = [];
$repartidoresDisp    = [];
$facturasRecientes   = [];
$promocionesActivas  = [];

if ($pdo) {
    try {
        // 1. Clientes registrados activos
        $stmtCli = $pdo->query("
            SELECT c.persona_id AS cliente_id,
                   c.codigo_cliente,
                   c.categoria_cliente,
                   c.limite_credito,
                   c.permite_credito,
                   p.tipo_documento,
                   p.documento_identidad,
                   p.nombre_razon_social,
                   p.nombre_comercial,
                   p.telefono_principal
            FROM identidad.clientes c
            INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
            WHERE p.estado = TRUE
            ORDER BY p.nombre_razon_social ASC
        ");
        $clientes = $stmtCli->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 2. Métodos de pago activos
        $stmtMp = $pdo->query("
            SELECT id, codigo, descripcion, requiere_referencia
            FROM ventas.metodos_pago
            WHERE activo = TRUE
            ORDER BY id ASC
        ");
        $metodosPago = $stmtMp->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 3. Secuencias fiscales DGII activas (B01 y B02)
        $stmtSec = $pdo->query("
            SELECT id, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual,
                   TO_CHAR(fecha_vencimiento, 'DD/MM/YYYY') AS vencimiento_fmt
            FROM ventas.secuencias_fiscales_dgii
            WHERE estado = 'ACTIVO'
              AND secuencia_actual <= secuencia_hasta
            ORDER BY id ASC
        ");
        $secuenciasFiscales = $stmtSec->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 4. Catálogo de Productos y sus Presentaciones para agregar al carrito
        $stmtProd = $pdo->query("
            SELECT pm.id AS producto_maestro_id,
                   pm.sku,
                   COALESCE(
                       NULLIF(NULLIF(TRIM(pm.nombre), ''), 'Producto sin nombre asignado'),
                       (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id ORDER BY factor_multiplicador ASC LIMIT 1),
                       pm.sku
                   ) AS producto_nombre,
                   pm.categoria_id,
                   pm.marca_id,
                   COALESCE(cp.nombre, 'General') AS categoria_nombre,
                   COALESCE(m.nombre, 'Genérica') AS marca_nombre,
                   pm.costo_promedio,
                   pm.precio_base_unidad,
                   COALESCE((
                       SELECT imp.tasa_o_monto
                       FROM inventario.producto_impuestos pi
                       JOIN tesoreria.impuestos imp ON pi.impuesto_id = imp.id
                       WHERE pi.producto_maestro_id = pm.id
                         AND imp.siglas IN ('ITBIS_18', 'ITBIS_16', 'EXENTO')
                       ORDER BY imp.id ASC
                       LIMIT 1
                   ), 18.00) AS porcentaje_itbis,
                   pcb.id AS codigo_barra_id,
                   COALESCE(pcb.codigo_barra, pm.sku) AS codigo_barra,
                   COALESCE(pcb.descripcion_presentacion, 'Unidad Individual') AS descripcion_presentacion,
                   COALESCE(pcb.factor_multiplicador, 1) AS factor_conversion,
                   COALESCE(pcb.precio_venta_presentacion, pm.precio_base_unidad) AS precio_venta,
                   GREATEST(
                       COALESCE((
                           SELECT SUM(s.stock_fisico_unidades - s.stock_comprometido)
                           FROM inventario.inventario_stock s
                           WHERE s.producto_maestro_id = pm.id
                       ), 0),
                       COALESCE((
                           SELECT SUM(l.cantidad_actual)
                           FROM inventario.lotes l
                           WHERE l.producto_maestro_id = pm.id
                       ), 0)
                   ) AS stock_disponible
            FROM inventario.productos_maestro pm
            LEFT JOIN inventario.categoria_producto cp ON pm.categoria_id = cp.id
            LEFT JOIN inventario.marca m ON pm.marca_id = m.id
            LEFT JOIN inventario.producto_codigos_barra pcb ON pcb.producto_maestro_id = pm.id
            WHERE pm.id > 0
            ORDER BY pm.sku ASC, COALESCE(pcb.factor_multiplicador, 1) ASC
        ");
        $catalogoProductos = $stmtProd->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 5. Campañas promocionales activas para indicar etiquetas en productos
        $stmtPromo = $pdo->query("
            SELECT pc.id AS promocion_id,
                   pc.nombre_promocion AS nombre_campana,
                   pc.tipo_promocion AS tipo_regla,
                   pd.condicion_producto_id,
                   pd.condicion_categoria_id,
                   pd.condicion_marca_id AS marca_id,
                   pd.cantidad_requerida,
                   pd.beneficio_porcentaje,
                   pd.beneficio_monto_fijo
            FROM promociones.promociones_campanas pc
            INNER JOIN promociones.promociones_detalles pd ON pd.promocion_id = pc.id
            WHERE pc.estado = 'ACTIVA'
              AND CURRENT_DATE BETWEEN pc.fecha_inicio AND pc.fecha_fin
            ORDER BY pc.id DESC
        ");
        $promocionesActivas = $stmtPromo->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 6. Cotizaciones pendientes para importar
        $stmtCot = $pdo->query("
            SELECT cot.id,
                   cot.numero_cotizacion,
                   cot.cliente_id,
                   p.nombre_razon_social AS cliente_nombre,
                   p.documento_identidad AS cliente_doc,
                   TO_CHAR(cot.fecha_emision, 'DD/MM/YYYY') AS fecha_emision_fmt,
                   TO_CHAR(cot.fecha_vencimiento, 'DD/MM/YYYY') AS fecha_vencimiento_fmt,
                   cot.monto_subtotal,
                   cot.monto_impuestos AS monto_itbis,
                   cot.monto_impuestos,
                   cot.monto_total,
                   cot.observaciones
            FROM ventas.cotizaciones cot
            INNER JOIN identidad.clientes c ON cot.cliente_id = c.persona_id
            INNER JOIN identidad.personas p ON c.persona_id = p.persona_id
            WHERE cot.estado = 'PENDIENTE'
            ORDER BY cot.fecha_emision DESC
        ");
        $cotizacionesPend = $stmtCot->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 7. Zonas de delivery activas
        $stmtZonas = $pdo->query("
            SELECT id, nombre, tarifa_envio, tiempo_promedio_min
            FROM ventas.zonas_delivery
            WHERE activa = TRUE
            ORDER BY nombre ASC
        ");
        $zonasDelivery = $stmtZonas->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // 8. Repartidores disponibles asignados por cada Zona (ventas.repartidor_zonas + identidad.repartidores)
        $stmtRepZonas = $pdo->query("
            SELECT rz.zona_id,
                   rz.es_ruta_principal,
                   r.empleado_id AS repartidor_id,
                   e.codigo_empleado,
                   p.nombre_razon_social AS nombre_completo,
                   p.telefono_principal,
                   r.tipo_vehiculo,
                   COALESCE(r.placa_vehiculo, '') AS placa_vehiculo,
                   r.estado_logistico,
                   r.capacidad_pedidos
            FROM ventas.repartidor_zonas rz
            INNER JOIN identidad.repartidores r ON rz.repartidor_id = r.empleado_id
            INNER JOIN identidad.empleados e    ON r.empleado_id = e.empleado_id
            INNER JOIN identidad.personas p     ON e.persona_id = p.persona_id
            WHERE e.estado_laboral = 'ACTIVO'
              AND r.estado_logistico IN ('BASE', 'EN_RUTA_CON_ESPACIO')
            ORDER BY rz.es_ruta_principal DESC,
                     CASE WHEN r.estado_logistico = 'BASE' THEN 0 ELSE 1 END ASC,
                     p.nombre_razon_social ASC
        ");
        $repartidoresPorZona = $stmtRepZonas->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // También todos los repartidores disponibles por si alguna zona aún no tiene asignación en repartidor_zonas
        $stmtRepAll = $pdo->query("
            SELECT r.empleado_id AS repartidor_id,
                   e.codigo_empleado,
                   p.nombre_razon_social AS nombre_completo,
                   p.telefono_principal,
                   r.tipo_vehiculo,
                   COALESCE(r.placa_vehiculo, '') AS placa_vehiculo,
                   r.estado_logistico,
                   r.capacidad_pedidos
            FROM identidad.repartidores r
            INNER JOIN identidad.empleados e ON r.empleado_id = e.empleado_id
            INNER JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado_laboral = 'ACTIVO'
              AND r.estado_logistico IN ('BASE', 'EN_RUTA_CON_ESPACIO')
            ORDER BY CASE WHEN r.estado_logistico = 'BASE' THEN 0 ELSE 1 END ASC,
                     p.nombre_razon_social ASC
        ");
        $repartidoresDisp = $stmtRepAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    try {
        // 9. Historial reciente de facturas emitidas
        $stmtFac = $pdo->query("
            SELECT f.id,
                   f.numero_factura,
                   f.tipo_ncf,
                   COALESCE(f.ncf, 'S/C') AS ncf,
                   f.condicion_pago,
                   f.origen_venta,
                   f.monto_subtotal,
                   f.monto_descuento,
                   f.monto_impuestos AS monto_itbis,
                   f.monto_impuestos,
                   f.monto_total,
                   f.estado,
                   TO_CHAR(f.fecha_emision, 'DD/MM/YYYY HH12:MI AM') AS fecha_fmt,
                   COALESCE(NULLIF(f.razon_social_cliente, ''), p.nombre_razon_social, 'Consumidor Final') AS cliente_nombre,
                   COALESCE(NULLIF(f.rnc_cedula_cliente, ''), p.documento_identidad, '') AS cliente_doc
            FROM ventas.facturas f
            LEFT JOIN identidad.clientes c ON f.cliente_id = c.persona_id
            LEFT JOIN identidad.personas p ON c.persona_id = p.persona_id
            ORDER BY f.fecha_emision DESC, f.id DESC
            LIMIT 25
        ");
        $facturasRecientes = $stmtFac->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
}

// Mapa rápido de NCF disponible por tipo
$ncfDisponibles = [
    'B02' => null,
    'B01' => null,
];
foreach ($secuenciasFiscales as $sec) {
    $tipo = strtoupper(trim($sec['tipo_comprobante']));
    if (!isset($ncfDisponibles[$tipo]) || $ncfDisponibles[$tipo] === null) {
        $ncfFormateado = $tipo . str_pad((string)$sec['secuencia_actual'], 8, '0', STR_PAD_LEFT);
        $ncfDisponibles[$tipo] = [
            'id'          => (int)$sec['id'],
            'ncf_proximo' => $ncfFormateado,
            'restantes'   => (int)$sec['secuencia_hasta'] - (int)$sec['secuencia_actual'] + 1,
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
  <title>House &amp; Ice • Facturación</title>
  <link rel="stylesheet" href="../styles.css?v=23" />
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
            <span class="user-caja-pill open">
              <span class="pill-dot green"></span>
              Caja 0<?= htmlspecialchars((string)$sesionCajaId) ?>
            </span>
          </div>
        </div>
      </div>
    </div>
  </header>

  <div class="app-container">
    <!-- SIDEBAR UNIFICADO -->
    <?php include __DIR__ . '/../../sidebar.php'; ?>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-content">

      <!-- ENCABEZADO -->
      <div class="page-header">
        <div>
          <h1 class="page-title">Facturación</h1>
          <p class="page-subtitle">Emisión de comprobantes fiscales, control de carrito y registro de cobros en caja</p>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
          <button type="button" class="btn btn-outline" onclick="abrirModalHistorial()">
            🧾 Facturas Emitidas (<?= count($facturasRecientes) ?>)
          </button>
        </div>
      </div>

      <!-- BLOQUE SUPERIOR: TARJETA IZQUIERDA (CLIENTE Y PAGO) + TARJETA DERECHA (TOTAL FACTURA) -->
      <div class="pos-top-grid">

        <!-- TARJETA IZQUIERDA: IGUAL AL DISEÑO DE REFERENCIA -->
        <div class="pos-config-card">
          <div class="pos-config-row-clean">
            <!-- CLIENTE / RNC -->
            <div class="pos-field-group">
              <label for="selectCliente" class="form-label">CLIENTE / RNC:</label>
              <div class="pos-client-input-wrap">
                <select id="selectCliente" class="form-control" onchange="alCambiarCliente()">
                  <option value="CONSUMIDOR_FINAL"
                          data-doc=""
                          data-nombre="Consumidor Final"
                          data-tipo-doc="CEDULA"
                          data-credito="0"
                          data-limite="0">
                    Consumidor Final (Mostrador)
                  </option>
                  <?php foreach ($clientes as $cli): ?>
                    <?php
                      $docFmt = trim($cli['documento_identidad'] ?? '');
                      $labelDoc = $docFmt !== '' ? ($cli['tipo_documento'] . ': ' . $docFmt) : 'Sin Doc.';
                    ?>
                    <option value="<?= htmlspecialchars($cli['cliente_id']) ?>"
                            data-doc="<?= htmlspecialchars($docFmt) ?>"
                            data-nombre="<?= htmlspecialchars($cli['nombre_razon_social']) ?>"
                            data-tipo-doc="<?= htmlspecialchars($cli['tipo_documento']) ?>"
                            data-credito="<?= $cli['permite_credito'] ? '1' : '0' ?>"
                            data-limite="<?= htmlspecialchars((string)$cli['limite_credito']) ?>">
                      <?= htmlspecialchars($cli['nombre_razon_social']) ?> (<?= htmlspecialchars($labelDoc) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-beige pos-btn-add-client" onclick="abrirModalNuevoCliente()">
                  + Cliente
                </button>
              </div>
            </div>

            <!-- COMPROBANTE FISCAL (NCF) -->
            <div class="pos-field-group">
              <label for="selectTipoNcf" class="form-label">COMPROBANTE (NCF):</label>
              <select id="selectTipoNcf" class="form-control" onchange="actualizarInfoNcf()">
                <option value="B02">
                  B02 • Consumo Final<?= $ncfDisponibles['B02'] ? ' (' . $ncfDisponibles['B02']['ncf_proximo'] . ')' : '' ?>
                </option>
                <option value="B01">
                  B01 • Crédito Fiscal<?= $ncfDisponibles['B01'] ? ' (' . $ncfDisponibles['B01']['ncf_proximo'] . ')' : '' ?>
                </option>
                <option value="CONSUMO_INTERNO">
                  Consumo Interno
                </option>
              </select>
            </div>

            <!-- CONDICIÓN DE PAGO -->
            <div class="pos-field-group">
              <label for="selectMetodoPago" class="form-label">CONDICIÓN DE PAGO:</label>
              <select id="selectMetodoPago" class="form-control" onchange="alCambiarCondicionPago()">
                <?php if (empty($metodosPago)): ?>
                  <option value="EFECTIVO_DEFAULT" data-condicion="CONTADO" data-req-ref="0">Efectivo de Mostrador</option>
                <?php else: ?>
                  <?php foreach ($metodosPago as $mp): ?>
                    <option value="<?= (int)$mp['id'] ?>"
                            data-condicion="CONTADO"
                            data-codigo="<?= htmlspecialchars($mp['codigo']) ?>"
                            data-req-ref="<?= $mp['requiere_referencia'] ? '1' : '0' ?>">
                      <?= htmlspecialchars($mp['descripcion']) ?>
                    </option>
                  <?php endforeach; ?>
                <?php endif; ?>
                <option value="CREDITO_CXC" data-condicion="CREDITO" data-req-ref="0">
                  Crédito Comercial (CxC)
                </option>
              </select>
            </div>
          </div>

          <!-- FILA COMPACTA SOLO SI EL MÉTODO DE PAGO EXIGE NÚMERO DE REFERENCIA / VOUCHER -->
          <div id="bloqueReferenciaPago" style="display: none; margin-top: 10px; padding-top: 10px; border-top: 1px dashed var(--border-light);">
            <div style="display: flex; align-items: center; gap: 12px;">
              <label for="inputReferenciaPago" class="form-label" style="margin: 0; white-space: nowrap;">N° REFERENCIA / VOUCHER *:</label>
              <input
                type="text"
                id="inputReferenciaPago"
                class="form-control"
                placeholder="Ingresa el número de aprobación o referencia bancaria..."
                maxlength="60"
                style="max-width: 340px;"
              />
            </div>
          </div>
        </div>

        <!-- TARJETA DERECHA: TOTAL FACTURA -->
        <div class="pos-total-card">
          <div class="pos-total-header-row">
            <span class="pos-total-label">TOTAL FACTURA</span>
            <span class="pos-ncf-pill" id="badgeNcfActivo">NCF: B02</span>
          </div>

          <div class="pos-total-amount" id="displayTotalFactura">RD$ 0.00</div>

          <div class="pos-total-divider"></div>

          <div class="pos-total-breakdown">
            <span>Neto: <strong id="displaySubtotalNeto">RD$ 0.00</strong></span>
            <span id="spanDescuentoResumen" style="display: none; color: #2e7d52;">
              Desc.: <strong id="displayDescuentoTotal">-RD$ 0.00</strong>
            </span>
            <span>ITBIS (18%): <strong id="displayItbisTotal">RD$ 0.00</strong></span>
          </div>
        </div>

      </div>

      <!-- BARRA DE ACCIONES -->
      <div class="pos-action-bar">
        <div class="pos-action-left">
          <button type="button" class="btn btn-gold" onclick="abrirModalCatalogo()">
            + Agregar Producto
          </button>

          <button type="button" class="btn btn-outline-card" onclick="abrirModalCotizaciones()">
            Importar Cotización
            <?php if (count($cotizacionesPend) > 0): ?>
              <span class="pos-inline-count"><?= count($cotizacionesPend) ?></span>
            <?php endif; ?>
          </button>

          <button type="button" class="btn btn-outline-card" id="btnAbrirPedidoDelivery" onclick="abrirModalPedidoDelivery()">
            🛵 Pedido a Domicilio (Delivery)
          </button>
        </div>

        <div class="pos-action-right">
          <button type="button" class="btn btn-outline-card" id="btnLimpiarCarrito" onclick="limpiarCarritoConConfirmacion()" style="display: none;">
            Vaciar Carrito
          </button>
          <button type="button" class="btn btn-dark-save" id="btnGuardarFactura" onclick="procesarGuardadoFactura()">
            Guardar Factura
          </button>
        </div>
      </div>

      <!-- RESUMEN ACTIVO CUANDO LA FACTURA ES UN PEDIDO DELIVERY -->
      <div id="bannerPedidoDeliveryActivo" class="pos-delivery-summary-bar" style="display: none;">
        <div class="pos-delivery-summary-info">
          <span class="pos-delivery-badge-icon">🛵</span>
          <div>
            <div style="font-weight: 700; color: var(--text-dark); font-size: 0.88rem;">
              Factura configurada como Pedido a Domicilio (Delivery)
            </div>
            <div id="textoResumenDelivery" style="font-size: 0.8rem; color: var(--text-body); margin-top: 2px;"></div>
          </div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <button type="button" class="btn-sm btn-outline" onclick="abrirModalPedidoDelivery()">Editar Pedido</button>
          <button type="button" class="btn-sm btn-danger" onclick="quitarConfiguracionDelivery()">Cambiar a Mostrador</button>
        </div>
      </div>

      <!-- RESUMEN SI HAY UNA COTIZACIÓN IMPORTADA ACTIVA -->
      <div id="bannerCotizacionActiva" class="pos-quote-banner" style="display: none;">
        <div>
          <strong>📄 Cotización Importada:</strong>
          <span id="textoCotizacionActiva"></span>
        </div>
        <button type="button" class="btn-sm btn-outline" onclick="desvincularCotizacion()">Quitar Vínculo</button>
      </div>

      <!-- TABLA PRINCIPAL: DETALLE DE PARTIDAS EN CARRITO -->
      <div class="table-card pos-cart-card">
        <div class="table-card-top">
          <div class="table-card-info">
            <h2 class="table-card-title">Detalle de Partidas en Carrito</h2>
          </div>
          <span class="badge-count" id="badgeArticulosCarrito">0 Artículos</span>
        </div>

        <div class="table-wrapper">
          <table class="data-table pos-cart-table">
            <thead>
              <tr>
                <th style="width: 13%;">SKU</th>
                <th style="width: 30%;">PRODUCTO</th>
                <th class="text-center" style="width: 14%;">CANTIDAD</th>
                <th class="text-right" style="width: 12%;">PRECIO UNITARIO</th>
                <th class="text-right" style="width: 15%;">DESCUENTO</th>
                <th class="text-right" style="width: 10%;">SUBTOTAL</th>
                <th class="text-right" style="width: 6%;">ACCIONES</th>
              </tr>
            </thead>
            <tbody id="tbodyCarrito">
              <tr id="filaCarritoVacio">
                <td colspan="7" class="text-center" style="padding: 34px 20px; color: var(--text-muted);">
                  No hay productos en el carrito. Haz clic en <strong>+ Agregar Producto</strong> para comenzar a facturar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="table-card-footer">
          <span id="footerResumenPartidas">0 líneas registradas en la factura actual</span>
          <span>House &amp; Ice • Facturación y Punto de Venta</span>
        </div>
      </div>

    </main>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 1: VENTANA DE DATOS DEL PEDIDO A DOMICILIO (DELIVERY)          -->
  <!-- ==================================================================== -->
  <div id="modalPedidoDelivery" class="modal-overlay">
    <div class="modal-box" style="max-width: 640px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Datos del Pedido a Domicilio (Delivery)</h3>
          <p class="modal-subtitle">Selecciona la zona de entrega, el repartidor disponible asignado a esa zona y la dirección del cliente</p>
        </div>
      </div>

      <form id="formPedidoDelivery" onsubmit="confirmarPedidoDeliveryModal(event)">
        <div class="modal-body">

          <!-- 1. ZONA DE ENTREGA -->
          <div class="form-group">
            <label for="selectZonaDelivery" class="form-label">ZONA DE ENTREGA *:</label>
            <select id="selectZonaDelivery" class="form-control" required onchange="filtrarRepartidoresPorZonaModal()">
              <option value="" data-nombre="" data-tarifa="0" data-tiempo="0">-- Seleccione la Zona del Pedido --</option>
              <?php foreach ($zonasDelivery as $z): ?>
                <option value="<?= (int)$z['id'] ?>"
                        data-nombre="<?= htmlspecialchars($z['nombre']) ?>"
                        data-tarifa="<?= htmlspecialchars((string)$z['tarifa_envio']) ?>"
                        data-tiempo="<?= (int)$z['tiempo_promedio_min'] ?>">
                  <?= htmlspecialchars($z['nombre']) ?> • Tarifa: RD$ <?= number_format((float)$z['tarifa_envio'], 2) ?> (~<?= (int)$z['tiempo_promedio_min'] ?> min)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- 2. REPARTIDORES DISPONIBLES DE ESA ZONA -->
          <div class="form-group">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
              <label class="form-label" style="margin: 0;">REPARTIDOR DISPONIBLE EN LA ZONA *:</label>
              <span id="badgeCountRepZona" class="badge-count" style="font-size: 0.72rem; padding: 2px 8px;">Seleccione una zona</span>
            </div>

            <input type="hidden" id="inputRepartidorSeleccionado" value="" />

            <div id="listaRepartidoresZonaModal" class="pos-rep-cards-container">
              <div class="pos-rep-empty-msg">
                Selecciona primero una <strong>Zona de Entrega</strong> arriba para ver los repartidores disponibles asignados a esa zona.
              </div>
            </div>
          </div>

          <!-- 3. DIRECCIÓN DE ENTREGA -->
          <div class="form-group" style="margin-bottom: 0;">
            <label for="inputDireccionDelivery" class="form-label">DIRECCIÓN EXACTA DEL PEDIDO *:</label>
            <textarea
              id="inputDireccionDelivery"
              class="form-control"
              rows="3"
              required
              placeholder="Ej. Calle Principal #42, Residencial Las Palmas, Edif. B Apto. 302 (Próximo a la plaza comercial)..."
              maxlength="250"
              style="resize: vertical; min-height: 74px; padding-top: 10px;"
            ></textarea>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalConConfirmacion('modalPedidoDelivery')">Cancelar</button>
          <button type="submit" class="btn btn-gold">Confirmar Pedido Delivery</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 2: CATÁLOGO DE PRODUCTOS PARA AGREGAR AL CARRITO               -->
  <!-- ==================================================================== -->
  <div id="modalCatalogoProductos" class="modal-overlay">
    <div class="modal-box" style="max-width: 820px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Seleccionar Producto o Presentación</h3>
          <p class="modal-subtitle">Busca por nombre, SKU, marca o código de barra y agrégalo al carrito</p>
        </div>
      </div>

      <div class="modal-body">
        <div style="display: flex; gap: 10px; margin-bottom: 14px;">
          <div class="search-box" style="width: 100%;">
            <span class="search-icon">🔍</span>
            <input
              type="text"
              id="buscadorModalCatalogo"
              placeholder="Buscar licor, cerveza, vino, SKU o código EAN..."
              oninput="filtrarCatalogoModal()"
              autocomplete="off"
            />
          </div>
        </div>

        <div class="table-wrapper" style="max-height: 360px; overflow-y: auto; border: 1px solid var(--border-light); border-radius: var(--radius-sm);">
          <table class="data-table">
            <thead style="position: sticky; top: 0; z-index: 2; background: var(--bg-table-header);">
              <tr>
                <th>SKU / Código</th>
                <th>Producto y Presentación</th>
                <th class="text-center">Factor</th>
                <th class="text-center">Stock</th>
                <th class="text-right">Precio Unitario</th>
                <th class="text-right">Acción</th>
              </tr>
            </thead>
            <tbody id="tbodyCatalogoModal">
              <?php if (empty($catalogoProductos)): ?>
                <tr>
                  <td colspan="6" class="text-center" style="padding: 28px; color: var(--text-muted);">
                    No hay productos registrados en el catálogo. Regístralos primero desde el Maestro de Productos.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($catalogoProductos as $idx => $prod): ?>
                  <?php
                    $searchStr = strtolower(
                        ($prod['sku'] ?? '') . ' ' .
                        ($prod['producto_nombre'] ?? '') . ' ' .
                        ($prod['descripcion_presentacion'] ?? '') . ' ' .
                        ($prod['codigo_barra'] ?? '') . ' ' .
                        ($prod['marca_nombre'] ?? '') . ' ' .
                        ($prod['categoria_nombre'] ?? '')
                    );
                    $factor = max(1, (int)$prod['factor_conversion']);
                    $stockUnidades = (int)$prod['stock_disponible'];
                  ?>
                  <tr class="fila-catalogo-modal" data-search="<?= htmlspecialchars($searchStr) ?>">
                    <td>
                      <div style="font-family: var(--font-mono); font-weight: 700; color: var(--accent-gold); font-size: 0.85rem;">
                        <?= htmlspecialchars($prod['sku']) ?>
                      </div>
                      <?php if (!empty($prod['codigo_barra']) && $prod['codigo_barra'] !== $prod['sku']): ?>
                        <div style="font-size: 0.72rem; color: var(--text-muted); font-family: var(--font-mono);">
                          <?= htmlspecialchars($prod['codigo_barra']) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div style="font-weight: 600; color: var(--text-dark);">
                        <?= htmlspecialchars($prod['producto_nombre']) ?>
                      </div>
                      <div style="font-size: 0.77rem; color: var(--text-muted);">
                        <?= htmlspecialchars($prod['descripcion_presentacion']) ?> • <?= htmlspecialchars($prod['marca_nombre']) ?>
                      </div>
                    </td>
                    <td class="text-center">
                      <span class="badge-count" style="font-size: 0.74rem;">x<?= $factor ?> <?= $factor === 1 ? 'Und.' : 'Unds.' ?></span>
                    </td>
                    <td class="text-center">
                      <?php if ($stockUnidades > 0): ?>
                        <span class="status-badge active"><?= $stockUnidades ?> disp.</span>
                      <?php else: ?>
                        <span class="status-badge inactive">Sin stock</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: var(--text-dark);">
                      RD$ <?= number_format((float)$prod['precio_venta'], 2) ?>
                    </td>
                    <td class="text-right">
                      <button
                        type="button"
                        class="btn-sm btn-gold"
                        onclick="agregarItemDesdeCatalogo(<?= (int)$idx ?>)">
                        + Agregar
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModalConConfirmacion('modalCatalogoProductos')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 3: IMPORTAR COTIZACIÓN PENDIENTE                               -->
  <!-- ==================================================================== -->
  <div id="modalImportarCotizacion" class="modal-overlay">
    <div class="modal-box" style="max-width: 720px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Importar Cotización Pendiente</h3>
          <p class="modal-subtitle">Selecciona una cotización vigente para cargar su cliente y productos al carrito</p>
        </div>
      </div>

      <div class="modal-body">
        <div class="table-wrapper" style="max-height: 340px; overflow-y: auto; border: 1px solid var(--border-light); border-radius: var(--radius-sm);">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Cotización</th>
                <th>Cliente</th>
                <th>Emisión / Vence</th>
                <th class="text-right">Monto Total</th>
                <th class="text-right">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($cotizacionesPend)): ?>
                <tr>
                  <td colspan="5" class="text-center" style="padding: 28px; color: var(--text-muted);">
                    No hay cotizaciones pendientes por facturar en este momento.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($cotizacionesPend as $cot): ?>
                  <tr>
                    <td><strong style="font-family: var(--font-mono); color: var(--accent-gold);"><?= htmlspecialchars($cot['numero_cotizacion']) ?></strong></td>
                    <td>
                      <div style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($cot['cliente_nombre']) ?></div>
                      <div style="font-size: 0.74rem; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($cot['cliente_doc']) ?></div>
                    </td>
                    <td style="font-size: 0.78rem; color: var(--text-body);">
                      <div>Emitida: <?= htmlspecialchars($cot['fecha_emision_fmt']) ?></div>
                      <div style="color: var(--text-muted);">Vence: <?= htmlspecialchars($cot['fecha_vencimiento_fmt']) ?></div>
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700;">
                      RD$ <?= number_format((float)$cot['monto_total'], 2) ?>
                    </td>
                    <td class="text-right">
                      <button type="button" class="btn-sm btn-gold" onclick="cargarCotizacionEnCarrito(<?= (int)$cot['id'] ?>)">
                        Cargar al Carrito
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModalConConfirmacion('modalImportarCotizacion')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 4: REGISTRO RÁPIDO DE NUEVO CLIENTE (+ CLIENTE)                -->
  <!-- ==================================================================== -->
  <div id="modalNuevoClienteRapido" class="modal-overlay">
    <div class="modal-box" style="max-width: 540px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Registrar Nuevo Cliente</h3>
          <p class="modal-subtitle">Crea un cliente rápidamente y selecciónalo de inmediato en la factura actual</p>
        </div>
      </div>

      <form id="formClienteRapido" onsubmit="guardarClienteRapido(event)">
        <div class="modal-body">
          <div class="form-row-2">
            <div class="form-group">
              <label for="cliTipoDoc" class="form-label">Tipo de Documento *</label>
              <select id="cliTipoDoc" name="tipo_documento" class="form-control" onchange="aplicarMascaraDocClienteRapido()">
                <option value="RNC">RNC (Empresas / Jurídico)</option>
                <option value="CEDULA" selected>Cédula de Identidad</option>
                <option value="PASAPORTE">Pasaporte</option>
              </select>
            </div>

            <div class="form-group">
              <label for="cliDocumento" class="form-label">Número de Documento *</label>
              <input
                type="text"
                id="cliDocumento"
                name="documento_identidad"
                class="form-control"
                required
                placeholder="000-0000000-0"
                maxlength="13"
                oninput="aplicarMascaraDocClienteRapido()"
              />
            </div>
          </div>

          <div class="form-group">
            <label for="cliNombre" class="form-label">Nombre Completo o Razón Social *</label>
            <input
              type="text"
              id="cliNombre"
              name="nombre_razon_social"
              class="form-control"
              required
              placeholder="Ej. Grupo Hotelero del Norte SRL"
              maxlength="150"
            />
          </div>

          <div class="form-row-2">
            <div class="form-group">
              <label for="cliTelefono" class="form-label">Teléfono de Contacto</label>
              <input
                type="text"
                id="cliTelefono"
                name="telefono_principal"
                class="form-control"
                placeholder="(809) 555-0101"
                maxlength="14"
                oninput="formatearTelefonoRapido(this)"
              />
            </div>

            <div class="form-group">
              <label for="cliCategoria" class="form-label">Categoría</label>
              <select id="cliCategoria" name="categoria_cliente" class="form-control">
                <option value="REGULAR">Regular</option>
                <option value="VIP">VIP</option>
                <option value="MAYORISTA">Mayorista</option>
              </select>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="cerrarModalConConfirmacion('modalNuevoClienteRapido')">Cancelar</button>
          <button type="submit" class="btn btn-gold" id="btnSubmitClienteRapido">Guardar y Seleccionar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ==================================================================== -->
  <!-- MODAL 5: HISTORIAL RECIENTE DE FACTURAS EMITIDAS                     -->
  <!-- ==================================================================== -->
  <div id="modalHistorialFacturas" class="modal-overlay">
    <div class="modal-box" style="max-width: 840px;">
      <div class="modal-header">
        <div>
          <h3 class="modal-title">Facturas Emitidas Recientemente</h3>
          <p class="modal-subtitle">Consulta rápida de comprobantes facturados en el punto de venta</p>
        </div>
      </div>

      <div class="modal-body">
        <div class="table-wrapper" style="max-height: 380px; overflow-y: auto; border: 1px solid var(--border-light); border-radius: var(--radius-sm);">
          <table class="data-table">
            <thead>
              <tr>
                <th>N° Factura</th>
                <th>NCF</th>
                <th>Cliente</th>
                <th>Condición / Modalidad</th>
                <th>Fecha</th>
                <th class="text-right">Total</th>
                <th class="text-center">Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($facturasRecientes)): ?>
                <tr>
                  <td colspan="7" class="text-center" style="padding: 28px; color: var(--text-muted);">Aún no se han emitido facturas.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($facturasRecientes as $f): ?>
                  <tr>
                    <td><strong style="font-family: var(--font-mono); color: var(--accent-gold);"><?= htmlspecialchars($f['numero_factura']) ?></strong></td>
                    <td><span class="badge-count" style="font-size: 0.74rem;"><?= htmlspecialchars($f['ncf']) ?></span></td>
                    <td>
                      <div style="font-weight: 600; color: var(--text-dark);"><?= htmlspecialchars($f['cliente_nombre']) ?></div>
                      <?php if (!empty($f['cliente_doc'])): ?>
                        <div style="font-size: 0.72rem; color: var(--text-muted); font-family: var(--font-mono);"><?= htmlspecialchars($f['cliente_doc']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="font-size: 0.78rem;">
                      <strong><?= htmlspecialchars($f['condicion_pago']) ?></strong>
                      <div style="color: var(--text-muted);"><?= htmlspecialchars($f['origen_venta']) ?></div>
                    </td>
                    <td style="font-size: 0.77rem; color: var(--text-muted); font-family: var(--font-mono);">
                      <?= htmlspecialchars($f['fecha_fmt']) ?>
                    </td>
                    <td class="text-right" style="font-family: var(--font-mono); font-weight: 700; color: var(--accent-gold);">
                      RD$ <?= number_format((float)$f['monto_total'], 2) ?>
                    </td>
                    <td class="text-center">
                      <span class="status-badge active"><?= htmlspecialchars($f['estado']) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModalConConfirmacion('modalHistorialFacturas')">Cancelar</button>
      </div>
    </div>
  </div>

  <!-- NOTIFICACIÓN TOAST -->
  <div id="toast" class="toast"></div>

  <!-- DATOS INICIALES PARA JS -->
  <script>
    window.POS_CATALOGO           = <?= json_encode($catalogoProductos, JSON_UNESCAPED_UNICODE) ?>;
    window.POS_NCF_INFO           = <?= json_encode($ncfDisponibles, JSON_UNESCAPED_UNICODE) ?>;
    window.POS_PROMOCIONES        = <?= json_encode($promocionesActivas, JSON_UNESCAPED_UNICODE) ?>;
    window.POS_REPARTIDORES_ZONAS = <?= json_encode($repartidoresPorZona, JSON_UNESCAPED_UNICODE) ?>;
    window.POS_REPARTIDORES_TODOS = <?= json_encode($repartidoresDisp, JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <script src="facturacion.js?v=4"></script>
</body>
</html>
