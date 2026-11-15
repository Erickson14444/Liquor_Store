<?php
/**
 * ============================================================================
 * Barra Lateral de Navegación Unificada (Sidebar Principal)
 * Ubicación: Raíz del proyecto (c:\xampp\htdocs\Liquor_Store\sidebar.php)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));

// Detección de nivel de carpeta respecto a la raíz del proyecto (Liquor_Store/)
$isSubdir = in_array($currentDir, [
    'almacenes', 'cajas', 'clasificaciones', 'clientes', 'cuentas-bancarias',
    'metodos-pago', 'personal', 'presentaciones', 'productos',
    'proveedores', 'roles', 'secuencias-dgii', 'usuarios', 'zonas-delivery',
    'Delivery', 'Facturacion'
]);

if ($isSubdir) {
    $rootPfx = '../../';
} elseif ($currentDir === 'Liquor_Store') {
    $rootPfx = './';
} else {
    $rootPfx = '../';
}

$menuLink   = $rootPfx . 'Login y Menu/menu.php';
$logoutLink = $rootPfx . 'Login y Menu/logout.php';
$cfgPfx     = $rootPfx . 'Panel de configuracion/';
$promoPfx   = $rootPfx . 'Gestion de Promociones/';
$ventasPfx  = $rootPfx . 'Ventas y Facturacion/';

// Lista de páginas pertenecientes al panel de configuración
$configPages = [
    'clasificaciones.php', 'productos.php', 'presentaciones.php', 'almacenes.php',
    'clientes.php', 'proveedores.php', 'personal.php',
    'cajas.php', 'secuencias-dgii.php', 'cuentas-bancarias.php', 'metodos-pago.php',
    'zonas-delivery.php',
    'roles.php', 'usuarios.php'
];

// Detección de módulo activo para abrir el acordeón correspondiente
$isIdentidadActive   = in_array($currentPage, ['clientes.php', 'proveedores.php', 'personal.php']);
$isSeguridadActive   = false;
$isInventarioActive  = false;
$isVentasActive      = in_array($currentPage, ['delivery.php', 'facturacion.php']);
$isPromocionesActive = in_array($currentPage, ['promociones.php']);
$isBancosActive      = false;
$isConfigOnlyActive  = in_array($currentPage, [
    'clasificaciones.php', 'productos.php', 'presentaciones.php', 'almacenes.php',
    'cajas.php', 'secuencias-dgii.php', 'cuentas-bancarias.php', 'metodos-pago.php',
    'zonas-delivery.php',
    'roles.php', 'usuarios.php'
]);
$isConfigActive      = (!empty($configPages) && is_array($configPages)) ? in_array($currentPage, $configPages) : false;
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
        <li><a href="<?= $cfgPfx ?>clientes/clientes.php" class="sidebar-sublink <?= ($currentPage === 'clientes.php') ? 'active' : '' ?>"><span>👥</span> Clientes</a></li>
        <li><a href="<?= $cfgPfx ?>proveedores/proveedores.php" class="sidebar-sublink <?= ($currentPage === 'proveedores.php') ? 'active' : '' ?>"><span>🏭</span> Proveedores</a></li>
        <li><a href="<?= $cfgPfx ?>personal/personal.php" class="sidebar-sublink <?= ($currentPage === 'personal.php') ? 'active' : '' ?>"><span>👔</span> Personal y Repartidores</a></li>
      </ul>
    </li>

    <!-- MÓDULO 2: Seguridad y Control (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isSeguridadActive ? '' : 'collapsed' ?>" id="menuSeguridad">
      <div class="sidebar-link <?= $isSeguridadActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuSeguridad')">
        <span>🛡️ Seguridad y Control</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink" onclick="alert('Bitácora de Auditoría Forense en desarrollo'); return false;"><span>🔍</span> Auditoría Forense</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Control de Accesos y Sesiones en desarrollo'); return false;"><span>🔐</span> Control de Accesos</a></li>
      </ul>
    </li>

    <!-- MÓDULO 3: Inventario & Stock (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isInventarioActive ? '' : 'collapsed' ?>" id="menuInventario">
      <div class="sidebar-link <?= $isInventarioActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuInventario')">
        <span>📦 Inventario &amp; Stock</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink" onclick="alert('Control de Lotes y Caducidad FEFO en desarrollo'); return false;"><span>⏳</span> Lotes y FEFO</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Ajustes de Inventario y Mermas en desarrollo'); return false;"><span>⚖️</span> Ajustes y Mermas</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Traslados entre Bodegas en desarrollo'); return false;"><span>🔄</span> Traslados entre Bodegas</a></li>
      </ul>
    </li>

    <!-- MÓDULO 4: Ventas y Facturación (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isVentasActive ? '' : 'collapsed' ?>" id="menuVentas">
      <div class="sidebar-link <?= $isVentasActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuVentas')">
        <span>🛒 Ventas y Facturación</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $ventasPfx ?>Facturacion/facturacion.php" class="sidebar-sublink <?= ($currentPage === 'facturacion.php') ? 'active' : '' ?>"><span>🛒</span> Facturación (POS)</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Apertura y Arqueo Ciego de Caja en desarrollo'); return false;"><span>🔒</span> Apertura y Cierre Caja</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Historial de Facturas en desarrollo'); return false;"><span>🧾</span> Historial de Ventas</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso de Cotizaciones en desarrollo'); return false;"><span>📝</span> Cotizaciones</a></li>
        <li><a href="<?= $ventasPfx ?>Delivery/delivery.php" class="sidebar-sublink <?= ($currentPage === 'delivery.php') ? 'active' : '' ?>"><span>🛵</span> Despacho Delivery</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso de Cuentas por Cobrar (CxC) en desarrollo'); return false;"><span>💳</span> Cuentas por Cobrar (CxC)</a></li>
      </ul>
    </li>

    <!-- MÓDULO 5: Compras y Recepción (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item collapsed" id="menuCompras">
      <div class="sidebar-link" onclick="toggleSidebarMenu('menuCompras')">
        <span>📥 Compras y Recepción</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink" onclick="alert('Órdenes de Compra en desarrollo'); return false;"><span>📥</span> Órdenes de Compra</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Recepción de Mercancía con Lote FEFO en desarrollo'); return false;"><span>🏷️</span> Recepción Check-in</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Proceso de Cuentas por Pagar (CxP) en desarrollo'); return false;"><span>🏛️</span> Cuentas por Pagar (CxP)</a></li>
      </ul>
    </li>

    <!-- MÓDULO 6: Gestión de Promociones (PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isPromocionesActive ? '' : 'collapsed' ?>" id="menuPromociones">
      <div class="sidebar-link <?= $isPromocionesActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuPromociones')">
        <span>🎁 Gestión de Promociones</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $promoPfx ?>promociones.php" class="sidebar-sublink <?= ($currentPage === 'promociones.php') ? 'active' : '' ?>"><span>🎁</span> Campañas y Reglas</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Emisión y Canje de Cupones en desarrollo'); return false;"><span>🏷️</span> Cupones &amp; Descuentos</a></li>
      </ul>
    </li>

    <!-- MÓDULO 7: Movimientos Bancarios (SOLO PROCESOS) ▸ -->
    <li class="sidebar-item <?= $isBancosActive ? '' : 'collapsed' ?>" id="menuBancos">
      <div class="sidebar-link <?= $isBancosActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuBancos')">
        <span>🏦 Movimientos Bancarios</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink" onclick="alert('Depósito de Arqueos en Cuentas Bancarias en desarrollo'); return false;"><span>💰</span> Depósitos de Arqueos</a></li>
      </ul>
    </li>

    <!-- Reportes & Auditoría ▸ -->
    <li class="sidebar-item collapsed" id="menuReportes">
      <div class="sidebar-link" onclick="toggleSidebarMenu('menuReportes')">
        <span>📊 Reportes &amp; Auditoría</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="#" class="sidebar-sublink" onclick="alert('Reportes Fiscales DGII 606 y 607'); return false;"><span>📜</span> Fiscal DGII (606 / 607)</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Kardex FEFO en desarrollo'); return false;"><span>📊</span> Kardex Valorado FEFO</a></li>
        <li><a href="#" class="sidebar-sublink" onclick="alert('Auditoría Forense'); return false;"><span>🛡️</span> Registro de Auditoría</a></li>
      </ul>
    </li>

    <!-- ======================================================= -->
    <!-- PANEL DE CONFIGURACIÓN (UBICADO ABAJO DE REPORTES)       -->
    <!-- ======================================================= -->
    <li class="sidebar-item <?= $isConfigOnlyActive ? '' : 'collapsed' ?>" id="menuConfiguracion">
      <div class="sidebar-link <?= $isConfigOnlyActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuConfiguracion')" style="cursor: pointer;">
        <span>⚙️ Configuración</span>
        <span class="sidebar-chevron">▸</span>
      </div>
      <ul class="sidebar-subnav">
        <li><a href="<?= $cfgPfx ?>clientes/clientes.php" class="sidebar-sublink <?= ($currentPage === 'clientes.php') ? 'active' : '' ?>"><span>👥</span> Clientes</a></li>
        <li><a href="<?= $cfgPfx ?>proveedores/proveedores.php" class="sidebar-sublink <?= ($currentPage === 'proveedores.php') ? 'active' : '' ?>"><span>🏭</span> Proveedores</a></li>
        <li><a href="<?= $cfgPfx ?>personal/personal.php" class="sidebar-sublink <?= ($currentPage === 'personal.php') ? 'active' : '' ?>"><span>👔</span> Personal y Repartidores</a></li>
        <li><a href="<?= $cfgPfx ?>roles/roles.php" class="sidebar-sublink <?= ($currentPage === 'roles.php') ? 'active' : '' ?>"><span>🔑</span> Roles y Permisos</a></li>
        <li><a href="<?= $cfgPfx ?>usuarios/usuarios.php" class="sidebar-sublink <?= ($currentPage === 'usuarios.php') ? 'active' : '' ?>"><span>👤</span> Usuarios</a></li>
        <li><a href="<?= $cfgPfx ?>clasificaciones/clasificaciones.php" class="sidebar-sublink <?= ($currentPage === 'clasificaciones.php') ? 'active' : '' ?>"><span>🏷️</span> Marcas y Categorías</a></li>
        <li><a href="<?= $cfgPfx ?>productos/productos.php" class="sidebar-sublink <?= ($currentPage === 'productos.php') ? 'active' : '' ?>"><span>🍾</span> Maestro de Productos</a></li>
        <li><a href="<?= $cfgPfx ?>presentaciones/presentaciones.php" class="sidebar-sublink <?= ($currentPage === 'presentaciones.php') ? 'active' : '' ?>"><span>📦</span> Presentaciones</a></li>
        <li><a href="<?= $cfgPfx ?>almacenes/almacenes.php" class="sidebar-sublink <?= ($currentPage === 'almacenes.php') ? 'active' : '' ?>"><span>🏢</span> Almacenes Físicos</a></li>
        <li><a href="<?= $cfgPfx ?>cajas/cajas.php" class="sidebar-sublink <?= ($currentPage === 'cajas.php') ? 'active' : '' ?>"><span>💻</span> Cajas POS</a></li>
        <li><a href="<?= $cfgPfx ?>secuencias-dgii/secuencias-dgii.php" class="sidebar-sublink <?= ($currentPage === 'secuencias-dgii.php') ? 'active' : '' ?>"><span>📜</span> Secuencias Fiscales</a></li>
        <li><a href="<?= $cfgPfx ?>cuentas-bancarias/cuentas-bancarias.php" class="sidebar-sublink <?= ($currentPage === 'cuentas-bancarias.php') ? 'active' : '' ?>"><span>🏦</span> Cuentas Bancarias</a></li>
        <li><a href="<?= $cfgPfx ?>metodos-pago/metodos-pago.php" class="sidebar-sublink <?= ($currentPage === 'metodos-pago.php') ? 'active' : '' ?>"><span>💳</span> Métodos de Pago</a></li>
        <li><a href="<?= $cfgPfx ?>zonas-delivery/zonas-delivery.php" class="sidebar-sublink <?= ($currentPage === 'zonas-delivery.php') ? 'active' : '' ?>"><span>📍</span> Zonas Delivery</a></li>
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
</script>

