<?php
/**
 * ============================================================================
 * Barra Lateral de Navegación Unificada (Sidebar Principal con RBAC)
 * Ubicación: Raíz del proyecto (c:\xampp\htdocs\Liquor_Store\sidebar.php)
 * House & Ice • Sistema Administrativo (ERP Licorero)
 * ============================================================================
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir = basename(dirname($_SERVER['PHP_SELF']));

// Detección de nivel de carpeta respecto a la raíz del proyecto (Liquor_Store/)
$isSubdir = in_array($currentDir, [
  'almacenes',
  'cajas',
  'clasificaciones',
  'clientes',
  'cuentas-bancarias',
  'impuestos',
  'lotes',
  'metodos-pago',
  'monedas',
  'personal',
  'presentaciones',
  'productos',
  'proveedores',
  'retenciones',
  'roles',
  'secuencias-dgii',
  'usuarios',
  'zonas-delivery',
  'Delivery',
  'Facturacion',
  'ControlCaja',
  'Movimientos',
  'Traslados',
  'Lotes',
  'ordenes_compra',
  'recepciones',
  'cuentas_por_pagar',
  'CuentasPorCobrar',
  'cuentas_por_cobrar',
  'Consignaciones',
  'NotasDebito',
  'AuditoriaForense'
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

// ============================================================================
// EVALUACIÓN DE PERMISOS RBAC PARA VISIBILIDAD DEL MENÚ
// ============================================================================
$esAdmin = function_exists('usuarioEsAdministrador') ? usuarioEsAdministrador() : false;
$tienePermiso = static function (string $clave): bool {
  return function_exists('usuarioTienePermiso') && usuarioTienePermiso($clave);
};

// Módulo 1: Identidad
$pClientes = function_exists('usuarioPuedeAccederClientes') ? usuarioPuedeAccederClientes() : true;
$pProveedores = function_exists('usuarioPuedeAccederProveedores') ? usuarioPuedeAccederProveedores() : $esAdmin;
$pPersonal = function_exists('usuarioPuedeAccederPersonal') ? usuarioPuedeAccederPersonal() : $esAdmin;
$pUsuarios = function_exists('usuarioPuedeAccederSeguridad') ? usuarioPuedeAccederSeguridad() : $esAdmin;
$verIdentidad = $pClientes || $pProveedores || $pPersonal || $pUsuarios;

// Módulo 2: Seguridad
$pSeguridad = function_exists('usuarioPuedeAccederSeguridad') ? usuarioPuedeAccederSeguridad() : $esAdmin;
$pAuditoria = function_exists('usuarioPuedeAccederAuditoria') ? usuarioPuedeAccederAuditoria() : $esAdmin;
$verSeguridad = $pSeguridad || $pAuditoria;

// Módulo 3: Inventario & Stock (Procesos)
$pLotes = function_exists('usuarioPuedeAccederLotes') ? usuarioPuedeAccederLotes() : $esAdmin;
$pMovInv = function_exists('usuarioPuedeAccederMovimientosInventario') ? usuarioPuedeAccederMovimientosInventario() : $esAdmin;
$pTraslados = function_exists('usuarioPuedeAccederTraslados') ? usuarioPuedeAccederTraslados() : $esAdmin;
$verInventario = $pLotes || $pMovInv || $pTraslados;

// Módulo 4: Ventas y Facturación (Procesos)
$pControlCaja = function_exists('usuarioPuedeAccederControlCaja') ? usuarioPuedeAccederControlCaja() : true;
$pFacturacion = function_exists('usuarioPuedeAccederFacturacion') ? usuarioPuedeAccederFacturacion() : true;
$pDelivery = function_exists('usuarioPuedeAccederDelivery') ? usuarioPuedeAccederDelivery() : $esAdmin;
$pCotizaciones = function_exists('usuarioPuedeAccederCotizaciones') ? usuarioPuedeAccederCotizaciones() : $esAdmin;
$pConsignaciones = function_exists('usuarioPuedeAccederConsignaciones') ? usuarioPuedeAccederConsignaciones() : $esAdmin;
$pDevoluciones = function_exists('usuarioPuedeAccederDevoluciones') ? usuarioPuedeAccederDevoluciones() : $esAdmin;
$pCxc = function_exists('usuarioPuedeAccederCxc') ? usuarioPuedeAccederCxc() : $esAdmin;
$pNotaDebito = $esAdmin || $tienePermiso('EMITIR_NOTA_DEBITO') || $tienePermiso('GESTIONAR_CXC');
$verVentas = $pControlCaja || $pFacturacion || $pDelivery || $pCotizaciones || $pConsignaciones || $pDevoluciones || $pCxc || $pNotaDebito;

// Módulo 5: Compras y Recepción (Procesos)
$pOrdenCompra = $esAdmin || $tienePermiso('CREAR_ORDEN_COMPRA');
$pRecepcion = $esAdmin || $tienePermiso('REGISTRAR_RECEPCION_COMPRA');
$pCxp = $esAdmin || $tienePermiso('GESTIONAR_CXP');
$verCompras = $pOrdenCompra || $pRecepcion || $pCxp;

// Módulo 6: Promociones
$verPromociones = function_exists('usuarioPuedeAccederPromociones') ? usuarioPuedeAccederPromociones() : $esAdmin;

// Módulo 7: Movimientos Bancarios
$pBancos = function_exists('usuarioPuedeAccederBancos') ? usuarioPuedeAccederBancos() : $esAdmin;
$verBancos = $pBancos;

// Reportes & Auditoría
$pRepFiscal = $esAdmin || $tienePermiso('EJECUTAR_CIERRES_FISCALES_PERIODO') || $tienePermiso('VER_REPORTES_FINANCIEROS');
$pRepKardex = $pMovInv || $pLotes;
$verReportes = $pRepFiscal || $pRepKardex || $pAuditoria;

// Panel de Configuración (Mantenimientos)
$pCfgProductos = function_exists('usuarioPuedeGestionarProductos') ? usuarioPuedeGestionarProductos() : $esAdmin;
$pCfgInfra = function_exists('usuarioPuedeAccederConfigInfraestructura') ? usuarioPuedeAccederConfigInfraestructura() : $esAdmin;
$pCfgSecuencias = function_exists('usuarioPuedeAccederSecuenciasFiscales') ? usuarioPuedeAccederSecuenciasFiscales() : $esAdmin;
$pCfgMonedas = function_exists('usuarioPuedeAccederMonedas') ? usuarioPuedeAccederMonedas() : $esAdmin;
$pCfgImpuestos = function_exists('usuarioPuedeAccederImpuestos') ? usuarioPuedeAccederImpuestos() : $esAdmin;
$pCfgRetenciones = function_exists('usuarioPuedeAccederRetenciones') ? usuarioPuedeAccederRetenciones() : $esAdmin;
$pCfgMetodosPago = function_exists('usuarioPuedeAccederMetodosPago') ? usuarioPuedeAccederMetodosPago() : $esAdmin;
$pCfgBancos = $pBancos;
$verConfig = $pCfgProductos || $pCfgInfra || $pCfgSecuencias || $pCfgMonedas || $pCfgImpuestos || $pCfgRetenciones || $pCfgBancos || $pCfgMetodosPago;

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
  'zonas-delivery.php'
];

// Detección de módulo activo para abrir el acordeón correspondiente
$isIdentidadActive = in_array($currentPage, ['clientes.php', 'proveedores.php', 'personal.php', 'usuarios.php']);
$isSeguridadActive = in_array($currentPage, ['roles.php', 'auditoria.php']);
$isInventarioActive = in_array($currentPage, ['movimientos.php', 'traslados.php', 'lotes.php']);
$isVentasActive = in_array($currentPage, ['delivery.php', 'facturacion.php', 'control_caja.php', 'cuentas_por_cobrar.php', 'consignaciones.php', 'notas_debito.php']);
$isComprasActive = in_array($currentPage, ['ordenes_compra.php', 'recepciones.php', 'cuentas_por_pagar.php']);
$isPromocionesActive = in_array($currentPage, ['promociones.php', 'cupones.php']);
$isBancosActive = in_array($currentPage, ['movimientos_bancarios.php']);
$isConfigOnlyActive = in_array($currentPage, $configPages);
$isConfigActive = $isConfigOnlyActive;
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
    <?php if ($verIdentidad): ?>
      <li class="sidebar-item <?= $isIdentidadActive ? '' : 'collapsed' ?>" id="menuIdentidad">
        <div class="sidebar-link <?= $isIdentidadActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuIdentidad')">
          <span>👥 Gestión de Identidad</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pUsuarios): ?>
            <li><a href="<?= $identidadPfx ?>usuarios/usuarios.php"
                class="sidebar-sublink <?= ($currentPage === 'usuarios.php') ? 'active' : '' ?>"><span>👤</span>
                Usuarios y Accesos</a></li>
          <?php endif; ?>
          <?php if ($pClientes): ?>
            <li><a href="<?= $identidadPfx ?>clientes/clientes.php"
                class="sidebar-sublink <?= ($currentPage === 'clientes.php') ? 'active' : '' ?>"><span>👥</span>
                Clientes</a></li>
          <?php endif; ?>
          <?php if ($pProveedores): ?>
            <li><a href="<?= $identidadPfx ?>proveedores/proveedores.php"
                class="sidebar-sublink <?= ($currentPage === 'proveedores.php') ? 'active' : '' ?>"><span>🏭</span>
                Proveedores</a></li>
          <?php endif; ?>
          <?php if ($pPersonal): ?>
            <li><a href="<?= $identidadPfx ?>personal/personal.php"
                class="sidebar-sublink <?= ($currentPage === 'personal.php') ? 'active' : '' ?>"><span>👔</span> Personal y
                Repartidores</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- MÓDULO 2: Seguridad y Control ▸ -->
    <?php if ($verSeguridad): ?>
      <li class="sidebar-item <?= $isSeguridadActive ? '' : 'collapsed' ?>" id="menuSeguridad">
        <div class="sidebar-link <?= $isSeguridadActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuSeguridad')">
          <span>🛡️ Seguridad y Control</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pSeguridad): ?>
            <li><a href="<?= $seguridadPfx ?>roles/roles.php"
                class="sidebar-sublink <?= ($currentPage === 'roles.php') ? 'active' : '' ?>"><span>🔑</span> Roles y
                Permisos</a></li>
          <?php endif; ?>
          <?php if ($pAuditoria): ?>
            <li><a href="<?= $seguridadPfx ?>AuditoriaForense/auditoria.php"
                class="sidebar-sublink <?= ($currentPage === 'auditoria.php') ? 'active' : '' ?>"><span>🔍</span> Auditoría
                Forense</a></li>
          <?php endif; ?>
          <?php if ($pSeguridad): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Control de Accesos y Sesiones en desarrollo'); return false;"><span>🔐</span> Control de
                Accesos</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- MÓDULO 3: Inventario & Stock (SOLO PROCESOS) ▸ -->
    <?php if ($verInventario): ?>
      <li class="sidebar-item <?= $isInventarioActive ? '' : 'collapsed' ?>" id="menuInventario">
        <div class="sidebar-link <?= $isInventarioActive ? 'active' : '' ?>"
          onclick="toggleSidebarMenu('menuInventario')">
          <span>📦 Inventario &amp; Stock</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pLotes): ?>
            <li><a href="<?= $inventarioPfx ?>lotes/lotes.php"
                class="sidebar-sublink <?= ($currentPage === 'lotes.php') ? 'active' : '' ?>"><span>⏳</span> Lotes y
                Vencimientos</a></li>
          <?php endif; ?>
          <?php if ($pMovInv): ?>
            <li><a href="<?= $inventarioPfx ?>Movimientos/movimientos.php"
                class="sidebar-sublink <?= ($currentPage === 'movimientos.php') ? 'active' : '' ?>"><span>⚖️</span> Ajustes
                y Mermas</a></li>
          <?php endif; ?>
          <?php if ($pTraslados): ?>
            <li><a href="<?= $inventarioPfx ?>Traslados/traslados.php"
                class="sidebar-sublink <?= ($currentPage === 'traslados.php') ? 'active' : '' ?>"><span>🚚</span> Traslados
                entre Almacenes</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- MÓDULO 4: Ventas y Facturación (SOLO PROCESOS) ▸ -->
    <?php if ($verVentas): ?>
      <li class="sidebar-item <?= $isVentasActive ? '' : 'collapsed' ?>" id="menuVentas">
        <div class="sidebar-link <?= $isVentasActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuVentas')">
          <span>🛒 Ventas y Facturación</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pControlCaja): ?>
            <li><a href="<?= $ventasPfx ?>ControlCaja/control_caja.php"
                class="sidebar-sublink <?= ($currentPage === 'control_caja.php') ? 'active' : '' ?>"><span>🔒</span> Control
                de Caja</a></li>
          <?php endif; ?>
          <?php if ($pFacturacion): ?>
            <li><a href="<?= $ventasPfx ?>Facturacion/facturacion.php"
                class="sidebar-sublink <?= ($currentPage === 'facturacion.php') ? 'active' : '' ?>"><span>🛒</span>
                Facturación (POS)</a></li>
          <?php endif; ?>
          <?php if ($pDelivery): ?>
            <li><a href="<?= $ventasPfx ?>Delivery/delivery.php"
                class="sidebar-sublink <?= ($currentPage === 'delivery.php') ? 'active' : '' ?>"><span>🛵</span> Despacho
                Delivery</a></li>
          <?php endif; ?>
          <?php if ($pFacturacion): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Historial de Ventas en desarrollo'); return false;"><span>🧾</span> Historial de Ventas</a>
            </li>
          <?php endif; ?>
          <?php if ($pCotizaciones): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Proceso 2: Cotizaciones en desarrollo'); return false;"><span>📝</span> Cotizaciones</a>
            </li>
          <?php endif; ?>
          <?php if ($pConsignaciones): ?>
            <li><a href="<?= $ventasPfx ?>Consignaciones/consignaciones.php"
                class="sidebar-sublink <?= ($currentPage === 'consignaciones.php') ? 'active' : '' ?>"><span>🍾</span> Consignaciones</a>
            </li>
          <?php endif; ?>
          <?php if ($pDevoluciones): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Proceso 6: Devoluciones en desarrollo'); return false;"><span>🔄</span> Devoluciones</a>
            </li>
          <?php endif; ?>
          <?php if ($pCxc): ?>
            <li><a href="<?= $ventasPfx ?>CuentasPorCobrar/cuentas_por_cobrar.php"
                class="sidebar-sublink <?= ($currentPage === 'cuentas_por_cobrar.php') ? 'active' : '' ?>"><span>💳</span> Cuentas
                por Cobrar (CxC)</a></li>
          <?php endif; ?>
          <?php if ($pNotaDebito): ?>
            <li><a href="<?= $ventasPfx ?>NotasDebito/notas_debito.php"
                class="sidebar-sublink <?= ($currentPage === 'notas_debito.php') ? 'active' : '' ?>"><span>📄</span> Notas de
                Débito (B03)</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- MÓDULO 5: Compras y Recepción (SOLO PROCESOS) ▸ -->
    <?php if ($verCompras): ?>
      <li class="sidebar-item <?= $isComprasActive ? '' : 'collapsed' ?>" id="menuCompras">
        <div class="sidebar-link <?= $isComprasActive ? 'active' : '' ?>" onclick="toggleSidebarMenu('menuCompras')">
          <span>📥 Compras y Recepción</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pOrdenCompra): ?>
            <li><a href="<?= $comprasPfx ?>ordenes_compra/ordenes_compra.php" class="sidebar-sublink <?= ($currentPage === 'ordenes_compra.php') ? 'active' : '' ?>"><span>📥</span> Órdenes de Compra</a></li>
          <?php endif; ?>
          <?php if ($pRecepcion): ?>
            <li><a href="<?= $comprasPfx ?>recepciones/recepciones.php" class="sidebar-sublink <?= ($currentPage === 'recepciones.php') ? 'active' : '' ?>"><span>🏷️</span> Recepción Check-in</a></li>
          <?php endif; ?>
          <?php if ($pCxp): ?>
            <li><a href="<?= $comprasPfx ?>cuentas_por_pagar/cuentas_por_pagar.php" class="sidebar-sublink <?= ($currentPage === 'cuentas_por_pagar.php') ? 'active' : '' ?>"><span>🏛️</span> Cuentas por Pagar (CxP)</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- MÓDULO 6: Gestión de Promociones (PROCESOS) ▸ -->
    <?php if ($verPromociones): ?>
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
    <?php endif; ?>

    <!-- MÓDULO 7: Movimientos Bancarios (SOLO PROCESOS) ▸ -->
    <?php if ($verBancos): ?>
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
    <?php endif; ?>

    <!-- Reportes & Auditoría ▸ -->
    <?php if ($verReportes): ?>
      <li class="sidebar-item collapsed" id="menuReportes">
        <div class="sidebar-link" onclick="toggleSidebarMenu('menuReportes')">
          <span>📊 Reportes &amp; Auditoría</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pRepFiscal): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Reportes Fiscales DGII 606 y 607'); return false;"><span>📜</span> Fiscal DGII (606 /
                607)</a></li>
          <?php endif; ?>
          <?php if ($pRepKardex): ?>
            <li><a href="#" class="sidebar-sublink"
                onclick="alert('Kardex FEFO en desarrollo'); return false;"><span>📊</span> Kardex Valorado FEFO</a></li>
          <?php endif; ?>
          <?php if ($pAuditoria): ?>
            <li><a href="<?= $seguridadPfx ?>AuditoriaForense/auditoria.php"
                class="sidebar-sublink <?= ($currentPage === 'auditoria.php') ? 'active' : '' ?>"><span>🛡️</span>
                Registro de Auditoría</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

    <!-- ======================================================= -->
    <!-- PANEL DE CONFIGURACIÓN (UBICADO ABAJO DE REPORTES)      -->
    <!-- ======================================================= -->
    <?php if ($verConfig): ?>
      <li class="sidebar-item <?= $isConfigOnlyActive ? '' : 'collapsed' ?>" id="menuConfiguracion">
        <div class="sidebar-link <?= $isConfigOnlyActive ? 'active' : '' ?>"
          onclick="toggleSidebarMenu('menuConfiguracion')" style="cursor: pointer;">
          <span>⚙️ Configuración</span>
          <span class="sidebar-chevron">▸</span>
        </div>
        <ul class="sidebar-subnav">
          <?php if ($pCfgProductos): ?>
            <li><a href="<?= $cfgPfx ?>clasificaciones/clasificaciones.php"
                class="sidebar-sublink <?= ($currentPage === 'clasificaciones.php') ? 'active' : '' ?>"><span>🏷️</span>
                Marcas y Categorías</a></li>
            <li><a href="<?= $cfgPfx ?>productos/productos.php"
                class="sidebar-sublink <?= ($currentPage === 'productos.php') ? 'active' : '' ?>"><span>🍾</span> Maestro de
                Productos</a></li>
            <li><a href="<?= $cfgPfx ?>presentaciones/presentaciones.php"
                class="sidebar-sublink <?= ($currentPage === 'presentaciones.php') ? 'active' : '' ?>"><span>📦</span>
                Presentaciones</a></li>
          <?php endif; ?>
          <?php if ($pCfgInfra): ?>
            <li><a href="<?= $cfgPfx ?>almacenes/almacenes.php"
                class="sidebar-sublink <?= ($currentPage === 'almacenes.php') ? 'active' : '' ?>"><span>🏢</span> Almacenes
                Físicos</a></li>
            <li><a href="<?= $cfgPfx ?>cajas/cajas.php"
                class="sidebar-sublink <?= ($currentPage === 'cajas.php') ? 'active' : '' ?>"><span>💻</span> Cajas
                Registradoras</a></li>
          <?php endif; ?>
          <?php if ($pCfgSecuencias): ?>
            <li><a href="<?= $cfgPfx ?>secuencias-dgii/secuencias-dgii.php"
                class="sidebar-sublink <?= ($currentPage === 'secuencias-dgii.php') ? 'active' : '' ?>"><span>📜</span>
                Secuencias Fiscales</a></li>
          <?php endif; ?>
          <?php if ($pCfgMonedas): ?>
            <li><a href="<?= $cfgPfx ?>monedas/monedas.php"
                class="sidebar-sublink <?= ($currentPage === 'monedas.php') ? 'active' : '' ?>"><span>💱</span> Monedas y
                Tasas</a></li>
          <?php endif; ?>
          <?php if ($pCfgImpuestos): ?>
            <li><a href="<?= $cfgPfx ?>impuestos/impuestos.php"
                class="sidebar-sublink <?= ($currentPage === 'impuestos.php') ? 'active' : '' ?>"><span>🧾</span> Impuestos
                y Tributos</a></li>
          <?php endif; ?>
          <?php if ($pCfgRetenciones): ?>
            <li><a href="<?= $cfgPfx ?>retenciones/retenciones.php"
                class="sidebar-sublink <?= ($currentPage === 'retenciones.php') ? 'active' : '' ?>"><span>🏛️</span>
                Retenciones DGII</a></li>
          <?php endif; ?>
          <?php if ($pCfgBancos): ?>
            <li><a href="<?= $cfgPfx ?>cuentas-bancarias/cuentas-bancarias.php"
                class="sidebar-sublink <?= ($currentPage === 'cuentas-bancarias.php') ? 'active' : '' ?>"><span>🏦</span>
                Cuentas Bancarias</a></li>
          <?php endif; ?>
          <?php if ($pCfgMetodosPago): ?>
            <li><a href="<?= $cfgPfx ?>metodos-pago/metodos-pago.php"
                class="sidebar-sublink <?= ($currentPage === 'metodos-pago.php') ? 'active' : '' ?>"><span>💳</span> Métodos
                de Pago</a></li>
          <?php endif; ?>
          <?php if ($pCfgInfra): ?>
            <li><a href="<?= $cfgPfx ?>zonas-delivery/zonas-delivery.php"
                class="sidebar-sublink <?= ($currentPage === 'zonas-delivery.php') ? 'active' : '' ?>"><span>📍</span> Zonas
                Delivery</a></li>
          <?php endif; ?>
        </ul>
      </li>
    <?php endif; ?>

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

      if (integerIds.has(id) || phoneIds.has(id) || id === 'rep_numero_licencia' || el.classList.contains('pos-qty-input')) {
        if (!/^[0-9]$/.test(e.key)) {
          e.preventDefault();
        }
        return;
      }

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

      if (codeDashIds.has(id)) {
        if (!/^[0-9a-zA-Z-]$/.test(e.key)) {
          e.preventDefault();
        }
        return;
      }

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

      if (integerIds.has(id) || el.classList.contains('pos-qty-input')) {
        const maxLen = (id === 'codigo_barra') ? 14 : ((id === 'numero_cuenta') ? 20 : 12);
        const limpio = el.value.replace(/[^0-9]/g, '').slice(0, maxLen);
        if (el.value !== limpio) el.value = limpio;
        return;
      }

      if (codeDashIds.has(id)) {
        const limpio = el.value.toUpperCase().replace(/[^A-Z0-9-]/g, '');
        if (el.value !== limpio) el.value = limpio;
      }
    }, true);
  })();
</script>