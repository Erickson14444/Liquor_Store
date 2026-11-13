<?php
/**
 * Sembrador de Datos Iniciales (Database Seeder)
 * House & Ice • Sistema Administrativo
 * Tablas: seguridad, identidad, inventario, ventas, tesoreria
 */

require_once __DIR__ . '/conexion.php';

$log = [];

try {
    $pdo->beginTransaction();

    // 1. Roles de Seguridad
    $rolesCount = $pdo->query("SELECT count(*) FROM seguridad.roles")->fetchColumn();
    if ($rolesCount == 0) {
        $roles = [
            ['Administrador', 'Control total administrativo y fiscal del ERP'],
            ['Supervisor', 'Autorizaciones de caja, cancelaciones, overrides y arqueos'],
            ['Cajero', 'Operador de Punto de Venta (POS) y cobros'],
            ['Almacenista', 'Recepción de mercancía, traslados y control FEFO'],
            ['Repartidor', 'Despacho de pedidos a domicilio y entrega en ruta']
        ];
        $stmt = $pdo->prepare("INSERT INTO seguridad.roles (nombre, descripcion, es_activo) VALUES (?, ?, true)");
        foreach ($roles as $r) {
            $stmt->execute($r);
        }
        $log[] = "✅ 5 Roles del sistema creados.";
    }

    // 2. Persona y Usuario Administrador Inicial
    $userCount = $pdo->query("SELECT count(*) FROM seguridad.usuarios")->fetchColumn();
    if ($userCount == 0) {
        $stmtPersona = $pdo->prepare("
            INSERT INTO identidad.personas 
            (tipo_documento, numero_documento, primer_nombre, primer_apellido, fecha_nacimiento, genero, email, telefono, direccion) 
            VALUES ('CEDULA', '031-0000000-1', 'Nathalia', 'Meléndez', '1996-03-15', 'F', 'admin@cavaybarrica.do', '809-555-0100', 'Santiago, R.D.') 
            RETURNING persona_id
        ");
        $stmtPersona->execute();
        $personaId = $stmtPersona->fetchColumn();

        $passwordHash = password_hash('password123', PASSWORD_BCRYPT);
        $stmtUser = $pdo->prepare("
            INSERT INTO seguridad.usuarios 
            (persona_id, username, password_hash, es_activo, requiere_cambio_password) 
            VALUES (?, 'nmelendez', ?, true, false) 
            RETURNING usuario_id
        ");
        $stmtUser->execute([$personaId, $passwordHash]);
        $usuarioId = $stmtUser->fetchColumn();

        $rolAdminId = $pdo->query("SELECT rol_id FROM seguridad.roles WHERE nombre = 'Administrador'")->fetchColumn();
        if ($rolAdminId) {
            $pdo->prepare("INSERT INTO seguridad.usuarios_roles (usuario_id, rol_id) VALUES (?, ?)")->execute([$usuarioId, $rolAdminId]);
        }

        $pdo->prepare("
            INSERT INTO identidad.empleados (persona_id, codigo_empleado, cargo, fecha_ingreso, salario, estado) 
            VALUES (?, 'EMP-001', 'Administrador General', CURRENT_DATE, 65000.00, 'ACTIVO')
        ")->execute([$personaId]);

        $log[] = "✅ Usuario Administrador creado: 'nmelendez' (clave: 'password123').";
    }

    // 3. Categorías de Inventario
    $catCount = $pdo->query("SELECT count(*) FROM inventario.categoria_producto")->fetchColumn();
    if ($catCount == 0) {
        $cats = ['Rones', 'Whiskies', 'Cervezas', 'Vinos & Champagnes', 'Tequilas & Mezcales', 'Licores & Cremas', 'Mezcladores & Hielo'];
        $stmt = $pdo->prepare("INSERT INTO inventario.categoria_producto (nombre, activo) VALUES (?, true)");
        foreach ($cats as $c) {
            $stmt->execute([$c]);
        }
        $log[] = "✅ " . count($cats) . " Categorías de catálogo creadas.";
    }

    // 4. Marcas de Inventario
    $marcasCount = $pdo->query("SELECT count(*) FROM inventario.marca")->fetchColumn();
    if ($marcasCount == 0) {
        $marcas = ['Brugal', 'Barceló', 'Presidente', 'Johnnie Walker', 'Chivas Regal', 'Moët & Chandon', 'Don Julio', 'Baileys', 'Corona', 'Heineken'];
        $stmt = $pdo->prepare("INSERT INTO inventario.marca (nombre, activo) VALUES (?, true)");
        foreach ($marcas as $m) {
            $stmt->execute([$m]);
        }
        $log[] = "✅ " . count($marcas) . " Marcas de catálogo creadas.";
    }

    // 5. Almacenes Físicos
    $almacenesCount = $pdo->query("SELECT count(*) FROM inventario.almacenes")->fetchColumn();
    if ($almacenesCount == 0) {
        $almacenes = [
            ['Bodega Principal', 'Área de almacenamiento y recepción central', true],
            ['Mostrador Entrada', 'Estanterías y exhibidores de venta directa', true],
            ['Cámara Fría', 'Área refrigerada de cervezas y vinos blancos', true]
        ];
        $stmt = $pdo->prepare("INSERT INTO inventario.almacenes (nombre, ubicacion, activo) VALUES (?, ?, ?)");
        foreach ($almacenes as $a) {
            $stmt->execute($a);
        }
        $log[] = "✅ 3 Almacenes físicos registrados.";
    }

    // 6. Cajas POS
    $cajasCount = $pdo->query("SELECT count(*) FROM ventas.cajas_pos")->fetchColumn();
    if ($cajasCount == 0) {
        $cajas = [
            ['Caja POS 01 (Mostrador)', 'ABIERTA'],
            ['Caja POS 02 (Delivery)', 'CERRADA'],
            ['Caja POS 03 (Bar & Eventos)', 'CERRADA']
        ];
        $stmt = $pdo->prepare("INSERT INTO ventas.cajas_pos (nombre_equipo, estado) VALUES (?, ?)");
        foreach ($cajas as $c) {
            $stmt->execute($c);
        }
        $log[] = "✅ 3 Cajas POS registradas.";
    }

    // 7. Métodos de Pago
    $metodosCount = $pdo->query("SELECT count(*) FROM ventas.metodos_pago")->fetchColumn();
    if ($metodosCount == 0) {
        $metodos = [
            ['EFECTIVO', 'Pago en efectivo en moneda local (DOP)', false, true],
            ['TARJETA', 'Tarjeta de Débito o Crédito (VISA / Mastercard)', true, true],
            ['TRANSFERENCIA', 'Transferencia bancaria directa comprobada', true, true],
            ['CHEQUE', 'Cheque comercial certificado', true, true]
        ];
        $stmt = $pdo->prepare("INSERT INTO ventas.metodos_pago (codigo, descripcion, requiere_referencia, activo) VALUES (?, ?, ?, ?)");
        foreach ($metodos as $m) {
            $stmt->execute($m);
        }
        $log[] = "✅ 4 Métodos de pago registrados.";
    }

    // 8. Cuentas Bancarias
    $bancosCount = $pdo->query("SELECT count(*) FROM tesoreria.cuentas_bancarias")->fetchColumn();
    if ($bancosCount == 0) {
        $bancos = [
            ['Banco Popular Dominicano', '7845129630', 'CORRIENTE', 'DOP', 250000.00, true],
            ['Banco de Reservas', '9602581473', 'CORRIENTE', 'DOP', 145000.00, true],
            ['Banco BHD León', '1025896347', 'AHORRO', 'USD', 4500.00, true]
        ];
        $stmt = $pdo->prepare("INSERT INTO tesoreria.cuentas_bancarias (nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($bancos as $b) {
            $stmt->execute($b);
        }
        $log[] = "✅ 3 Cuentas bancarias registradas.";
    }

    // 9. Zonas de Delivery
    $zonasCount = $pdo->query("SELECT count(*) FROM ventas.zonas_delivery")->fetchColumn();
    if ($zonasCount == 0) {
        $zonas = [
            ['Los Jardines Metropolitanos', 150.00, 25, true],
            ['Villa Olga', 150.00, 20, true],
            ['Cerros de Gurabo', 200.00, 30, true],
            ['Gurabo Centro', 250.00, 35, true],
            ['Cienfuegos', 300.00, 45, true]
        ];
        $stmt = $pdo->prepare("INSERT INTO ventas.zonas_delivery (nombre, tarifa_envio, tiempo_promedio_min, activa) VALUES (?, ?, ?, ?)");
        foreach ($zonas as $z) {
            $stmt->execute($z);
        }
        $log[] = "✅ 5 Zonas de delivery configuradas.";
    }

    // 10. Secuencias Fiscales NCF (DGII)
    $ncfCount = $pdo->query("SELECT count(*) FROM ventas.secuencias_fiscales_dgii")->fetchColumn();
    if ($ncfCount == 0) {
        $secuencias = [
            ['B01', 1, 10000, 1420, '2027-12-31', 'ACTIVO'], // Crédito Fiscal
            ['B02', 1, 50000, 8950, '2027-12-31', 'ACTIVO'], // Consumo
            ['B04', 1, 5000, 115, '2027-12-31', 'ACTIVO']    // Nota de Crédito
        ];
        $stmt = $pdo->prepare("INSERT INTO ventas.secuencias_fiscales_dgii (tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($secuencias as $s) {
            $stmt->execute($s);
        }
        $log[] = "✅ 3 Bloques de secuencias DGII (B01, B02, B04) creados.";
    }

    // 11. Productos Maestros & Presentaciones con Códigos de Barra
    $prodCount = $pdo->query("SELECT count(*) FROM inventario.productos_maestro")->fetchColumn();
    if ($prodCount == 0) {
        // Obtener IDs de categorías y marcas
        $catRon = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Rones'")->fetchColumn() ?: 1;
        $catWhi = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Whiskies'")->fetchColumn() ?: 2;
        $catCer = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Cervezas'")->fetchColumn() ?: 3;
        $catVin = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Vinos & Champagnes'")->fetchColumn() ?: 4;
        $catTeq = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Tequilas & Mezcales'")->fetchColumn() ?: 5;
        $catLic = $pdo->query("SELECT id FROM inventario.categoria_producto WHERE nombre = 'Licores & Cremas'")->fetchColumn() ?: 6;

        $marBru = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Brugal'")->fetchColumn() ?: 1;
        $marPre = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Presidente'")->fetchColumn() ?: 3;
        $marJW  = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Johnnie Walker'")->fetchColumn() ?: 4;
        $marMoe = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Moët & Chandon'")->fetchColumn() ?: 6;
        $marDJ  = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Don Julio'")->fetchColumn() ?: 7;
        $marBai = $pdo->query("SELECT id FROM inventario.marca WHERE nombre = 'Baileys'")->fetchColumn() ?: 8;

        $productosCatalogo = [
            ['sku' => 'RON-BRU-LEY700', 'cat' => $catRon, 'mar' => $marBru, 'lote' => true, 'costo' => 1850.00, 'precio' => 2450.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '7460100123456', 'desc' => 'Ron Brugal Leyenda 700ml (Botella)', 'factor' => 1, 'precio' => 2450.00],
                 ['barcode' => '7460100123457', 'desc' => 'Ron Brugal Leyenda Caja x 6 Botellas', 'factor' => 6, 'precio' => 14100.00]
             ]],
            ['sku' => 'WHI-JW-BLA750', 'cat' => $catWhi, 'mar' => $marJW, 'lote' => true, 'costo' => 2100.00, 'precio' => 2850.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '5000267014203', 'desc' => 'Johnnie Walker Black Label 12 Años 750ml', 'factor' => 1, 'precio' => 2850.00],
                 ['barcode' => '5000267014204', 'desc' => 'Johnnie Walker Black Label Caja x 12', 'factor' => 12, 'precio' => 32500.00]
             ]],
            ['sku' => 'CER-PRE-REG650', 'cat' => $catCer, 'mar' => $marPre, 'lote' => true, 'costo' => 145.00, 'precio' => 195.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '7461234567890', 'desc' => 'Cerveza Presidente Regular Grande 650ml', 'factor' => 1, 'precio' => 195.00],
                 ['barcode' => '7461234567891', 'desc' => 'Cerveza Presidente Regular Caja x 16 Grandes', 'factor' => 16, 'precio' => 2950.00]
             ]],
            ['sku' => 'VIN-MOE-IMP750', 'cat' => $catVin, 'mar' => $marMoe, 'lote' => true, 'costo' => 4200.00, 'precio' => 5600.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '3185370000335', 'desc' => 'Champagne Moët & Chandon Brut Impérial 750ml', 'factor' => 1, 'precio' => 5600.00]
             ]],
            ['sku' => 'TEQ-DON-REP750', 'cat' => $catTeq, 'mar' => $marDJ, 'lote' => true, 'costo' => 3400.00, 'precio' => 4500.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '674545000012', 'desc' => 'Tequila Don Julio Reposado 750ml', 'factor' => 1, 'precio' => 4500.00]
             ]],
            ['sku' => 'LIC-BAI-ORI750', 'cat' => $catLic, 'mar' => $marBai, 'lote' => true, 'costo' => 1200.00, 'precio' => 1650.00, 'isc' => 0.00, 'itbis' => 18.00,
             'presentaciones' => [
                 ['barcode' => '5011013100156', 'desc' => 'Licor de Crema Baileys Original 750ml', 'factor' => 1, 'precio' => 1650.00]
             ]]
        ];

        $stmtProd = $pdo->prepare("
            INSERT INTO inventario.productos_maestro 
            (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, impuesto_isc, porcentaje_itbis) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
            RETURNING id
        ");

        $stmtPres = $pdo->prepare("
            INSERT INTO inventario.producto_codigos_barra 
            (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) 
            VALUES (?, ?, ?, ?, ?)
        ");

        foreach ($productosCatalogo as $p) {
            $stmtProd->execute([$p['sku'], $p['cat'], $p['mar'], $p['lote'] ? 'true' : 'false', $p['costo'], $p['precio'], $p['isc'], $p['itbis']]);
            $prodId = $stmtProd->fetchColumn();

            foreach ($p['presentaciones'] as $pres) {
                $stmtPres->execute([$prodId, $pres['barcode'], $pres['desc'], $pres['factor'], $pres['precio']]);
            }
        }
        $log[] = "✅ " . count($productosCatalogo) . " Productos Maestros y sus Presentaciones/Códigos de Barra creados.";
    }

    // 12. Clientes Iniciales (Personas + Clientes)
    $cliCount = $pdo->query("SELECT count(*) FROM identidad.clientes")->fetchColumn();
    if ($cliCount == 0) {
        $clientesSeed = [
            [
                'tipo_doc' => 'CEDULA', 'num_doc' => '031-0123456-7', 'nombre' => 'Carlos', 'apellido' => 'Gómez',
                'fnac' => '1985-04-12', 'gen' => 'M', 'email' => 'carlos.gomez@gmail.com', 'tel' => '809-582-1122',
                'dir' => 'La Trinitaria, Santiago', 'codigo' => 'CLI-001', 'limite' => 50000.00, 'estado' => 'ACTIVO'
            ],
            [
                'tipo_doc' => 'RNC', 'num_doc' => '131-98765-4', 'nombre' => 'Inversiones', 'apellido' => 'Gourmet SRL',
                'fnac' => '1990-01-01', 'gen' => 'O', 'email' => 'compras@gourmetsrl.com', 'tel' => '809-583-4455',
                'dir' => 'Av. Juan Pablo Duarte #45, Santiago', 'codigo' => 'CLI-002', 'limite' => 200000.00, 'estado' => 'ACTIVO'
            ],
            [
                'tipo_doc' => 'CEDULA', 'num_doc' => '031-7654321-0', 'nombre' => 'María', 'apellido' => 'Fernández',
                'fnac' => '1992-07-22', 'gen' => 'F', 'email' => 'maria.f@hotmail.com', 'tel' => '829-456-7890',
                'dir' => 'Villa Olga, Santiago', 'codigo' => 'CLI-003', 'limite' => 25000.00, 'estado' => 'ACTIVO'
            ],
            [
                'tipo_doc' => 'CEDULA', 'num_doc' => '031-9988776-5', 'nombre' => 'Alejandro', 'apellido' => 'Taveras',
                'fnac' => '1988-11-05', 'gen' => 'M', 'email' => 'ataveras@gmail.com', 'tel' => '809-724-3311',
                'dir' => 'Cerros de Gurabo, Santiago', 'codigo' => 'CLI-004', 'limite' => 100000.00, 'estado' => 'ACTIVO'
            ]
        ];

        $stmtPer = $pdo->prepare("
            INSERT INTO identidad.personas 
            (tipo_documento, numero_documento, primer_nombre, primer_apellido, fecha_nacimiento, genero, email, telefono, direccion) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) 
            RETURNING persona_id
        ");

        $stmtCli = $pdo->prepare("
            INSERT INTO identidad.clientes 
            (persona_id, codigo_cliente, estado, limite_credito) 
            VALUES (?, ?, ?, ?)
        ");

        foreach ($clientesSeed as $cs) {
            $stmtPer->execute([$cs['tipo_doc'], $cs['num_doc'], $cs['nombre'], $cs['apellido'], $cs['fnac'], $cs['gen'], $cs['email'], $cs['tel'], $cs['dir']]);
            $pid = $stmtPer->fetchColumn();
            $stmtCli->execute([$pid, $cs['codigo'], $cs['estado'], $cs['limite']]);
        }
        $log[] = "✅ " . count($clientesSeed) . " Clientes iniciales registrados.";
    }

    // 13. Proveedores Iniciales
    $provCount = $pdo->query("SELECT count(*) FROM identidad.proveedores")->fetchColumn();
    if ($provCount == 0) {
        $proveedoresSeed = [
            ['ruc' => '101-00157-1', 'razon' => 'Cervecería Nacional Dominicana S.A.', 'comercial' => 'CND Cervezas', 'email' => 'pedidos@cnd.com.do', 'tel' => '809-487-3000', 'dir' => 'Autopista 30 de Mayo, Santo Domingo', 'estado' => 'ACTIVO'],
            ['ruc' => '101-01248-2', 'razon' => 'Brugal & Co. S.A.S.', 'comercial' => 'Casa Brugal', 'email' => 'ventas@brugal.com.do', 'tel' => '809-582-2111', 'dir' => 'Carretera Luperón Km 3, Puerto Plata', 'estado' => 'ACTIVO'],
            ['ruc' => '101-85296-3', 'razon' => 'United Brands Dominicana S.R.L.', 'comercial' => 'United Brands (Diageo)', 'email' => 'atencion@unitedbrands.do', 'tel' => '809-566-5000', 'dir' => 'Av. Winston Churchill #1099, D.N.', 'estado' => 'ACTIVO'],
            ['ruc' => '101-74125-8', 'razon' => 'MercaSID S.A.', 'comercial' => 'MercaSID Bebidas', 'email' => 'contacto@mercasid.com.do', 'tel' => '809-565-2141', 'dir' => 'Av. Máximo Gómez #182, D.N.', 'estado' => 'ACTIVO']
        ];

        $stmtProv = $pdo->prepare("
            INSERT INTO identidad.proveedores 
            (ruc, razon_social, nombre_comercial, email_contacto, telefono_contacto, direccion, estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($proveedoresSeed as $ps) {
            $stmtProv->execute([$ps['ruc'], $ps['razon'], $ps['comercial'], $ps['email'], $ps['tel'], $ps['dir'], $ps['estado']]);
        }
        $log[] = "✅ " . count($proveedoresSeed) . " Proveedores oficiales registrados.";
    }

    // 14. Empleados y Repartidores
    $repCount = $pdo->query("SELECT count(*) FROM identidad.repartidores")->fetchColumn();
    if ($repCount == 0) {
        // Cajero
        $stmtP2 = $pdo->prepare("
            INSERT INTO identidad.personas 
            (tipo_documento, numero_documento, primer_nombre, primer_apellido, fecha_nacimiento, genero, email, telefono, direccion) 
            VALUES ('CEDULA', '031-1122334-4', 'Juan', 'Pérez', '1995-05-14', 'M', 'jperez@cavaybarrica.do', '809-581-2233', 'Gurabo, Santiago') 
            RETURNING persona_id
        ");
        $stmtP2->execute();
        $p2Id = $stmtP2->fetchColumn();

        $pdo->prepare("
            INSERT INTO identidad.empleados (persona_id, codigo_empleado, cargo, fecha_ingreso, salario, estado) 
            VALUES (?, 'EMP-002', 'Cajero POS', CURRENT_DATE - 90, 28000.00, 'ACTIVO')
        ")->execute([$p2Id]);

        // Repartidor
        $stmtP3 = $pdo->prepare("
            INSERT INTO identidad.personas 
            (tipo_documento, numero_documento, primer_nombre, primer_apellido, fecha_nacimiento, genero, email, telefono, direccion) 
            VALUES ('CEDULA', '031-5566778-9', 'Pedro', 'Martínez', '1998-08-20', 'M', 'pmartinez@cavaybarrica.do', '829-334-5566', 'Cienfuegos, Santiago') 
            RETURNING persona_id
        ");
        $stmtP3->execute();
        $p3Id = $stmtP3->fetchColumn();

        $stmtEmp3 = $pdo->prepare("
            INSERT INTO identidad.empleados (persona_id, codigo_empleado, cargo, fecha_ingreso, salario, estado) 
            VALUES (?, 'EMP-003', 'Repartidor Delivery', CURRENT_DATE - 45, 24000.00, 'ACTIVO') 
            RETURNING empleado_id
        ");
        $stmtEmp3->execute([$p3Id]);
        $emp3Id = $stmtEmp3->fetchColumn();

        $pdo->prepare("
            INSERT INTO identidad.repartidores (empleado_id, numero_licencia, tipo_vehiculo, placa_vehiculo, estado_logistico, capacidad_pedidos) 
            VALUES (?, 'LIC-03155667', 'MOTOCICLETA', 'K-0982341', 'BASE', 4)
        ")->execute([$emp3Id]);

        $log[] = "✅ Empleados y Repartidores con unidades vehiculares registrados.";
    }

    // 15. Permisos de Seguridad
    $permCount = $pdo->query("SELECT count(*) FROM seguridad.permisos")->fetchColumn();
    if ($permCount == 0) {
        $permisosSeed = [
            ['VENTAS_FACTURAR', 'ventas', 'Emitir facturas en el Punto de Venta'],
            ['VENTAS_ANULAR', 'ventas', 'Anular facturas y comprobantes fiscales'],
            ['INVENTARIO_AJUSTE', 'inventario', 'Registrar ajustes de entrada/salida de stock'],
            ['INVENTARIO_VER', 'inventario', 'Consultar existencias y kardex FEFO'],
            ['CONFIG_ADMIN', 'configuracion', 'Modificar parámetros maestros del sistema'],
            ['REPORTES_VER', 'reportes', 'Ver reportes gerenciales y cierres de caja'],
            ['SUPERVISOR_PIN', 'seguridad', 'Autorizar overrides y descuentos mediante PIN']
        ];
        $stmtPerm = $pdo->prepare("INSERT INTO seguridad.permisos (codigo, modulo, descripcion) VALUES (?, ?, ?)");
        foreach ($permisosSeed as $ps) {
            $stmtPerm->execute($ps);
        }
        $log[] = "✅ " . count($permisosSeed) . " Permisos del sistema configurados.";
    }

    // 16. Promociones y Reglas de Venta Iniciales
    $promoCount = $pdo->query("SELECT count(*) FROM promociones.promociones_campanas")->fetchColumn();
    if ($promoCount == 0) {
        // Campaña 1: Happy Hour Whiskies
        $stmtC1 = $pdo->prepare("
            INSERT INTO promociones.promociones_campanas 
            (nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, monto_minimo_factura, estado) 
            VALUES ('Viernes de Whiskies 15% OFF', 'HAPPY_HOUR', CURRENT_DATE, CURRENT_DATE + 60, '18:00', '23:00', 'VIERNES', 0.00, 'ACTIVA') 
            RETURNING id
        ");
        $stmtC1->execute();
        $c1Id = $stmtC1->fetchColumn();

        $prodJW = $pdo->query("SELECT id FROM inventario.productos_maestro WHERE sku = 'WHI-JW-BLA750'")->fetchColumn();
        if ($prodJW) {
            $pdo->prepare("
                INSERT INTO promociones.promociones_detalles 
                (promocion_id, condicion_producto_id, cantidad_requerida, beneficio_porcentaje) 
                VALUES (?, ?, 1, 15.00)
            ")->execute([$c1Id, $prodJW]);
        }

        // Campaña 2: Volumen Brugal Leyenda
        $stmtC2 = $pdo->prepare("
            INSERT INTO promociones.promociones_campanas 
            (nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, dias_semana, monto_minimo_factura, estado) 
            VALUES ('Llévalos en Pareja: Ron Brugal Leyenda', 'VOLUMEN', CURRENT_DATE, CURRENT_DATE + 45, 'TODOS', 0.00, 'ACTIVA') 
            RETURNING id
        ");
        $stmtC2->execute();
        $c2Id = $stmtC2->fetchColumn();

        $prodBru = $pdo->query("SELECT id FROM inventario.productos_maestro WHERE sku = 'RON-BRU-LEY700'")->fetchColumn();
        if ($prodBru) {
            $pdo->prepare("
                INSERT INTO promociones.promociones_detalles 
                (promocion_id, condicion_producto_id, cantidad_requerida, beneficio_porcentaje) 
                VALUES (?, ?, 2, 10.00)
            ")->execute([$c2Id, $prodBru]);
        }

        $log[] = "✅ 2 Campañas promocionales con reglas de descuento configuradas.";
    }

    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $log[] = "❌ Error en seeder: " . $e->getMessage();
}

// Si se ejecuta directamente vía web
if (php_sapi_name() != 'cli' || isset($_GET['web'])) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Sembrador de Datos • House &amp; Ice</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a1410; padding: 40px; color: #f8f5ef; }
        .box { background: #261b14; padding: 32px; border-radius: 14px; border: 1px solid #3d2b20; max-width: 650px; margin: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        h2 { color: #d4a359; margin-top: 0; font-family: "Playfair Display", Georgia, serif; }
        p { color: #c4b5a5; font-size: 0.95em; }
        ul { list-style: none; padding: 0; margin: 20px 0; }
        li { padding: 10px 14px; border-bottom: 1px solid #332319; font-size: 0.92em; }
        li:last-child { border-bottom: none; }
        .btn { display: inline-block; margin-top: 20px; background: #a66e2e; color: #fff; padding: 12px 22px; text-decoration: none; border-radius: 8px; font-weight: bold; transition: 0.2s; }
        .btn:hover { background: #b87b33; }
    </style>
</head>
<body>
    <div class="box">
        <h2>🌱 Sembrador de Datos • House &amp; Ice ERP</h2>
        <p>Inicialización de tablas maestras de la base de datos <strong>Liquor_Store</strong>:</p>
        <ul>
            <?php foreach ($log as $l): ?>
                <li><?= htmlspecialchars($l) ?></li>
            <?php endforeach; ?>
        </ul>
        <a href="../Login y Menu/menu.php" class="btn">Ir al Tablero Principal →</a>
    </div>
</body>
</html>
<?php
} else {
    foreach ($log as $l) {
        echo $l . "\n";
    }
}
