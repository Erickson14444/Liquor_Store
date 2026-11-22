-- ============================================================================
-- SCRIPT DE INSERTS: CATÁLOGO DE PRODUCTOS DE LICORERÍA
-- Base de datos: liquor (PostgreSQL)
-- Esquema: inventario
-- ============================================================================

BEGIN;

-- 0. Sincronizar secuencias para evitar violación de llaves primarias
SELECT setval(pg_get_serial_sequence('inventario.marca', 'id'), COALESCE((SELECT MAX(id) FROM inventario.marca), 1));
SELECT setval(pg_get_serial_sequence('inventario.categoria_producto', 'id'), COALESCE((SELECT MAX(id) FROM inventario.categoria_producto), 1));
SELECT setval(pg_get_serial_sequence('inventario.productos_maestro', 'id'), COALESCE((SELECT MAX(id) FROM inventario.productos_maestro), 1));
SELECT setval(pg_get_serial_sequence('inventario.producto_codigos_barra', 'id'), COALESCE((SELECT MAX(id) FROM inventario.producto_codigos_barra), 1));

-- 1. Insertar Marcas Adicionales
INSERT INTO inventario.marca (nombre, estado)
SELECT v.nombre, 'ACTIVO'
FROM (VALUES 
    ('Barceló'),
    ('Johnnie Walker'),
    ('Buchanan''s'),
    ('Chivas Regal'),
    ('Corona'),
    ('Grey Goose'),
    ('Don Julio'),
    ('Coca Cola'),
    ('Sprite'),
    ('Schweppes')
) AS v(nombre)
WHERE NOT EXISTS (
    SELECT 1 FROM inventario.marca m WHERE UPPER(m.nombre) = UPPER(v.nombre)
);

-- 2. Insertar Categorías Adicionales
INSERT INTO inventario.categoria_producto (nombre, estado)
SELECT v.nombre, 'ACTIVO'
FROM (VALUES 
    ('Vodka'),
    ('Tequila'),
    ('Vinos')
) AS v(nombre)
WHERE NOT EXISTS (
    SELECT 1 FROM inventario.categoria_producto c WHERE UPPER(c.nombre) = UPPER(v.nombre)
);

-- 3. Insertar Productos Maestros
-- Función anónima DO para resolver IDs dinámicamente
DO $$
DECLARE
    r_cat_ron BIGINT;
    r_cat_whisky BIGINT;
    r_cat_cerveza BIGINT;
    r_cat_vodka BIGINT;
    r_cat_tequila BIGINT;
    r_cat_refresco BIGINT;

    r_mrc_brugal BIGINT;
    r_mrc_barcelo BIGINT;
    r_mrc_jw BIGINT;
    r_mrc_buchanans BIGINT;
    r_mrc_chivas BIGINT;
    r_mrc_presidente BIGINT;
    r_mrc_corona BIGINT;
    r_mrc_smirnoff BIGINT;
    r_mrc_greygoose BIGINT;
    r_mrc_fireball BIGINT;
    r_mrc_donjulio BIGINT;
    r_mrc_cocacola BIGINT;
    r_mrc_sprite BIGINT;
    r_mrc_schweppes BIGINT;

    new_prod_id BIGINT;
BEGIN
    -- Obtener Categorías
    SELECT id INTO r_cat_ron FROM inventario.categoria_producto WHERE UPPER(nombre) = 'RON' LIMIT 1;
    SELECT id INTO r_cat_whisky FROM inventario.categoria_producto WHERE UPPER(nombre) = 'WHISKY' LIMIT 1;
    SELECT id INTO r_cat_cerveza FROM inventario.categoria_producto WHERE UPPER(nombre) = 'CERVEZA' LIMIT 1;
    SELECT id INTO r_cat_vodka FROM inventario.categoria_producto WHERE UPPER(nombre) = 'VODKA' LIMIT 1;
    SELECT id INTO r_cat_tequila FROM inventario.categoria_producto WHERE UPPER(nombre) = 'TEQUILA' LIMIT 1;
    SELECT id INTO r_cat_refresco FROM inventario.categoria_producto WHERE UPPER(nombre) = 'REFRESCOS' LIMIT 1;

    -- Obtener Marcas
    SELECT id INTO r_mrc_brugal FROM inventario.marca WHERE UPPER(nombre) = 'BRUGAL' LIMIT 1;
    SELECT id INTO r_mrc_barcelo FROM inventario.marca WHERE UPPER(nombre) = 'BARCELÓ' LIMIT 1;
    SELECT id INTO r_mrc_jw FROM inventario.marca WHERE UPPER(nombre) = 'JOHNNIE WALKER' LIMIT 1;
    SELECT id INTO r_mrc_buchanans FROM inventario.marca WHERE UPPER(nombre) = 'BUCHANAN''S' LIMIT 1;
    SELECT id INTO r_mrc_chivas FROM inventario.marca WHERE UPPER(nombre) = 'CHIVAS REGAL' LIMIT 1;
    SELECT id INTO r_mrc_presidente FROM inventario.marca WHERE UPPER(nombre) = 'PRESIDENTE' LIMIT 1;
    SELECT id INTO r_mrc_corona FROM inventario.marca WHERE UPPER(nombre) = 'CORONA' LIMIT 1;
    SELECT id INTO r_mrc_smirnoff FROM inventario.marca WHERE UPPER(nombre) = 'SMIRNOFF' LIMIT 1;
    SELECT id INTO r_mrc_greygoose FROM inventario.marca WHERE UPPER(nombre) = 'GREY GOOSE' LIMIT 1;
    SELECT id INTO r_mrc_fireball FROM inventario.marca WHERE UPPER(nombre) = 'FIREBALL' LIMIT 1;
    SELECT id INTO r_mrc_donjulio FROM inventario.marca WHERE UPPER(nombre) = 'DON JULIO' LIMIT 1;
    SELECT id INTO r_mrc_cocacola FROM inventario.marca WHERE UPPER(nombre) = 'COCA COLA' LIMIT 1;
    SELECT id INTO r_mrc_sprite FROM inventario.marca WHERE UPPER(nombre) = 'SPRITE' LIMIT 1;
    SELECT id INTO r_mrc_schweppes FROM inventario.marca WHERE UPPER(nombre) = 'SCHWEPPES' LIMIT 1;

    -- Helper para insertar producto y código de barra
    -- 1. Ron Brugal Extra Viejo 700ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'RON-BRU-EV700') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('RON-BRU-EV700', COALESCE(r_cat_ron, 1), COALESCE(r_mrc_brugal, 1), true, 480.00, 650.00, 'Ron Brugal Extra Viejo 700ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100100011', 'Ron Brugal Extra Viejo Botella 700ml', 1, 650.00);
    END IF;

    -- 2. Ron Brugal XV Reserva Exclusiva 700ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'RON-BRU-XV700') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('RON-BRU-XV700', COALESCE(r_cat_ron, 1), COALESCE(r_mrc_brugal, 1), true, 650.00, 850.00, 'Ron Brugal XV Reserva Exclusiva 700ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100100028', 'Ron Brugal XV Reserva Exclusiva 700ml', 1, 850.00);
    END IF;

    -- 3. Ron Barceló Imperial 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'RON-BAR-IMP750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('RON-BAR-IMP750', COALESCE(r_cat_ron, 1), COALESCE(r_mrc_barcelo, 1), true, 850.00, 1100.00, 'Ron Barceló Imperial 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100200035', 'Ron Barceló Imperial Botella 750ml', 1, 1100.00);
    END IF;

    -- 4. Ron Barceló Gran Añejo 700ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'RON-BAR-GA700') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('RON-BAR-GA700', COALESCE(r_cat_ron, 1), COALESCE(r_mrc_barcelo, 1), true, 460.00, 620.00, 'Ron Barceló Gran Añejo 700ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100200042', 'Ron Barceló Gran Añejo Botella 700ml', 1, 620.00);
    END IF;

    -- 5. Whisky Johnnie Walker Black Label 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'WHI-JWB-750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('WHI-JWB-750', COALESCE(r_cat_whisky, 4), COALESCE(r_mrc_jw, 1), true, 1400.00, 1850.00, 'Whisky Johnnie Walker Black Label 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '5000267024203', 'Whisky Johnnie Walker Black Label 750ml', 1, 1850.00);
    END IF;

    -- 6. Whisky Johnnie Walker Red Label 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'WHI-JWR-750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('WHI-JWR-750', COALESCE(r_cat_whisky, 4), COALESCE(r_mrc_jw, 1), true, 750.00, 1050.00, 'Whisky Johnnie Walker Red Label 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '5000267014006', 'Whisky Johnnie Walker Red Label 750ml', 1, 1050.00);
    END IF;

    -- 7. Whisky Buchanan''s Deluxe 12 Años 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'WHI-BUC-12A750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('WHI-BUC-12A750', COALESCE(r_cat_whisky, 4), COALESCE(r_mrc_buchanans, 1), true, 1600.00, 2150.00, 'Whisky Buchanan''s Deluxe 12 Años 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '0881109012351', 'Whisky Buchanan''s 12 Años Botella 750ml', 1, 2150.00);
    END IF;

    -- 8. Whisky Chivas Regal 12 Años 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'WHI-CHV-12A750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('WHI-CHV-12A750', COALESCE(r_cat_whisky, 4), COALESCE(r_mrc_chivas, 1), true, 1550.00, 2100.00, 'Whisky Chivas Regal 12 Años 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '5000299210025', 'Whisky Chivas Regal 12 Años 750ml', 1, 2100.00);
    END IF;

    -- 9. Cerveza Presidente Normal 12oz (Botella)
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'CER-PRE-NOR12') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('CER-PRE-NOR12', COALESCE(r_cat_cerveza, 2), COALESCE(r_mrc_presidente, 4), true, 95.00, 140.00, 'Cerveza Presidente Normal 12oz')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100300010', 'Cerveza Presidente Regular Botella 12oz', 1, 140.00);
    END IF;

    -- 10. Cerveza Presidente Light 12oz (Botella)
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'CER-PRE-LGT12') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('CER-PRE-LGT12', COALESCE(r_cat_cerveza, 2), COALESCE(r_mrc_presidente, 4), true, 95.00, 140.00, 'Cerveza Presidente Light 12oz')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100300027', 'Cerveza Presidente Light Botella 12oz', 1, 140.00);
    END IF;

    -- 11. Cerveza Corona Extra 355ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'CER-COR-EXT355') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('CER-COR-EXT355', COALESCE(r_cat_cerveza, 2), COALESCE(r_mrc_corona, 4), true, 110.00, 160.00, 'Cerveza Corona Extra 355ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7501064191316', 'Cerveza Corona Extra Botella 355ml', 1, 160.00);
    END IF;

    -- 12. Vodka Smirnoff Red 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'VOD-SMI-RED750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('VOD-SMI-RED750', COALESCE(r_cat_vodka, 1), COALESCE(r_mrc_smirnoff, 5), true, 450.00, 650.00, 'Vodka Smirnoff Red 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '0820000001234', 'Vodka Smirnoff Red Botella 750ml', 1, 650.00);
    END IF;

    -- 13. Vodka Grey Goose 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'VOD-GRY-GOO750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('VOD-GRY-GOO750', COALESCE(r_cat_vodka, 1), COALESCE(r_mrc_greygoose, 5), true, 1800.00, 2400.00, 'Vodka Grey Goose 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '0804802800247', 'Vodka Grey Goose Botella 750ml', 1, 2400.00);
    END IF;

    -- 14. Licor Fireball Cinnamon Whisky 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'LIC-FIR-CIN750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('LIC-FIR-CIN750', COALESCE(r_cat_whisky, 4), COALESCE(r_mrc_fireball, 3), true, 700.00, 950.00, 'Licor Fireball Cinnamon Whisky 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '0880041446914', 'Licor Fireball Cinnamon Botella 750ml', 1, 950.00);
    END IF;

    -- 15. Tequila Don Julio Blanco 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'TEQ-DON-BLA750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('TEQ-DON-BLA750', COALESCE(r_cat_tequila, 1), COALESCE(r_mrc_donjulio, 1), true, 2200.00, 2950.00, 'Tequila Don Julio Blanco 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '6745450001025', 'Tequila Don Julio Blanco 750ml', 1, 2950.00);
    END IF;

    -- 16. Tequila Don Julio Reposado 750ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'TEQ-DON-REP750') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('TEQ-DON-REP750', COALESCE(r_cat_tequila, 1), COALESCE(r_mrc_donjulio, 1), true, 2600.00, 3450.00, 'Tequila Don Julio Reposado 750ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '6745450002015', 'Tequila Don Julio Reposado 750ml', 1, 3450.00);
    END IF;

    -- 17. Refresco Coca Cola Original 2 Litros
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'REF-COC-2L') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('REF-COC-2L', COALESCE(r_cat_refresco, 3), COALESCE(r_mrc_cocacola, 1), false, 80.00, 120.00, 'Refresco Coca Cola Original 2 Litros')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100400018', 'Refresco Coca Cola Botella 2L', 1, 120.00);
    END IF;

    -- 18. Refresco Sprite 2 Litros
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'REF-SPR-2L') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('REF-SPR-2L', COALESCE(r_cat_refresco, 3), COALESCE(r_mrc_sprite, 1), false, 80.00, 120.00, 'Refresco Sprite 2 Litros')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100400025', 'Refresco Sprite Botella 2L', 1, 120.00);
    END IF;

    -- 19. Agua Tónica Schweppes 300ml
    IF NOT EXISTS (SELECT 1 FROM inventario.productos_maestro WHERE sku = 'REF-SCH-TON300') THEN
        INSERT INTO inventario.productos_maestro (sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre)
        VALUES ('REF-SCH-TON300', COALESCE(r_cat_refresco, 3), COALESCE(r_mrc_schweppes, 1), false, 45.00, 75.00, 'Agua Tónica Schweppes Lata 300ml')
        RETURNING id INTO new_prod_id;

        INSERT INTO inventario.producto_codigos_barra (producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion)
        VALUES (new_prod_id, '7460100400032', 'Agua Tónica Schweppes Lata 300ml', 1, 75.00);
    END IF;

END $$;

COMMIT;
