<?php
/**
 * ============================================================================
 * Barra Lateral de Navegación Unificada (Sidebar Principal)
 * Ubicación: Raíz del proyecto (c:\xampp\htdocs\Liquor_Store\sidebar.php)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Detección de nivel de carpeta respecto a la raíz del proyecto (Liquor_Store/)
$isSubdir = in_array($currentDir, [
  'almacenes', 'cajas', 'clasificaciones', 'clientes', 'cuentas-bancarias',
  'impuestos', 'lotes', 'metodos-pago', 'monedas', 'personal', 'presentaciones',
  'productos', 'proveedores', 'retenciones', 'roles', 'secuencias-dgii',
  'usuarios', 'zonas-delivery', 'Delivery', 'Facturacion', 'ControlCaja',
  'Movimientos', 'Traslados', 'Lotes', 'ordenes_compra', 'recepciones'
]);

if ($isSubdir) {
  $rootPfx = '../../';
} elseif ($currentDir === 'Liquor_Store') {
  $rootPfx = './';
} else {
  $rootPfx = '../';
}

$menuLink = $rootPfx . 'Login y Menu/menu.php';
$logoutLink = $rootPfx . 'Login y Menu/logout.php';
$identidadPfx = $rootPfx . 'Gestion de Identidad/';
$cfgPfx = $rootPfx . 'Panel de configuracion/';
$seguridadPfx = $rootPfx . 'Seguridad y Control/';
$inventarioPfx = $rootPfx . 'Inventario y Stock/';
$promoPfx = $rootPfx . 'Gestion de Promociones/';
$ventasPfx = $rootPfx . 'Ventas y Facturacion/';
$comprasPfx = $rootPfx . 'Compras y Recepcion/';

// Lista de páginas pertenecientes al panel de configuración
$configPages = [
  'clasificaciones.php',
  'productos.php',
  'presentaciones.php',
  'almacenes.php',
  'cajas.php',
  'secuencias-dgii.php',
  'cuentas-bancarias.php',
  'metodos-pago.php',
  'monedas.php',
  'impuestos.php',
  'retenciones.php',
  'zonas-delivery.php',
  'usuarios.php'
];

// Detección de módulo activo para abrir el acordeón correspondiente
$isIdentidadActive   = in_array($currentPage, ['clientes.php', 'proveedores.php', 'personal.php']);
$isSeguridadActive   = in_array($currentPage, ['roles.php']);
$isInventarioActive  = in_array($currentPage, ['movimientos.php', 'traslados.php', 'lotes.php']);
$isVentasActive      = in_array($currentPage, ['delivery.php', 'facturacion.php', 'control_caja.php']);
$isComprasActive     = in_array($currentPage, ['ordenes_compra.php', 'recepciones.php']);
$isPromocionesActive = in_array($currentPage, ['promociones.php', 'cupones.php']);
$isBancosActive = false;
$isConfigOnlyActive = in_array($currentPage, [
  'clasificaciones.php',
  'productos.php',
  'presentaciones.php',
  'almacenes.php',
  'cajas.php',
  'secuencias-dgii.php',
  'cuentas-bancarias.php',
  'metodos-pago.php',
  'monedas.php',
  'impuestos.php',
  'retenciones.php',
  'zonas-delivery.php',
  'usuarios.php'
]);
$isConfigActive = (!empty($configPages) && is_array($configPages)) ? in_array($currentPage, $configPages) : false;
?>
<aside class="sidebar">
  <ul class="sidebar-nav">

    <!-- Inicio -->
    <li class="sidebar-item">
      <a href="<?= $menuLink ?>" class="sidebar-link <?= ($currentPage === 'menu.php') ? 'active' : '' ?>">
        <span>🏠 Inicio</span>
      </a>
    </li>

    <!-- MÓDULO 1: Gestión de Identidad ▸ -->
    <li class="sidebar-item <?= $isIdentidadActive ? '' : 'collapsed' ?>" id="menuIdentidad">
      <div class="sidebar-link <?= $isIdentidadActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuIdentidad')">
        <span>👥 Gestión de Identidad</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $identidadPfx ?>clientes/clientes.php"
            class="sidebar-sublink <?= ($currentPage === 'clientes.php') ? 'active' : '' ?>"><span>👥</span>
            Clientes</a></li>
        <li><a href="<?= $identidadPfx ?>proveedores/proveedores.php"
            class="sidebar-sublink <?= ($currentPage === 'proveedores.php') ? 'active' : '' ?>"><span>🏭</span>
            Proveedores</a></li>
        <li><a href="<?= $identidadPfx ?>personal/personal.php"
            class="sidebar-sublink <?= ($currentPage === 'personal.php') ? 'active' : '' ?>"><span>👔</span> Personal y
            Repartidores</a></li>
      </ul>
    </li>

    <!-- MÓDULO 2: Seguridad y Control ▸ -->
    <li class="sidebar-item <?= $isSeguridadActive ? '' : 'collapsed' ?>" id="menuSeguridad">
      <div class="sidebar-link <?= $isSeguridadActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuSeguridad')">
        <span>🛡️ Seguridad y Control</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $seguridadPfx ?>roles/roles.php"
            class="sidebar-sublink <?= ($currentPage === 'roles.php') ? 'active' : '' ?>"><span>🔑</span> Roles y
            Permisos</a></li>
        <li><a href="#" class="sidebar-sublink"
            onclick="alert('Bitácora de Auditoría Forense en desarrollo'); return false;"><span>🔍</span> Auditoría
            Forense</a></li>
        <li><a href="#" class="sidebar-sublink"
            onclick="alert('Control de Accesos y Sesiones en desarrollo'); return false;"><span>🔐</span> Control de
            Accesos</a></li>
      </ul>
    </li>

    <!-- MÓDULO 3: Inventario & Stock (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isInventarioActive ? '' : 'collapsed' ?>" id="menuInventario">
      <div class="sidebar-link <?= $isInventarioActive ? 'active' : '' ?>"
        onclick="toggleSidebarMenu('menuInventario')">
        <span>📦 Inventario &amp; Stock</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $inventarioPfx ?>lotes/lotes.php"
            class="sidebar-sublink <?= ($currentPage === 'lotes.php') ? 'active' : '' ?>"><span>⏳</span> Lotes y
            Vencimientos</a></li>
        <li><a href="<?= $inventarioPfx ?>Movimientos/movimientos.php"
            class="sidebar-sublink <?= ($currentPage === 'movimientos.php') ? 'active' : '' ?>"><span>⚖️</span> Ajustes
            y Mermas</a></li>
        <li><a href="<?= $inventarioPfx ?>Traslados/traslados.php"
            class="sidebar-sublink <?= ($currentPage === 'traslados.php') ? 'active' : '' ?>"><span>🚚</span> Traslados
            entre Almacenes</a></li>
      </ul>
    </li>

    <!-- MÓDULO 4: Ventas y Facturación (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isVentasActive ? '' : 'collapsed' ?>" id="menuVentas">
      <div class="sidebar-link <?= $isVentasActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuVentas')">
        <span>🛒 Ventas y Facturación</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <?php if (!function_exists('usuarioPuedeAccederControlCaja') || usuarioPuedeAccederControlCaja()): ?>
          <li><a href="<?= $ventasPfx ?>ControlCaja/control_caja.php" class="sidebar-sublink <?= ($currentPage === 'control_caja.php') ? 'active' : '' ?>"><span>🔒</span> Control de Caja</a></li>
        <?php endif; ?>
        <li><a href="<?= $ventasPfx ?>Facturacion/facturacion.php" class="sidebar-sublink <?= ($currentPage === 'facturacion.php') ? 'active' : '' ?>"><span>🛒</span> Facturación (POS)</a></li>
        <li><a href="<?= $ventasPfx ?>Delivery/delivery.php" class="sidebar-sublink <?= ($currentPage === 'delivery.php') ? 'active' : '' ?>"><span>🛵</span> Despacho Delivery</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Historial de Ventas en desarrollo'); return false;"><span>🧾</span> Historial de Ventas</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso 2: Cotizaciones en desarrollo'); return false;"><span>📝</span> Cotizaciones</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso 5: Consignaciones en desarrollo'); return false;"><span>🍾</span> Consignaciones</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso 6: Devoluciones en desarrollo'); return false;"><span>🔄</span> Devoluciones</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso de Cuentas por Cobrar (CxC) en desarrollo'); return false;"><span>💳</span> Cuentas por Cobrar (CxC)</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso 8: Notas de Débito en desarrollo'); return false;"><span>📄</span> Notas de Débito</a></li>
      </ul>
    </li>

    <!-- MÓDULO 5: Compras y Recepción (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isComprasActive ? '' : 'collapsed' ?>" id="menuCompras">
      <div class="sidebar-link <?= $isComprasActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuCompras')">
        <span>📥 Compras y Recepción</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $comprasPfx ?>ordenes_compra/ordenes_compra.php" class="sidebar-sublink <?= ($currentPage === 'ordenes_compra.php') ? 'active' : '' ?>"><span>📥</span> Órdenes de Compra</a></li>
        <li><a href="<?= $comprasPfx ?>recepciones/recepciones.php" class="sidebar-sublink <?= ($currentPage === 'recepciones.php') ? 'active' : '' ?>"><span>🏷️</span> Recepción Check-in</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso de Cuentas por Pagar (CxP) en desarrollo'); return false;"><span>🏛️</span> Cuentas por Pagar (CxP)</a></li>
      </ul>
    </li>

    <!-- MÓDULO 6: Gestión de Promociones (PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isPromocionesActive ? '' : 'collapsed' ?>" id="menuPromociones">
      <div class="sidebar-link <?= $isPromocionesActive ? 'active' : '' ?>"
        onclick="toggleSidebarMenu('menuPromociones')">
        <span>🎁 Gestión de Promociones</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $promoPfx ?>promociones.php"
            class="sidebar-sublink <?= ($currentPage === 'promociones.php') ? 'active' : '' ?>"><span>🎁</span> Campañas
            y Reglas</a></li>
        <li><a href="<?= $promoPfx ?>cupones.php"
            class="sidebar-sublink <?= ($currentPage === 'cupones.php') ? 'active' : '' ?>"><span>🏷️</span> Cupones
            &amp; Descuentos</a></li>
      </ul>
    </li>

    <!-- MÓDULO 7: Movimientos Bancarios (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isBancosActive ? '' : 'collapsed' ?>" id="menuBancos">
      <div class="sidebar-link <?= $isBancosActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuBancos')">
        <span>🏦 Movimientos Bancarios</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink"
            onclick="alert('Depósito de Arqueos en Cuentas Bancarias en desarrollo'); return false;"><span>💰</span>
            Depósitos de Arqueos</a></li>
      </ul>
    </li>

    <!-- Reportes & Auditoría ▸ -->
    <li class="sidebar-item collapsed" id="menuReportes">
      <div class="sidebar-link" onclick="toggleSidebarMenu('menuReportes')">
        <span>📊 Reportes &amp; Auditoría</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink"
            onclick="alert('Reportes Fiscales DGII 606 y 607'); return false;"><span>📜</span> Fiscal DGII (606 /
            607)</a></li>
        <li><a href="#" class="sidebar-sublink"
            onclick="alert('Kardex FEFO en desarrollo'); return false;"><span>📊</span> Kardex Valorado FEFO</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Auditoría Forense'); return false;"><span>🛡️</span>
            Registro de Auditoría</a></li>
      </ul>
    </li>

    <!-- ======================================================= -->
    <!-- PANEL DE CONFIGURACIÓN (UBICADO ABAJO DE REPORTES)       -->
    <!-- ======================================================= -->
    <li class="sidebar-item <?= $isConfigOnlyActive ? '' : 'collapsed' ?>" id="menuConfiguracion">
      <div class="sidebar-link <?= $isConfigOnlyActive ? 'active' : '' ?>"
        onclick="toggleSidebarMenu('menuConfiguracion')" style="cursor: pointer;">
        <span>⚙️ Configuración</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $cfgPfx ?>usuarios/usuarios.php"
            class="sidebar-sublink <?= ($currentPage === 'usuarios.php') ? 'active' : '' ?>"><span>👤</span>
            Usuarios</a></li>
        <li><a href="<?= $cfgPfx ?>clasificaciones/clasificaciones.php"
            class="sidebar-sublink <?= ($currentPage === 'clasificaciones.php') ? 'active' : '' ?>"><span>🏷️</span>
            Marcas y Categorías</a></li>
        <li><a href="<?= $cfgPfx ?>productos/productos.php"
            class="sidebar-sublink <?= ($currentPage === 'productos.php') ? 'active' : '' ?>"><span>🍾</span> Maestro de
            Productos</a></li>
        <li><a href="<?= $cfgPfx ?>presentaciones/presentaciones.php"
            class="sidebar-sublink <?= ($currentPage === 'presentaciones.php') ? 'active' : '' ?>"><span>📦</span>
            Presentaciones</a></li>
        <li><a href="<?= $cfgPfx ?>almacenes/almacenes.php"
            class="sidebar-sublink <?= ($currentPage === 'almacenes.php') ? 'active' : '' ?>"><span>🏢</span> Almacenes
            Físicos</a></li>
        <li><a href="<?= $cfgPfx ?>cajas/cajas.php"
            class="sidebar-sublink <?= ($currentPage === 'cajas.php') ? 'active' : '' ?>"><span>💻</span> Cajas
            Registradoras</a></li>
        <?php if (!function_exists('usuarioPuedeAccederSecuenciasFiscales') || usuarioPuedeAccederSecuenciasFiscales()): ?>
          <li><a href="<?= $cfgPfx ?>secuencias-dgii/secuencias-dgii.php"
              class="sidebar-sublink <?= ($currentPage === 'secuencias-dgii.php') ? 'active' : '' ?>"><span>📜</span>
              Secuencias Fiscales</a></li>
        <?php endif; ?>
        <?php if (!function_exists('usuarioPuedeAccederMonedas') || usuarioPuedeAccederMonedas()): ?>
          <li><a href="<?= $cfgPfx ?>monedas/monedas.php"
              class="sidebar-sublink <?= ($currentPage === 'monedas.php') ? 'active' : '' ?>"><span>💱</span> Monedas y
              Tasas</a></li>
        <?php endif; ?>
        <?php if (!function_exists('usuarioPuedeAccederImpuestos') || usuarioPuedeAccederImpuestos()): ?>
          <li><a href="<?= $cfgPfx ?>impuestos/impuestos.php"
              class="sidebar-sublink <?= ($currentPage === 'impuestos.php') ? 'active' : '' ?>"><span>🧾</span> Impuestos
              y Tributos</a></li>
        <?php endif; ?>
        <?php if (!function_exists('usuarioPuedeAccederRetenciones') || usuarioPuedeAccederRetenciones()): ?>
          <li><a href="<?= $cfgPfx ?>retenciones/retenciones.php"
              class="sidebar-sublink <?= ($currentPage === 'retenciones.php') ? 'active' : '' ?>"><span>🏛️</span>
              Retenciones DGII</a></li>
        <?php endif; ?>
        <li><a href="<?= $cfgPfx ?>cuentas-bancarias/cuentas-bancarias.php"
            class="sidebar-sublink <?= ($currentPage === 'cuentas-bancarias.php') ? 'active' : '' ?>"><span>🏦</span>
            Cuentas Bancarias</a></li>
        <?php if (!function_exists('usuarioPuedeAccederMetodosPago') || usuarioPuedeAccederMetodosPago()): ?>
          <li><a href="<?= $cfgPfx ?>metodos-pago/metodos-pago.php"
              class="sidebar-sublink <?= ($currentPage === 'metodos-pago.php') ? 'active' : '' ?>"><span>💳</span> Métodos
              de Pago</a></li>
        <?php endif; ?>
        <li><a href="<?= $cfgPfx ?>zonas-delivery/zonas-delivery.php"
            class="sidebar-sublink <?= ($currentPage === 'zonas-delivery.php') ? 'active' : '' ?>"><span>📍</span> Zonas
            Delivery</a></li>
      </ul>
    </li>

  </ul>

  <!-- Pie de la barra lateral: Cerrar sesión -->
  <div class="sidebar-footer">
    <a href="<?= $logoutLink ?>" class="logout-link">
      <span>🚪</span>
      <span>Cerrar Sesión</span>
    </a>
  </div>
</aside>

<!-- Overlay para celulares -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

<script>
  if (typeof toggleSidebarMenu !== 'function') {
    function toggleSidebarMenu(menuId) {
      const item = document.getElementById(menuId);
      if (!item) return;

      const willOpen = item.classList.contains('collapsed');

      // Comportamiento acordeón: cerrar los demás módulos abiertos
      document.querySelectorAll('.sidebar-nav > .sidebar-item').forEach(el => {
        if (el !== item && el.classList.contains('sidebar-item')) {
          el.classList.add('collapsed');
        }
      });

      // Abrir el módulo clickeado (o cerrarlo si ya estaba abierto)
      if (willOpen) {
        item.classList.remove('collapsed');
      } else {
        item.classList.add('collapsed');
      }
    }
  }

  if (typeof toggleMobileSidebar !== 'function') {
    function toggleMobileSidebar() {
      const sidebar = document.querySelector('.sidebar');
      const overlay = document.getElementById('sidebarOverlay');
      if (sidebar) sidebar.classList.toggle('mobile-open');
      if (overlay) overlay.classList.toggle('active');
    }
  }

  // =========================================================================
  // VALIDACIÓN ESTRICTA GLOBAL: CAMPOS NUMÉRICOS (SOLO NÚMEROS) Y FORMATOS
  // =========================================================================
  (function () {
    const navKeys = ['Backspace', 'Delete', 'Tab', 'Escape', 'Enter', 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];

    const integerIds = new Set([
      'factor_multiplicador', 'secuencia_desde', 'secuencia_hasta', 'secuencia_actual',
      'tiempo_promedio_min', 'dias_credito_habitual', 'regla_cantidad', 'rep_capacidad_pedidos',
      'codigo_barra', 'numero_cuenta', 'pin_autorizacion', 'cantidad_actual', 'orden_calculo'
    ]);

    const phoneIds = new Set([
      'telefono_principal', 'emp_telefono_principal', 'rep_telefono_principal', 'cliRapidoTelefono'
    ]);

    const docIds = new Set([
      'documento_identidad', 'emp_documento_identidad', 'cliRapidoDocumento'
    ]);

    const codeDashIds = new Set([
      'codigo_cliente', 'codigo_proveedor', 'emp_codigo_empleado', 'sku', 'rep_placa_vehiculo'
    ]);

    function obtenerTipoDocAsociado(el) {
      if (el.id === 'emp_documento_identidad') {
        return (document.getElementById('emp_tipo_documento')?.value || 'CEDULA').toUpperCase();
      }
      if (el.id === 'cliRapidoDocumento') {
        return (document.getElementById('cliRapidoTipoDoc')?.value || 'CEDULA').toUpperCase();
      }
      return (document.getElementById('tipo_documento')?.value || 'CEDULA').toUpperCase();
    }

    document.addEventListener('keydown', function (e) {
      const el = e.target;
      if (!el || el.tagName !== 'INPUT') return;
      if (navKeys.includes(e.key) || e.ctrlKey || e.metaKey || e.altKey) return;

      const id = el.id || '';

      // 1. Enteros estrictos, código de barra, número de cuenta, licencia y teléfonos: solo dígitos 0-9
      if (integerIds.has(id) || phoneIds.has(id) || id === 'rep_numero_licencia' || el.classList.contains('pos-qty-input')) {
        if (!/^[0-9]$/.test(e.key)) {
          e.preventDefault();
        }
        return;
      }

      // 2. Documentos (Cédula / RNC = solo dígitos; Pasaporte = alfanumérico)
      if (docIds.has(id)) {
        const tipoDoc = obtenerTipoDocAsociado(el);
        if (tipoDoc === 'CEDULA' || tipoDoc === 'RNC') {
          if (!/^[0-9]$/.test(e.key)) {
            e.preventDefault();
          }
        } else {
          if (!/^[0-9a-zA-Z]$/.test(e.key)) {
            e.preventDefault();
          }
        }
        return;
      }

      // 3. Códigos estructurados (PRO-001, CLI-001, EMP-001, SKU, Placa): solo letras, números y guion
      if (codeDashIds.has(id)) {
        if (!/^[0-9a-zA-Z-]$/.test(e.key)) {
          e.preventDefault();
        }
        return;
      }

      // 4. Inputs numéricos decimales (type="number"): solo dígitos 0-9 y un único punto decimal (bloquear e, E, +, -)
      if (el.type === 'number') {
        const step = el.getAttribute('step') || '';
        const permiteDecimal = (step === 'any' || step.includes('.'));
        if (permiteDecimal) {
          if (!/^[0-9.]$/.test(e.key) || (e.key === '.' && String(el.value).includes('.'))) {
            e.preventDefault();
          }
        } else {
          if (!/^[0-9]$/.test(e.key)) {
            e.preventDefault();
          }
        }
      }
    }, true);

    document.addEventListener('input', function (e) {
      const el = e.target;
      if (!el || el.tagName !== 'INPUT') return;
      const id = el.id || '';

      // Sanitizar enteros estrictos al pegar
      if (integerIds.has(id) || el.classList.contains('pos-qty-input')) {
        const maxLen = (id === 'codigo_barra') ? 14 : ((id === 'numero_cuenta') ? 20 : 12);
        const limpio = el.value.replace(/[^0-9]/g, '').slice(0, maxLen);
        if (el.value !== limpio) el.value = limpio;
        return;
      }

      // Sanitizar códigos con guion (PRO-001, CLI-001, EMP-001, SKU, Placa)
      if (codeDashIds.has(id)) {
        const limpio = el.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
        if (el.value !== limpio) el.value = limpio;
      }
    }, true);
  })();
</script>