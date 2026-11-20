-- ============================================================================
-- House & Ice • Sistema Administrativo (ERP Licorero)
-- Script: Inserción de Zonas, Asignación a Repartidor y Cupones
-- ============================================================================

BEGIN;

-- ============================================================================
-- 1. INSERTAR LAS 5 ZONAS DE DELIVERY GEOGRÁFICAS
-- ============================================================================
INSERT INTO ventas.zonas_delivery (id, nombre, tarifa_envio, tiempo_promedio_min, activa)
VALUES
    (1, 'Los Jardines Metropolitanos', 150.00, 25, TRUE),
    (2, 'Villa Olga',                  150.00, 20, TRUE),
    (3, 'Cerros de Gurabo',            200.00, 30, TRUE),
    (4, 'Gurabo Centro',               250.00, 35, TRUE),
    (5, 'Cienfuegos',                  300.00, 45, TRUE)
ON CONFLICT (nombre) DO UPDATE 
SET tarifa_envio        = EXCLUDED.tarifa_envio,
    tiempo_promedio_min = EXCLUDED.tiempo_promedio_min,
    activa              = EXCLUDED.activa;

SELECT setval('ventas.zonas_delivery_id_seq', COALESCE((SELECT MAX(id) FROM ventas.zonas_delivery), 1));


-- ============================================================================
-- 2. ASIGNAR LAS 5 ZONAS AL REPARTIDOR (Albert Salazar)
-- (Esta es la tabla que muestra la pantalla de la captura: ventas.repartidor_zonas)
-- ============================================================================
INSERT INTO ventas.repartidor_zonas (repartidor_id, zona_id, es_ruta_principal)
SELECT 
    r.empleado_id, 
    z.id, 
    (CASE WHEN z.id = 1 THEN TRUE ELSE FALSE END) AS es_ruta_principal
FROM identidad.repartidores r
JOIN identidad.empleados e ON e.empleado_id = r.empleado_id
JOIN identidad.personas p ON p.persona_id = e.persona_id
CROSS JOIN ventas.zonas_delivery z
WHERE LOWER(p.nombre_razon_social) LIKE '%albert%'
ON CONFLICT (repartidor_id, zona_id) DO UPDATE
SET es_ruta_principal = EXCLUDED.es_ruta_principal;


-- ============================================================================
-- 3. AJUSTE DE COLUMNA Y CAMPAÑAS PARA CUPONES DE DESCUENTO
-- ============================================================================
ALTER TABLE promociones.cupones_descuento 
ALTER COLUMN factura_origen_id DROP NOT NULL;

INSERT INTO promociones.promociones_campanas (
    id, nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, 
    hora_inicio, hora_fin, monto_minimo_factura, estado
)
VALUES
    (1, 'Cupones de Fidelización y Cortesía', 'CUPON_TICKET', CURRENT_DATE, CURRENT_DATE + INTERVAL '1 year', '08:00', '23:59', 1000.00, 'ACTIVA'),
    (2, 'Black Friday Licorero - Bono Compra', 'CUPON_TICKET', CURRENT_DATE, CURRENT_DATE + INTERVAL '3 months', '09:00', '22:00', 2500.00, 'ACTIVA')
ON CONFLICT (id) DO UPDATE 
SET nombre_promocion     = EXCLUDED.nombre_promocion,
    tipo_promocion       = EXCLUDED.tipo_promocion,
    monto_minimo_factura = EXCLUDED.monto_minimo_factura,
    estado               = EXCLUDED.estado;

SELECT setval('promociones.promociones_campanas_id_seq', COALESCE((SELECT MAX(id) FROM promociones.promociones_campanas), 1));


-- ============================================================================
-- 4. CUPONES PROMOCIONALES INICIALES (DISPONIBLES)
-- ============================================================================
INSERT INTO promociones.cupones_descuento (
    id, codigo_cupon, promocion_id, factura_origen_id, factura_canje_id, 
    cliente_id, monto_credito, fecha_vencimiento, estado
)
VALUES
    (1, 'CUP-BIENVENIDA', 1, NULL, NULL, NULL, 500.00, CURRENT_DATE + INTERVAL '30 days', 'DISPONIBLE'),
    (2, 'CUP-VIP2026',    1, NULL, NULL, NULL, 1000.00, CURRENT_DATE + INTERVAL '45 days', 'DISPONIBLE'),
    (3, 'CUP-REGALO250',  2, NULL, NULL, NULL, 250.00, CURRENT_DATE + INTERVAL '15 days', 'DISPONIBLE'),
    (4, 'CUP-PROMO7D',    2, NULL, NULL, NULL, 750.00, CURRENT_DATE + INTERVAL '5 days',  'DISPONIBLE')
ON CONFLICT (codigo_cupon) DO UPDATE
SET monto_credito     = EXCLUDED.monto_credito,
    fecha_vencimiento = EXCLUDED.fecha_vencimiento,
    estado            = EXCLUDED.estado;

SELECT setval('promociones.cupones_descuento_id_seq', COALESCE((SELECT MAX(id) FROM promociones.cupones_descuento), 1));

COMMIT;
