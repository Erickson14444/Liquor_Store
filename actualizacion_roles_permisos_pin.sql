BEGIN;

-- ============================================================================
-- 1. INSERTAR / ACTUALIZAR PERMISOS
-- ============================================================================
INSERT INTO seguridad.permisos (permiso_id, clave_sistema, modulo, descripcion, entidad, accion)
VALUES
    ('c4100000-0000-4000-8000-000000000000', 'AUTORIZAR_CON_PIN', 'seguridad', 'Permite autorizar operaciones críticas mediante PIN de supervisor.', 'AUTORIZACION', 'AUTORIZAR'),
    ('c4100001-0000-4000-8000-000000000001', 'GESTIONAR_METODOS_PAGO', 'configuracion', 'Acceso exclusivo del Administrador para crear, editar y activar/desactivar los Métodos de Pago.', 'METODOS_PAGO', 'GESTIONAR'),
    ('c4100002-0000-4000-8000-000000000002', 'GESTIONAR_LOTES_FEFO', 'inventario', 'Consultar existencias por caducidad y trazabilidad de lotes FEFO.', 'LOTES', 'CONSULTAR'),
    ('c4100003-0000-4000-8000-000000000003', 'GESTIONAR_PROVEEDORES', 'compras', 'Registrar y actualizar proveedores, días de crédito habitual y retenciones fiscales DGII.', 'PROVEEDORES', 'GESTIONAR')
ON CONFLICT (clave_sistema) DO NOTHING;

INSERT INTO seguridad.permisos (permiso_id, clave_sistema, modulo, descripcion, entidad, accion)
VALUES
    ('a1b2c3d4-0003-4000-8000-000000000003', 'GESTIONAR_MONEDAS', 'configuracion', 'Acceso exclusivo del Administrador para gestionar divisas, tasas de cambio y moneda base.', 'MONEDAS', 'GESTIONAR'),
    ('a1b2c3d4-0004-4000-8000-000000000004', 'GESTIONAR_IMPUESTOS', 'configuracion', 'Acceso exclusivo del Administrador para configurar impuestos (ITBIS, ISC, Exento).', 'IMPUESTOS', 'GESTIONAR'),
    ('a1b2c3d4-0005-4000-8000-000000000005', 'GESTIONAR_RETENCIONES', 'configuracion', 'Acceso exclusivo del Administrador para configurar retenciones fiscales DGII (ISR, ITBIS).', 'RETENCIONES', 'GESTIONAR'),
    ('a1b2c3d4-0006-4000-8000-000000000006', 'GESTIONAR_SECUENCIAS_FISCALES', 'configuracion', 'Permite gestionar los rangos y vencimientos de Secuencias Fiscales NCF (DGII).', 'SECUENCIAS_FISCALES', 'GESTIONAR')
ON CONFLICT (clave_sistema) DO UPDATE
SET modulo      = EXCLUDED.modulo,
    descripcion = EXCLUDED.descripcion,
    entidad     = EXCLUDED.entidad,
    accion      = EXCLUDED.accion;

-- ============================================================================
-- 2. INSERTAR NUEVOS ROLES
-- ============================================================================
INSERT INTO seguridad.roles (rol_id, nombre, descripcion, es_activo)
VALUES
    ('d5200001-0000-4000-8000-000000000001', 'Almacenista', 'Encargado de bodega, control físico de existencias, consulta de lotes y vencimientos FEFO, recepción física de camiones, traslados internos y registro de mermas.', TRUE),
    ('d5200002-0000-4000-8000-000000000002', 'Gerente de Compras', 'Responsable de abastecimiento, negociación con proveedores y casas licoreras, emisión de órdenes de compra, recepción con NCF y seguimiento de cuentas por pagar.', TRUE)
ON CONFLICT (nombre) DO NOTHING;

-- ============================================================================
-- 3. ASIGNAR PERMISOS EN seguridad.roles_permisos
-- ============================================================================

-- 3.1 Administrador
INSERT INTO seguridad.roles_permisos (rol_id, permiso_id)
SELECT r.rol_id, p.permiso_id
FROM seguridad.roles r
JOIN seguridad.permisos p ON p.clave_sistema IN (
    'AUTORIZAR_CON_PIN',
    'GESTIONAR_METODOS_PAGO',
    'GESTIONAR_MONEDAS',
    'GESTIONAR_IMPUESTOS',
    'GESTIONAR_RETENCIONES',
    'GESTIONAR_SECUENCIAS_FISCALES',
    'GESTIONAR_LOTES_FEFO',
    'GESTIONAR_PROVEEDORES'
)
WHERE LOWER(TRIM(r.nombre)) = 'administrador'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 3.2 Supervisor de turno
INSERT INTO seguridad.roles_permisos (rol_id, permiso_id)
SELECT r.rol_id, p.permiso_id
FROM seguridad.roles r
JOIN seguridad.permisos p ON p.clave_sistema IN (
    'AUTORIZAR_CON_PIN',
    'GESTIONAR_SECUENCIAS_FISCALES'
)
WHERE LOWER(TRIM(r.nombre)) LIKE 'supervisor%'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 3.3 Almacenista
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
WHERE LOWER(TRIM(r.nombre)) = 'almacenista'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 3.4 Gerente de Compras
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
WHERE LOWER(TRIM(r.nombre)) = 'gerente de compras'
ON CONFLICT (rol_id, permiso_id) DO NOTHING;

-- 4. Restringir permisos exclusivos solo a Administrador
DELETE FROM seguridad.roles_permisos rp
USING seguridad.roles r, seguridad.permisos p
WHERE rp.rol_id = r.rol_id
  AND rp.permiso_id = p.permiso_id
  AND p.clave_sistema IN (
      'GESTIONAR_MONEDAS',
      'GESTIONAR_IMPUESTOS',
      'GESTIONAR_RETENCIONES',
      'GESTIONAR_METODOS_PAGO'
  )
  AND LOWER(TRIM(r.nombre)) <> 'administrador';

COMMIT;