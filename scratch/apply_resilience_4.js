const fs = require('fs');
const path = require('path');

const dir = 'c:\\Users\\natha\\OneDrive\\Desktop\\Prototipo-pantallas\\Panel de configuracion';

// productos.php
let prod = fs.readFileSync(path.join(dir, 'productos.php'), 'utf8');
prod = prod.replace(/\$productos = \[\];[\s\S]*?\$marcas = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$productos = [];
$categorias = [];
$marcas = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pm.*, 
                   c.nombre AS categoria_nombre, 
                   m.nombre AS marca_nombre,
                   COALESCE((
                       SELECT descripcion_presentacion 
                       FROM inventario.producto_codigos_barra 
                       WHERE producto_maestro_id = pm.id 
                       ORDER BY factor_multiplicador ASC LIMIT 1
                   ), pm.sku) AS descripcion,
                   COALESCE((
                       SELECT codigo_barra 
                       FROM inventario.producto_codigos_barra 
                       WHERE producto_maestro_id = pm.id 
                       ORDER BY factor_multiplicador ASC LIMIT 1
                   ), '') AS codigo_barra
            FROM inventario.productos_maestro pm
            LEFT JOIN inventario.categoria_producto c ON pm.categoria_id = c.id
            LEFT JOIN inventario.marca m ON pm.marca_id = m.id
            ORDER BY pm.id ASC
        ");
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $categorias = $pdo->query("SELECT id, nombre FROM inventario.categoria_producto WHERE activo = true ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        $marcas = $pdo->query("SELECT id, nombre FROM inventario.marca WHERE activo = true ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($productos)) {
    $productos = [
        ['id' => 1, 'sku' => 'RON-BRU-LEY700', 'descripcion' => 'Ron Brugal Leyenda Botella 700ml', 'codigo_barra' => '7460100123456', 'categoria_id' => 1, 'categoria_nombre' => 'Rones', 'marca_id' => 1, 'marca_nombre' => 'Brugal', 'requiere_lote' => true, 'costo_promedio' => 1850.00, 'precio_base_unidad' => 2450.00, 'porcentaje_itbis' => 18.00],
        ['id' => 2, 'sku' => 'WHI-JW-BLA750', 'descripcion' => 'Johnnie Walker Black Label 12 Años 750ml', 'codigo_barra' => '5000267014203', 'categoria_id' => 2, 'categoria_nombre' => 'Whiskies', 'marca_id' => 4, 'marca_nombre' => 'Johnnie Walker', 'requiere_lote' => true, 'costo_promedio' => 2100.00, 'precio_base_unidad' => 2850.00, 'porcentaje_itbis' => 18.00],
        ['id' => 3, 'sku' => 'CER-PRE-REG650', 'descripcion' => 'Cerveza Presidente Regular Grande 650ml', 'codigo_barra' => '7461234567890', 'categoria_id' => 3, 'categoria_nombre' => 'Cervezas', 'marca_id' => 3, 'marca_nombre' => 'Presidente', 'requiere_lote' => true, 'costo_promedio' => 145.00, 'precio_base_unidad' => 195.00, 'porcentaje_itbis' => 18.00],
        ['id' => 4, 'sku' => 'VIN-MOE-IMP750', 'descripcion' => 'Champagne Moët & Chandon Brut Impérial 750ml', 'codigo_barra' => '3185370000335', 'categoria_id' => 4, 'categoria_nombre' => 'Vinos & Champagnes', 'marca_id' => 6, 'marca_nombre' => 'Moët & Chandon', 'requiere_lote' => true, 'costo_promedio' => 4200.00, 'precio_base_unidad' => 5600.00, 'porcentaje_itbis' => 18.00],
        ['id' => 5, 'sku' => 'TEQ-DON-REP750', 'descripcion' => 'Tequila Don Julio Reposado 750ml', 'codigo_barra' => '674545000012', 'categoria_id' => 5, 'categoria_nombre' => 'Tequilas & Mezcales', 'marca_id' => 7, 'marca_nombre' => 'Don Julio', 'requiere_lote' => true, 'costo_promedio' => 3400.00, 'precio_base_unidad' => 4500.00, 'porcentaje_itbis' => 18.00]
    ];
}
if (empty($categorias)) {
    $categorias = [
        ['id' => 1, 'nombre' => 'Rones'], ['id' => 2, 'nombre' => 'Whiskies'], ['id' => 3, 'nombre' => 'Cervezas'],
        ['id' => 4, 'nombre' => 'Vinos & Champagnes'], ['id' => 5, 'nombre' => 'Tequilas & Mezcales'], ['id' => 6, 'nombre' => 'Licores & Cremas']
    ];
}
if (empty($marcas)) {
    $marcas = [
        ['id' => 1, 'nombre' => 'Brugal'], ['id' => 2, 'nombre' => 'Barceló'], ['id' => 3, 'nombre' => 'Presidente'],
        ['id' => 4, 'nombre' => 'Johnnie Walker'], ['id' => 5, 'nombre' => 'Chivas Regal'], ['id' => 6, 'nombre' => 'Moët & Chandon'], ['id' => 7, 'nombre' => 'Don Julio']
    ];
}`);
fs.writeFileSync(path.join(dir, 'productos.php'), prod, 'utf8');

// presentaciones.php
let pres = fs.readFileSync(path.join(dir, 'presentaciones.php'), 'utf8');
pres = pres.replace(/\$presentaciones = \[\];[\s\S]*?\$productosMaestros = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$presentaciones = [];
$productosMaestros = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pcb.*, pm.sku, pm.precio_base_unidad
            FROM inventario.producto_codigos_barra pcb
            JOIN inventario.productos_maestro pm ON pcb.producto_maestro_id = pm.id
            ORDER BY pm.sku ASC, pcb.factor_multiplicador ASC
        ");
        $presentaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $productosMaestros = $pdo->query("SELECT id, sku, precio_base_unidad FROM inventario.productos_maestro ORDER BY sku ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($presentaciones)) {
    $presentaciones = [
        ['id' => 1, 'codigo_barra' => '7460100123456', 'descripcion_presentacion' => 'Ron Brugal Leyenda 700ml (Botella)', 'sku' => 'RON-BRU-LEY700', 'factor_multiplicador' => 1, 'precio_venta_presentacion' => 2450.00],
        ['id' => 2, 'codigo_barra' => '7460100123457', 'descripcion_presentacion' => 'Ron Brugal Leyenda Caja x 6 Botellas', 'sku' => 'RON-BRU-LEY700', 'factor_multiplicador' => 6, 'precio_venta_presentacion' => 14100.00],
        ['id' => 3, 'codigo_barra' => '5000267014203', 'descripcion_presentacion' => 'Johnnie Walker Black Label 12 Años 750ml', 'sku' => 'WHI-JW-BLA750', 'factor_multiplicador' => 1, 'precio_venta_presentacion' => 2850.00],
        ['id' => 4, 'codigo_barra' => '5000267014204', 'descripcion_presentacion' => 'Johnnie Walker Black Label Caja x 12', 'sku' => 'WHI-JW-BLA750', 'factor_multiplicador' => 12, 'precio_venta_presentacion' => 32500.00],
        ['id' => 5, 'codigo_barra' => '7461234567890', 'descripcion_presentacion' => 'Cerveza Presidente Regular Grande 650ml', 'sku' => 'CER-PRE-REG650', 'factor_multiplicador' => 1, 'precio_venta_presentacion' => 195.00],
        ['id' => 6, 'codigo_barra' => '7461234567891', 'descripcion_presentacion' => 'Cerveza Presidente Regular Caja x 16 Grandes', 'sku' => 'CER-PRE-REG650', 'factor_multiplicador' => 16, 'precio_venta_presentacion' => 2950.00]
    ];
}
if (empty($productosMaestros)) {
    $productosMaestros = [
        ['id' => 1, 'sku' => 'RON-BRU-LEY700', 'precio_base_unidad' => 2450.00],
        ['id' => 2, 'sku' => 'WHI-JW-BLA750', 'precio_base_unidad' => 2850.00],
        ['id' => 3, 'sku' => 'CER-PRE-REG650', 'precio_base_unidad' => 195.00]
    ];
}`);
fs.writeFileSync(path.join(dir, 'presentaciones.php'), pres, 'utf8');

// promociones.php
let promp = fs.readFileSync(path.join(dir, 'promociones.php'), 'utf8');
promp = promp.replace(/\$promociones = \[\];[\s\S]*?\$productos = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$promociones = [];
$productos = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT pc.*, 
                   pd.id AS detalle_id,
                   pd.condicion_producto_id,
                   pd.cantidad_requerida,
                   pd.beneficio_porcentaje,
                   pd.beneficio_monto_fijo,
                   pm.sku AS producto_sku,
                   (SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id LIMIT 1) AS producto_nombre
            FROM promociones.promociones_campanas pc
            LEFT JOIN promociones.promociones_detalles pd ON pc.id = pd.promocion_id
            LEFT JOIN inventario.productos_maestro pm ON pd.condicion_producto_id = pm.id
            ORDER BY pc.fecha_inicio DESC, pc.id DESC
        ");
        $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $productos = $pdo->query("
            SELECT pm.id, pm.sku, 
                   COALESCE((SELECT descripcion_presentacion FROM inventario.producto_codigos_barra WHERE producto_maestro_id = pm.id LIMIT 1), pm.sku) AS nombre
            FROM inventario.productos_maestro pm
            ORDER BY pm.sku ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($promociones)) {
    $promociones = [
        ['id' => 1, 'nombre_promocion' => 'Viernes de Whiskies 15% OFF', 'tipo_promocion' => 'HAPPY_HOUR', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-12-31', 'hora_inicio' => '18:00', 'hora_fin' => '23:00', 'dias_semana' => 'VIERNES', 'monto_minimo_factura' => 0.00, 'producto_sku' => 'WHI-JW-BLA750', 'cantidad_requerida' => 1, 'beneficio_porcentaje' => 15.00, 'estado' => 'ACTIVA'],
        ['id' => 2, 'nombre_promocion' => 'Llévalos en Pareja: Ron Brugal Leyenda', 'tipo_promocion' => 'VOLUMEN', 'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-12-15', 'hora_inicio' => null, 'hora_fin' => null, 'dias_semana' => 'TODOS', 'monto_minimo_factura' => 0.00, 'producto_sku' => 'RON-BRU-LEY700', 'cantidad_requerida' => 2, 'beneficio_porcentaje' => 10.00, 'estado' => 'ACTIVA']
    ];
}
if (empty($productos)) {
    $productos = [
        ['id' => 1, 'sku' => 'RON-BRU-LEY700', 'nombre' => 'Ron Brugal Leyenda 700ml'],
        ['id' => 2, 'sku' => 'WHI-JW-BLA750', 'nombre' => 'Johnnie Walker Black Label 750ml']
    ];
}`);
fs.writeFileSync(path.join(dir, 'promociones.php'), promp, 'utf8');

// roles.php
let rol = fs.readFileSync(path.join(dir, 'roles.php'), 'utf8');
rol = rol.replace(/\$roles = \[\];[\s\S]*?\$permisos = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$roles = [];
$permisos = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT r.*,
                   (SELECT count(*) FROM seguridad.roles_permisos WHERE rol_id = r.rol_id) AS total_permisos,
                   (SELECT array_to_string(array_agg(permiso_id), ',') FROM seguridad.roles_permisos WHERE rol_id = r.rol_id) AS permisos_ids
            FROM seguridad.roles r
            ORDER BY r.creado_en ASC
        ");
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $permisos = $pdo->query("SELECT * FROM seguridad.permisos ORDER BY modulo ASC, codigo ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($roles)) {
    $roles = [
        ['rol_id' => '1', 'nombre' => 'Administrador', 'descripcion' => 'Control total administrativo y fiscal del ERP', 'total_permisos' => 7, 'es_activo' => true],
        ['rol_id' => '2', 'nombre' => 'Supervisor', 'descripcion' => 'Autorizaciones de caja, cancelaciones, overrides y arqueos', 'total_permisos' => 4, 'es_activo' => true],
        ['rol_id' => '3', 'nombre' => 'Cajero', 'descripcion' => 'Operador de Punto de Venta (POS) y facturación', 'total_permisos' => 2, 'es_activo' => true],
        ['rol_id' => '4', 'nombre' => 'Almacenista', 'descripcion' => 'Recepción de mercancía, traslados y control FEFO', 'total_permisos' => 3, 'es_activo' => true],
        ['rol_id' => '5', 'nombre' => 'Repartidor', 'descripcion' => 'Despacho de pedidos a domicilio y entrega en ruta', 'total_permisos' => 1, 'es_activo' => true]
    ];
}
if (empty($permisos)) {
    $permisos = [
        ['permiso_id' => '1', 'codigo' => 'VENTAS_FACTURAR', 'modulo' => 'ventas', 'descripcion' => 'Emitir facturas en el Punto de Venta'],
        ['permiso_id' => '2', 'codigo' => 'VENTAS_ANULAR', 'modulo' => 'ventas', 'descripcion' => 'Anular facturas y comprobantes fiscales'],
        ['permiso_id' => '3', 'codigo' => 'INVENTARIO_AJUSTE', 'modulo' => 'inventario', 'descripcion' => 'Registrar ajustes de entrada/salida de stock'],
        ['permiso_id' => '4', 'codigo' => 'CONFIG_ADMIN', 'modulo' => 'configuracion', 'descripcion' => 'Modificar parámetros maestros del sistema']
    ];
}`);
fs.writeFileSync(path.join(dir, 'roles.php'), rol, 'utf8');

// usuarios.php
let usr = fs.readFileSync(path.join(dir, 'usuarios.php'), 'utf8');
usr = usr.replace(/\$usuarios = \[\];[\s\S]*?\$roles = \$pdo->query[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$usuarios = [];
$empleados = [];
$roles = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT u.usuario_id, u.username, u.es_activo, u.ultimo_acceso, u.creado_en,
                   p.persona_id, p.primer_nombre, p.primer_apellido, p.email, p.numero_documento,
                   r.rol_id, r.nombre AS rol_nombre
            FROM seguridad.usuarios u
            JOIN identidad.personas p ON u.persona_id = p.persona_id
            LEFT JOIN seguridad.usuarios_roles ur ON u.usuario_id = ur.usuario_id
            LEFT JOIN seguridad.roles r ON ur.rol_id = r.rol_id
            ORDER BY u.creado_en DESC
        ");
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $empleados = $pdo->query("
            SELECT e.empleado_id, e.persona_id, e.codigo_empleado, e.cargo,
                   p.primer_nombre, p.primer_apellido, p.numero_documento
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado = 'ACTIVO'
            ORDER BY p.primer_nombre ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $roles = $pdo->query("SELECT rol_id, nombre FROM seguridad.roles WHERE es_activo = true ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($usuarios)) {
    $usuarios = [
        ['usuario_id' => '1', 'username' => 'nmelendez', 'primer_nombre' => 'Nathalia', 'primer_apellido' => 'Meléndez', 'email' => 'admin@cavaybarrica.do', 'rol_nombre' => 'Administrador', 'es_activo' => true, 'ultimo_acceso' => date('Y-m-d H:i:s')],
        ['usuario_id' => '2', 'username' => 'jperez', 'primer_nombre' => 'Juan', 'primer_apellido' => 'Pérez', 'email' => 'jperez@cavaybarrica.do', 'rol_nombre' => 'Cajero', 'es_activo' => true, 'ultimo_acceso' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['usuario_id' => '3', 'username' => 'pmartinez', 'primer_nombre' => 'Pedro', 'primer_apellido' => 'Martínez', 'email' => 'pmartinez@cavaybarrica.do', 'rol_nombre' => 'Repartidor', 'es_activo' => true, 'ultimo_acceso' => date('Y-m-d H:i:s', strtotime('-2 days'))]
    ];
}
if (empty($empleados)) {
    $empleados = [
        ['empleado_id' => '1', 'persona_id' => '1', 'codigo_empleado' => 'EMP-001', 'cargo' => 'Administrador General', 'primer_nombre' => 'Nathalia', 'primer_apellido' => 'Meléndez'],
        ['empleado_id' => '2', 'persona_id' => '2', 'codigo_empleado' => 'EMP-002', 'cargo' => 'Cajero POS', 'primer_nombre' => 'Juan', 'primer_apellido' => 'Pérez'],
        ['empleado_id' => '3', 'persona_id' => '3', 'codigo_empleado' => 'EMP-003', 'cargo' => 'Repartidor Delivery', 'primer_nombre' => 'Pedro', 'primer_apellido' => 'Martínez']
    ];
}
if (empty($roles)) {
    $roles = [
        ['rol_id' => '1', 'nombre' => 'Administrador'], ['rol_id' => '2', 'nombre' => 'Supervisor'], ['rol_id' => '3', 'nombre' => 'Cajero']
    ];
}`);
fs.writeFileSync(path.join(dir, 'usuarios.php'), usr, 'utf8');

console.log('Fixed productos, presentaciones, promociones, roles, usuarios');

