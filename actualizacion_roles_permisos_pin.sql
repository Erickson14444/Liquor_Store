-- ============================================================================
-- 1. INSERTAR NUEVOS PERMISOS EN seguridad.permisos (CON UUID EXPLÍCITO)
-- ============================================================================
INSERT INTO seguridad.permisos (permiso_id, clave_sistema, modulo, descripcion, entidad, accion)
VALUES
    ('c4100001-0000-4000-8000-000000000001', 'GESTIONAR_METODOS_PAGO', 'configuracion', 'Acceso exclusivo del Administrador para crear, editar y activar/desactivar los Métodos de Pago.', 'METODOS_PAGO', 'GESTIONAR'),
    ('c4100002-0000-4000-8000-000000000002', 'GESTIONAR_LOTES_FEFO', 'inventario', 'Consultar existencias por caducidad, corregir fechas de vencimiento y dar de alta lotes iniciales (FEFO).', 'LOTES', 'GESTIONAR'),
    ('c4100003-0000-4000-8000-000000000003', 'GESTIONAR_PROVEEDORES', 'compras', 'Registrar y actualizar proveedores, días de crédito habitual y retenciones fiscales DGII.', 'PROVEEDORES', 'GESTIONAR')
ON CONFLICT (clave_sistema) DO NOTHING;

-- ============================================================================
-- 2. INSERTAR NUEVOS ROLES EN seguridad.roles (CON UUID EXPLÍCITO)
-- ============================================================================
INSERT INTO seguridad.roles (rol_id, nombre, descripcion, es_activo)
VALUES
    ('d5200001-0000-4000-8000-000000000001', 'Almacenista', 'Encargado de bodega, control físico de existencias, lotes y vencimientos FEFO, recepción física de camiones, traslados internos y registro de mermas.', TRUE),
    ('d5200002-0000-4000-8000-000000000002', 'Gerente de Compras', 'Responsable de abastecimiento, negociación con proveedores y casas licoreras, emisión de órdenes de compra, recepción con NCF y seguimiento de cuentas por pagar.', TRUE)
ON CONFLICT (nombre) DO NOTHING;

-- ============================================================================
-- 3. ASIGNAR PERMISOS EN seguridad.roles_permisos
-- ============================================================================

-- 3.1 Administrador: asignar AUTORIZAR_CON_PIN y GESTIONAR_METODOS_PAGO (exclusivo Admin)
INSERT INTO seguridad.roles_permisos (rol_id, permiso_id)
SELECT r.rol_id, p.permiso_id
FROM seguridad.roles r
JOIN seguridad.permisos p ON p.clave_sistema IN (
    'AUTORIZAR_CON_PIN',
    'GESTIONAR_METODOS_PAGO',
    'GESTIONAR_LOTES_FEFO',
    'GESTIONAR_PROVEEDORES'
)
WHERE r.nombre = 'Administrador'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 3.2 Almacenista: asignar sus permisos de bodega e inventario
INSERT INTO seguridad.roles_permisos (rol_id, permiso_id)
SELECT r.rol_id, p.permiso_id
FROM seguridad.roles r
JOIN seguridad.permisos p ON p.clave_sistema IN (
    'VER_PRODUCTOS',
    'GESTIONAR_LOTES_FEFO',
    'REGISTRAR_RECEPCION_COMPRA',
    'REALIZAR_TRASLADOS',
    'REGISTRAR_MERMAS',
    'GESTIONAR_CONSIGNACIONES'
)
WHERE r.nombre = 'Almacenista'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 3.3 Gerente de Compras: asignar sus permisos de suplidores, órdenes y CxP
INSERT INTO seguridad.roles_permisos (rol_id, permiso_id)
SELECT r.rol_id, p.permiso_id
FROM seguridad.roles r
JOIN seguridad.permisos p ON p.clave_sistema IN (
    'VER_PRODUCTOS',
    'GESTIONAR_PROVEEDORES',
    'CREAR_ORDEN_COMPRA',
    'REGISTRAR_RECEPCION_COMPRA',
    'GESTIONAR_CXP'
)
WHERE r.nombre = 'Gerente de Compras'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;
