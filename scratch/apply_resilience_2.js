const fs = require('fs');
const path = require('path');

const dir = 'c:\\Users\\natha\\OneDrive\\Desktop\\Prototipo-pantallas\\Panel de configuracion';

// clientes.php
let cli = fs.readFileSync(path.join(dir, 'clientes.php'), 'utf8');
cli = cli.replace(/\$clientes = \[\];[\s\S]*?\$clientes = \$stmt->fetchAll\(PDO::FETCH_ASSOC\);[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$clientes = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT c.cliente_id, c.codigo_cliente, c.estado, c.limite_credito, c.creado_en,
                   p.persona_id, p.tipo_documento, p.numero_documento, p.primer_nombre, p.primer_apellido,
                   p.email, p.telefono, p.direccion, p.fecha_nacimiento
            FROM identidad.clientes c
            JOIN identidad.personas p ON c.persona_id = p.persona_id
            ORDER BY c.creado_en DESC
        ");
        $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($clientes)) {
    $clientes = [
        ['cliente_id' => '1', 'persona_id' => '1', 'codigo_cliente' => 'CLI-001', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-0123456-7', 'primer_nombre' => 'Carlos', 'primer_apellido' => 'Gómez', 'telefono' => '809-582-1122', 'email' => 'carlos.gomez@gmail.com', 'direccion' => 'La Trinitaria, Santiago', 'estado' => 'ACTIVO', 'limite_credito' => 50000.00],
        ['cliente_id' => '2', 'persona_id' => '2', 'codigo_cliente' => 'CLI-002', 'tipo_documento' => 'RNC', 'numero_documento' => '131-98765-4', 'primer_nombre' => 'Inversiones Gourmet', 'primer_apellido' => 'SRL', 'telefono' => '809-583-4455', 'email' => 'compras@gourmetsrl.com', 'direccion' => 'Av. Juan Pablo Duarte #45, Santiago', 'estado' => 'ACTIVO', 'limite_credito' => 200000.00],
        ['cliente_id' => '3', 'persona_id' => '3', 'codigo_cliente' => 'CLI-003', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-7654321-0', 'primer_nombre' => 'María', 'primer_apellido' => 'Fernández', 'telefono' => '829-456-7890', 'email' => 'maria.f@hotmail.com', 'direccion' => 'Villa Olga, Santiago', 'estado' => 'ACTIVO', 'limite_credito' => 25000.00],
        ['cliente_id' => '4', 'persona_id' => '4', 'codigo_cliente' => 'CLI-004', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-9988776-5', 'primer_nombre' => 'Alejandro', 'primer_apellido' => 'Taveras', 'telefono' => '809-724-3311', 'email' => 'ataveras@gmail.com', 'direccion' => 'Cerros de Gurabo, Santiago', 'estado' => 'ACTIVO', 'limite_credito' => 100000.00]
    ];
}`);
fs.writeFileSync(path.join(dir, 'clientes.php'), cli, 'utf8');

// proveedores.php
let prov = fs.readFileSync(path.join(dir, 'proveedores.php'), 'utf8');
prov = prov.replace(/\$proveedores = \[\];[\s\S]*?\$proveedores = \$stmt->fetchAll\(PDO::FETCH_ASSOC\);[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$proveedores = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("SELECT * FROM identidad.proveedores ORDER BY creado_en DESC");
        $proveedores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($proveedores)) {
    $proveedores = [
        ['proveedor_id' => '1', 'ruc' => '101-00157-1', 'razon_social' => 'Cervecería Nacional Dominicana S.A.', 'nombre_comercial' => 'CND Cervezas', 'email_contacto' => 'pedidos@cnd.com.do', 'telefono_contacto' => '809-487-3000', 'direccion' => 'Autopista 30 de Mayo, D.N.', 'estado' => 'ACTIVO'],
        ['proveedor_id' => '2', 'ruc' => '101-01248-2', 'razon_social' => 'Brugal & Co. S.A.S.', 'nombre_comercial' => 'Casa Brugal', 'email_contacto' => 'ventas@brugal.com.do', 'telefono_contacto' => '809-582-2111', 'direccion' => 'Carretera Luperón Km 3, Puerto Plata', 'estado' => 'ACTIVO'],
        ['proveedor_id' => '3', 'ruc' => '101-85296-3', 'razon_social' => 'United Brands Dominicana S.R.L.', 'nombre_comercial' => 'United Brands (Diageo)', 'email_contacto' => 'atencion@unitedbrands.do', 'telefono_contacto' => '809-566-5000', 'direccion' => 'Av. Winston Churchill #1099, D.N.', 'estado' => 'ACTIVO'],
        ['proveedor_id' => '4', 'ruc' => '101-74125-8', 'razon_social' => 'MercaSID S.A.', 'nombre_comercial' => 'MercaSID Bebidas', 'email_contacto' => 'contacto@mercasid.com.do', 'telefono_contacto' => '809-565-2141', 'direccion' => 'Av. Máximo Gómez #182, D.N.', 'estado' => 'ACTIVO']
    ];
}`);
fs.writeFileSync(path.join(dir, 'proveedores.php'), prov, 'utf8');

// empleados.php
let emp = fs.readFileSync(path.join(dir, 'empleados.php'), 'utf8');
emp = emp.replace(/\$empleados = \[\];[\s\S]*?\$empleados = \$stmt->fetchAll\(PDO::FETCH_ASSOC\);[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$empleados = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT e.empleado_id, e.codigo_empleado, e.cargo, e.fecha_ingreso, e.salario, e.estado,
                   p.persona_id, p.tipo_documento, p.numero_documento, p.primer_nombre, p.primer_apellido,
                   p.email, p.telefono, p.direccion, p.fecha_nacimiento
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            ORDER BY e.creado_en DESC
        ");
        $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($empleados)) {
    $empleados = [
        ['empleado_id' => '1', 'persona_id' => '1', 'codigo_empleado' => 'EMP-001', 'cargo' => 'Administrador General', 'salario' => 65000.00, 'fecha_ingreso' => '2024-01-15', 'estado' => 'ACTIVO', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-0000000-1', 'primer_nombre' => 'Nathalia', 'primer_apellido' => 'Meléndez', 'telefono' => '809-555-0100', 'email' => 'admin@cavaybarrica.do', 'direccion' => 'Santiago, R.D.'],
        ['empleado_id' => '2', 'persona_id' => '2', 'codigo_empleado' => 'EMP-002', 'cargo' => 'Cajero POS', 'salario' => 28000.00, 'fecha_ingreso' => '2025-03-01', 'estado' => 'ACTIVO', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-1122334-4', 'primer_nombre' => 'Juan', 'primer_apellido' => 'Pérez', 'telefono' => '809-581-2233', 'email' => 'jperez@cavaybarrica.do', 'direccion' => 'Gurabo, Santiago'],
        ['empleado_id' => '3', 'persona_id' => '3', 'codigo_empleado' => 'EMP-003', 'cargo' => 'Repartidor Delivery', 'salario' => 24000.00, 'fecha_ingreso' => '2025-06-10', 'estado' => 'ACTIVO', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-5566778-9', 'primer_nombre' => 'Pedro', 'primer_apellido' => 'Martínez', 'telefono' => '829-334-5566', 'email' => 'pmartinez@cavaybarrica.do', 'direccion' => 'Cienfuegos, Santiago'],
        ['empleado_id' => '4', 'persona_id' => '4', 'codigo_empleado' => 'EMP-004', 'cargo' => 'Encargado de Almacén', 'salario' => 32000.00, 'fecha_ingreso' => '2024-09-01', 'estado' => 'ACTIVO', 'tipo_documento' => 'CEDULA', 'numero_documento' => '031-9900112-2', 'primer_nombre' => 'Luis', 'primer_apellido' => 'Gómez', 'telefono' => '809-587-9900', 'email' => 'lgomez@cavaybarrica.do', 'direccion' => 'Bella Vista, Santiago']
    ];
}`);
fs.writeFileSync(path.join(dir, 'empleados.php'), emp, 'utf8');

// repartidores.php
let rep = fs.readFileSync(path.join(dir, 'repartidores.php'), 'utf8');
rep = rep.replace(/\$repartidores = \[\];[\s\S]*?\$empleadosDisponibles = \$stmtEmp->fetchAll\(PDO::FETCH_ASSOC\);[\s\S]*?\} catch \(Exception \$e\) \{\s*\$errorDb = \$e->getMessage\(\);\s*\}/,
`$repartidores = [];
$empleadosDisponibles = [];
if ($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT r.empleado_id, r.numero_licencia, r.tipo_vehiculo, r.placa_vehiculo, r.estado_logistico, r.capacidad_pedidos,
                   e.codigo_empleado, e.cargo,
                   p.primer_nombre, p.primer_apellido, p.telefono, p.email
            FROM identidad.repartidores r
            JOIN identidad.empleados e ON r.empleado_id = e.empleado_id
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            ORDER BY p.primer_nombre ASC
        ");
        $repartidores = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtEmp = $pdo->query("
            SELECT e.empleado_id, e.codigo_empleado, p.primer_nombre, p.primer_apellido 
            FROM identidad.empleados e
            JOIN identidad.personas p ON e.persona_id = p.persona_id
            WHERE e.estado = 'ACTIVO'
            ORDER BY p.primer_nombre ASC
        ");
        $empleadosDisponibles = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
if (empty($repartidores)) {
    $repartidores = [
        ['empleado_id' => '3', 'codigo_empleado' => 'EMP-003', 'primer_nombre' => 'Pedro', 'primer_apellido' => 'Martínez', 'telefono' => '829-334-5566', 'tipo_vehiculo' => 'MOTOCICLETA', 'placa_vehiculo' => 'K-0982341', 'numero_licencia' => 'LIC-03155667', 'capacidad_pedidos' => 4, 'estado_logistico' => 'BASE']
    ];
}
if (empty($empleadosDisponibles)) {
    $empleadosDisponibles = [
        ['empleado_id' => '3', 'codigo_empleado' => 'EMP-003', 'primer_nombre' => 'Pedro', 'primer_apellido' => 'Martínez']
    ];
}`);
fs.writeFileSync(path.join(dir, 'repartidores.php'), rep, 'utf8');

console.log('Fixed clientes, proveedores, empleados, repartidores');

