--
-- PostgreSQL database dump
--

\restrict yBxKcV155dmghfeDaHUsKUefsJjXBQRM0VAyCLC9TMrdkljdawASvqaGcIGW6V6

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

-- Started on 2026-11-08 01:15:26

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- TOC entry 11 (class 2615 OID 17398)
-- Name: compras; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA compras;


ALTER SCHEMA compras OWNER TO postgres;

--
-- TOC entry 7 (class 2615 OID 16397)
-- Name: identidad; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA identidad;


ALTER SCHEMA identidad OWNER TO postgres;

--
-- TOC entry 9 (class 2615 OID 16629)
-- Name: inventario; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA inventario;


ALTER SCHEMA inventario OWNER TO postgres;

--
-- TOC entry 12 (class 2615 OID 17595)
-- Name: promociones; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA promociones;


ALTER SCHEMA promociones OWNER TO postgres;

--
-- TOC entry 8 (class 2615 OID 16398)
-- Name: seguridad; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA seguridad;


ALTER SCHEMA seguridad OWNER TO postgres;

--
-- TOC entry 13 (class 2615 OID 17680)
-- Name: tesoreria; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA tesoreria;


ALTER SCHEMA tesoreria OWNER TO postgres;

--
-- TOC entry 10 (class 2615 OID 16818)
-- Name: ventas; Type: SCHEMA; Schema: -; Owner: postgres
--

CREATE SCHEMA ventas;


ALTER SCHEMA ventas OWNER TO postgres;

--
-- TOC entry 2 (class 3079 OID 16386)
-- Name: uuid-ossp; Type: EXTENSION; Schema: -; Owner: -
--

CREATE EXTENSION IF NOT EXISTS "uuid-ossp" WITH SCHEMA public;


--
-- TOC entry 5966 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 317 (class 1259 OID 17559)
-- Name: comprobante_egreso_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.comprobante_egreso_detalles (
    id bigint NOT NULL,
    comprobante_egreso_id bigint NOT NULL,
    cuenta_por_pagar_id bigint NOT NULL,
    monto_aplicado numeric(12,2) NOT NULL,
    monto_retencion_isr numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_retencion_itbis numeric(12,2) DEFAULT 0.00 NOT NULL,
    saldo_anterior numeric(12,2) NOT NULL,
    saldo_restante numeric(12,2) NOT NULL,
    CONSTRAINT chk_egresodet_total CHECK ((((monto_aplicado + monto_retencion_isr) + monto_retencion_itbis) <= saldo_anterior)),
    CONSTRAINT comprobante_egreso_detalles_monto_aplicado_check CHECK ((monto_aplicado > (0)::numeric)),
    CONSTRAINT comprobante_egreso_detalles_monto_retencion_isr_check CHECK ((monto_retencion_isr >= (0)::numeric)),
    CONSTRAINT comprobante_egreso_detalles_monto_retencion_itbis_check CHECK ((monto_retencion_itbis >= (0)::numeric))
);


ALTER TABLE compras.comprobante_egreso_detalles OWNER TO postgres;

--
-- TOC entry 316 (class 1259 OID 17558)
-- Name: comprobante_egreso_detalles_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.comprobante_egreso_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.comprobante_egreso_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5967 (class 0 OID 0)
-- Dependencies: 316
-- Name: comprobante_egreso_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.comprobante_egreso_detalles_id_seq OWNED BY compras.comprobante_egreso_detalles.id;


--
-- TOC entry 315 (class 1259 OID 17536)
-- Name: comprobantes_egreso_cxp; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.comprobantes_egreso_cxp (
    id bigint NOT NULL,
    numero_comprobante character varying(30) NOT NULL,
    proveedor_id uuid NOT NULL,
    usuario_registro_id uuid NOT NULL,
    monto_total_pagado numeric(12,2) NOT NULL,
    metodo_pago_id integer NOT NULL,
    cuenta_bancaria_id integer,
    referencia_pago character varying(100),
    fecha_pago timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT comprobantes_egreso_cxp_monto_total_pagado_check CHECK ((monto_total_pagado >= (0)::numeric))
);


ALTER TABLE compras.comprobantes_egreso_cxp OWNER TO postgres;

--
-- TOC entry 314 (class 1259 OID 17535)
-- Name: comprobantes_egreso_cxp_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.comprobantes_egreso_cxp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.comprobantes_egreso_cxp_id_seq OWNER TO postgres;

--
-- TOC entry 5968 (class 0 OID 0)
-- Dependencies: 314
-- Name: comprobantes_egreso_cxp_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.comprobantes_egreso_cxp_id_seq OWNED BY compras.comprobantes_egreso_cxp.id;


--
-- TOC entry 313 (class 1259 OID 17507)
-- Name: cuentas_por_pagar; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.cuentas_por_pagar (
    id bigint NOT NULL,
    proveedor_id uuid NOT NULL,
    recepcion_compra_id bigint,
    monto_original numeric(12,2) NOT NULL,
    saldo_pendiente numeric(12,2) NOT NULL,
    fecha_emision date NOT NULL,
    fecha_vencimiento date NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    CONSTRAINT chk_cxp_saldo CHECK (((saldo_pendiente >= (0)::numeric) AND (saldo_pendiente <= monto_original))),
    CONSTRAINT cuentas_por_pagar_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying, 'PAGADA'::character varying])::text[])))
);


ALTER TABLE compras.cuentas_por_pagar OWNER TO postgres;

--
-- TOC entry 312 (class 1259 OID 17506)
-- Name: cuentas_por_pagar_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.cuentas_por_pagar_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.cuentas_por_pagar_id_seq OWNER TO postgres;

--
-- TOC entry 5969 (class 0 OID 0)
-- Dependencies: 312
-- Name: cuentas_por_pagar_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.cuentas_por_pagar_id_seq OWNED BY compras.cuentas_por_pagar.id;


--
-- TOC entry 307 (class 1259 OID 17425)
-- Name: orden_compra_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.orden_compra_detalles (
    id bigint NOT NULL,
    orden_compra_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    cantidad_ordenada integer NOT NULL,
    cantidad_recibida_total integer DEFAULT 0,
    costo_pactado_unitario numeric(12,2) NOT NULL,
    CONSTRAINT orden_compra_detalles_cantidad_ordenada_check CHECK ((cantidad_ordenada > 0))
);


ALTER TABLE compras.orden_compra_detalles OWNER TO postgres;

--
-- TOC entry 306 (class 1259 OID 17424)
-- Name: orden_compra_detalles_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.orden_compra_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.orden_compra_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5970 (class 0 OID 0)
-- Dependencies: 306
-- Name: orden_compra_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.orden_compra_detalles_id_seq OWNED BY compras.orden_compra_detalles.id;


--
-- TOC entry 305 (class 1259 OID 17400)
-- Name: ordenes_compra; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.ordenes_compra (
    id bigint NOT NULL,
    numero_orden character varying(30) NOT NULL,
    proveedor_id uuid NOT NULL,
    usuario_id uuid NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    monto_total_estimado numeric(12,2) NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_esperada date,
    CONSTRAINT ordenes_compra_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'RECIBIDA_PARCIAL'::character varying, 'COMPLETADA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT ordenes_compra_monto_total_estimado_check CHECK ((monto_total_estimado >= (0)::numeric))
);


ALTER TABLE compras.ordenes_compra OWNER TO postgres;

--
-- TOC entry 304 (class 1259 OID 17399)
-- Name: ordenes_compra_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.ordenes_compra_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.ordenes_compra_id_seq OWNER TO postgres;

--
-- TOC entry 5971 (class 0 OID 0)
-- Dependencies: 304
-- Name: ordenes_compra_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.ordenes_compra_id_seq OWNED BY compras.ordenes_compra.id;


--
-- TOC entry 311 (class 1259 OID 17480)
-- Name: recepcion_compra_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.recepcion_compra_detalles (
    id bigint NOT NULL,
    recepcion_id bigint NOT NULL,
    orden_detalle_id bigint,
    producto_maestro_id bigint NOT NULL,
    numero_lote character varying(50) NOT NULL,
    fecha_vencimiento date NOT NULL,
    cantidad_recibida_buena integer NOT NULL,
    costo_unitario_facturado numeric(12,2) NOT NULL,
    CONSTRAINT recepcion_compra_detalles_cantidad_recibida_buena_check CHECK ((cantidad_recibida_buena > 0))
);


ALTER TABLE compras.recepcion_compra_detalles OWNER TO postgres;

--
-- TOC entry 310 (class 1259 OID 17479)
-- Name: recepcion_compra_detalles_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.recepcion_compra_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.recepcion_compra_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5972 (class 0 OID 0)
-- Dependencies: 310
-- Name: recepcion_compra_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.recepcion_compra_detalles_id_seq OWNED BY compras.recepcion_compra_detalles.id;


--
-- TOC entry 309 (class 1259 OID 17451)
-- Name: recepciones_compra; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.recepciones_compra (
    id bigint NOT NULL,
    orden_compra_id bigint,
    proveedor_id uuid NOT NULL,
    numero_factura_proveedor character varying(50) NOT NULL,
    ncf_proveedor character varying(13),
    monto_total_factura numeric(12,2) NOT NULL,
    usuario_recepcion_id uuid NOT NULL,
    fecha_recepcion timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT recepciones_compra_monto_total_factura_check CHECK ((monto_total_factura >= (0)::numeric))
);


ALTER TABLE compras.recepciones_compra OWNER TO postgres;

--
-- TOC entry 308 (class 1259 OID 17450)
-- Name: recepciones_compra_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.recepciones_compra_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.recepciones_compra_id_seq OWNER TO postgres;

--
-- TOC entry 5973 (class 0 OID 0)
-- Dependencies: 308
-- Name: recepciones_compra_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.recepciones_compra_id_seq OWNED BY compras.recepciones_compra.id;


--
-- TOC entry 228 (class 1259 OID 16425)
-- Name: clientes; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.clientes (
    cliente_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    codigo_cliente character varying(20) NOT NULL,
    estado character varying(15) DEFAULT 'ACTIVO'::character varying NOT NULL,
    limite_credito numeric(10,2) DEFAULT 0.00 NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_clientes_estado CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying, 'SUSPENDIDO'::character varying])::text[]))),
    CONSTRAINT chk_clientes_limite_credito CHECK ((limite_credito >= (0)::numeric))
);


ALTER TABLE identidad.clientes OWNER TO postgres;

--
-- TOC entry 229 (class 1259 OID 16451)
-- Name: empleados; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.empleados (
    empleado_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    codigo_empleado character varying(20) NOT NULL,
    cargo character varying(50) NOT NULL,
    fecha_ingreso date NOT NULL,
    salario numeric(10,2) NOT NULL,
    estado character varying(15) DEFAULT 'ACTIVO'::character varying NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_empleados_estado CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying, 'LICENCIA'::character varying])::text[]))),
    CONSTRAINT chk_empleados_salario CHECK ((salario > (0)::numeric))
);


ALTER TABLE identidad.empleados OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 16399)
-- Name: personas; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.personas (
    persona_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tipo_documento character varying(10) NOT NULL,
    numero_documento character varying(20) NOT NULL,
    primer_nombre character varying(50) NOT NULL,
    segundo_nombre character varying(50),
    primer_apellido character varying(50) NOT NULL,
    segundo_apellido character varying(50),
    fecha_nacimiento date NOT NULL,
    genero character(1),
    email character varying(100) NOT NULL,
    telefono character varying(20),
    direccion text,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    actualizado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_personas_genero CHECK ((genero = ANY (ARRAY['M'::bpchar, 'F'::bpchar, 'O'::bpchar]))),
    CONSTRAINT chk_personas_mayoria_edad CHECK ((fecha_nacimiento <= (CURRENT_DATE - '18 years'::interval))),
    CONSTRAINT chk_personas_tipo_doc CHECK (((tipo_documento)::text = ANY ((ARRAY['DNI'::character varying, 'CEDULA'::character varying, 'PASAPORTE'::character varying, 'RUC'::character varying])::text[])))
);


ALTER TABLE identidad.personas OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 16478)
-- Name: proveedores; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.proveedores (
    proveedor_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    ruc character varying(20) NOT NULL,
    razon_social character varying(150) NOT NULL,
    nombre_comercial character varying(150),
    email_contacto character varying(100) NOT NULL,
    telefono_contacto character varying(20) NOT NULL,
    direccion text NOT NULL,
    estado character varying(15) DEFAULT 'ACTIVO'::character varying NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_proveedores_estado CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying])::text[])))
);


ALTER TABLE identidad.proveedores OWNER TO postgres;

--
-- TOC entry 328 (class 1259 OID 17734)
-- Name: repartidores; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.repartidores (
    empleado_id uuid NOT NULL,
    numero_licencia character varying(30) NOT NULL,
    tipo_vehiculo character varying(30) NOT NULL,
    placa_vehiculo character varying(20),
    estado_logistico character varying(20) DEFAULT 'BASE'::character varying NOT NULL,
    capacidad_pedidos integer DEFAULT 1 NOT NULL,
    CONSTRAINT repartidores_capacidad_pedidos_check CHECK ((capacidad_pedidos > 0)),
    CONSTRAINT repartidores_estado_logistico_check CHECK (((estado_logistico)::text = ANY ((ARRAY['BASE'::character varying, 'EN_RUTA_CON_ESPACIO'::character varying, 'EN_RUTA_LLENO'::character varying, 'INACTIVO'::character varying])::text[]))),
    CONSTRAINT repartidores_tipo_vehiculo_check CHECK (((tipo_vehiculo)::text = ANY ((ARRAY['MOTOCICLETA'::character varying, 'FURGONETA'::character varying, 'AUTOMOVIL'::character varying])::text[])))
);


ALTER TABLE identidad.repartidores OWNER TO postgres;

--
-- TOC entry 247 (class 1259 OID 16711)
-- Name: almacenes; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.almacenes (
    id integer NOT NULL,
    nombre character varying(50) NOT NULL,
    ubicacion character varying(100),
    activo boolean DEFAULT true NOT NULL
);


ALTER TABLE inventario.almacenes OWNER TO postgres;

--
-- TOC entry 246 (class 1259 OID 16710)
-- Name: almacenes_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.almacenes_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.almacenes_id_seq OWNER TO postgres;

--
-- TOC entry 5974 (class 0 OID 0)
-- Dependencies: 246
-- Name: almacenes_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.almacenes_id_seq OWNED BY inventario.almacenes.id;


--
-- TOC entry 239 (class 1259 OID 16631)
-- Name: categoria_producto; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.categoria_producto (
    id bigint NOT NULL,
    nombre character varying(50) NOT NULL,
    activo boolean DEFAULT true NOT NULL
);


ALTER TABLE inventario.categoria_producto OWNER TO postgres;

--
-- TOC entry 238 (class 1259 OID 16630)
-- Name: categoria_producto_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.categoria_producto_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.categoria_producto_id_seq OWNER TO postgres;

--
-- TOC entry 5975 (class 0 OID 0)
-- Dependencies: 238
-- Name: categoria_producto_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.categoria_producto_id_seq OWNED BY inventario.categoria_producto.id;


--
-- TOC entry 251 (class 1259 OID 16744)
-- Name: inventario_stock; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.inventario_stock (
    id bigint NOT NULL,
    almacen_id integer NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint,
    stock_fisico_unidades integer DEFAULT 0 NOT NULL,
    stock_comprometido integer DEFAULT 0 NOT NULL,
    CONSTRAINT chk_stock_comprometido CHECK (((stock_comprometido >= 0) AND (stock_comprometido <= stock_fisico_unidades))),
    CONSTRAINT chk_stock_fisico CHECK ((stock_fisico_unidades >= 0))
);


ALTER TABLE inventario.inventario_stock OWNER TO postgres;

--
-- TOC entry 250 (class 1259 OID 16743)
-- Name: inventario_stock_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.inventario_stock_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.inventario_stock_id_seq OWNER TO postgres;

--
-- TOC entry 5976 (class 0 OID 0)
-- Dependencies: 250
-- Name: inventario_stock_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.inventario_stock_id_seq OWNED BY inventario.inventario_stock.id;


--
-- TOC entry 249 (class 1259 OID 16724)
-- Name: lotes; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.lotes (
    id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    numero_lote character varying(50) NOT NULL,
    fecha_vencimiento date NOT NULL,
    cantidad_actual integer NOT NULL,
    CONSTRAINT chk_lote_cantidad CHECK ((cantidad_actual >= 0))
);


ALTER TABLE inventario.lotes OWNER TO postgres;

--
-- TOC entry 248 (class 1259 OID 16723)
-- Name: lotes_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.lotes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.lotes_id_seq OWNER TO postgres;

--
-- TOC entry 5977 (class 0 OID 0)
-- Dependencies: 248
-- Name: lotes_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.lotes_id_seq OWNED BY inventario.lotes.id;


--
-- TOC entry 241 (class 1259 OID 16642)
-- Name: marca; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.marca (
    id bigint NOT NULL,
    nombre character varying(50) NOT NULL,
    activo boolean DEFAULT true NOT NULL
);


ALTER TABLE inventario.marca OWNER TO postgres;

--
-- TOC entry 240 (class 1259 OID 16641)
-- Name: marca_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.marca_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.marca_id_seq OWNER TO postgres;

--
-- TOC entry 5978 (class 0 OID 0)
-- Dependencies: 240
-- Name: marca_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.marca_id_seq OWNED BY inventario.marca.id;


--
-- TOC entry 255 (class 1259 OID 16791)
-- Name: movimiento_inventario_detalles; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.movimiento_inventario_detalles (
    id bigint NOT NULL,
    movimiento_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint,
    cantidad_unidades integer NOT NULL,
    costo_unitario_referencia numeric(12,2) CONSTRAINT movimiento_inventario_detall_costo_unitario_referencia_not_null NOT NULL
);


ALTER TABLE inventario.movimiento_inventario_detalles OWNER TO postgres;

--
-- TOC entry 254 (class 1259 OID 16790)
-- Name: movimiento_inventario_detalles_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.movimiento_inventario_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.movimiento_inventario_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5979 (class 0 OID 0)
-- Dependencies: 254
-- Name: movimiento_inventario_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.movimiento_inventario_detalles_id_seq OWNED BY inventario.movimiento_inventario_detalles.id;


--
-- TOC entry 253 (class 1259 OID 16777)
-- Name: movimientos_inventario; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.movimientos_inventario (
    id bigint NOT NULL,
    tipo_movimiento character varying(30) NOT NULL,
    justificacion text NOT NULL,
    supervisor_id uuid,
    documento_referencia_id bigint,
    fecha_movimiento timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE inventario.movimientos_inventario OWNER TO postgres;

--
-- TOC entry 252 (class 1259 OID 16776)
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.movimientos_inventario_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.movimientos_inventario_id_seq OWNER TO postgres;

--
-- TOC entry 5980 (class 0 OID 0)
-- Dependencies: 252
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.movimientos_inventario_id_seq OWNED BY inventario.movimientos_inventario.id;


--
-- TOC entry 245 (class 1259 OID 16688)
-- Name: producto_codigos_barra; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.producto_codigos_barra (
    id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    codigo_barra character varying(50) NOT NULL,
    descripcion_presentacion character varying(100) NOT NULL,
    factor_multiplicador integer NOT NULL,
    precio_venta_presentacion numeric(12,2) NOT NULL,
    CONSTRAINT chk_factor_mult CHECK ((factor_multiplicador > 0))
);


ALTER TABLE inventario.producto_codigos_barra OWNER TO postgres;

--
-- TOC entry 244 (class 1259 OID 16687)
-- Name: producto_codigos_barra_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.producto_codigos_barra_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.producto_codigos_barra_id_seq OWNER TO postgres;

--
-- TOC entry 5981 (class 0 OID 0)
-- Dependencies: 244
-- Name: producto_codigos_barra_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.producto_codigos_barra_id_seq OWNED BY inventario.producto_codigos_barra.id;


--
-- TOC entry 243 (class 1259 OID 16653)
-- Name: productos_maestro; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.productos_maestro (
    id bigint NOT NULL,
    sku character varying(30) NOT NULL,
    categoria_id bigint NOT NULL,
    marca_id bigint NOT NULL,
    requiere_lote boolean DEFAULT false NOT NULL,
    costo_promedio numeric(12,2) DEFAULT 0.00 NOT NULL,
    precio_base_unidad numeric(12,2) DEFAULT 0.00 NOT NULL,
    impuesto_isc numeric(12,2) DEFAULT 0.00 NOT NULL,
    porcentaje_itbis numeric(5,2) DEFAULT 18.00 NOT NULL,
    CONSTRAINT chk_prod_costo CHECK ((costo_promedio >= (0)::numeric)),
    CONSTRAINT chk_prod_precio CHECK ((precio_base_unidad >= (0)::numeric))
);


ALTER TABLE inventario.productos_maestro OWNER TO postgres;

--
-- TOC entry 242 (class 1259 OID 16652)
-- Name: productos_maestro_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.productos_maestro_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.productos_maestro_id_seq OWNER TO postgres;

--
-- TOC entry 5982 (class 0 OID 0)
-- Dependencies: 242
-- Name: productos_maestro_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.productos_maestro_id_seq OWNED BY inventario.productos_maestro.id;


--
-- TOC entry 332 (class 1259 OID 17785)
-- Name: traslado_detalles; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.traslado_detalles (
    id bigint NOT NULL,
    traslado_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint,
    cantidad_enviada integer NOT NULL,
    cantidad_recibida integer DEFAULT 0 NOT NULL,
    CONSTRAINT traslado_detalles_cantidad_enviada_check CHECK ((cantidad_enviada > 0)),
    CONSTRAINT traslado_detalles_cantidad_recibida_check CHECK ((cantidad_recibida >= 0))
);


ALTER TABLE inventario.traslado_detalles OWNER TO postgres;

--
-- TOC entry 331 (class 1259 OID 17784)
-- Name: traslado_detalles_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.traslado_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.traslado_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5983 (class 0 OID 0)
-- Dependencies: 331
-- Name: traslado_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.traslado_detalles_id_seq OWNED BY inventario.traslado_detalles.id;


--
-- TOC entry 330 (class 1259 OID 17755)
-- Name: traslados_internos; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.traslados_internos (
    id bigint NOT NULL,
    numero_traslado character varying(30) NOT NULL,
    almacen_origen_id integer NOT NULL,
    almacen_destino_id integer NOT NULL,
    usuario_emisor_id uuid NOT NULL,
    usuario_receptor_id uuid,
    estado character varying(20) DEFAULT 'EN_TRANSITO'::character varying NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_traslado_almacenes CHECK ((almacen_origen_id <> almacen_destino_id)),
    CONSTRAINT traslados_internos_estado_check CHECK (((estado)::text = ANY ((ARRAY['EN_TRANSITO'::character varying, 'RECIBIDO'::character varying, 'CANCELADO'::character varying])::text[])))
);


ALTER TABLE inventario.traslados_internos OWNER TO postgres;

--
-- TOC entry 329 (class 1259 OID 17754)
-- Name: traslados_internos_id_seq; Type: SEQUENCE; Schema: inventario; Owner: postgres
--

CREATE SEQUENCE inventario.traslados_internos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE inventario.traslados_internos_id_seq OWNER TO postgres;

--
-- TOC entry 5984 (class 0 OID 0)
-- Dependencies: 329
-- Name: traslados_internos_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.traslados_internos_id_seq OWNED BY inventario.traslados_internos.id;


--
-- TOC entry 323 (class 1259 OID 17643)
-- Name: cupones_descuento; Type: TABLE; Schema: promociones; Owner: postgres
--

CREATE TABLE promociones.cupones_descuento (
    id bigint NOT NULL,
    codigo_cupon character varying(20) NOT NULL,
    promocion_id bigint NOT NULL,
    factura_origen_id bigint NOT NULL,
    factura_canje_id bigint,
    cliente_id uuid,
    monto_credito numeric(12,2) NOT NULL,
    fecha_vencimiento date NOT NULL,
    estado character varying(20) DEFAULT 'DISPONIBLE'::character varying NOT NULL,
    CONSTRAINT cupones_descuento_estado_check CHECK (((estado)::text = ANY ((ARRAY['DISPONIBLE'::character varying, 'USADO'::character varying, 'VENCIDO'::character varying, 'ANULADO'::character varying])::text[]))),
    CONSTRAINT cupones_descuento_monto_credito_check CHECK ((monto_credito > (0)::numeric))
);


ALTER TABLE promociones.cupones_descuento OWNER TO postgres;

--
-- TOC entry 322 (class 1259 OID 17642)
-- Name: cupones_descuento_id_seq; Type: SEQUENCE; Schema: promociones; Owner: postgres
--

CREATE SEQUENCE promociones.cupones_descuento_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE promociones.cupones_descuento_id_seq OWNER TO postgres;

--
-- TOC entry 5985 (class 0 OID 0)
-- Dependencies: 322
-- Name: cupones_descuento_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.cupones_descuento_id_seq OWNED BY promociones.cupones_descuento.id;


--
-- TOC entry 319 (class 1259 OID 17597)
-- Name: promociones_campanas; Type: TABLE; Schema: promociones; Owner: postgres
--

CREATE TABLE promociones.promociones_campanas (
    id bigint NOT NULL,
    nombre_promocion character varying(100) NOT NULL,
    tipo_promocion character varying(30) NOT NULL,
    fecha_inicio date NOT NULL,
    fecha_fin date NOT NULL,
    hora_inicio time without time zone,
    hora_fin time without time zone,
    dias_semana character varying(50),
    monto_minimo_factura numeric(12,2),
    estado character varying(20) DEFAULT 'PROGRAMADA'::character varying NOT NULL,
    CONSTRAINT chk_promo_fechas CHECK ((fecha_fin >= fecha_inicio)),
    CONSTRAINT promociones_campanas_estado_check CHECK (((estado)::text = ANY ((ARRAY['PROGRAMADA'::character varying, 'ACTIVA'::character varying, 'INACTIVA'::character varying, 'FINALIZADA'::character varying])::text[]))),
    CONSTRAINT promociones_campanas_monto_minimo_factura_check CHECK ((monto_minimo_factura >= (0)::numeric)),
    CONSTRAINT promociones_campanas_tipo_promocion_check CHECK (((tipo_promocion)::text = ANY ((ARRAY['VOLUMEN'::character varying, 'CROSS_SELLING'::character varying, 'HAPPY_HOUR'::character varying, 'FECHAS'::character varying, 'CUPON_TICKET'::character varying])::text[])))
);


ALTER TABLE promociones.promociones_campanas OWNER TO postgres;

--
-- TOC entry 318 (class 1259 OID 17596)
-- Name: promociones_campanas_id_seq; Type: SEQUENCE; Schema: promociones; Owner: postgres
--

CREATE SEQUENCE promociones.promociones_campanas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE promociones.promociones_campanas_id_seq OWNER TO postgres;

--
-- TOC entry 5986 (class 0 OID 0)
-- Dependencies: 318
-- Name: promociones_campanas_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.promociones_campanas_id_seq OWNED BY promociones.promociones_campanas.id;


--
-- TOC entry 321 (class 1259 OID 17617)
-- Name: promociones_detalles; Type: TABLE; Schema: promociones; Owner: postgres
--

CREATE TABLE promociones.promociones_detalles (
    id bigint NOT NULL,
    promocion_id bigint NOT NULL,
    condicion_producto_id bigint,
    condicion_categoria_id integer,
    cantidad_requerida integer DEFAULT 1 NOT NULL,
    beneficio_producto_id bigint,
    beneficio_porcentaje numeric(5,2),
    beneficio_monto_fijo numeric(12,2),
    CONSTRAINT promociones_detalles_beneficio_monto_fijo_check CHECK ((beneficio_monto_fijo >= (0)::numeric)),
    CONSTRAINT promociones_detalles_beneficio_porcentaje_check CHECK (((beneficio_porcentaje > (0)::numeric) AND (beneficio_porcentaje <= (100)::numeric))),
    CONSTRAINT promociones_detalles_cantidad_requerida_check CHECK ((cantidad_requerida > 0))
);


ALTER TABLE promociones.promociones_detalles OWNER TO postgres;

--
-- TOC entry 320 (class 1259 OID 17616)
-- Name: promociones_detalles_id_seq; Type: SEQUENCE; Schema: promociones; Owner: postgres
--

CREATE SEQUENCE promociones.promociones_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE promociones.promociones_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5987 (class 0 OID 0)
-- Dependencies: 320
-- Name: promociones_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.promociones_detalles_id_seq OWNED BY promociones.promociones_detalles.id;


--
-- TOC entry 237 (class 1259 OID 16604)
-- Name: auditoria; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.auditoria (
    auditoria_id bigint NOT NULL,
    usuario_id uuid,
    tipo_evento character varying(50) NOT NULL,
    esquema_afectado character varying(50) NOT NULL,
    tabla_afectada character varying(50) NOT NULL,
    registro_id uuid,
    datos_anteriores jsonb,
    datos_nuevos jsonb,
    ip_origen character varying(45),
    agente_usuario text,
    fecha_hora timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_auditoria_evento CHECK (((tipo_evento)::text = ANY ((ARRAY['INSERT'::character varying, 'UPDATE'::character varying, 'DELETE'::character varying, 'LOGIN_SUCCESS'::character varying, 'LOGIN_FAILURE'::character varying, 'PASSWORD_CHANGE'::character varying])::text[])))
);


ALTER TABLE seguridad.auditoria OWNER TO postgres;

--
-- TOC entry 236 (class 1259 OID 16603)
-- Name: auditoria_auditoria_id_seq; Type: SEQUENCE; Schema: seguridad; Owner: postgres
--

CREATE SEQUENCE seguridad.auditoria_auditoria_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE seguridad.auditoria_auditoria_id_seq OWNER TO postgres;

--
-- TOC entry 5988 (class 0 OID 0)
-- Dependencies: 236
-- Name: auditoria_auditoria_id_seq; Type: SEQUENCE OWNED BY; Schema: seguridad; Owner: postgres
--

ALTER SEQUENCE seguridad.auditoria_auditoria_id_seq OWNED BY seguridad.auditoria.auditoria_id;


--
-- TOC entry 233 (class 1259 OID 16550)
-- Name: permisos; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.permisos (
    permiso_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    codigo character varying(100) NOT NULL,
    modulo character varying(50) NOT NULL,
    descripcion text,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE seguridad.permisos OWNER TO postgres;

--
-- TOC entry 232 (class 1259 OID 16534)
-- Name: roles; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.roles (
    rol_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    nombre character varying(50) NOT NULL,
    descripcion text,
    es_activo boolean DEFAULT true NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE seguridad.roles OWNER TO postgres;

--
-- TOC entry 235 (class 1259 OID 16584)
-- Name: roles_permisos; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.roles_permisos (
    rol_id uuid NOT NULL,
    permiso_id uuid NOT NULL,
    asignado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE seguridad.roles_permisos OWNER TO postgres;

--
-- TOC entry 231 (class 1259 OID 16506)
-- Name: usuarios; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.usuarios (
    usuario_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    persona_id uuid NOT NULL,
    username character varying(50) NOT NULL,
    password_hash character varying(255) NOT NULL,
    es_activo boolean DEFAULT true NOT NULL,
    requiere_cambio_password boolean DEFAULT false NOT NULL,
    ultimo_acceso timestamp with time zone,
    intentos_fallidos integer DEFAULT 0 NOT NULL,
    bloqueado_hasta timestamp with time zone,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_usuarios_intentos CHECK ((intentos_fallidos >= 0))
);


ALTER TABLE seguridad.usuarios OWNER TO postgres;

--
-- TOC entry 234 (class 1259 OID 16565)
-- Name: usuarios_roles; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.usuarios_roles (
    usuario_id uuid NOT NULL,
    rol_id uuid NOT NULL,
    asignado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


ALTER TABLE seguridad.usuarios_roles OWNER TO postgres;

--
-- TOC entry 325 (class 1259 OID 17682)
-- Name: cuentas_bancarias; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.cuentas_bancarias (
    id integer NOT NULL,
    nombre_banco character varying(50) NOT NULL,
    numero_cuenta character varying(50) NOT NULL,
    tipo_cuenta character varying(20),
    moneda character varying(3) DEFAULT 'DOP'::character varying NOT NULL,
    saldo_actual numeric(15,2) DEFAULT 0.00 NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    CONSTRAINT cuentas_bancarias_tipo_cuenta_check CHECK (((tipo_cuenta)::text = ANY ((ARRAY['CORRIENTE'::character varying, 'AHORRO'::character varying])::text[])))
);


ALTER TABLE tesoreria.cuentas_bancarias OWNER TO postgres;

--
-- TOC entry 324 (class 1259 OID 17681)
-- Name: cuentas_bancarias_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.cuentas_bancarias_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.cuentas_bancarias_id_seq OWNER TO postgres;

--
-- TOC entry 5989 (class 0 OID 0)
-- Dependencies: 324
-- Name: cuentas_bancarias_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.cuentas_bancarias_id_seq OWNED BY tesoreria.cuentas_bancarias.id;


--
-- TOC entry 327 (class 1259 OID 17701)
-- Name: movimientos_bancarios; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.movimientos_bancarios (
    id bigint NOT NULL,
    cuenta_bancaria_id integer NOT NULL,
    tipo_movimiento character varying(20) NOT NULL,
    monto numeric(15,2) NOT NULL,
    referencia character varying(100) NOT NULL,
    concepto character varying(255) NOT NULL,
    fecha_movimiento date NOT NULL,
    usuario_id uuid NOT NULL,
    arqueo_origen_id bigint,
    CONSTRAINT movimientos_bancarios_monto_check CHECK ((monto > (0)::numeric)),
    CONSTRAINT movimientos_bancarios_tipo_movimiento_check CHECK (((tipo_movimiento)::text = ANY ((ARRAY['DEPOSITO'::character varying, 'RETIRO'::character varying, 'TRANSFERENCIA_SALIDA'::character varying, 'NOTA_CREDITO'::character varying, 'NOTA_DEBITO'::character varying, 'CHEQUE'::character varying])::text[])))
);


ALTER TABLE tesoreria.movimientos_bancarios OWNER TO postgres;

--
-- TOC entry 326 (class 1259 OID 17700)
-- Name: movimientos_bancarios_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.movimientos_bancarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.movimientos_bancarios_id_seq OWNER TO postgres;

--
-- TOC entry 5990 (class 0 OID 0)
-- Dependencies: 326
-- Name: movimientos_bancarios_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.movimientos_bancarios_id_seq OWNED BY tesoreria.movimientos_bancarios.id;


--
-- TOC entry 277 (class 1259 OID 17020)
-- Name: arqueo_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.arqueo_detalles (
    id bigint NOT NULL,
    sesion_caja_id bigint NOT NULL,
    metodo_pago_id integer NOT NULL,
    monto_declarado_cajero numeric(12,2) NOT NULL,
    monto_esperado_sistema numeric(12,2) NOT NULL,
    diferencia numeric(12,2) GENERATED ALWAYS AS ((monto_declarado_cajero - monto_esperado_sistema)) STORED,
    cantidad_vouchers integer DEFAULT 0,
    estado_deposito character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    CONSTRAINT arqueo_detalles_cantidad_vouchers_check CHECK ((cantidad_vouchers >= 0)),
    CONSTRAINT arqueo_detalles_estado_deposito_check CHECK (((estado_deposito)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'DEPOSITADO'::character varying])::text[]))),
    CONSTRAINT arqueo_detalles_monto_declarado_cajero_check CHECK ((monto_declarado_cajero >= (0)::numeric))
);


ALTER TABLE ventas.arqueo_detalles OWNER TO postgres;

--
-- TOC entry 276 (class 1259 OID 17019)
-- Name: arqueo_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.arqueo_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.arqueo_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5991 (class 0 OID 0)
-- Dependencies: 276
-- Name: arqueo_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.arqueo_detalles_id_seq OWNED BY ventas.arqueo_detalles.id;


--
-- TOC entry 259 (class 1259 OID 16836)
-- Name: cajas_pos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cajas_pos (
    id integer NOT NULL,
    nombre_equipo character varying(50) NOT NULL,
    estado character varying(20) DEFAULT 'CERRADA'::character varying NOT NULL,
    CONSTRAINT cajas_pos_estado_check CHECK (((estado)::text = ANY ((ARRAY['ABIERTA'::character varying, 'CERRADA'::character varying, 'EN_ARQUEO'::character varying])::text[])))
);


ALTER TABLE ventas.cajas_pos OWNER TO postgres;

--
-- TOC entry 258 (class 1259 OID 16835)
-- Name: cajas_pos_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.cajas_pos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.cajas_pos_id_seq OWNER TO postgres;

--
-- TOC entry 5992 (class 0 OID 0)
-- Dependencies: 258
-- Name: cajas_pos_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cajas_pos_id_seq OWNED BY ventas.cajas_pos.id;


--
-- TOC entry 293 (class 1259 OID 17248)
-- Name: consignacion_facturas; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignacion_facturas (
    consignacion_id bigint NOT NULL,
    factura_id bigint NOT NULL
);


ALTER TABLE ventas.consignacion_facturas OWNER TO postgres;

--
-- TOC entry 292 (class 1259 OID 17225)
-- Name: consignaciones; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignaciones (
    id bigint NOT NULL,
    numero_consignacion character varying(30) NOT NULL,
    cliente_id uuid NOT NULL,
    responsable_evento character varying(100) NOT NULL,
    nombre_evento character varying(150) NOT NULL,
    fecha_despacho timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_estimada_retorno date NOT NULL,
    monto_deposito_garantia numeric(12,2) DEFAULT 0.00 NOT NULL,
    estado character varying(20) DEFAULT 'DESPACHADA'::character varying NOT NULL,
    supervisor_id uuid NOT NULL,
    CONSTRAINT consignaciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['DESPACHADA'::character varying, 'LIQUIDADA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT consignaciones_monto_deposito_garantia_check CHECK ((monto_deposito_garantia >= (0)::numeric))
);


ALTER TABLE ventas.consignaciones OWNER TO postgres;

--
-- TOC entry 295 (class 1259 OID 17266)
-- Name: consignaciones_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignaciones_detalle (
    id bigint NOT NULL,
    consignacion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint,
    cantidad_despachada integer NOT NULL,
    cantidad_devuelta integer DEFAULT 0 NOT NULL,
    cantidad_consumida integer GENERATED ALWAYS AS ((cantidad_despachada - cantidad_devuelta)) STORED,
    precio_pactado_unitario numeric(12,2) NOT NULL,
    CONSTRAINT chk_consig_devuelta CHECK (((cantidad_devuelta >= 0) AND (cantidad_devuelta <= cantidad_despachada))),
    CONSTRAINT consignaciones_detalle_cantidad_despachada_check CHECK ((cantidad_despachada > 0)),
    CONSTRAINT consignaciones_detalle_precio_pactado_unitario_check CHECK ((precio_pactado_unitario >= (0)::numeric))
);


ALTER TABLE ventas.consignaciones_detalle OWNER TO postgres;

--
-- TOC entry 294 (class 1259 OID 17265)
-- Name: consignaciones_detalle_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.consignaciones_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.consignaciones_detalle_id_seq OWNER TO postgres;

--
-- TOC entry 5993 (class 0 OID 0)
-- Dependencies: 294
-- Name: consignaciones_detalle_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.consignaciones_detalle_id_seq OWNED BY ventas.consignaciones_detalle.id;


--
-- TOC entry 291 (class 1259 OID 17224)
-- Name: consignaciones_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.consignaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.consignaciones_id_seq OWNER TO postgres;

--
-- TOC entry 5994 (class 0 OID 0)
-- Dependencies: 291
-- Name: consignaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.consignaciones_id_seq OWNED BY ventas.consignaciones.id;


--
-- TOC entry 279 (class 1259 OID 17051)
-- Name: cotizaciones; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cotizaciones (
    id bigint NOT NULL,
    numero_cotizacion character varying(30) NOT NULL,
    cliente_id uuid NOT NULL,
    vendedor_id uuid NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_vencimiento date NOT NULL,
    monto_subtotal numeric(12,2) NOT NULL,
    monto_itbis numeric(12,2) NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    factura_generada_id bigint,
    observaciones text,
    CONSTRAINT cotizaciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'CONVERTIDA_A_FACTURA'::character varying, 'VENCIDA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT cotizaciones_monto_itbis_check CHECK ((monto_itbis >= (0)::numeric)),
    CONSTRAINT cotizaciones_monto_subtotal_check CHECK ((monto_subtotal >= (0)::numeric)),
    CONSTRAINT cotizaciones_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.cotizaciones OWNER TO postgres;

--
-- TOC entry 281 (class 1259 OID 17085)
-- Name: cotizaciones_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cotizaciones_detalle (
    id bigint NOT NULL,
    cotizacion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    codigo_barra_id bigint,
    cantidad integer NOT NULL,
    precio_unitario numeric(12,2) NOT NULL,
    monto_itbis numeric(12,2) NOT NULL,
    monto_descuento numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    CONSTRAINT cotizaciones_detalle_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT cotizaciones_detalle_monto_descuento_check CHECK ((monto_descuento >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_monto_itbis_check CHECK ((monto_itbis >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_monto_total_check CHECK ((monto_total >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_precio_unitario_check CHECK ((precio_unitario >= (0)::numeric))
);


ALTER TABLE ventas.cotizaciones_detalle OWNER TO postgres;

--
-- TOC entry 280 (class 1259 OID 17084)
-- Name: cotizaciones_detalle_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.cotizaciones_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.cotizaciones_detalle_id_seq OWNER TO postgres;

--
-- TOC entry 5995 (class 0 OID 0)
-- Dependencies: 280
-- Name: cotizaciones_detalle_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cotizaciones_detalle_id_seq OWNED BY ventas.cotizaciones_detalle.id;


--
-- TOC entry 278 (class 1259 OID 17050)
-- Name: cotizaciones_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.cotizaciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.cotizaciones_id_seq OWNER TO postgres;

--
-- TOC entry 5996 (class 0 OID 0)
-- Dependencies: 278
-- Name: cotizaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cotizaciones_id_seq OWNED BY ventas.cotizaciones.id;


--
-- TOC entry 269 (class 1259 OID 16943)
-- Name: cuentas_por_cobrar; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cuentas_por_cobrar (
    id bigint NOT NULL,
    cliente_id uuid NOT NULL,
    factura_id bigint,
    monto_original numeric(12,2) NOT NULL,
    saldo_pendiente numeric(12,2) NOT NULL,
    estado character varying(20) NOT NULL,
    CONSTRAINT chk_cxc_saldo CHECK (((saldo_pendiente >= (0)::numeric) AND (saldo_pendiente <= monto_original))),
    CONSTRAINT cuentas_por_cobrar_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying, 'PAGADA'::character varying])::text[])))
);


ALTER TABLE ventas.cuentas_por_cobrar OWNER TO postgres;

--
-- TOC entry 268 (class 1259 OID 16942)
-- Name: cuentas_por_cobrar_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.cuentas_por_cobrar_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.cuentas_por_cobrar_id_seq OWNER TO postgres;

--
-- TOC entry 5997 (class 0 OID 0)
-- Dependencies: 268
-- Name: cuentas_por_cobrar_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cuentas_por_cobrar_id_seq OWNED BY ventas.cuentas_por_cobrar.id;


--
-- TOC entry 297 (class 1259 OID 17291)
-- Name: devoluciones; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.devoluciones (
    id bigint NOT NULL,
    factura_id bigint NOT NULL,
    secuencia_dgii_id integer,
    ncf_nota_credito character varying(13),
    ncf_factura_modificada character varying(13),
    monto_total numeric(12,2) NOT NULL,
    metodo_reembolso character varying(30) NOT NULL,
    justificacion_motivo text NOT NULL,
    supervisor_id uuid NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT devoluciones_metodo_reembolso_check CHECK (((metodo_reembolso)::text = ANY ((ARRAY['EFECTIVO'::character varying, 'TARJETA'::character varying, 'SALDO_FAVOR'::character varying, 'REVERSION_CXC'::character varying])::text[]))),
    CONSTRAINT devoluciones_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.devoluciones OWNER TO postgres;

--
-- TOC entry 299 (class 1259 OID 17317)
-- Name: devoluciones_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.devoluciones_detalles (
    id bigint NOT NULL,
    nota_credito_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint,
    cantidad integer NOT NULL,
    monto_reembolsado numeric(12,2) NOT NULL,
    reingresado_a_inventario boolean DEFAULT true NOT NULL,
    CONSTRAINT devoluciones_detalles_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT devoluciones_detalles_monto_reembolsado_check CHECK ((monto_reembolsado >= (0)::numeric))
);


ALTER TABLE ventas.devoluciones_detalles OWNER TO postgres;

--
-- TOC entry 298 (class 1259 OID 17316)
-- Name: devoluciones_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.devoluciones_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.devoluciones_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 5998 (class 0 OID 0)
-- Dependencies: 298
-- Name: devoluciones_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.devoluciones_detalles_id_seq OWNED BY ventas.devoluciones_detalles.id;


--
-- TOC entry 296 (class 1259 OID 17290)
-- Name: devoluciones_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.devoluciones_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.devoluciones_id_seq OWNER TO postgres;

--
-- TOC entry 5999 (class 0 OID 0)
-- Dependencies: 296
-- Name: devoluciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.devoluciones_id_seq OWNED BY ventas.devoluciones.id;


--
-- TOC entry 265 (class 1259 OID 16905)
-- Name: factura_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.factura_detalle (
    id bigint NOT NULL,
    factura_id bigint NOT NULL,
    numero_linea integer NOT NULL,
    producto_maestro_id bigint NOT NULL,
    cantidad_unidades integer NOT NULL,
    precio_unitario_his numeric(12,2) NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    CONSTRAINT chk_factdet_cantidad CHECK ((cantidad_unidades > 0))
);


ALTER TABLE ventas.factura_detalle OWNER TO postgres;

--
-- TOC entry 264 (class 1259 OID 16904)
-- Name: factura_detalle_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.factura_detalle_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.factura_detalle_id_seq OWNER TO postgres;

--
-- TOC entry 6000 (class 0 OID 0)
-- Dependencies: 264
-- Name: factura_detalle_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.factura_detalle_id_seq OWNED BY ventas.factura_detalle.id;


--
-- TOC entry 267 (class 1259 OID 16927)
-- Name: factura_pagos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.factura_pagos (
    id bigint NOT NULL,
    factura_id bigint NOT NULL,
    metodo_pago_id integer NOT NULL,
    monto_pagado numeric(12,2) NOT NULL
);


ALTER TABLE ventas.factura_pagos OWNER TO postgres;

--
-- TOC entry 266 (class 1259 OID 16926)
-- Name: factura_pagos_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.factura_pagos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.factura_pagos_id_seq OWNER TO postgres;

--
-- TOC entry 6001 (class 0 OID 0)
-- Dependencies: 266
-- Name: factura_pagos_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.factura_pagos_id_seq OWNED BY ventas.factura_pagos.id;


--
-- TOC entry 263 (class 1259 OID 16871)
-- Name: facturas; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.facturas (
    id bigint NOT NULL,
    numero_factura character varying(30) NOT NULL,
    sesion_caja_id bigint NOT NULL,
    cliente_id uuid NOT NULL,
    vendedor_id uuid NOT NULL,
    secuencia_dgii_id integer,
    tipo_ncf character varying(10) NOT NULL,
    ncf character varying(13),
    condicion_pago character varying(20) NOT NULL,
    monto_subtotal numeric(12,2) NOT NULL,
    monto_descuento numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_itbis numeric(12,2) NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    estado character varying(20) DEFAULT 'EMITIDA'::character varying NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_factura_descuento CHECK (((monto_descuento >= (0)::numeric) AND (monto_descuento <= monto_subtotal))),
    CONSTRAINT chk_factura_montos CHECK (((monto_subtotal >= (0)::numeric) AND (monto_total >= (0)::numeric))),
    CONSTRAINT facturas_condicion_pago_check CHECK (((condicion_pago)::text = ANY ((ARRAY['CONTADO'::character varying, 'CREDITO'::character varying])::text[])))
);


ALTER TABLE ventas.facturas OWNER TO postgres;

--
-- TOC entry 262 (class 1259 OID 16870)
-- Name: facturas_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.facturas_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.facturas_id_seq OWNER TO postgres;

--
-- TOC entry 6002 (class 0 OID 0)
-- Dependencies: 262
-- Name: facturas_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.facturas_id_seq OWNED BY ventas.facturas.id;


--
-- TOC entry 273 (class 1259 OID 16983)
-- Name: metodos_pago; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.metodos_pago (
    id integer NOT NULL,
    codigo character varying(20) NOT NULL,
    descripcion character varying(50) NOT NULL,
    requiere_referencia boolean DEFAULT false NOT NULL,
    activo boolean DEFAULT true NOT NULL
);


ALTER TABLE ventas.metodos_pago OWNER TO postgres;

--
-- TOC entry 272 (class 1259 OID 16982)
-- Name: metodos_pago_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.metodos_pago_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.metodos_pago_id_seq OWNER TO postgres;

--
-- TOC entry 6003 (class 0 OID 0)
-- Dependencies: 272
-- Name: metodos_pago_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.metodos_pago_id_seq OWNED BY ventas.metodos_pago.id;


--
-- TOC entry 275 (class 1259 OID 16999)
-- Name: movimiento_caja; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.movimiento_caja (
    id bigint NOT NULL,
    sesion_caja_id bigint NOT NULL,
    tipo_movimiento character varying(10) NOT NULL,
    monto numeric(12,2) NOT NULL,
    concepto character varying(255) NOT NULL,
    supervisor_id uuid,
    fecha_hora timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT movimiento_caja_monto_check CHECK ((monto > (0)::numeric)),
    CONSTRAINT movimiento_caja_tipo_movimiento_check CHECK (((tipo_movimiento)::text = ANY ((ARRAY['INGRESO'::character varying, 'EGRESO'::character varying])::text[])))
);


ALTER TABLE ventas.movimiento_caja OWNER TO postgres;

--
-- TOC entry 274 (class 1259 OID 16998)
-- Name: movimiento_caja_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.movimiento_caja_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.movimiento_caja_id_seq OWNER TO postgres;

--
-- TOC entry 6004 (class 0 OID 0)
-- Dependencies: 274
-- Name: movimiento_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.movimiento_caja_id_seq OWNED BY ventas.movimiento_caja.id;


--
-- TOC entry 303 (class 1259 OID 17369)
-- Name: nota_debito_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.nota_debito_detalles (
    id bigint NOT NULL,
    nota_debito_id bigint NOT NULL,
    producto_maestro_id bigint,
    descripcion_cargo character varying(150) NOT NULL,
    cantidad integer DEFAULT 1 NOT NULL,
    precio_unitario numeric(12,2) NOT NULL,
    monto_itbis numeric(12,2) NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    CONSTRAINT nota_debito_detalles_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT nota_debito_detalles_monto_itbis_check CHECK ((monto_itbis >= (0)::numeric)),
    CONSTRAINT nota_debito_detalles_monto_total_check CHECK ((monto_total >= (0)::numeric)),
    CONSTRAINT nota_debito_detalles_precio_unitario_check CHECK ((precio_unitario >= (0)::numeric))
);


ALTER TABLE ventas.nota_debito_detalles OWNER TO postgres;

--
-- TOC entry 302 (class 1259 OID 17368)
-- Name: nota_debito_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.nota_debito_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.nota_debito_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 6005 (class 0 OID 0)
-- Dependencies: 302
-- Name: nota_debito_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.nota_debito_detalles_id_seq OWNED BY ventas.nota_debito_detalles.id;


--
-- TOC entry 301 (class 1259 OID 17340)
-- Name: notas_debito; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.notas_debito (
    id bigint NOT NULL,
    factura_id bigint NOT NULL,
    cliente_id uuid NOT NULL,
    secuencia_dgii_id integer,
    ncf_nota_debito character varying(13) NOT NULL,
    ncf_factura_modificada character varying(13) NOT NULL,
    concepto_cargo character varying(255) NOT NULL,
    monto_subtotal numeric(12,2) NOT NULL,
    monto_itbis numeric(12,2) NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    usuario_emisor_id uuid NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT notas_debito_monto_itbis_check CHECK ((monto_itbis >= (0)::numeric)),
    CONSTRAINT notas_debito_monto_subtotal_check CHECK ((monto_subtotal >= (0)::numeric)),
    CONSTRAINT notas_debito_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.notas_debito OWNER TO postgres;

--
-- TOC entry 300 (class 1259 OID 17339)
-- Name: notas_debito_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.notas_debito_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.notas_debito_id_seq OWNER TO postgres;

--
-- TOC entry 6006 (class 0 OID 0)
-- Dependencies: 300
-- Name: notas_debito_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.notas_debito_id_seq OWNED BY ventas.notas_debito.id;


--
-- TOC entry 290 (class 1259 OID 17200)
-- Name: pedidos_delivery; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.pedidos_delivery (
    id bigint NOT NULL,
    factura_id bigint NOT NULL,
    repartidor_id uuid NOT NULL,
    direccion_destino text NOT NULL,
    zona_id integer NOT NULL,
    estado_delivery character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    hora_despacho timestamp without time zone,
    hora_entrega timestamp without time zone,
    CONSTRAINT chk_tiempos_delivery CHECK ((hora_entrega >= hora_despacho)),
    CONSTRAINT pedidos_delivery_estado_delivery_check CHECK (((estado_delivery)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_CAMINO'::character varying, 'ENTREGADO'::character varying, 'CANCELADO'::character varying])::text[])))
);


ALTER TABLE ventas.pedidos_delivery OWNER TO postgres;

--
-- TOC entry 289 (class 1259 OID 17199)
-- Name: pedidos_delivery_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.pedidos_delivery_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.pedidos_delivery_id_seq OWNER TO postgres;

--
-- TOC entry 6007 (class 0 OID 0)
-- Dependencies: 289
-- Name: pedidos_delivery_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.pedidos_delivery_id_seq OWNED BY ventas.pedidos_delivery.id;


--
-- TOC entry 271 (class 1259 OID 16959)
-- Name: recibo_ingreso_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.recibo_ingreso_detalles (
    id bigint NOT NULL,
    recibo_ingreso_id bigint NOT NULL,
    cuenta_por_cobrar_id bigint NOT NULL,
    monto_aplicado numeric(12,2) NOT NULL,
    CONSTRAINT recibo_ingreso_detalles_monto_aplicado_check CHECK ((monto_aplicado > (0)::numeric))
);


ALTER TABLE ventas.recibo_ingreso_detalles OWNER TO postgres;

--
-- TOC entry 270 (class 1259 OID 16958)
-- Name: recibo_ingreso_detalles_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.recibo_ingreso_detalles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.recibo_ingreso_detalles_id_seq OWNER TO postgres;

--
-- TOC entry 6008 (class 0 OID 0)
-- Dependencies: 270
-- Name: recibo_ingreso_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.recibo_ingreso_detalles_id_seq OWNED BY ventas.recibo_ingreso_detalles.id;


--
-- TOC entry 283 (class 1259 OID 17113)
-- Name: recibos_ingreso_cxc; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.recibos_ingreso_cxc (
    id bigint NOT NULL,
    numero_recibo character varying(30) NOT NULL,
    cliente_id uuid NOT NULL,
    sesion_caja_id bigint NOT NULL,
    cajero_id uuid NOT NULL,
    monto_total_recibido numeric(12,2) NOT NULL,
    metodo_pago_id integer NOT NULL,
    cuenta_bancaria_id integer,
    monto_devuelta numeric(12,2) DEFAULT 0.00 NOT NULL,
    referencia_pago character varying(100),
    fecha_recibo timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    saldo_a_favor_generado numeric(12,2) DEFAULT 0.00 NOT NULL,
    CONSTRAINT recibos_ingreso_cxc_monto_devuelta_check CHECK ((monto_devuelta >= (0)::numeric)),
    CONSTRAINT recibos_ingreso_cxc_monto_total_recibido_check CHECK ((monto_total_recibido > (0)::numeric)),
    CONSTRAINT recibos_ingreso_cxc_saldo_a_favor_generado_check CHECK ((saldo_a_favor_generado >= (0)::numeric))
);


ALTER TABLE ventas.recibos_ingreso_cxc OWNER TO postgres;

--
-- TOC entry 282 (class 1259 OID 17112)
-- Name: recibos_ingreso_cxc_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.recibos_ingreso_cxc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.recibos_ingreso_cxc_id_seq OWNER TO postgres;

--
-- TOC entry 6009 (class 0 OID 0)
-- Dependencies: 282
-- Name: recibos_ingreso_cxc_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.recibos_ingreso_cxc_id_seq OWNED BY ventas.recibos_ingreso_cxc.id;


--
-- TOC entry 288 (class 1259 OID 17184)
-- Name: repartidor_horarios; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.repartidor_horarios (
    id bigint NOT NULL,
    repartidor_id uuid NOT NULL,
    dia_semana integer NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    CONSTRAINT chk_horario_logico CHECK ((hora_fin > hora_inicio)),
    CONSTRAINT repartidor_horarios_dia_semana_check CHECK (((dia_semana >= 1) AND (dia_semana <= 7)))
);


ALTER TABLE ventas.repartidor_horarios OWNER TO postgres;

--
-- TOC entry 287 (class 1259 OID 17183)
-- Name: repartidor_horarios_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.repartidor_horarios_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.repartidor_horarios_id_seq OWNER TO postgres;

--
-- TOC entry 6010 (class 0 OID 0)
-- Dependencies: 287
-- Name: repartidor_horarios_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.repartidor_horarios_id_seq OWNED BY ventas.repartidor_horarios.id;


--
-- TOC entry 286 (class 1259 OID 17164)
-- Name: repartidor_zonas; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.repartidor_zonas (
    repartidor_id uuid NOT NULL,
    zona_id integer NOT NULL,
    es_ruta_principal boolean DEFAULT false NOT NULL
);


ALTER TABLE ventas.repartidor_zonas OWNER TO postgres;

--
-- TOC entry 257 (class 1259 OID 16820)
-- Name: secuencias_fiscales_dgii; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.secuencias_fiscales_dgii (
    id integer NOT NULL,
    tipo_comprobante character varying(10) NOT NULL,
    secuencia_desde bigint NOT NULL,
    secuencia_hasta bigint NOT NULL,
    secuencia_actual bigint NOT NULL,
    fecha_vencimiento date NOT NULL,
    estado character varying(20) DEFAULT 'ACTIVO'::character varying NOT NULL
);


ALTER TABLE ventas.secuencias_fiscales_dgii OWNER TO postgres;

--
-- TOC entry 256 (class 1259 OID 16819)
-- Name: secuencias_fiscales_dgii_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.secuencias_fiscales_dgii_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.secuencias_fiscales_dgii_id_seq OWNER TO postgres;

--
-- TOC entry 6011 (class 0 OID 0)
-- Dependencies: 256
-- Name: secuencias_fiscales_dgii_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.secuencias_fiscales_dgii_id_seq OWNED BY ventas.secuencias_fiscales_dgii.id;


--
-- TOC entry 261 (class 1259 OID 16848)
-- Name: sesiones_caja; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.sesiones_caja (
    id bigint NOT NULL,
    caja_id integer NOT NULL,
    cajero_id uuid NOT NULL,
    monto_inicial_efectivo numeric(12,2) NOT NULL,
    estado character varying(20) DEFAULT 'ABIERTA'::character varying NOT NULL,
    fecha_apertura timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    fecha_cierre timestamp without time zone,
    CONSTRAINT sesiones_caja_estado_check CHECK (((estado)::text = ANY ((ARRAY['ABIERTA'::character varying, 'EN_CUADRE'::character varying, 'CERRADA'::character varying])::text[])))
);


ALTER TABLE ventas.sesiones_caja OWNER TO postgres;

--
-- TOC entry 260 (class 1259 OID 16847)
-- Name: sesiones_caja_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.sesiones_caja_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.sesiones_caja_id_seq OWNER TO postgres;

--
-- TOC entry 6012 (class 0 OID 0)
-- Dependencies: 260
-- Name: sesiones_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.sesiones_caja_id_seq OWNED BY ventas.sesiones_caja.id;


--
-- TOC entry 285 (class 1259 OID 17148)
-- Name: zonas_delivery; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.zonas_delivery (
    id integer NOT NULL,
    nombre character varying(100) NOT NULL,
    tarifa_envio numeric(12,2) NOT NULL,
    tiempo_promedio_min integer DEFAULT 30,
    activa boolean DEFAULT true NOT NULL,
    CONSTRAINT zonas_delivery_tarifa_envio_check CHECK ((tarifa_envio >= (0)::numeric)),
    CONSTRAINT zonas_delivery_tiempo_promedio_min_check CHECK ((tiempo_promedio_min > 0))
);


ALTER TABLE ventas.zonas_delivery OWNER TO postgres;

--
-- TOC entry 284 (class 1259 OID 17147)
-- Name: zonas_delivery_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.zonas_delivery_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.zonas_delivery_id_seq OWNER TO postgres;

--
-- TOC entry 6013 (class 0 OID 0)
-- Dependencies: 284
-- Name: zonas_delivery_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.zonas_delivery_id_seq OWNED BY ventas.zonas_delivery.id;


--
-- TOC entry 5266 (class 2604 OID 17562)
-- Name: comprobante_egreso_detalles id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles ALTER COLUMN id SET DEFAULT nextval('compras.comprobante_egreso_detalles_id_seq'::regclass);


--
-- TOC entry 5264 (class 2604 OID 17539)
-- Name: comprobantes_egreso_cxp id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp ALTER COLUMN id SET DEFAULT nextval('compras.comprobantes_egreso_cxp_id_seq'::regclass);


--
-- TOC entry 5262 (class 2604 OID 17510)
-- Name: cuentas_por_pagar id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar ALTER COLUMN id SET DEFAULT nextval('compras.cuentas_por_pagar_id_seq'::regclass);


--
-- TOC entry 5257 (class 2604 OID 17428)
-- Name: orden_compra_detalles id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles ALTER COLUMN id SET DEFAULT nextval('compras.orden_compra_detalles_id_seq'::regclass);


--
-- TOC entry 5254 (class 2604 OID 17403)
-- Name: ordenes_compra id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra ALTER COLUMN id SET DEFAULT nextval('compras.ordenes_compra_id_seq'::regclass);


--
-- TOC entry 5261 (class 2604 OID 17483)
-- Name: recepcion_compra_detalles id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles ALTER COLUMN id SET DEFAULT nextval('compras.recepcion_compra_detalles_id_seq'::regclass);


--
-- TOC entry 5259 (class 2604 OID 17454)
-- Name: recepciones_compra id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra ALTER COLUMN id SET DEFAULT nextval('compras.recepciones_compra_id_seq'::regclass);


--
-- TOC entry 5190 (class 2604 OID 16714)
-- Name: almacenes id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes ALTER COLUMN id SET DEFAULT nextval('inventario.almacenes_id_seq'::regclass);


--
-- TOC entry 5179 (class 2604 OID 16634)
-- Name: categoria_producto id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.categoria_producto ALTER COLUMN id SET DEFAULT nextval('inventario.categoria_producto_id_seq'::regclass);


--
-- TOC entry 5193 (class 2604 OID 16747)
-- Name: inventario_stock id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock ALTER COLUMN id SET DEFAULT nextval('inventario.inventario_stock_id_seq'::regclass);


--
-- TOC entry 5192 (class 2604 OID 16727)
-- Name: lotes id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes ALTER COLUMN id SET DEFAULT nextval('inventario.lotes_id_seq'::regclass);


--
-- TOC entry 5181 (class 2604 OID 16645)
-- Name: marca id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.marca ALTER COLUMN id SET DEFAULT nextval('inventario.marca_id_seq'::regclass);


--
-- TOC entry 5198 (class 2604 OID 16794)
-- Name: movimiento_inventario_detalles id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles ALTER COLUMN id SET DEFAULT nextval('inventario.movimiento_inventario_detalles_id_seq'::regclass);


--
-- TOC entry 5196 (class 2604 OID 16780)
-- Name: movimientos_inventario id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario ALTER COLUMN id SET DEFAULT nextval('inventario.movimientos_inventario_id_seq'::regclass);


--
-- TOC entry 5189 (class 2604 OID 16691)
-- Name: producto_codigos_barra id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra ALTER COLUMN id SET DEFAULT nextval('inventario.producto_codigos_barra_id_seq'::regclass);


--
-- TOC entry 5183 (class 2604 OID 16656)
-- Name: productos_maestro id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro ALTER COLUMN id SET DEFAULT nextval('inventario.productos_maestro_id_seq'::regclass);


--
-- TOC entry 5285 (class 2604 OID 17788)
-- Name: traslado_detalles id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles ALTER COLUMN id SET DEFAULT nextval('inventario.traslado_detalles_id_seq'::regclass);


--
-- TOC entry 5282 (class 2604 OID 17758)
-- Name: traslados_internos id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos ALTER COLUMN id SET DEFAULT nextval('inventario.traslados_internos_id_seq'::regclass);


--
-- TOC entry 5273 (class 2604 OID 17646)
-- Name: cupones_descuento id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento ALTER COLUMN id SET DEFAULT nextval('promociones.cupones_descuento_id_seq'::regclass);


--
-- TOC entry 5269 (class 2604 OID 17600)
-- Name: promociones_campanas id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas ALTER COLUMN id SET DEFAULT nextval('promociones.promociones_campanas_id_seq'::regclass);


--
-- TOC entry 5271 (class 2604 OID 17620)
-- Name: promociones_detalles id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles ALTER COLUMN id SET DEFAULT nextval('promociones.promociones_detalles_id_seq'::regclass);


--
-- TOC entry 5177 (class 2604 OID 16607)
-- Name: auditoria auditoria_id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria ALTER COLUMN auditoria_id SET DEFAULT nextval('seguridad.auditoria_auditoria_id_seq'::regclass);


--
-- TOC entry 5275 (class 2604 OID 17685)
-- Name: cuentas_bancarias id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias ALTER COLUMN id SET DEFAULT nextval('tesoreria.cuentas_bancarias_id_seq'::regclass);


--
-- TOC entry 5279 (class 2604 OID 17704)
-- Name: movimientos_bancarios id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios ALTER COLUMN id SET DEFAULT nextval('tesoreria.movimientos_bancarios_id_seq'::regclass);


--
-- TOC entry 5219 (class 2604 OID 17023)
-- Name: arqueo_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.arqueo_detalles_id_seq'::regclass);


--
-- TOC entry 5201 (class 2604 OID 16839)
-- Name: cajas_pos id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cajas_pos ALTER COLUMN id SET DEFAULT nextval('ventas.cajas_pos_id_seq'::regclass);


--
-- TOC entry 5239 (class 2604 OID 17228)
-- Name: consignaciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones ALTER COLUMN id SET DEFAULT nextval('ventas.consignaciones_id_seq'::regclass);


--
-- TOC entry 5243 (class 2604 OID 17269)
-- Name: consignaciones_detalle id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle ALTER COLUMN id SET DEFAULT nextval('ventas.consignaciones_detalle_id_seq'::regclass);


--
-- TOC entry 5223 (class 2604 OID 17054)
-- Name: cotizaciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones ALTER COLUMN id SET DEFAULT nextval('ventas.cotizaciones_id_seq'::regclass);


--
-- TOC entry 5226 (class 2604 OID 17088)
-- Name: cotizaciones_detalle id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle ALTER COLUMN id SET DEFAULT nextval('ventas.cotizaciones_detalle_id_seq'::regclass);


--
-- TOC entry 5212 (class 2604 OID 16946)
-- Name: cuentas_por_cobrar id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar ALTER COLUMN id SET DEFAULT nextval('ventas.cuentas_por_cobrar_id_seq'::regclass);


--
-- TOC entry 5246 (class 2604 OID 17294)
-- Name: devoluciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones ALTER COLUMN id SET DEFAULT nextval('ventas.devoluciones_id_seq'::regclass);


--
-- TOC entry 5248 (class 2604 OID 17320)
-- Name: devoluciones_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.devoluciones_detalles_id_seq'::regclass);


--
-- TOC entry 5210 (class 2604 OID 16908)
-- Name: factura_detalle id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle ALTER COLUMN id SET DEFAULT nextval('ventas.factura_detalle_id_seq'::regclass);


--
-- TOC entry 5211 (class 2604 OID 16930)
-- Name: factura_pagos id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos ALTER COLUMN id SET DEFAULT nextval('ventas.factura_pagos_id_seq'::regclass);


--
-- TOC entry 5206 (class 2604 OID 16874)
-- Name: facturas id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas ALTER COLUMN id SET DEFAULT nextval('ventas.facturas_id_seq'::regclass);


--
-- TOC entry 5214 (class 2604 OID 16986)
-- Name: metodos_pago id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago ALTER COLUMN id SET DEFAULT nextval('ventas.metodos_pago_id_seq'::regclass);


--
-- TOC entry 5217 (class 2604 OID 17002)
-- Name: movimiento_caja id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja ALTER COLUMN id SET DEFAULT nextval('ventas.movimiento_caja_id_seq'::regclass);


--
-- TOC entry 5252 (class 2604 OID 17372)
-- Name: nota_debito_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.nota_debito_detalles_id_seq'::regclass);


--
-- TOC entry 5250 (class 2604 OID 17343)
-- Name: notas_debito id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito ALTER COLUMN id SET DEFAULT nextval('ventas.notas_debito_id_seq'::regclass);


--
-- TOC entry 5237 (class 2604 OID 17203)
-- Name: pedidos_delivery id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery ALTER COLUMN id SET DEFAULT nextval('ventas.pedidos_delivery_id_seq'::regclass);


--
-- TOC entry 5213 (class 2604 OID 16962)
-- Name: recibo_ingreso_detalles id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles ALTER COLUMN id SET DEFAULT nextval('ventas.recibo_ingreso_detalles_id_seq'::regclass);


--
-- TOC entry 5228 (class 2604 OID 17116)
-- Name: recibos_ingreso_cxc id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc ALTER COLUMN id SET DEFAULT nextval('ventas.recibos_ingreso_cxc_id_seq'::regclass);


--
-- TOC entry 5236 (class 2604 OID 17187)
-- Name: repartidor_horarios id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_horarios ALTER COLUMN id SET DEFAULT nextval('ventas.repartidor_horarios_id_seq'::regclass);


--
-- TOC entry 5199 (class 2604 OID 16823)
-- Name: secuencias_fiscales_dgii id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.secuencias_fiscales_dgii ALTER COLUMN id SET DEFAULT nextval('ventas.secuencias_fiscales_dgii_id_seq'::regclass);


--
-- TOC entry 5203 (class 2604 OID 16851)
-- Name: sesiones_caja id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja ALTER COLUMN id SET DEFAULT nextval('ventas.sesiones_caja_id_seq'::regclass);


--
-- TOC entry 5232 (class 2604 OID 17151)
-- Name: zonas_delivery id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery ALTER COLUMN id SET DEFAULT nextval('ventas.zonas_delivery_id_seq'::regclass);


--
-- TOC entry 5945 (class 0 OID 17559)
-- Dependencies: 317
-- Data for Name: comprobante_egreso_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.comprobante_egreso_detalles (id, comprobante_egreso_id, cuenta_por_pagar_id, monto_aplicado, monto_retencion_isr, monto_retencion_itbis, saldo_anterior, saldo_restante) FROM stdin;
\.


--
-- TOC entry 5943 (class 0 OID 17536)
-- Dependencies: 315
-- Data for Name: comprobantes_egreso_cxp; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.comprobantes_egreso_cxp (id, numero_comprobante, proveedor_id, usuario_registro_id, monto_total_pagado, metodo_pago_id, cuenta_bancaria_id, referencia_pago, fecha_pago) FROM stdin;
\.


--
-- TOC entry 5941 (class 0 OID 17507)
-- Dependencies: 313
-- Data for Name: cuentas_por_pagar; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.cuentas_por_pagar (id, proveedor_id, recepcion_compra_id, monto_original, saldo_pendiente, fecha_emision, fecha_vencimiento, estado) FROM stdin;
\.


--
-- TOC entry 5935 (class 0 OID 17425)
-- Dependencies: 307
-- Data for Name: orden_compra_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.orden_compra_detalles (id, orden_compra_id, producto_maestro_id, cantidad_ordenada, cantidad_recibida_total, costo_pactado_unitario) FROM stdin;
\.


--
-- TOC entry 5933 (class 0 OID 17400)
-- Dependencies: 305
-- Data for Name: ordenes_compra; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.ordenes_compra (id, numero_orden, proveedor_id, usuario_id, estado, monto_total_estimado, fecha_emision, fecha_esperada) FROM stdin;
\.


--
-- TOC entry 5939 (class 0 OID 17480)
-- Dependencies: 311
-- Data for Name: recepcion_compra_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.recepcion_compra_detalles (id, recepcion_id, orden_detalle_id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_recibida_buena, costo_unitario_facturado) FROM stdin;
\.


--
-- TOC entry 5937 (class 0 OID 17451)
-- Dependencies: 309
-- Data for Name: recepciones_compra; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.recepciones_compra (id, orden_compra_id, proveedor_id, numero_factura_proveedor, ncf_proveedor, monto_total_factura, usuario_recepcion_id, fecha_recepcion) FROM stdin;
\.


--
-- TOC entry 5856 (class 0 OID 16425)
-- Dependencies: 228
-- Data for Name: clientes; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.clientes (cliente_id, persona_id, codigo_cliente, estado, limite_credito, creado_en) FROM stdin;
\.


--
-- TOC entry 5857 (class 0 OID 16451)
-- Dependencies: 229
-- Data for Name: empleados; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.empleados (empleado_id, persona_id, codigo_empleado, cargo, fecha_ingreso, salario, estado, creado_en) FROM stdin;
\.


--
-- TOC entry 5855 (class 0 OID 16399)
-- Dependencies: 227
-- Data for Name: personas; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.personas (persona_id, tipo_documento, numero_documento, primer_nombre, segundo_nombre, primer_apellido, segundo_apellido, fecha_nacimiento, genero, email, telefono, direccion, creado_en, actualizado_en) FROM stdin;
\.


--
-- TOC entry 5858 (class 0 OID 16478)
-- Dependencies: 230
-- Data for Name: proveedores; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.proveedores (proveedor_id, ruc, razon_social, nombre_comercial, email_contacto, telefono_contacto, direccion, estado, creado_en) FROM stdin;
\.


--
-- TOC entry 5956 (class 0 OID 17734)
-- Dependencies: 328
-- Data for Name: repartidores; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.repartidores (empleado_id, numero_licencia, tipo_vehiculo, placa_vehiculo, estado_logistico, capacidad_pedidos) FROM stdin;
\.


--
-- TOC entry 5875 (class 0 OID 16711)
-- Dependencies: 247
-- Data for Name: almacenes; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.almacenes (id, nombre, ubicacion, activo) FROM stdin;
\.


--
-- TOC entry 5867 (class 0 OID 16631)
-- Dependencies: 239
-- Data for Name: categoria_producto; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.categoria_producto (id, nombre, activo) FROM stdin;
\.


--
-- TOC entry 5879 (class 0 OID 16744)
-- Dependencies: 251
-- Data for Name: inventario_stock; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.inventario_stock (id, almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido) FROM stdin;
\.


--
-- TOC entry 5877 (class 0 OID 16724)
-- Dependencies: 249
-- Data for Name: lotes; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.lotes (id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual) FROM stdin;
\.


--
-- TOC entry 5869 (class 0 OID 16642)
-- Dependencies: 241
-- Data for Name: marca; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.marca (id, nombre, activo) FROM stdin;
\.


--
-- TOC entry 5883 (class 0 OID 16791)
-- Dependencies: 255
-- Data for Name: movimiento_inventario_detalles; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.movimiento_inventario_detalles (id, movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia) FROM stdin;
\.


--
-- TOC entry 5881 (class 0 OID 16777)
-- Dependencies: 253
-- Data for Name: movimientos_inventario; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.movimientos_inventario (id, tipo_movimiento, justificacion, supervisor_id, documento_referencia_id, fecha_movimiento) FROM stdin;
\.


--
-- TOC entry 5873 (class 0 OID 16688)
-- Dependencies: 245
-- Data for Name: producto_codigos_barra; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.producto_codigos_barra (id, producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) FROM stdin;
\.


--
-- TOC entry 5871 (class 0 OID 16653)
-- Dependencies: 243
-- Data for Name: productos_maestro; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.productos_maestro (id, sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, impuesto_isc, porcentaje_itbis) FROM stdin;
\.


--
-- TOC entry 5960 (class 0 OID 17785)
-- Dependencies: 332
-- Data for Name: traslado_detalles; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.traslado_detalles (id, traslado_id, producto_maestro_id, lote_id, cantidad_enviada, cantidad_recibida) FROM stdin;
\.


--
-- TOC entry 5958 (class 0 OID 17755)
-- Dependencies: 330
-- Data for Name: traslados_internos; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.traslados_internos (id, numero_traslado, almacen_origen_id, almacen_destino_id, usuario_emisor_id, usuario_receptor_id, estado, fecha_emision) FROM stdin;
\.


--
-- TOC entry 5951 (class 0 OID 17643)
-- Dependencies: 323
-- Data for Name: cupones_descuento; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.cupones_descuento (id, codigo_cupon, promocion_id, factura_origen_id, factura_canje_id, cliente_id, monto_credito, fecha_vencimiento, estado) FROM stdin;
\.


--
-- TOC entry 5947 (class 0 OID 17597)
-- Dependencies: 319
-- Data for Name: promociones_campanas; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.promociones_campanas (id, nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, hora_inicio, hora_fin, dias_semana, monto_minimo_factura, estado) FROM stdin;
\.


--
-- TOC entry 5949 (class 0 OID 17617)
-- Dependencies: 321
-- Data for Name: promociones_detalles; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.promociones_detalles (id, promocion_id, condicion_producto_id, condicion_categoria_id, cantidad_requerida, beneficio_producto_id, beneficio_porcentaje, beneficio_monto_fijo) FROM stdin;
\.


--
-- TOC entry 5865 (class 0 OID 16604)
-- Dependencies: 237
-- Data for Name: auditoria; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.auditoria (auditoria_id, usuario_id, tipo_evento, esquema_afectado, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_origen, agente_usuario, fecha_hora) FROM stdin;
\.


--
-- TOC entry 5861 (class 0 OID 16550)
-- Dependencies: 233
-- Data for Name: permisos; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.permisos (permiso_id, codigo, modulo, descripcion, creado_en) FROM stdin;
\.


--
-- TOC entry 5860 (class 0 OID 16534)
-- Dependencies: 232
-- Data for Name: roles; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.roles (rol_id, nombre, descripcion, es_activo, creado_en) FROM stdin;
\.


--
-- TOC entry 5863 (class 0 OID 16584)
-- Dependencies: 235
-- Data for Name: roles_permisos; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.roles_permisos (rol_id, permiso_id, asignado_en) FROM stdin;
\.


--
-- TOC entry 5859 (class 0 OID 16506)
-- Dependencies: 231
-- Data for Name: usuarios; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.usuarios (usuario_id, persona_id, username, password_hash, es_activo, requiere_cambio_password, ultimo_acceso, intentos_fallidos, bloqueado_hasta, creado_en) FROM stdin;
\.


--
-- TOC entry 5862 (class 0 OID 16565)
-- Dependencies: 234
-- Data for Name: usuarios_roles; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.usuarios_roles (usuario_id, rol_id, asignado_en) FROM stdin;
\.


--
-- TOC entry 5953 (class 0 OID 17682)
-- Dependencies: 325
-- Data for Name: cuentas_bancarias; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.cuentas_bancarias (id, nombre_banco, numero_cuenta, tipo_cuenta, moneda, saldo_actual, activa) FROM stdin;
\.


--
-- TOC entry 5955 (class 0 OID 17701)
-- Dependencies: 327
-- Data for Name: movimientos_bancarios; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.movimientos_bancarios (id, cuenta_bancaria_id, tipo_movimiento, monto, referencia, concepto, fecha_movimiento, usuario_id, arqueo_origen_id) FROM stdin;
\.


--
-- TOC entry 5905 (class 0 OID 17020)
-- Dependencies: 277
-- Data for Name: arqueo_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.arqueo_detalles (id, sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito) FROM stdin;
\.


--
-- TOC entry 5887 (class 0 OID 16836)
-- Dependencies: 259
-- Data for Name: cajas_pos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cajas_pos (id, nombre_equipo, estado) FROM stdin;
\.


--
-- TOC entry 5921 (class 0 OID 17248)
-- Dependencies: 293
-- Data for Name: consignacion_facturas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignacion_facturas (consignacion_id, factura_id) FROM stdin;
\.


--
-- TOC entry 5920 (class 0 OID 17225)
-- Dependencies: 292
-- Data for Name: consignaciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignaciones (id, numero_consignacion, cliente_id, responsable_evento, nombre_evento, fecha_despacho, fecha_estimada_retorno, monto_deposito_garantia, estado, supervisor_id) FROM stdin;
\.


--
-- TOC entry 5923 (class 0 OID 17266)
-- Dependencies: 295
-- Data for Name: consignaciones_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignaciones_detalle (id, consignacion_id, producto_maestro_id, lote_id, cantidad_despachada, cantidad_devuelta, precio_pactado_unitario) FROM stdin;
\.


--
-- TOC entry 5907 (class 0 OID 17051)
-- Dependencies: 279
-- Data for Name: cotizaciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cotizaciones (id, numero_cotizacion, cliente_id, vendedor_id, fecha_emision, fecha_vencimiento, monto_subtotal, monto_itbis, monto_total, estado, factura_generada_id, observaciones) FROM stdin;
\.


--
-- TOC entry 5909 (class 0 OID 17085)
-- Dependencies: 281
-- Data for Name: cotizaciones_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cotizaciones_detalle (id, cotizacion_id, producto_maestro_id, codigo_barra_id, cantidad, precio_unitario, monto_itbis, monto_descuento, monto_total) FROM stdin;
\.


--
-- TOC entry 5897 (class 0 OID 16943)
-- Dependencies: 269
-- Data for Name: cuentas_por_cobrar; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cuentas_por_cobrar (id, cliente_id, factura_id, monto_original, saldo_pendiente, estado) FROM stdin;
\.


--
-- TOC entry 5925 (class 0 OID 17291)
-- Dependencies: 297
-- Data for Name: devoluciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.devoluciones (id, factura_id, secuencia_dgii_id, ncf_nota_credito, ncf_factura_modificada, monto_total, metodo_reembolso, justificacion_motivo, supervisor_id, fecha_emision) FROM stdin;
\.


--
-- TOC entry 5927 (class 0 OID 17317)
-- Dependencies: 299
-- Data for Name: devoluciones_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.devoluciones_detalles (id, nota_credito_id, producto_maestro_id, lote_id, cantidad, monto_reembolsado, reingresado_a_inventario) FROM stdin;
\.


--
-- TOC entry 5893 (class 0 OID 16905)
-- Dependencies: 265
-- Data for Name: factura_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.factura_detalle (id, factura_id, numero_linea, producto_maestro_id, cantidad_unidades, precio_unitario_his, monto_total) FROM stdin;
\.


--
-- TOC entry 5895 (class 0 OID 16927)
-- Dependencies: 267
-- Data for Name: factura_pagos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.factura_pagos (id, factura_id, metodo_pago_id, monto_pagado) FROM stdin;
\.


--
-- TOC entry 5891 (class 0 OID 16871)
-- Dependencies: 263
-- Data for Name: facturas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.facturas (id, numero_factura, sesion_caja_id, cliente_id, vendedor_id, secuencia_dgii_id, tipo_ncf, ncf, condicion_pago, monto_subtotal, monto_descuento, monto_itbis, monto_total, estado, fecha_emision) FROM stdin;
\.


--
-- TOC entry 5901 (class 0 OID 16983)
-- Dependencies: 273
-- Data for Name: metodos_pago; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.metodos_pago (id, codigo, descripcion, requiere_referencia, activo) FROM stdin;
\.


--
-- TOC entry 5903 (class 0 OID 16999)
-- Dependencies: 275
-- Data for Name: movimiento_caja; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.movimiento_caja (id, sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora) FROM stdin;
\.


--
-- TOC entry 5931 (class 0 OID 17369)
-- Dependencies: 303
-- Data for Name: nota_debito_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.nota_debito_detalles (id, nota_debito_id, producto_maestro_id, descripcion_cargo, cantidad, precio_unitario, monto_itbis, monto_total) FROM stdin;
\.


--
-- TOC entry 5929 (class 0 OID 17340)
-- Dependencies: 301
-- Data for Name: notas_debito; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.notas_debito (id, factura_id, cliente_id, secuencia_dgii_id, ncf_nota_debito, ncf_factura_modificada, concepto_cargo, monto_subtotal, monto_itbis, monto_total, usuario_emisor_id, fecha_emision) FROM stdin;
\.


--
-- TOC entry 5918 (class 0 OID 17200)
-- Dependencies: 290
-- Data for Name: pedidos_delivery; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.pedidos_delivery (id, factura_id, repartidor_id, direccion_destino, zona_id, estado_delivery, hora_despacho, hora_entrega) FROM stdin;
\.


--
-- TOC entry 5899 (class 0 OID 16959)
-- Dependencies: 271
-- Data for Name: recibo_ingreso_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.recibo_ingreso_detalles (id, recibo_ingreso_id, cuenta_por_cobrar_id, monto_aplicado) FROM stdin;
\.


--
-- TOC entry 5911 (class 0 OID 17113)
-- Dependencies: 283
-- Data for Name: recibos_ingreso_cxc; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.recibos_ingreso_cxc (id, numero_recibo, cliente_id, sesion_caja_id, cajero_id, monto_total_recibido, metodo_pago_id, cuenta_bancaria_id, monto_devuelta, referencia_pago, fecha_recibo, saldo_a_favor_generado) FROM stdin;
\.


--
-- TOC entry 5916 (class 0 OID 17184)
-- Dependencies: 288
-- Data for Name: repartidor_horarios; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.repartidor_horarios (id, repartidor_id, dia_semana, hora_inicio, hora_fin) FROM stdin;
\.


--
-- TOC entry 5914 (class 0 OID 17164)
-- Dependencies: 286
-- Data for Name: repartidor_zonas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.repartidor_zonas (repartidor_id, zona_id, es_ruta_principal) FROM stdin;
\.


--
-- TOC entry 5885 (class 0 OID 16820)
-- Dependencies: 257
-- Data for Name: secuencias_fiscales_dgii; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.secuencias_fiscales_dgii (id, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) FROM stdin;
\.


--
-- TOC entry 5889 (class 0 OID 16848)
-- Dependencies: 261
-- Data for Name: sesiones_caja; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.sesiones_caja (id, caja_id, cajero_id, monto_inicial_efectivo, estado, fecha_apertura, fecha_cierre) FROM stdin;
\.


--
-- TOC entry 5913 (class 0 OID 17148)
-- Dependencies: 285
-- Data for Name: zonas_delivery; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.zonas_delivery (id, nombre, tarifa_envio, tiempo_promedio_min, activa) FROM stdin;
\.


--
-- TOC entry 6014 (class 0 OID 0)
-- Dependencies: 316
-- Name: comprobante_egreso_detalles_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.comprobante_egreso_detalles_id_seq', 1, false);


--
-- TOC entry 6015 (class 0 OID 0)
-- Dependencies: 314
-- Name: comprobantes_egreso_cxp_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.comprobantes_egreso_cxp_id_seq', 1, false);


--
-- TOC entry 6016 (class 0 OID 0)
-- Dependencies: 312
-- Name: cuentas_por_pagar_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.cuentas_por_pagar_id_seq', 1, false);


--
-- TOC entry 6017 (class 0 OID 0)
-- Dependencies: 306
-- Name: orden_compra_detalles_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.orden_compra_detalles_id_seq', 1, false);


--
-- TOC entry 6018 (class 0 OID 0)
-- Dependencies: 304
-- Name: ordenes_compra_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.ordenes_compra_id_seq', 1, false);


--
-- TOC entry 6019 (class 0 OID 0)
-- Dependencies: 310
-- Name: recepcion_compra_detalles_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.recepcion_compra_detalles_id_seq', 1, false);


--
-- TOC entry 6020 (class 0 OID 0)
-- Dependencies: 308
-- Name: recepciones_compra_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.recepciones_compra_id_seq', 1, false);


--
-- TOC entry 6021 (class 0 OID 0)
-- Dependencies: 246
-- Name: almacenes_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.almacenes_id_seq', 1, false);


--
-- TOC entry 6022 (class 0 OID 0)
-- Dependencies: 238
-- Name: categoria_producto_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.categoria_producto_id_seq', 1, false);


--
-- TOC entry 6023 (class 0 OID 0)
-- Dependencies: 250
-- Name: inventario_stock_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.inventario_stock_id_seq', 1, false);


--
-- TOC entry 6024 (class 0 OID 0)
-- Dependencies: 248
-- Name: lotes_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.lotes_id_seq', 1, false);


--
-- TOC entry 6025 (class 0 OID 0)
-- Dependencies: 240
-- Name: marca_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.marca_id_seq', 1, false);


--
-- TOC entry 6026 (class 0 OID 0)
-- Dependencies: 254
-- Name: movimiento_inventario_detalles_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.movimiento_inventario_detalles_id_seq', 1, false);


--
-- TOC entry 6027 (class 0 OID 0)
-- Dependencies: 252
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.movimientos_inventario_id_seq', 1, false);


--
-- TOC entry 6028 (class 0 OID 0)
-- Dependencies: 244
-- Name: producto_codigos_barra_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.producto_codigos_barra_id_seq', 1, false);


--
-- TOC entry 6029 (class 0 OID 0)
-- Dependencies: 242
-- Name: productos_maestro_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.productos_maestro_id_seq', 1, false);


--
-- TOC entry 6030 (class 0 OID 0)
-- Dependencies: 331
-- Name: traslado_detalles_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.traslado_detalles_id_seq', 1, false);


--
-- TOC entry 6031 (class 0 OID 0)
-- Dependencies: 329
-- Name: traslados_internos_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.traslados_internos_id_seq', 1, false);


--
-- TOC entry 6032 (class 0 OID 0)
-- Dependencies: 322
-- Name: cupones_descuento_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.cupones_descuento_id_seq', 1, false);


--
-- TOC entry 6033 (class 0 OID 0)
-- Dependencies: 318
-- Name: promociones_campanas_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.promociones_campanas_id_seq', 1, false);


--
-- TOC entry 6034 (class 0 OID 0)
-- Dependencies: 320
-- Name: promociones_detalles_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.promociones_detalles_id_seq', 1, false);


--
-- TOC entry 6035 (class 0 OID 0)
-- Dependencies: 236
-- Name: auditoria_auditoria_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: postgres
--

SELECT pg_catalog.setval('seguridad.auditoria_auditoria_id_seq', 1, false);


--
-- TOC entry 6036 (class 0 OID 0)
-- Dependencies: 324
-- Name: cuentas_bancarias_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.cuentas_bancarias_id_seq', 1, false);


--
-- TOC entry 6037 (class 0 OID 0)
-- Dependencies: 326
-- Name: movimientos_bancarios_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.movimientos_bancarios_id_seq', 1, false);


--
-- TOC entry 6038 (class 0 OID 0)
-- Dependencies: 276
-- Name: arqueo_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.arqueo_detalles_id_seq', 1, false);


--
-- TOC entry 6039 (class 0 OID 0)
-- Dependencies: 258
-- Name: cajas_pos_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cajas_pos_id_seq', 1, false);


--
-- TOC entry 6040 (class 0 OID 0)
-- Dependencies: 294
-- Name: consignaciones_detalle_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.consignaciones_detalle_id_seq', 1, false);


--
-- TOC entry 6041 (class 0 OID 0)
-- Dependencies: 291
-- Name: consignaciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.consignaciones_id_seq', 1, false);


--
-- TOC entry 6042 (class 0 OID 0)
-- Dependencies: 280
-- Name: cotizaciones_detalle_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cotizaciones_detalle_id_seq', 1, false);


--
-- TOC entry 6043 (class 0 OID 0)
-- Dependencies: 278
-- Name: cotizaciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cotizaciones_id_seq', 1, false);


--
-- TOC entry 6044 (class 0 OID 0)
-- Dependencies: 268
-- Name: cuentas_por_cobrar_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cuentas_por_cobrar_id_seq', 1, false);


--
-- TOC entry 6045 (class 0 OID 0)
-- Dependencies: 298
-- Name: devoluciones_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.devoluciones_detalles_id_seq', 1, false);


--
-- TOC entry 6046 (class 0 OID 0)
-- Dependencies: 296
-- Name: devoluciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.devoluciones_id_seq', 1, false);


--
-- TOC entry 6047 (class 0 OID 0)
-- Dependencies: 264
-- Name: factura_detalle_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.factura_detalle_id_seq', 1, false);


--
-- TOC entry 6048 (class 0 OID 0)
-- Dependencies: 266
-- Name: factura_pagos_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.factura_pagos_id_seq', 1, false);


--
-- TOC entry 6049 (class 0 OID 0)
-- Dependencies: 262
-- Name: facturas_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.facturas_id_seq', 1, false);


--
-- TOC entry 6050 (class 0 OID 0)
-- Dependencies: 272
-- Name: metodos_pago_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.metodos_pago_id_seq', 1, false);


--
-- TOC entry 6051 (class 0 OID 0)
-- Dependencies: 274
-- Name: movimiento_caja_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.movimiento_caja_id_seq', 1, false);


--
-- TOC entry 6052 (class 0 OID 0)
-- Dependencies: 302
-- Name: nota_debito_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.nota_debito_detalles_id_seq', 1, false);


--
-- TOC entry 6053 (class 0 OID 0)
-- Dependencies: 300
-- Name: notas_debito_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.notas_debito_id_seq', 1, false);


--
-- TOC entry 6054 (class 0 OID 0)
-- Dependencies: 289
-- Name: pedidos_delivery_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.pedidos_delivery_id_seq', 1, false);


--
-- TOC entry 6055 (class 0 OID 0)
-- Dependencies: 270
-- Name: recibo_ingreso_detalles_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.recibo_ingreso_detalles_id_seq', 1, false);


--
-- TOC entry 6056 (class 0 OID 0)
-- Dependencies: 282
-- Name: recibos_ingreso_cxc_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.recibos_ingreso_cxc_id_seq', 1, false);


--
-- TOC entry 6057 (class 0 OID 0)
-- Dependencies: 287
-- Name: repartidor_horarios_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.repartidor_horarios_id_seq', 1, false);


--
-- TOC entry 6058 (class 0 OID 0)
-- Dependencies: 256
-- Name: secuencias_fiscales_dgii_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.secuencias_fiscales_dgii_id_seq', 1, false);


--
-- TOC entry 6059 (class 0 OID 0)
-- Dependencies: 260
-- Name: sesiones_caja_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.sesiones_caja_id_seq', 1, false);


--
-- TOC entry 6060 (class 0 OID 0)
-- Dependencies: 284
-- Name: zonas_delivery_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.zonas_delivery_id_seq', 1, false);


--
-- TOC entry 5604 (class 2606 OID 17578)
-- Name: comprobante_egreso_detalles comprobante_egreso_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT comprobante_egreso_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5600 (class 2606 OID 17552)
-- Name: comprobantes_egreso_cxp comprobantes_egreso_cxp_numero_comprobante_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT comprobantes_egreso_cxp_numero_comprobante_key UNIQUE (numero_comprobante);


--
-- TOC entry 5602 (class 2606 OID 17550)
-- Name: comprobantes_egreso_cxp comprobantes_egreso_cxp_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT comprobantes_egreso_cxp_pkey PRIMARY KEY (id);


--
-- TOC entry 5594 (class 2606 OID 17522)
-- Name: cuentas_por_pagar cuentas_por_pagar_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_pkey PRIMARY KEY (id);


--
-- TOC entry 5596 (class 2606 OID 17524)
-- Name: cuentas_por_pagar cuentas_por_pagar_recepcion_compra_id_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_recepcion_compra_id_key UNIQUE (recepcion_compra_id);


--
-- TOC entry 5579 (class 2606 OID 17437)
-- Name: orden_compra_detalles orden_compra_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT orden_compra_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5575 (class 2606 OID 17418)
-- Name: ordenes_compra ordenes_compra_numero_orden_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT ordenes_compra_numero_orden_key UNIQUE (numero_orden);


--
-- TOC entry 5577 (class 2606 OID 17416)
-- Name: ordenes_compra ordenes_compra_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT ordenes_compra_pkey PRIMARY KEY (id);


--
-- TOC entry 5590 (class 2606 OID 17493)
-- Name: recepcion_compra_detalles recepcion_compra_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT recepcion_compra_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5584 (class 2606 OID 17464)
-- Name: recepciones_compra recepciones_compra_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT recepciones_compra_pkey PRIMARY KEY (id);


--
-- TOC entry 5606 (class 2606 OID 17580)
-- Name: comprobante_egreso_detalles uk_egresodet_cxp; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT uk_egresodet_cxp UNIQUE (comprobante_egreso_id, cuenta_por_pagar_id);


--
-- TOC entry 5581 (class 2606 OID 17439)
-- Name: orden_compra_detalles uk_orden_producto; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT uk_orden_producto UNIQUE (orden_compra_id, producto_maestro_id);


--
-- TOC entry 5592 (class 2606 OID 17495)
-- Name: recepcion_compra_detalles uk_recdet_prod_lote; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT uk_recdet_prod_lote UNIQUE (recepcion_id, producto_maestro_id, numero_lote);


--
-- TOC entry 5586 (class 2606 OID 17468)
-- Name: recepciones_compra uk_recepcion_factura; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT uk_recepcion_factura UNIQUE (proveedor_id, numero_factura_proveedor);


--
-- TOC entry 5588 (class 2606 OID 17466)
-- Name: recepciones_compra uk_recepcion_ncf; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT uk_recepcion_ncf UNIQUE (proveedor_id, ncf_proveedor);


--
-- TOC entry 5391 (class 2606 OID 16441)
-- Name: clientes clientes_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT clientes_pkey PRIMARY KEY (cliente_id);


--
-- TOC entry 5398 (class 2606 OID 16468)
-- Name: empleados empleados_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT empleados_pkey PRIMARY KEY (empleado_id);


--
-- TOC entry 5385 (class 2606 OID 16420)
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (persona_id);


--
-- TOC entry 5406 (class 2606 OID 16496)
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (proveedor_id);


--
-- TOC entry 5636 (class 2606 OID 17748)
-- Name: repartidores repartidores_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.repartidores
    ADD CONSTRAINT repartidores_pkey PRIMARY KEY (empleado_id);


--
-- TOC entry 5394 (class 2606 OID 16445)
-- Name: clientes uk_clientes_codigo; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT uk_clientes_codigo UNIQUE (codigo_cliente);


--
-- TOC entry 5396 (class 2606 OID 16443)
-- Name: clientes uk_clientes_persona; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT uk_clientes_persona UNIQUE (persona_id);


--
-- TOC entry 5401 (class 2606 OID 16472)
-- Name: empleados uk_empleados_codigo; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT uk_empleados_codigo UNIQUE (codigo_empleado);


--
-- TOC entry 5403 (class 2606 OID 16470)
-- Name: empleados uk_empleados_persona; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT uk_empleados_persona UNIQUE (persona_id);


--
-- TOC entry 5387 (class 2606 OID 16422)
-- Name: personas uk_personas_documento; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.personas
    ADD CONSTRAINT uk_personas_documento UNIQUE (tipo_documento, numero_documento);


--
-- TOC entry 5389 (class 2606 OID 16424)
-- Name: personas uk_personas_email; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.personas
    ADD CONSTRAINT uk_personas_email UNIQUE (email);


--
-- TOC entry 5408 (class 2606 OID 16500)
-- Name: proveedores uk_proveedores_email; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT uk_proveedores_email UNIQUE (email_contacto);


--
-- TOC entry 5410 (class 2606 OID 16498)
-- Name: proveedores uk_proveedores_ruc; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT uk_proveedores_ruc UNIQUE (ruc);


--
-- TOC entry 5454 (class 2606 OID 16722)
-- Name: almacenes almacenes_nombre_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes
    ADD CONSTRAINT almacenes_nombre_key UNIQUE (nombre);


--
-- TOC entry 5456 (class 2606 OID 16720)
-- Name: almacenes almacenes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes
    ADD CONSTRAINT almacenes_pkey PRIMARY KEY (id);


--
-- TOC entry 5437 (class 2606 OID 16640)
-- Name: categoria_producto categoria_producto_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.categoria_producto
    ADD CONSTRAINT categoria_producto_pkey PRIMARY KEY (id);


--
-- TOC entry 5462 (class 2606 OID 16758)
-- Name: inventario_stock inventario_stock_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT inventario_stock_pkey PRIMARY KEY (id);


--
-- TOC entry 5458 (class 2606 OID 16735)
-- Name: lotes lotes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT lotes_pkey PRIMARY KEY (id);


--
-- TOC entry 5439 (class 2606 OID 16651)
-- Name: marca marca_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.marca
    ADD CONSTRAINT marca_pkey PRIMARY KEY (id);


--
-- TOC entry 5470 (class 2606 OID 16801)
-- Name: movimiento_inventario_detalles movimiento_inventario_detalles_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT movimiento_inventario_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5467 (class 2606 OID 16789)
-- Name: movimientos_inventario movimientos_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_pkey PRIMARY KEY (id);


--
-- TOC entry 5448 (class 2606 OID 16702)
-- Name: producto_codigos_barra producto_codigos_barra_codigo_barra_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT producto_codigos_barra_codigo_barra_key UNIQUE (codigo_barra);


--
-- TOC entry 5450 (class 2606 OID 16700)
-- Name: producto_codigos_barra producto_codigos_barra_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT producto_codigos_barra_pkey PRIMARY KEY (id);


--
-- TOC entry 5443 (class 2606 OID 16674)
-- Name: productos_maestro productos_maestro_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT productos_maestro_pkey PRIMARY KEY (id);


--
-- TOC entry 5445 (class 2606 OID 16676)
-- Name: productos_maestro productos_maestro_sku_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT productos_maestro_sku_key UNIQUE (sku);


--
-- TOC entry 5642 (class 2606 OID 17798)
-- Name: traslado_detalles traslado_detalles_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT traslado_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5638 (class 2606 OID 17773)
-- Name: traslados_internos traslados_internos_numero_traslado_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT traslados_internos_numero_traslado_key UNIQUE (numero_traslado);


--
-- TOC entry 5640 (class 2606 OID 17771)
-- Name: traslados_internos traslados_internos_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT traslados_internos_pkey PRIMARY KEY (id);


--
-- TOC entry 5460 (class 2606 OID 16737)
-- Name: lotes uk_lote_producto; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT uk_lote_producto UNIQUE (producto_maestro_id, numero_lote);


--
-- TOC entry 5452 (class 2606 OID 16704)
-- Name: producto_codigos_barra uk_prod_factor; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT uk_prod_factor UNIQUE (producto_maestro_id, factor_multiplicador);


--
-- TOC entry 5464 (class 2606 OID 16760)
-- Name: inventario_stock uk_stock_alm_prod_lote; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT uk_stock_alm_prod_lote UNIQUE (almacen_id, producto_maestro_id, lote_id);


--
-- TOC entry 5644 (class 2606 OID 17800)
-- Name: traslado_detalles uk_traslado_prod_lote; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT uk_traslado_prod_lote UNIQUE (traslado_id, producto_maestro_id, lote_id);


--
-- TOC entry 5617 (class 2606 OID 17660)
-- Name: cupones_descuento cupones_descuento_codigo_cupon_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_codigo_cupon_key UNIQUE (codigo_cupon);


--
-- TOC entry 5619 (class 2606 OID 17662)
-- Name: cupones_descuento cupones_descuento_factura_canje_id_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_factura_canje_id_key UNIQUE (factura_canje_id);


--
-- TOC entry 5621 (class 2606 OID 17658)
-- Name: cupones_descuento cupones_descuento_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_pkey PRIMARY KEY (id);


--
-- TOC entry 5608 (class 2606 OID 17615)
-- Name: promociones_campanas promociones_campanas_nombre_promocion_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas
    ADD CONSTRAINT promociones_campanas_nombre_promocion_key UNIQUE (nombre_promocion);


--
-- TOC entry 5610 (class 2606 OID 17613)
-- Name: promociones_campanas promociones_campanas_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas
    ADD CONSTRAINT promociones_campanas_pkey PRIMARY KEY (id);


--
-- TOC entry 5613 (class 2606 OID 17629)
-- Name: promociones_detalles promociones_detalles_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT promociones_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5615 (class 2606 OID 17631)
-- Name: promociones_detalles uk_promo_regla; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT uk_promo_regla UNIQUE (promocion_id, condicion_producto_id, cantidad_requerida);


--
-- TOC entry 5432 (class 2606 OID 16618)
-- Name: auditoria auditoria_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria
    ADD CONSTRAINT auditoria_pkey PRIMARY KEY (auditoria_id);


--
-- TOC entry 5424 (class 2606 OID 16562)
-- Name: permisos permisos_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.permisos
    ADD CONSTRAINT permisos_pkey PRIMARY KEY (permiso_id);


--
-- TOC entry 5430 (class 2606 OID 16592)
-- Name: roles_permisos roles_permisos_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT roles_permisos_pkey PRIMARY KEY (rol_id, permiso_id);


--
-- TOC entry 5419 (class 2606 OID 16547)
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (rol_id);


--
-- TOC entry 5426 (class 2606 OID 16564)
-- Name: permisos uk_permisos_codigo; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.permisos
    ADD CONSTRAINT uk_permisos_codigo UNIQUE (codigo);


--
-- TOC entry 5421 (class 2606 OID 16549)
-- Name: roles uk_roles_nombre; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT uk_roles_nombre UNIQUE (nombre);


--
-- TOC entry 5413 (class 2606 OID 16526)
-- Name: usuarios uk_usuarios_persona; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT uk_usuarios_persona UNIQUE (persona_id);


--
-- TOC entry 5415 (class 2606 OID 16528)
-- Name: usuarios uk_usuarios_username; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT uk_usuarios_username UNIQUE (username);


--
-- TOC entry 5417 (class 2606 OID 16524)
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (usuario_id);


--
-- TOC entry 5428 (class 2606 OID 16573)
-- Name: usuarios_roles usuarios_roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT usuarios_roles_pkey PRIMARY KEY (usuario_id, rol_id);


--
-- TOC entry 5624 (class 2606 OID 17699)
-- Name: cuentas_bancarias cuentas_bancarias_numero_cuenta_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias
    ADD CONSTRAINT cuentas_bancarias_numero_cuenta_key UNIQUE (numero_cuenta);


--
-- TOC entry 5626 (class 2606 OID 17697)
-- Name: cuentas_bancarias cuentas_bancarias_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias
    ADD CONSTRAINT cuentas_bancarias_pkey PRIMARY KEY (id);


--
-- TOC entry 5630 (class 2606 OID 17718)
-- Name: movimientos_bancarios movimientos_bancarios_arqueo_origen_id_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT movimientos_bancarios_arqueo_origen_id_key UNIQUE (arqueo_origen_id);


--
-- TOC entry 5632 (class 2606 OID 17716)
-- Name: movimientos_bancarios movimientos_bancarios_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT movimientos_bancarios_pkey PRIMARY KEY (id);


--
-- TOC entry 5634 (class 2606 OID 17720)
-- Name: movimientos_bancarios uk_movbanco_ref; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT uk_movbanco_ref UNIQUE (cuenta_bancaria_id, referencia);


--
-- TOC entry 5472 (class 2606 OID 17812)
-- Name: secuencias_fiscales_dgii Tipo_comprobante; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.secuencias_fiscales_dgii
    ADD CONSTRAINT "Tipo_comprobante" UNIQUE (tipo_comprobante);


--
-- TOC entry 5513 (class 2606 OID 17037)
-- Name: arqueo_detalles arqueo_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT arqueo_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5477 (class 2606 OID 16846)
-- Name: cajas_pos cajas_pos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cajas_pos
    ADD CONSTRAINT cajas_pos_pkey PRIMARY KEY (id);


--
-- TOC entry 5553 (class 2606 OID 17254)
-- Name: consignacion_facturas consignacion_facturas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT consignacion_facturas_pkey PRIMARY KEY (consignacion_id, factura_id);


--
-- TOC entry 5555 (class 2606 OID 17282)
-- Name: consignaciones_detalle consignaciones_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT consignaciones_detalle_pkey PRIMARY KEY (id);


--
-- TOC entry 5548 (class 2606 OID 17247)
-- Name: consignaciones consignaciones_numero_consignacion_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT consignaciones_numero_consignacion_key UNIQUE (numero_consignacion);


--
-- TOC entry 5550 (class 2606 OID 17245)
-- Name: consignaciones consignaciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT consignaciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5524 (class 2606 OID 17104)
-- Name: cotizaciones_detalle cotizaciones_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT cotizaciones_detalle_pkey PRIMARY KEY (id);


--
-- TOC entry 5517 (class 2606 OID 17078)
-- Name: cotizaciones cotizaciones_factura_generada_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_factura_generada_id_key UNIQUE (factura_generada_id);


--
-- TOC entry 5519 (class 2606 OID 17076)
-- Name: cotizaciones cotizaciones_numero_cotizacion_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_numero_cotizacion_key UNIQUE (numero_cotizacion);


--
-- TOC entry 5521 (class 2606 OID 17074)
-- Name: cotizaciones cotizaciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5498 (class 2606 OID 16957)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_factura_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_factura_id_key UNIQUE (factura_id);


--
-- TOC entry 5500 (class 2606 OID 16955)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_pkey PRIMARY KEY (id);


--
-- TOC entry 5564 (class 2606 OID 17331)
-- Name: devoluciones_detalles devoluciones_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT devoluciones_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5559 (class 2606 OID 17310)
-- Name: devoluciones devoluciones_ncf_nota_credito_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_ncf_nota_credito_key UNIQUE (ncf_nota_credito);


--
-- TOC entry 5561 (class 2606 OID 17308)
-- Name: devoluciones devoluciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5491 (class 2606 OID 16918)
-- Name: factura_detalle factura_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT factura_detalle_pkey PRIMARY KEY (id);


--
-- TOC entry 5495 (class 2606 OID 16936)
-- Name: factura_pagos factura_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT factura_pagos_pkey PRIMARY KEY (id);


--
-- TOC entry 5483 (class 2606 OID 16897)
-- Name: facturas facturas_numero_factura_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT facturas_numero_factura_key UNIQUE (numero_factura);


--
-- TOC entry 5485 (class 2606 OID 16895)
-- Name: facturas facturas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT facturas_pkey PRIMARY KEY (id);


--
-- TOC entry 5507 (class 2606 OID 16997)
-- Name: metodos_pago metodos_pago_codigo_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago
    ADD CONSTRAINT metodos_pago_codigo_key UNIQUE (codigo);


--
-- TOC entry 5509 (class 2606 OID 16995)
-- Name: metodos_pago metodos_pago_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago
    ADD CONSTRAINT metodos_pago_pkey PRIMARY KEY (id);


--
-- TOC entry 5511 (class 2606 OID 17013)
-- Name: movimiento_caja movimiento_caja_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja
    ADD CONSTRAINT movimiento_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5572 (class 2606 OID 17386)
-- Name: nota_debito_detalles nota_debito_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles
    ADD CONSTRAINT nota_debito_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5568 (class 2606 OID 17362)
-- Name: notas_debito notas_debito_ncf_nota_debito_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT notas_debito_ncf_nota_debito_key UNIQUE (ncf_nota_debito);


--
-- TOC entry 5570 (class 2606 OID 17360)
-- Name: notas_debito notas_debito_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT notas_debito_pkey PRIMARY KEY (id);


--
-- TOC entry 5544 (class 2606 OID 17218)
-- Name: pedidos_delivery pedidos_delivery_factura_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT pedidos_delivery_factura_id_key UNIQUE (factura_id);


--
-- TOC entry 5546 (class 2606 OID 17216)
-- Name: pedidos_delivery pedidos_delivery_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT pedidos_delivery_pkey PRIMARY KEY (id);


--
-- TOC entry 5503 (class 2606 OID 16969)
-- Name: recibo_ingreso_detalles recibo_ingreso_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT recibo_ingreso_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5529 (class 2606 OID 17136)
-- Name: recibos_ingreso_cxc recibos_ingreso_cxc_numero_recibo_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT recibos_ingreso_cxc_numero_recibo_key UNIQUE (numero_recibo);


--
-- TOC entry 5531 (class 2606 OID 17134)
-- Name: recibos_ingreso_cxc recibos_ingreso_cxc_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT recibos_ingreso_cxc_pkey PRIMARY KEY (id);


--
-- TOC entry 5539 (class 2606 OID 17196)
-- Name: repartidor_horarios repartidor_horarios_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_horarios
    ADD CONSTRAINT repartidor_horarios_pkey PRIMARY KEY (id);


--
-- TOC entry 5537 (class 2606 OID 17172)
-- Name: repartidor_zonas repartidor_zonas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT repartidor_zonas_pkey PRIMARY KEY (repartidor_id, zona_id);


--
-- TOC entry 5474 (class 2606 OID 16833)
-- Name: secuencias_fiscales_dgii secuencias_fiscales_dgii_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.secuencias_fiscales_dgii
    ADD CONSTRAINT secuencias_fiscales_dgii_pkey PRIMARY KEY (id);


--
-- TOC entry 5479 (class 2606 OID 16862)
-- Name: sesiones_caja sesiones_caja_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja
    ADD CONSTRAINT sesiones_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5515 (class 2606 OID 17039)
-- Name: arqueo_detalles uk_arqueo_metodo; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT uk_arqueo_metodo UNIQUE (sesion_caja_id, metodo_pago_id);


--
-- TOC entry 5557 (class 2606 OID 17284)
-- Name: consignaciones_detalle uk_consig_prod_lote; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT uk_consig_prod_lote UNIQUE (consignacion_id, producto_maestro_id, lote_id);


--
-- TOC entry 5526 (class 2606 OID 17106)
-- Name: cotizaciones_detalle uk_cotiz_linea; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT uk_cotiz_linea UNIQUE (cotizacion_id, producto_maestro_id);


--
-- TOC entry 5566 (class 2606 OID 17333)
-- Name: devoluciones_detalles uk_devdet_prod; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT uk_devdet_prod UNIQUE (nota_credito_id, producto_maestro_id, lote_id);


--
-- TOC entry 5493 (class 2606 OID 16920)
-- Name: factura_detalle uk_factura_linea; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT uk_factura_linea UNIQUE (factura_id, numero_linea);


--
-- TOC entry 5505 (class 2606 OID 16971)
-- Name: recibo_ingreso_detalles uk_recibo_cxc; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT uk_recibo_cxc UNIQUE (recibo_ingreso_id, cuenta_por_cobrar_id);


--
-- TOC entry 5541 (class 2606 OID 17198)
-- Name: repartidor_horarios uk_repartidor_horario; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_horarios
    ADD CONSTRAINT uk_repartidor_horario UNIQUE (repartidor_id, dia_semana, hora_inicio);


--
-- TOC entry 5533 (class 2606 OID 17163)
-- Name: zonas_delivery zonas_delivery_nombre_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery
    ADD CONSTRAINT zonas_delivery_nombre_key UNIQUE (nombre);


--
-- TOC entry 5535 (class 2606 OID 17161)
-- Name: zonas_delivery zonas_delivery_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery
    ADD CONSTRAINT zonas_delivery_pkey PRIMARY KEY (id);


--
-- TOC entry 5597 (class 1259 OID 17591)
-- Name: idx_cxp_pendientes; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_cxp_pendientes ON compras.cuentas_por_pagar USING btree (proveedor_id) WHERE ((estado)::text <> 'PAGADA'::text);


--
-- TOC entry 5598 (class 1259 OID 17592)
-- Name: idx_cxp_vencimiento; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_cxp_vencimiento ON compras.cuentas_por_pagar USING btree (fecha_vencimiento) WHERE ((estado)::text = 'PENDIENTE'::text);


--
-- TOC entry 5573 (class 1259 OID 17594)
-- Name: idx_ordenes_estado; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_ordenes_estado ON compras.ordenes_compra USING btree (estado) WHERE ((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'RECIBIDA_PARCIAL'::character varying])::text[]));


--
-- TOC entry 5582 (class 1259 OID 17593)
-- Name: idx_recepciones_fecha; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_recepciones_fecha ON compras.recepciones_compra USING btree (fecha_recepcion);


--
-- TOC entry 5392 (class 1259 OID 16503)
-- Name: idx_clientes_codigo; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_clientes_codigo ON identidad.clientes USING btree (codigo_cliente);


--
-- TOC entry 5399 (class 1259 OID 16504)
-- Name: idx_empleados_codigo; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_empleados_codigo ON identidad.empleados USING btree (codigo_empleado);


--
-- TOC entry 5382 (class 1259 OID 16502)
-- Name: idx_personas_apellidos; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_personas_apellidos ON identidad.personas USING btree (primer_apellido, segundo_apellido);


--
-- TOC entry 5383 (class 1259 OID 16501)
-- Name: idx_personas_documento; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_personas_documento ON identidad.personas USING btree (tipo_documento, numero_documento);


--
-- TOC entry 5404 (class 1259 OID 16505)
-- Name: idx_proveedores_ruc; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_proveedores_ruc ON identidad.proveedores USING btree (ruc);


--
-- TOC entry 5446 (class 1259 OID 16812)
-- Name: idx_codigos_producto; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_codigos_producto ON inventario.producto_codigos_barra USING btree (producto_maestro_id);


--
-- TOC entry 5468 (class 1259 OID 16815)
-- Name: idx_movdet_producto_lote; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_movdet_producto_lote ON inventario.movimiento_inventario_detalles USING btree (producto_maestro_id, lote_id);


--
-- TOC entry 5465 (class 1259 OID 16816)
-- Name: idx_movinv_supervisor; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_movinv_supervisor ON inventario.movimientos_inventario USING btree (supervisor_id) WHERE (supervisor_id IS NOT NULL);


--
-- TOC entry 5440 (class 1259 OID 16813)
-- Name: idx_productos_categoria; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_productos_categoria ON inventario.productos_maestro USING btree (categoria_id);


--
-- TOC entry 5441 (class 1259 OID 16814)
-- Name: idx_productos_marca; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_productos_marca ON inventario.productos_maestro USING btree (marca_id);


--
-- TOC entry 5622 (class 1259 OID 17679)
-- Name: idx_cupones_codigo; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_cupones_codigo ON promociones.cupones_descuento USING btree (codigo_cupon) WHERE ((estado)::text = 'DISPONIBLE'::text);


--
-- TOC entry 5611 (class 1259 OID 17678)
-- Name: idx_promo_producto; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promo_producto ON promociones.promociones_detalles USING btree (condicion_producto_id);


--
-- TOC entry 5433 (class 1259 OID 16628)
-- Name: idx_auditoria_gin_datos_nuevos; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_auditoria_gin_datos_nuevos ON seguridad.auditoria USING gin (datos_nuevos);


--
-- TOC entry 5434 (class 1259 OID 16627)
-- Name: idx_auditoria_tabla_registro; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_auditoria_tabla_registro ON seguridad.auditoria USING btree (tabla_afectada, registro_id);


--
-- TOC entry 5435 (class 1259 OID 16626)
-- Name: idx_auditoria_usuario_fecha; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_auditoria_usuario_fecha ON seguridad.auditoria USING btree (usuario_id, fecha_hora DESC);


--
-- TOC entry 5422 (class 1259 OID 16625)
-- Name: idx_permisos_modulo; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_permisos_modulo ON seguridad.permisos USING btree (modulo);


--
-- TOC entry 5411 (class 1259 OID 16624)
-- Name: idx_usuarios_username; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_usuarios_username ON seguridad.usuarios USING btree (username);


--
-- TOC entry 5627 (class 1259 OID 17732)
-- Name: idx_bancos_arqueo; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_bancos_arqueo ON tesoreria.movimientos_bancarios USING btree (arqueo_origen_id) WHERE (arqueo_origen_id IS NOT NULL);


--
-- TOC entry 5628 (class 1259 OID 17731)
-- Name: idx_bancos_cuenta_fecha; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_bancos_cuenta_fecha ON tesoreria.movimientos_bancarios USING btree (cuenta_bancaria_id, fecha_movimiento);


--
-- TOC entry 5551 (class 1259 OID 17395)
-- Name: idx_consig_estado; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_consig_estado ON ventas.consignaciones USING btree (estado) WHERE ((estado)::text = 'DESPACHADA'::text);


--
-- TOC entry 5522 (class 1259 OID 17392)
-- Name: idx_cotiz_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_cotiz_cliente ON ventas.cotizaciones USING btree (cliente_id);


--
-- TOC entry 5501 (class 1259 OID 16981)
-- Name: idx_cxc_pendientes; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_cxc_pendientes ON ventas.cuentas_por_cobrar USING btree (cliente_id) WHERE ((estado)::text <> 'PAGADA'::text);


--
-- TOC entry 5562 (class 1259 OID 17396)
-- Name: idx_devoluciones_fecha; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_devoluciones_fecha ON ventas.devoluciones USING btree (fecha_emision);


--
-- TOC entry 5486 (class 1259 OID 16980)
-- Name: idx_facturas_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_cliente ON ventas.facturas USING btree (cliente_id);


--
-- TOC entry 5487 (class 1259 OID 16979)
-- Name: idx_facturas_fecha; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_fecha ON ventas.facturas USING btree (fecha_emision);


--
-- TOC entry 5488 (class 1259 OID 16977)
-- Name: idx_facturas_sesion; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_sesion ON ventas.facturas USING btree (sesion_caja_id);


--
-- TOC entry 5496 (class 1259 OID 16978)
-- Name: idx_pagos_factura; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_pagos_factura ON ventas.factura_pagos USING btree (factura_id);


--
-- TOC entry 5542 (class 1259 OID 17394)
-- Name: idx_pedidos_estado; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_pedidos_estado ON ventas.pedidos_delivery USING btree (estado_delivery) WHERE ((estado_delivery)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_CAMINO'::character varying])::text[]));


--
-- TOC entry 5527 (class 1259 OID 17393)
-- Name: idx_recibos_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_recibos_cliente ON ventas.recibos_ingreso_cxc USING btree (cliente_id);


--
-- TOC entry 5480 (class 1259 OID 16868)
-- Name: uk_caja_abierta; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_caja_abierta ON ventas.sesiones_caja USING btree (caja_id) WHERE ((estado)::text = 'ABIERTA'::text);


--
-- TOC entry 5481 (class 1259 OID 16869)
-- Name: uk_cajero_abierto; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_cajero_abierto ON ventas.sesiones_caja USING btree (cajero_id) WHERE ((estado)::text = 'ABIERTA'::text);


--
-- TOC entry 5489 (class 1259 OID 16903)
-- Name: uk_factura_ncf; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_factura_ncf ON ventas.facturas USING btree (ncf) WHERE (ncf IS NOT NULL);


--
-- TOC entry 5475 (class 1259 OID 16834)
-- Name: uk_secuencia_activa; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_secuencia_activa ON ventas.secuencias_fiscales_dgii USING btree (tipo_comprobante) WHERE ((estado)::text = 'ACTIVO'::text);


--
-- TOC entry 5691 (class 2606 OID 17525)
-- Name: cuentas_por_pagar fk_cxp_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT fk_cxp_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(proveedor_id) ON DELETE RESTRICT;


--
-- TOC entry 5692 (class 2606 OID 17530)
-- Name: cuentas_por_pagar fk_cxp_recepcion; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT fk_cxp_recepcion FOREIGN KEY (recepcion_compra_id) REFERENCES compras.recepciones_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5693 (class 2606 OID 17553)
-- Name: comprobantes_egreso_cxp fk_egreso_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT fk_egreso_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(proveedor_id) ON DELETE RESTRICT;


--
-- TOC entry 5694 (class 2606 OID 17586)
-- Name: comprobante_egreso_detalles fk_egresodet_cxp; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT fk_egresodet_cxp FOREIGN KEY (cuenta_por_pagar_id) REFERENCES compras.cuentas_por_pagar(id) ON DELETE RESTRICT;


--
-- TOC entry 5695 (class 2606 OID 17581)
-- Name: comprobante_egreso_detalles fk_egresodet_egreso; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT fk_egresodet_egreso FOREIGN KEY (comprobante_egreso_id) REFERENCES compras.comprobantes_egreso_cxp(id) ON DELETE RESTRICT;


--
-- TOC entry 5684 (class 2606 OID 17419)
-- Name: ordenes_compra fk_orden_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT fk_orden_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(proveedor_id) ON DELETE RESTRICT;


--
-- TOC entry 5685 (class 2606 OID 17440)
-- Name: orden_compra_detalles fk_ordendet_orden; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT fk_ordendet_orden FOREIGN KEY (orden_compra_id) REFERENCES compras.ordenes_compra(id) ON DELETE CASCADE;


--
-- TOC entry 5686 (class 2606 OID 17445)
-- Name: orden_compra_detalles fk_ordendet_prod; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT fk_ordendet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5689 (class 2606 OID 17501)
-- Name: recepcion_compra_detalles fk_recdet_prod; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT fk_recdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5690 (class 2606 OID 17496)
-- Name: recepcion_compra_detalles fk_recdet_recepcion; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT fk_recdet_recepcion FOREIGN KEY (recepcion_id) REFERENCES compras.recepciones_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5687 (class 2606 OID 17469)
-- Name: recepciones_compra fk_recepcion_orden; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT fk_recepcion_orden FOREIGN KEY (orden_compra_id) REFERENCES compras.ordenes_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5688 (class 2606 OID 17474)
-- Name: recepciones_compra fk_recepcion_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT fk_recepcion_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(proveedor_id) ON DELETE RESTRICT;


--
-- TOC entry 5645 (class 2606 OID 16446)
-- Name: clientes fk_clientes_personas; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT fk_clientes_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5646 (class 2606 OID 16473)
-- Name: empleados fk_empleados_personas; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT fk_empleados_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5703 (class 2606 OID 17749)
-- Name: repartidores fk_repartidor_empleado; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.repartidores
    ADD CONSTRAINT fk_repartidor_empleado FOREIGN KEY (empleado_id) REFERENCES identidad.empleados(empleado_id) ON DELETE RESTRICT;


--
-- TOC entry 5655 (class 2606 OID 16705)
-- Name: producto_codigos_barra fk_cod_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT fk_cod_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE CASCADE;


--
-- TOC entry 5656 (class 2606 OID 16738)
-- Name: lotes fk_lote_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT fk_lote_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5660 (class 2606 OID 16802)
-- Name: movimiento_inventario_detalles fk_movdet_mov; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT fk_movdet_mov FOREIGN KEY (movimiento_id) REFERENCES inventario.movimientos_inventario(id) ON DELETE RESTRICT;


--
-- TOC entry 5661 (class 2606 OID 16807)
-- Name: movimiento_inventario_detalles fk_movdet_prod; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT fk_movdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5653 (class 2606 OID 16677)
-- Name: productos_maestro fk_prod_categoria; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT fk_prod_categoria FOREIGN KEY (categoria_id) REFERENCES inventario.categoria_producto(id) ON DELETE RESTRICT;


--
-- TOC entry 5654 (class 2606 OID 16682)
-- Name: productos_maestro fk_prod_marca; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT fk_prod_marca FOREIGN KEY (marca_id) REFERENCES inventario.marca(id) ON DELETE RESTRICT;


--
-- TOC entry 5657 (class 2606 OID 16761)
-- Name: inventario_stock fk_stock_almacen; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_almacen FOREIGN KEY (almacen_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 5658 (class 2606 OID 16771)
-- Name: inventario_stock fk_stock_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 5659 (class 2606 OID 16766)
-- Name: inventario_stock fk_stock_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5704 (class 2606 OID 17779)
-- Name: traslados_internos fk_traslado_destino; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_destino FOREIGN KEY (almacen_destino_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 5705 (class 2606 OID 17774)
-- Name: traslados_internos fk_traslado_origen; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_origen FOREIGN KEY (almacen_origen_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 5706 (class 2606 OID 17806)
-- Name: traslado_detalles fk_trasladodet_prod; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT fk_trasladodet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5707 (class 2606 OID 17801)
-- Name: traslado_detalles fk_trasladodet_traslado; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT fk_trasladodet_traslado FOREIGN KEY (traslado_id) REFERENCES inventario.traslados_internos(id) ON DELETE CASCADE;


--
-- TOC entry 5698 (class 2606 OID 17673)
-- Name: cupones_descuento fk_cupon_canje; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_canje FOREIGN KEY (factura_canje_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5699 (class 2606 OID 17668)
-- Name: cupones_descuento fk_cupon_origen; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_origen FOREIGN KEY (factura_origen_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5700 (class 2606 OID 17663)
-- Name: cupones_descuento fk_cupon_promo; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_promo FOREIGN KEY (promocion_id) REFERENCES promociones.promociones_campanas(id) ON DELETE RESTRICT;


--
-- TOC entry 5696 (class 2606 OID 17637)
-- Name: promociones_detalles fk_promodet_prod; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_prod FOREIGN KEY (condicion_producto_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5697 (class 2606 OID 17632)
-- Name: promociones_detalles fk_promodet_promo; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_promo FOREIGN KEY (promocion_id) REFERENCES promociones.promociones_campanas(id) ON DELETE CASCADE;


--
-- TOC entry 5652 (class 2606 OID 16619)
-- Name: auditoria fk_auditoria_usuarios; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria
    ADD CONSTRAINT fk_auditoria_usuarios FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5650 (class 2606 OID 16598)
-- Name: roles_permisos fk_rolperm_permisos; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT fk_rolperm_permisos FOREIGN KEY (permiso_id) REFERENCES seguridad.permisos(permiso_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5651 (class 2606 OID 16593)
-- Name: roles_permisos fk_rolperm_roles; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT fk_rolperm_roles FOREIGN KEY (rol_id) REFERENCES seguridad.roles(rol_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5648 (class 2606 OID 16579)
-- Name: usuarios_roles fk_usrrol_roles; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT fk_usrrol_roles FOREIGN KEY (rol_id) REFERENCES seguridad.roles(rol_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5649 (class 2606 OID 16574)
-- Name: usuarios_roles fk_usrrol_usuarios; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT fk_usrrol_usuarios FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5647 (class 2606 OID 16529)
-- Name: usuarios fk_usuarios_personas; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT fk_usuarios_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5701 (class 2606 OID 17721)
-- Name: movimientos_bancarios fk_movbanco_cuenta; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT fk_movbanco_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES tesoreria.cuentas_bancarias(id) ON DELETE RESTRICT;


--
-- TOC entry 5702 (class 2606 OID 17726)
-- Name: movimientos_bancarios fk_movbanco_usuario; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT fk_movbanco_usuario FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5668 (class 2606 OID 17045)
-- Name: arqueo_detalles fk_arqueo_metodo; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT fk_arqueo_metodo FOREIGN KEY (metodo_pago_id) REFERENCES ventas.metodos_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 5669 (class 2606 OID 17040)
-- Name: arqueo_detalles fk_arqueo_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT fk_arqueo_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5679 (class 2606 OID 17285)
-- Name: consignaciones_detalle fk_consigdet_consig; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT fk_consigdet_consig FOREIGN KEY (consignacion_id) REFERENCES ventas.consignaciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5677 (class 2606 OID 17255)
-- Name: consignacion_facturas fk_consigfac_con; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT fk_consigfac_con FOREIGN KEY (consignacion_id) REFERENCES ventas.consignaciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5678 (class 2606 OID 17260)
-- Name: consignacion_facturas fk_consigfac_fac; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT fk_consigfac_fac FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5670 (class 2606 OID 17079)
-- Name: cotizaciones fk_cotiz_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT fk_cotiz_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(cliente_id) ON DELETE RESTRICT;


--
-- TOC entry 5671 (class 2606 OID 17107)
-- Name: cotizaciones_detalle fk_cotizdet_cotiz; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT fk_cotizdet_cotiz FOREIGN KEY (cotizacion_id) REFERENCES ventas.cotizaciones(id) ON DELETE CASCADE;


--
-- TOC entry 5681 (class 2606 OID 17334)
-- Name: devoluciones_detalles fk_devdet_nc; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT fk_devdet_nc FOREIGN KEY (nota_credito_id) REFERENCES ventas.devoluciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5680 (class 2606 OID 17311)
-- Name: devoluciones fk_devol_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT fk_devol_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5664 (class 2606 OID 16921)
-- Name: factura_detalle fk_factdet_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT fk_factdet_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5663 (class 2606 OID 16898)
-- Name: facturas fk_factura_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5667 (class 2606 OID 17014)
-- Name: movimiento_caja fk_movcaja_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja
    ADD CONSTRAINT fk_movcaja_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5682 (class 2606 OID 17363)
-- Name: notas_debito fk_nd_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT fk_nd_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5683 (class 2606 OID 17387)
-- Name: nota_debito_detalles fk_nddet_nd; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles
    ADD CONSTRAINT fk_nddet_nd FOREIGN KEY (nota_debito_id) REFERENCES ventas.notas_debito(id) ON DELETE RESTRICT;


--
-- TOC entry 5665 (class 2606 OID 16937)
-- Name: factura_pagos fk_pago_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT fk_pago_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE CASCADE;


--
-- TOC entry 5676 (class 2606 OID 17219)
-- Name: pedidos_delivery fk_peddel_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT fk_peddel_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5666 (class 2606 OID 16972)
-- Name: recibo_ingreso_detalles fk_recdet_cxc; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT fk_recdet_cxc FOREIGN KEY (cuenta_por_cobrar_id) REFERENCES ventas.cuentas_por_cobrar(id) ON DELETE RESTRICT;


--
-- TOC entry 5672 (class 2606 OID 17137)
-- Name: recibos_ingreso_cxc fk_recibo_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT fk_recibo_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(cliente_id) ON DELETE RESTRICT;


--
-- TOC entry 5673 (class 2606 OID 17142)
-- Name: recibos_ingreso_cxc fk_recibo_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT fk_recibo_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5674 (class 2606 OID 17173)
-- Name: repartidor_zonas fk_repzon_rep; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT fk_repzon_rep FOREIGN KEY (repartidor_id) REFERENCES identidad.empleados(empleado_id) ON DELETE CASCADE;


--
-- TOC entry 5675 (class 2606 OID 17178)
-- Name: repartidor_zonas fk_repzon_zon; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT fk_repzon_zon FOREIGN KEY (zona_id) REFERENCES ventas.zonas_delivery(id) ON DELETE CASCADE;


--
-- TOC entry 5662 (class 2606 OID 16863)
-- Name: sesiones_caja fk_sesion_caja; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja
    ADD CONSTRAINT fk_sesion_caja FOREIGN KEY (caja_id) REFERENCES ventas.cajas_pos(id) ON DELETE RESTRICT;


-- Completed on 2026-11-08 01:15:27

--
-- PostgreSQL database dump complete
--
--\unrestrict yBxKcV155dmghfeDaHUsKUefsJjXBQRM0VAyCLC9TMrdkljdawASvqaGcIGW6V6

