--
-- PostgreSQL database dump
--

\restrict yUsgli8KFcP8pXdPEzLuDbeThf77czfZHujKIupFIkg25DkmgoO4rON8ap2cDfJ

-- Dumped from database version 18.6
-- Dumped by pg_dump version 18.6

-- Started on 2026-11-18 17:25:10

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
-- TOC entry 6318 (class 0 OID 0)
-- Dependencies: 2
-- Name: EXTENSION "uuid-ossp"; Type: COMMENT; Schema: -; Owner: 
--

COMMENT ON EXTENSION "uuid-ossp" IS 'generate universally unique identifiers (UUIDs)';


--
-- TOC entry 369 (class 1255 OID 27075)
-- Name: fn_bloquear_reapertura_cxp(); Type: FUNCTION; Schema: compras; Owner: postgres
--

CREATE FUNCTION compras.fn_bloquear_reapertura_cxp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.estado = 'ANULADA' AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'La cuenta por pagar % se encuentra ANULADA y no es reversible.', OLD.id;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION compras.fn_bloquear_reapertura_cxp() OWNER TO postgres;

--
-- TOC entry 367 (class 1255 OID 27071)
-- Name: fn_bloquear_cambio_metodo_pago(); Type: FUNCTION; Schema: tesoreria; Owner: postgres
--

CREATE FUNCTION tesoreria.fn_bloquear_cambio_metodo_pago() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.metodo_pago_id <> NEW.metodo_pago_id THEN
        RAISE EXCEPTION 'No se permite cambiar el metodo_pago_id de un detalle_pago ya emitido.';
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION tesoreria.fn_bloquear_cambio_metodo_pago() OWNER TO postgres;

--
-- TOC entry 366 (class 1255 OID 27066)
-- Name: fn_validar_subtipo_pago(); Type: FUNCTION; Schema: tesoreria; Owner: postgres
--

CREATE FUNCTION tesoreria.fn_validar_subtipo_pago() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_codigo_metodo VARCHAR(20);
BEGIN
    SELECT mp.codigo INTO v_codigo_metodo
    FROM tesoreria.detalles_pago dp
    INNER JOIN ventas.metodos_pago mp ON mp.id = dp.metodo_pago_id
    WHERE dp.id = NEW.detalle_pago_id;

    IF v_codigo_metodo IS NULL THEN
        RAISE EXCEPTION 'El detalle_pago_id % no existe en tesoreria.detalles_pago.', NEW.detalle_pago_id;
    END IF;

    IF TG_TABLE_NAME = 'pago_tarjeta' AND v_codigo_metodo <> 'TARJETA' THEN
        RAISE EXCEPTION 'Inconsistencia de subtipo: El pago % es % y no puede registrarse en pago_tarjeta.', NEW.detalle_pago_id, v_codigo_metodo;
    ELSIF TG_TABLE_NAME = 'pago_transferencia' AND v_codigo_metodo <> 'TRANSFERENCIA' THEN
        RAISE EXCEPTION 'Inconsistencia de subtipo: El pago % es % y no puede registrarse en pago_transferencia.', NEW.detalle_pago_id, v_codigo_metodo;
    ELSIF TG_TABLE_NAME = 'pago_cheque' AND v_codigo_metodo <> 'CHEQUE' THEN
        RAISE EXCEPTION 'Inconsistencia de subtipo: El pago % es % y no puede registrarse en pago_cheque.', NEW.detalle_pago_id, v_codigo_metodo;
    ELSIF TG_TABLE_NAME = 'pago_saldo_favor' AND v_codigo_metodo <> 'SALDO_FAVOR' THEN
        RAISE EXCEPTION 'Inconsistencia de subtipo: El pago % es % y no puede registrarse en pago_saldo_favor.', NEW.detalle_pago_id, v_codigo_metodo;
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION tesoreria.fn_validar_subtipo_pago() OWNER TO postgres;

--
-- TOC entry 368 (class 1255 OID 27073)
-- Name: fn_bloquear_reapertura_cxc(); Type: FUNCTION; Schema: ventas; Owner: postgres
--

CREATE FUNCTION ventas.fn_bloquear_reapertura_cxc() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF OLD.estado IN ('INCOBRABLE', 'ANULADA') AND NEW.estado <> OLD.estado THEN
        RAISE EXCEPTION 'La cuenta por cobrar % se encuentra en estado terminal (%) y no es reversible.', OLD.id, OLD.estado;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION ventas.fn_bloquear_reapertura_cxc() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- TOC entry 344 (class 1259 OID 27024)
-- Name: ajustes_cxp; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.ajustes_cxp (
    id bigint NOT NULL,
    cuenta_por_pagar_id bigint NOT NULL,
    tipo_ajuste character varying(30) NOT NULL,
    ncf_nota_credito_proveedor character varying(13),
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    monto_ajuste numeric(12,2) NOT NULL,
    saldo_anterior numeric(12,2) NOT NULL,
    saldo_restante numeric(12,2) NOT NULL,
    justificacion text NOT NULL,
    supervisor_id uuid NOT NULL,
    fecha_ajuste timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ajustes_cxp_monto_ajuste_check CHECK ((monto_ajuste > (0)::numeric)),
    CONSTRAINT ajustes_cxp_saldo_anterior_check CHECK ((saldo_anterior >= (0)::numeric)),
    CONSTRAINT ajustes_cxp_tasa_cambio_check CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT ajustes_cxp_tipo_ajuste_check CHECK (((tipo_ajuste)::text = ANY ((ARRAY['DESCUENTO_PRONTO_PAGO'::character varying, 'EXONERACION_PROVEEDOR'::character varying, 'NOTA_CREDITO_PROVEEDOR'::character varying, 'ANULACION'::character varying])::text[]))),
    CONSTRAINT chk_ajustecxp_saldos CHECK (((saldo_restante >= (0)::numeric) AND (saldo_restante = (saldo_anterior - monto_ajuste))))
);


ALTER TABLE compras.ajustes_cxp OWNER TO postgres;

--
-- TOC entry 343 (class 1259 OID 27023)
-- Name: ajustes_cxp_id_seq; Type: SEQUENCE; Schema: compras; Owner: postgres
--

CREATE SEQUENCE compras.ajustes_cxp_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE compras.ajustes_cxp_id_seq OWNER TO postgres;

--
-- TOC entry 6319 (class 0 OID 0)
-- Dependencies: 343
-- Name: ajustes_cxp_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.ajustes_cxp_id_seq OWNED BY compras.ajustes_cxp.id;


--
-- TOC entry 304 (class 1259 OID 17559)
-- Name: comprobante_egreso_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.comprobante_egreso_detalles (
    comprobante_egreso_id bigint NOT NULL,
    cuenta_por_pagar_id bigint NOT NULL,
    monto_aplicado numeric(12,2) NOT NULL,
    saldo_anterior numeric(12,2) NOT NULL,
    saldo_restante numeric(12,2) NOT NULL,
    monto_retenciones numeric(12,2) DEFAULT 0.00 NOT NULL,
    CONSTRAINT chk_egresodet_retenciones CHECK ((monto_retenciones >= (0)::numeric)),
    CONSTRAINT chk_egresodet_saldos CHECK (((saldo_restante >= (0)::numeric) AND (saldo_restante = (saldo_anterior - (monto_aplicado + monto_retenciones))))),
    CONSTRAINT chk_egresodet_total CHECK (((monto_aplicado + monto_retenciones) <= saldo_anterior)),
    CONSTRAINT comprobante_egreso_detalles_monto_aplicado_check CHECK ((monto_aplicado > (0)::numeric))
);


ALTER TABLE compras.comprobante_egreso_detalles OWNER TO postgres;

--
-- TOC entry 331 (class 1259 OID 26754)
-- Name: comprobante_egreso_retenciones; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.comprobante_egreso_retenciones (
    comprobante_egreso_id bigint NOT NULL,
    cuenta_por_pagar_id bigint NOT NULL,
    retencion_id integer NOT NULL,
    base_calculo_aplicada character varying(15) NOT NULL,
    monto_base numeric(12,2) NOT NULL,
    porcentaje_aplicado numeric(5,2) NOT NULL,
    monto_retenido numeric(12,2) NOT NULL,
    CONSTRAINT comprobante_egreso_retenciones_base_calculo_aplicada_check CHECK (((base_calculo_aplicada)::text = ANY ((ARRAY['SUBTOTAL'::character varying, 'IMPUESTO'::character varying])::text[]))),
    CONSTRAINT comprobante_egreso_retenciones_monto_base_check CHECK ((monto_base >= (0)::numeric)),
    CONSTRAINT comprobante_egreso_retenciones_monto_retenido_check CHECK ((monto_retenido > (0)::numeric)),
    CONSTRAINT comprobante_egreso_retenciones_porcentaje_aplicado_check CHECK (((porcentaje_aplicado > (0)::numeric) AND (porcentaje_aplicado <= (100)::numeric)))
);


ALTER TABLE compras.comprobante_egreso_retenciones OWNER TO postgres;

--
-- TOC entry 303 (class 1259 OID 17536)
-- Name: comprobantes_egreso_cxp; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.comprobantes_egreso_cxp (
    id bigint NOT NULL,
    numero_comprobante character varying(30) NOT NULL,
    proveedor_id uuid NOT NULL,
    usuario_registro_id uuid NOT NULL,
    monto_total_pagado numeric(12,2) NOT NULL,
    fecha_pago timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_egresos_cxp_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT comprobantes_egreso_cxp_monto_total_pagado_check CHECK ((monto_total_pagado >= (0)::numeric))
);


ALTER TABLE compras.comprobantes_egreso_cxp OWNER TO postgres;

--
-- TOC entry 302 (class 1259 OID 17535)
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
-- TOC entry 6320 (class 0 OID 0)
-- Dependencies: 302
-- Name: comprobantes_egreso_cxp_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.comprobantes_egreso_cxp_id_seq OWNED BY compras.comprobantes_egreso_cxp.id;


--
-- TOC entry 301 (class 1259 OID 17507)
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
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_cxp_saldo CHECK (((saldo_pendiente >= (0)::numeric) AND (saldo_pendiente <= monto_original))),
    CONSTRAINT chk_cxp_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT cuentas_por_pagar_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying, 'PAGADA'::character varying, 'ANULADA'::character varying])::text[])))
);


ALTER TABLE compras.cuentas_por_pagar OWNER TO postgres;

--
-- TOC entry 300 (class 1259 OID 17506)
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
-- TOC entry 6321 (class 0 OID 0)
-- Dependencies: 300
-- Name: cuentas_por_pagar_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.cuentas_por_pagar_id_seq OWNED BY compras.cuentas_por_pagar.id;


--
-- TOC entry 339 (class 1259 OID 26933)
-- Name: egreso_cxp_pagos; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.egreso_cxp_pagos (
    comprobante_egreso_id bigint NOT NULL,
    detalle_pago_id bigint NOT NULL
);


ALTER TABLE compras.egreso_cxp_pagos OWNER TO postgres;

--
-- TOC entry 296 (class 1259 OID 17425)
-- Name: orden_compra_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.orden_compra_detalles (
    orden_compra_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    cantidad_ordenada integer NOT NULL,
    cantidad_recibida_total integer DEFAULT 0 NOT NULL,
    costo_pactado_unitario numeric(12,2) NOT NULL,
    cantidad_pendiente integer GENERATED ALWAYS AS ((cantidad_ordenada - cantidad_recibida_total)) STORED,
    CONSTRAINT chk_ordendet_recibida CHECK (((cantidad_recibida_total >= 0) AND (cantidad_recibida_total <= cantidad_ordenada))),
    CONSTRAINT orden_compra_detalles_cantidad_ordenada_check CHECK ((cantidad_ordenada > 0))
);


ALTER TABLE compras.orden_compra_detalles OWNER TO postgres;

--
-- TOC entry 295 (class 1259 OID 17400)
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
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_ordenes_compra_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT ordenes_compra_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'RECIBIDA_PARCIAL'::character varying, 'COMPLETADA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT ordenes_compra_monto_total_estimado_check CHECK ((monto_total_estimado >= (0)::numeric))
);


ALTER TABLE compras.ordenes_compra OWNER TO postgres;

--
-- TOC entry 294 (class 1259 OID 17399)
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
-- TOC entry 6322 (class 0 OID 0)
-- Dependencies: 294
-- Name: ordenes_compra_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.ordenes_compra_id_seq OWNED BY compras.ordenes_compra.id;


--
-- TOC entry 299 (class 1259 OID 17480)
-- Name: recepcion_compra_detalles; Type: TABLE; Schema: compras; Owner: postgres
--

CREATE TABLE compras.recepcion_compra_detalles (
    recepcion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    numero_lote character varying(50) NOT NULL,
    fecha_vencimiento date NOT NULL,
    cantidad_recibida_buena integer NOT NULL,
    costo_unitario_facturado numeric(12,2) NOT NULL,
    orden_compra_id bigint,
    CONSTRAINT recepcion_compra_detalles_cantidad_recibida_buena_check CHECK ((cantidad_recibida_buena > 0))
);


ALTER TABLE compras.recepcion_compra_detalles OWNER TO postgres;

--
-- TOC entry 298 (class 1259 OID 17451)
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
    condicion_pago character varying(20) DEFAULT 'CREDITO'::character varying NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_recepcion_condicion_pago CHECK (((condicion_pago)::text = ANY ((ARRAY['CONTADO'::character varying, 'CREDITO'::character varying])::text[]))),
    CONSTRAINT chk_recepciones_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT recepciones_compra_monto_total_factura_check CHECK ((monto_total_factura >= (0)::numeric))
);


ALTER TABLE compras.recepciones_compra OWNER TO postgres;

--
-- TOC entry 297 (class 1259 OID 17450)
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
-- TOC entry 6323 (class 0 OID 0)
-- Dependencies: 297
-- Name: recepciones_compra_id_seq; Type: SEQUENCE OWNED BY; Schema: compras; Owner: postgres
--

ALTER SEQUENCE compras.recepciones_compra_id_seq OWNED BY compras.recepciones_compra.id;


--
-- TOC entry 228 (class 1259 OID 16425)
-- Name: clientes; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.clientes (
    persona_id uuid NOT NULL,
    codigo_cliente character varying(20) NOT NULL,
    limite_credito numeric(12,2) DEFAULT 0.00 NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    categoria_cliente character varying(20) DEFAULT 'REGULAR'::character varying NOT NULL,
    permite_credito boolean DEFAULT false NOT NULL,
    CONSTRAINT chk_clientes_categoria CHECK (((categoria_cliente)::text = ANY ((ARRAY['REGULAR'::character varying, 'VIP'::character varying, 'MAYORISTA'::character varying])::text[]))),
    CONSTRAINT chk_clientes_limite_credito CHECK ((limite_credito >= (0)::numeric))
);


ALTER TABLE identidad.clientes OWNER TO postgres;

--
-- TOC entry 229 (class 1259 OID 16451)
-- Name: empleados; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.empleados (
    persona_id uuid NOT NULL,
    codigo_empleado character varying(20) NOT NULL,
    cargo character varying(50) NOT NULL,
    fecha_ingreso date NOT NULL,
    estado_laboral character varying(20) DEFAULT 'ACTIVO'::character varying CONSTRAINT empleados_estado_not_null NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    empleado_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    CONSTRAINT chk_empleados_estado_laboral CHECK (((estado_laboral)::text = ANY ((ARRAY['ACTIVO'::character varying, 'VACACIONES'::character varying, 'SUSPENDIDO'::character varying, 'INACTIVO'::character varying])::text[])))
);


ALTER TABLE identidad.empleados OWNER TO postgres;

--
-- TOC entry 227 (class 1259 OID 16399)
-- Name: personas; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.personas (
    persona_id uuid DEFAULT public.uuid_generate_v4() NOT NULL,
    tipo_documento character varying(10) NOT NULL,
    documento_identidad character varying(20) CONSTRAINT personas_numero_documento_not_null NOT NULL,
    nombre_razon_social character varying(150) CONSTRAINT personas_primer_nombre_not_null NOT NULL,
    nombre_comercial character varying(150),
    fecha_nacimiento date,
    email character varying(100),
    telefono_principal character varying(20),
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    tipo_persona character varying(10) DEFAULT 'FISICA'::character varying NOT NULL,
    estado boolean DEFAULT true NOT NULL,
    CONSTRAINT chk_personas_mayoria_edad CHECK (((fecha_nacimiento IS NULL) OR (fecha_nacimiento <= (CURRENT_DATE - '18 years'::interval)))),
    CONSTRAINT chk_personas_tipo_doc CHECK (((tipo_documento)::text = ANY ((ARRAY['CEDULA'::character varying, 'RNC'::character varying, 'PASAPORTE'::character varying])::text[]))),
    CONSTRAINT chk_personas_tipo_persona CHECK (((tipo_persona)::text = ANY ((ARRAY['FISICA'::character varying, 'JURIDICA'::character varying])::text[])))
);


ALTER TABLE identidad.personas OWNER TO postgres;

--
-- TOC entry 330 (class 1259 OID 26732)
-- Name: proveedor_retenciones; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.proveedor_retenciones (
    proveedor_id uuid NOT NULL,
    retencion_id integer NOT NULL
);


ALTER TABLE identidad.proveedor_retenciones OWNER TO postgres;

--
-- TOC entry 230 (class 1259 OID 16478)
-- Name: proveedores; Type: TABLE; Schema: identidad; Owner: postgres
--

CREATE TABLE identidad.proveedores (
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    persona_id uuid NOT NULL,
    codigo_proveedor character varying(20) NOT NULL,
    dias_credito_habitual integer DEFAULT 30 NOT NULL
);


ALTER TABLE identidad.proveedores OWNER TO postgres;

--
-- TOC entry 314 (class 1259 OID 17734)
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
-- TOC entry 6324 (class 0 OID 0)
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
    estado character varying(15) DEFAULT 'ACTIVO'::character varying NOT NULL,
    CONSTRAINT categoria_producto_estado_check CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying])::text[])))
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
-- TOC entry 6325 (class 0 OID 0)
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
-- TOC entry 6326 (class 0 OID 0)
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
-- TOC entry 6327 (class 0 OID 0)
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
    estado character varying(15) DEFAULT 'ACTIVO'::character varying NOT NULL,
    CONSTRAINT marca_estado_check CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying])::text[])))
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
-- TOC entry 6328 (class 0 OID 0)
-- Dependencies: 240
-- Name: marca_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.marca_id_seq OWNED BY inventario.marca.id;


--
-- TOC entry 254 (class 1259 OID 16791)
-- Name: movimiento_inventario_detalles; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.movimiento_inventario_detalles (
    movimiento_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint DEFAULT 0 NOT NULL,
    cantidad_unidades integer NOT NULL,
    costo_unitario_referencia numeric(12,2) CONSTRAINT movimiento_inventario_detall_costo_unitario_referencia_not_null NOT NULL
);


ALTER TABLE inventario.movimiento_inventario_detalles OWNER TO postgres;

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
    fecha_movimiento timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_movinv_tipo_movimiento CHECK (((tipo_movimiento)::text = ANY ((ARRAY['ENTRADA_COMPRA'::character varying, 'SALIDA_VENTA'::character varying, 'AJUSTE_ENTRADA'::character varying, 'AJUSTE_SALIDA'::character varying, 'DEVOLUCION_CLIENTE'::character varying, 'MERMA_ROTURA'::character varying])::text[])))
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
-- TOC entry 6329 (class 0 OID 0)
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
-- TOC entry 6330 (class 0 OID 0)
-- Dependencies: 244
-- Name: producto_codigos_barra_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.producto_codigos_barra_id_seq OWNED BY inventario.producto_codigos_barra.id;


--
-- TOC entry 324 (class 1259 OID 26619)
-- Name: producto_impuestos; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.producto_impuestos (
    producto_maestro_id bigint NOT NULL,
    impuesto_id integer NOT NULL
);


ALTER TABLE inventario.producto_impuestos OWNER TO postgres;

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
    nombre character varying(150) NOT NULL,
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
-- TOC entry 6331 (class 0 OID 0)
-- Dependencies: 242
-- Name: productos_maestro_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.productos_maestro_id_seq OWNED BY inventario.productos_maestro.id;


--
-- TOC entry 317 (class 1259 OID 17785)
-- Name: traslado_detalles; Type: TABLE; Schema: inventario; Owner: postgres
--

CREATE TABLE inventario.traslado_detalles (
    traslado_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint DEFAULT 0 NOT NULL,
    cantidad_enviada integer NOT NULL,
    cantidad_recibida integer DEFAULT 0 NOT NULL,
    CONSTRAINT traslado_detalles_cantidad_enviada_check CHECK ((cantidad_enviada > 0)),
    CONSTRAINT traslado_detalles_cantidad_recibida_check CHECK ((cantidad_recibida >= 0))
);


ALTER TABLE inventario.traslado_detalles OWNER TO postgres;

--
-- TOC entry 316 (class 1259 OID 17755)
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
-- TOC entry 315 (class 1259 OID 17754)
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
-- TOC entry 6332 (class 0 OID 0)
-- Dependencies: 315
-- Name: traslados_internos_id_seq; Type: SEQUENCE OWNED BY; Schema: inventario; Owner: postgres
--

ALTER SEQUENCE inventario.traslados_internos_id_seq OWNED BY inventario.traslados_internos.id;


--
-- TOC entry 309 (class 1259 OID 17643)
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
-- TOC entry 308 (class 1259 OID 17642)
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
-- TOC entry 6333 (class 0 OID 0)
-- Dependencies: 308
-- Name: cupones_descuento_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.cupones_descuento_id_seq OWNED BY promociones.cupones_descuento.id;


--
-- TOC entry 306 (class 1259 OID 17597)
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
    monto_minimo_factura numeric(12,2),
    estado character varying(20) DEFAULT 'PROGRAMADA'::character varying NOT NULL,
    CONSTRAINT chk_promo_fechas CHECK ((fecha_fin >= fecha_inicio)),
    CONSTRAINT promociones_campanas_estado_check CHECK (((estado)::text = ANY ((ARRAY['PROGRAMADA'::character varying, 'ACTIVA'::character varying, 'INACTIVA'::character varying, 'FINALIZADA'::character varying])::text[]))),
    CONSTRAINT promociones_campanas_monto_minimo_factura_check CHECK ((monto_minimo_factura >= (0)::numeric)),
    CONSTRAINT promociones_campanas_tipo_promocion_check CHECK (((tipo_promocion)::text = ANY ((ARRAY['VOLUMEN'::character varying, 'CROSS_SELLING'::character varying, 'HAPPY_HOUR'::character varying, 'FECHAS'::character varying, 'CUPON_TICKET'::character varying])::text[])))
);


ALTER TABLE promociones.promociones_campanas OWNER TO postgres;

--
-- TOC entry 305 (class 1259 OID 17596)
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
-- TOC entry 6334 (class 0 OID 0)
-- Dependencies: 305
-- Name: promociones_campanas_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.promociones_campanas_id_seq OWNED BY promociones.promociones_campanas.id;


--
-- TOC entry 307 (class 1259 OID 17617)
-- Name: promociones_detalles; Type: TABLE; Schema: promociones; Owner: postgres
--

CREATE TABLE promociones.promociones_detalles (
    promocion_id bigint NOT NULL,
    condicion_producto_id bigint DEFAULT 0 NOT NULL,
    condicion_categoria_id integer,
    cantidad_requerida integer DEFAULT 1 NOT NULL,
    beneficio_producto_id bigint,
    beneficio_porcentaje numeric(5,2),
    beneficio_monto_fijo numeric(12,2),
    condicion_marca_id bigint,
    id bigint NOT NULL,
    CONSTRAINT promociones_detalles_beneficio_monto_fijo_check CHECK ((beneficio_monto_fijo >= (0)::numeric)),
    CONSTRAINT promociones_detalles_beneficio_porcentaje_check CHECK (((beneficio_porcentaje > (0)::numeric) AND (beneficio_porcentaje <= (100)::numeric))),
    CONSTRAINT promociones_detalles_cantidad_requerida_check CHECK ((cantidad_requerida > 0))
);


ALTER TABLE promociones.promociones_detalles OWNER TO postgres;

--
-- TOC entry 318 (class 1259 OID 26400)
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
-- TOC entry 6335 (class 0 OID 0)
-- Dependencies: 318
-- Name: promociones_detalles_id_seq; Type: SEQUENCE OWNED BY; Schema: promociones; Owner: postgres
--

ALTER SEQUENCE promociones.promociones_detalles_id_seq OWNED BY promociones.promociones_detalles.id;


--
-- TOC entry 319 (class 1259 OID 26427)
-- Name: promociones_dias; Type: TABLE; Schema: promociones; Owner: postgres
--

CREATE TABLE promociones.promociones_dias (
    promocion_id bigint NOT NULL,
    dia_semana smallint NOT NULL,
    CONSTRAINT chk_promodia_rango CHECK (((dia_semana >= 1) AND (dia_semana <= 7)))
);


ALTER TABLE promociones.promociones_dias OWNER TO postgres;

--
-- TOC entry 237 (class 1259 OID 16604)
-- Name: auditoria; Type: TABLE; Schema: seguridad; Owner: postgres
--

CREATE TABLE seguridad.auditoria (
    auditoria_id bigint NOT NULL,
    usuario_id uuid,
    accion character varying(100) CONSTRAINT auditoria_tipo_evento_not_null NOT NULL,
    tabla_afectada character varying(50) NOT NULL,
    datos_anteriores jsonb,
    datos_nuevos jsonb,
    fecha_hora timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    supervisor_id uuid
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
-- TOC entry 6336 (class 0 OID 0)
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
    clave_sistema character varying(100) CONSTRAINT permisos_codigo_not_null NOT NULL,
    modulo character varying(50) NOT NULL,
    descripcion character varying(255) NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    entidad character varying(50) NOT NULL,
    accion character varying(50) NOT NULL
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
    ultimo_acceso timestamp with time zone,
    intentos_fallidos integer DEFAULT 0 NOT NULL,
    creado_en timestamp with time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    pin_autorizacion character varying(255),
    estado character varying(20) DEFAULT 'ACTIVO'::character varying NOT NULL,
    CONSTRAINT chk_usuarios_estado CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'INACTIVO'::character varying, 'SUSPENDIDO'::character varying])::text[]))),
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
-- TOC entry 311 (class 1259 OID 17682)
-- Name: cuentas_bancarias; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.cuentas_bancarias (
    id integer NOT NULL,
    nombre_banco character varying(50) NOT NULL,
    numero_cuenta character varying(50) NOT NULL,
    tipo_cuenta character varying(20) NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    CONSTRAINT cuentas_bancarias_tipo_cuenta_check CHECK (((tipo_cuenta)::text = ANY ((ARRAY['CORRIENTE'::character varying, 'AHORRO'::character varying])::text[])))
);


ALTER TABLE tesoreria.cuentas_bancarias OWNER TO postgres;

--
-- TOC entry 310 (class 1259 OID 17681)
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
-- TOC entry 6337 (class 0 OID 0)
-- Dependencies: 310
-- Name: cuentas_bancarias_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.cuentas_bancarias_id_seq OWNED BY tesoreria.cuentas_bancarias.id;


--
-- TOC entry 333 (class 1259 OID 26781)
-- Name: detalles_pago; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.detalles_pago (
    id bigint NOT NULL,
    metodo_pago_id integer NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    monto_pagado numeric(12,2) NOT NULL,
    monto_devuelta numeric(12,2) DEFAULT 0.00 NOT NULL,
    sesion_caja_id bigint,
    fecha_pago timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT chk_detpago_devuelta CHECK (((monto_devuelta >= (0)::numeric) AND (monto_devuelta < monto_pagado))),
    CONSTRAINT chk_detpago_monto CHECK ((monto_pagado > (0)::numeric)),
    CONSTRAINT chk_detpago_tasa CHECK ((tasa_cambio > (0)::numeric))
);


ALTER TABLE tesoreria.detalles_pago OWNER TO postgres;

--
-- TOC entry 332 (class 1259 OID 26780)
-- Name: detalles_pago_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.detalles_pago_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.detalles_pago_id_seq OWNER TO postgres;

--
-- TOC entry 6338 (class 0 OID 0)
-- Dependencies: 332
-- Name: detalles_pago_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.detalles_pago_id_seq OWNED BY tesoreria.detalles_pago.id;


--
-- TOC entry 323 (class 1259 OID 26598)
-- Name: impuestos; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.impuestos (
    id integer NOT NULL,
    siglas character varying(20) NOT NULL,
    nombre character varying(100) NOT NULL,
    tipo_calculo character varying(15) DEFAULT 'PORCENTAJE'::character varying NOT NULL,
    tasa_o_monto numeric(12,4) NOT NULL,
    orden_calculo smallint DEFAULT 1 NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    CONSTRAINT chk_impuesto_orden CHECK ((orden_calculo > 0)),
    CONSTRAINT chk_impuesto_tipo CHECK (((tipo_calculo)::text = ANY ((ARRAY['PORCENTAJE'::character varying, 'MONTO_FIJO'::character varying])::text[]))),
    CONSTRAINT chk_impuesto_valor CHECK ((tasa_o_monto >= (0)::numeric))
);


ALTER TABLE tesoreria.impuestos OWNER TO postgres;

--
-- TOC entry 322 (class 1259 OID 26597)
-- Name: impuestos_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.impuestos_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.impuestos_id_seq OWNER TO postgres;

--
-- TOC entry 6339 (class 0 OID 0)
-- Dependencies: 322
-- Name: impuestos_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.impuestos_id_seq OWNED BY tesoreria.impuestos.id;


--
-- TOC entry 321 (class 1259 OID 26459)
-- Name: monedas; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.monedas (
    id integer NOT NULL,
    codigo_iso character varying(3) NOT NULL,
    nombre character varying(50) NOT NULL,
    simbolo character varying(10) NOT NULL,
    tasa_cambio_referencia numeric(12,4) DEFAULT 1.0000 NOT NULL,
    es_moneda_base boolean DEFAULT false NOT NULL,
    activa boolean DEFAULT true NOT NULL,
    CONSTRAINT chk_moneda_tasa_positiva CHECK ((tasa_cambio_referencia > (0)::numeric))
);


ALTER TABLE tesoreria.monedas OWNER TO postgres;

--
-- TOC entry 320 (class 1259 OID 26458)
-- Name: monedas_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.monedas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.monedas_id_seq OWNER TO postgres;

--
-- TOC entry 6340 (class 0 OID 0)
-- Dependencies: 320
-- Name: monedas_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.monedas_id_seq OWNED BY tesoreria.monedas.id;


--
-- TOC entry 313 (class 1259 OID 17701)
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
    detalle_pago_id bigint,
    CONSTRAINT movimientos_bancarios_monto_check CHECK ((monto > (0)::numeric)),
    CONSTRAINT movimientos_bancarios_tipo_movimiento_check CHECK (((tipo_movimiento)::text = ANY ((ARRAY['TRANSFERENCIA_ENTRADA'::character varying, 'TRANSFERENCIA_SALIDA'::character varying, 'CHEQUE_ENTRADA'::character varying, 'CHEQUE_SALIDA'::character varying])::text[])))
);


ALTER TABLE tesoreria.movimientos_bancarios OWNER TO postgres;

--
-- TOC entry 312 (class 1259 OID 17700)
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
-- TOC entry 6341 (class 0 OID 0)
-- Dependencies: 312
-- Name: movimientos_bancarios_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.movimientos_bancarios_id_seq OWNED BY tesoreria.movimientos_bancarios.id;


--
-- TOC entry 336 (class 1259 OID 26853)
-- Name: pago_cheque; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.pago_cheque (
    detalle_pago_id bigint NOT NULL,
    numero_cheque character varying(30) NOT NULL,
    banco_emisor character varying(60) NOT NULL,
    titular_cheque character varying(120) NOT NULL,
    fecha_emision date NOT NULL,
    fecha_deposito date,
    cuenta_bancaria_id integer,
    estado_cheque character varying(20) DEFAULT 'RECIBIDO'::character varying NOT NULL,
    CONSTRAINT pago_cheque_estado_cheque_check CHECK (((estado_cheque)::text = ANY ((ARRAY['RECIBIDO'::character varying, 'DEPOSITADO'::character varying, 'COBRADO'::character varying, 'DEVUELTO'::character varying, 'ANULADO'::character varying])::text[])))
);


ALTER TABLE tesoreria.pago_cheque OWNER TO postgres;

--
-- TOC entry 337 (class 1259 OID 26876)
-- Name: pago_saldo_favor; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.pago_saldo_favor (
    detalle_pago_id bigint NOT NULL,
    cliente_id uuid NOT NULL,
    origen_saldo character varying(30) NOT NULL,
    documento_origen_id bigint NOT NULL,
    CONSTRAINT pago_saldo_favor_origen_saldo_check CHECK (((origen_saldo)::text = ANY ((ARRAY['CONSIGNACION_GARANTIA'::character varying, 'EXCEDENTE_CXC'::character varying, 'DEVOLUCION_B04'::character varying])::text[])))
);


ALTER TABLE tesoreria.pago_saldo_favor OWNER TO postgres;

--
-- TOC entry 334 (class 1259 OID 26816)
-- Name: pago_tarjeta; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.pago_tarjeta (
    detalle_pago_id bigint NOT NULL,
    tipo_tarjeta character varying(15) NOT NULL,
    marca_tarjeta character varying(20) NOT NULL,
    ultimos_4_digitos character(4) NOT NULL,
    numero_autorizacion character varying(50) NOT NULL,
    numero_lote character varying(30),
    CONSTRAINT pago_tarjeta_marca_tarjeta_check CHECK (((marca_tarjeta)::text = ANY ((ARRAY['VISA'::character varying, 'MASTERCARD'::character varying, 'AMEX'::character varying, 'OTRA'::character varying])::text[]))),
    CONSTRAINT pago_tarjeta_tipo_tarjeta_check CHECK (((tipo_tarjeta)::text = ANY ((ARRAY['CREDITO'::character varying, 'DEBITO'::character varying])::text[])))
);


ALTER TABLE tesoreria.pago_tarjeta OWNER TO postgres;

--
-- TOC entry 335 (class 1259 OID 26833)
-- Name: pago_transferencia; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.pago_transferencia (
    detalle_pago_id bigint NOT NULL,
    banco_origen character varying(60) NOT NULL,
    cuenta_bancaria_id integer NOT NULL,
    numero_referencia character varying(100) NOT NULL,
    fecha_transferencia date NOT NULL,
    titular_origen character varying(120)
);


ALTER TABLE tesoreria.pago_transferencia OWNER TO postgres;

--
-- TOC entry 329 (class 1259 OID 26715)
-- Name: retenciones; Type: TABLE; Schema: tesoreria; Owner: postgres
--

CREATE TABLE tesoreria.retenciones (
    id integer NOT NULL,
    siglas character varying(20) NOT NULL,
    nombre character varying(100) NOT NULL,
    base_calculo character varying(15) NOT NULL,
    porcentaje numeric(5,2) NOT NULL,
    activo boolean DEFAULT true NOT NULL,
    CONSTRAINT chk_retencion_base CHECK (((base_calculo)::text = ANY ((ARRAY['SUBTOTAL'::character varying, 'IMPUESTO'::character varying])::text[]))),
    CONSTRAINT chk_retencion_porc CHECK (((porcentaje > (0)::numeric) AND (porcentaje <= (100)::numeric)))
);


ALTER TABLE tesoreria.retenciones OWNER TO postgres;

--
-- TOC entry 328 (class 1259 OID 26714)
-- Name: retenciones_id_seq; Type: SEQUENCE; Schema: tesoreria; Owner: postgres
--

CREATE SEQUENCE tesoreria.retenciones_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE tesoreria.retenciones_id_seq OWNER TO postgres;

--
-- TOC entry 6342 (class 0 OID 0)
-- Dependencies: 328
-- Name: retenciones_id_seq; Type: SEQUENCE OWNED BY; Schema: tesoreria; Owner: postgres
--

ALTER SEQUENCE tesoreria.retenciones_id_seq OWNED BY tesoreria.retenciones.id;


--
-- TOC entry 342 (class 1259 OID 26974)
-- Name: ajustes_cxc; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.ajustes_cxc (
    id bigint NOT NULL,
    cuenta_por_cobrar_id bigint NOT NULL,
    tipo_ajuste character varying(30) NOT NULL,
    devolucion_id bigint,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    monto_ajuste numeric(12,2) NOT NULL,
    saldo_anterior numeric(12,2) NOT NULL,
    saldo_restante numeric(12,2) NOT NULL,
    justificacion text NOT NULL,
    supervisor_id uuid NOT NULL,
    fecha_ajuste timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT ajustes_cxc_monto_ajuste_check CHECK ((monto_ajuste > (0)::numeric)),
    CONSTRAINT ajustes_cxc_saldo_anterior_check CHECK ((saldo_anterior >= (0)::numeric)),
    CONSTRAINT ajustes_cxc_tasa_cambio_check CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT ajustes_cxc_tipo_ajuste_check CHECK (((tipo_ajuste)::text = ANY ((ARRAY['DESCUENTO_PRONTO_PAGO'::character varying, 'CONDONACION'::character varying, 'INCOBRABLE'::character varying, 'ANULACION_FACTURA'::character varying, 'NOTA_CREDITO_B04'::character varying])::text[]))),
    CONSTRAINT chk_ajustecxc_saldos CHECK (((saldo_restante >= (0)::numeric) AND (saldo_restante = (saldo_anterior - monto_ajuste))))
);


ALTER TABLE ventas.ajustes_cxc OWNER TO postgres;

--
-- TOC entry 341 (class 1259 OID 26973)
-- Name: ajustes_cxc_id_seq; Type: SEQUENCE; Schema: ventas; Owner: postgres
--

CREATE SEQUENCE ventas.ajustes_cxc_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE ventas.ajustes_cxc_id_seq OWNER TO postgres;

--
-- TOC entry 6343 (class 0 OID 0)
-- Dependencies: 341
-- Name: ajustes_cxc_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.ajustes_cxc_id_seq OWNED BY ventas.ajustes_cxc.id;


--
-- TOC entry 272 (class 1259 OID 17020)
-- Name: arqueo_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.arqueo_detalles (
    sesion_caja_id bigint NOT NULL,
    metodo_pago_id integer NOT NULL,
    monto_declarado_cajero numeric(12,2) NOT NULL,
    monto_esperado_sistema numeric(12,2) NOT NULL,
    diferencia numeric(12,2) GENERATED ALWAYS AS ((monto_declarado_cajero - monto_esperado_sistema)) STORED,
    cantidad_vouchers integer DEFAULT 0,
    estado_deposito character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    monto_retenido_fondo numeric(12,2) DEFAULT 0.00 NOT NULL,
    CONSTRAINT arqueo_detalles_cantidad_vouchers_check CHECK ((cantidad_vouchers >= 0)),
    CONSTRAINT arqueo_detalles_estado_deposito_check CHECK (((estado_deposito)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'DEPOSITADO'::character varying, 'RETENIDO'::character varying, 'NO_APLICA'::character varying])::text[]))),
    CONSTRAINT arqueo_detalles_monto_declarado_cajero_check CHECK ((monto_declarado_cajero >= (0)::numeric)),
    CONSTRAINT chk_arqueo_retenido_valido CHECK (((monto_retenido_fondo >= (0)::numeric) AND (monto_retenido_fondo <= monto_declarado_cajero)))
);


ALTER TABLE ventas.arqueo_detalles OWNER TO postgres;

--
-- TOC entry 258 (class 1259 OID 16836)
-- Name: cajas_pos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cajas_pos (
    id integer NOT NULL,
    nombre_equipo character varying(50) NOT NULL,
    estado character varying(20) DEFAULT 'CERRADA'::character varying NOT NULL,
    ubicacion character varying(50),
    CONSTRAINT cajas_pos_estado_check CHECK (((estado)::text = ANY ((ARRAY['ABIERTA'::character varying, 'CERRADA'::character varying, 'EN_ARQUEO'::character varying])::text[])))
);


ALTER TABLE ventas.cajas_pos OWNER TO postgres;

--
-- TOC entry 257 (class 1259 OID 16835)
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
-- TOC entry 6344 (class 0 OID 0)
-- Dependencies: 257
-- Name: cajas_pos_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cajas_pos_id_seq OWNED BY ventas.cajas_pos.id;


--
-- TOC entry 286 (class 1259 OID 17248)
-- Name: consignacion_facturas; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignacion_facturas (
    consignacion_id bigint NOT NULL,
    factura_id bigint NOT NULL
);


ALTER TABLE ventas.consignacion_facturas OWNER TO postgres;

--
-- TOC entry 340 (class 1259 OID 26952)
-- Name: consignacion_garantia_pagos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignacion_garantia_pagos (
    consignacion_id bigint NOT NULL,
    detalle_pago_id bigint NOT NULL
);


ALTER TABLE ventas.consignacion_garantia_pagos OWNER TO postgres;

--
-- TOC entry 285 (class 1259 OID 17225)
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
    sesion_caja_id bigint,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_consignaciones_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT consignaciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['DESPACHADA'::character varying, 'LIQUIDADA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT consignaciones_monto_deposito_garantia_check CHECK ((monto_deposito_garantia >= (0)::numeric))
);


ALTER TABLE ventas.consignaciones OWNER TO postgres;

--
-- TOC entry 287 (class 1259 OID 17266)
-- Name: consignaciones_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.consignaciones_detalle (
    consignacion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint DEFAULT 0 NOT NULL,
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
-- TOC entry 284 (class 1259 OID 17224)
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
-- TOC entry 6345 (class 0 OID 0)
-- Dependencies: 284
-- Name: consignaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.consignaciones_id_seq OWNED BY ventas.consignaciones.id;


--
-- TOC entry 326 (class 1259 OID 26662)
-- Name: cotizacion_detalle_impuestos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cotizacion_detalle_impuestos (
    cotizacion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    impuesto_id integer NOT NULL,
    base_imponible numeric(12,2) NOT NULL,
    tipo_calculo_aplicado character varying(15) NOT NULL,
    tasa_aplicada numeric(12,4) NOT NULL,
    monto_impuesto numeric(12,2) NOT NULL,
    CONSTRAINT cotizacion_detalle_impuestos_base_imponible_check CHECK ((base_imponible >= (0)::numeric)),
    CONSTRAINT cotizacion_detalle_impuestos_monto_impuesto_check CHECK ((monto_impuesto >= (0)::numeric)),
    CONSTRAINT cotizacion_detalle_impuestos_tasa_aplicada_check CHECK ((tasa_aplicada >= (0)::numeric)),
    CONSTRAINT cotizacion_detalle_impuestos_tipo_calculo_aplicado_check CHECK (((tipo_calculo_aplicado)::text = ANY ((ARRAY['PORCENTAJE'::character varying, 'MONTO_FIJO'::character varying])::text[])))
);


ALTER TABLE ventas.cotizacion_detalle_impuestos OWNER TO postgres;

--
-- TOC entry 274 (class 1259 OID 17051)
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
    monto_impuestos numeric(12,2) CONSTRAINT cotizaciones_monto_itbis_not_null NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    estado character varying(20) DEFAULT 'PENDIENTE'::character varying NOT NULL,
    factura_generada_id bigint,
    observaciones text,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_cotizaciones_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT cotizaciones_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'CONVERTIDA_A_FACTURA'::character varying, 'VENCIDA'::character varying, 'CANCELADA'::character varying])::text[]))),
    CONSTRAINT cotizaciones_monto_impuestos_check CHECK ((monto_impuestos >= (0)::numeric)),
    CONSTRAINT cotizaciones_monto_subtotal_check CHECK ((monto_subtotal >= (0)::numeric)),
    CONSTRAINT cotizaciones_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.cotizaciones OWNER TO postgres;

--
-- TOC entry 275 (class 1259 OID 17085)
-- Name: cotizaciones_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cotizaciones_detalle (
    cotizacion_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    codigo_barra_id bigint,
    cantidad integer NOT NULL,
    precio_unitario numeric(12,2) NOT NULL,
    monto_impuestos numeric(12,2) CONSTRAINT cotizaciones_detalle_monto_itbis_not_null NOT NULL,
    monto_descuento numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    CONSTRAINT cotizaciones_detalle_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT cotizaciones_detalle_monto_descuento_check CHECK ((monto_descuento >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_monto_impuestos_check CHECK ((monto_impuestos >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_monto_total_check CHECK ((monto_total >= (0)::numeric)),
    CONSTRAINT cotizaciones_detalle_precio_unitario_check CHECK ((precio_unitario >= (0)::numeric))
);


ALTER TABLE ventas.cotizaciones_detalle OWNER TO postgres;

--
-- TOC entry 273 (class 1259 OID 17050)
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
-- TOC entry 6346 (class 0 OID 0)
-- Dependencies: 273
-- Name: cotizaciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cotizaciones_id_seq OWNED BY ventas.cotizaciones.id;


--
-- TOC entry 266 (class 1259 OID 16943)
-- Name: cuentas_por_cobrar; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.cuentas_por_cobrar (
    id bigint NOT NULL,
    cliente_id uuid NOT NULL,
    factura_id bigint,
    monto_original numeric(12,2) NOT NULL,
    saldo_pendiente numeric(12,2) NOT NULL,
    estado character varying(20) NOT NULL,
    fecha_emision date DEFAULT CURRENT_DATE NOT NULL,
    fecha_vencimiento date DEFAULT CURRENT_DATE NOT NULL,
    nota_debito_id bigint,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_cxc_origen_documento CHECK ((num_nonnulls(factura_id, nota_debito_id) = 1)),
    CONSTRAINT chk_cxc_saldo CHECK (((saldo_pendiente >= (0)::numeric) AND (saldo_pendiente <= monto_original))),
    CONSTRAINT chk_cxc_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT cuentas_por_cobrar_estado_check CHECK (((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying, 'PAGADA'::character varying, 'ANULADA'::character varying, 'INCOBRABLE'::character varying])::text[])))
);


ALTER TABLE ventas.cuentas_por_cobrar OWNER TO postgres;

--
-- TOC entry 265 (class 1259 OID 16942)
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
-- TOC entry 6347 (class 0 OID 0)
-- Dependencies: 265
-- Name: cuentas_por_cobrar_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.cuentas_por_cobrar_id_seq OWNED BY ventas.cuentas_por_cobrar.id;


--
-- TOC entry 289 (class 1259 OID 17291)
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
    sesion_caja_id bigint,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_devoluciones_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT devoluciones_metodo_reembolso_check CHECK (((metodo_reembolso)::text = ANY ((ARRAY['EFECTIVO'::character varying, 'TARJETA'::character varying, 'SALDO_FAVOR'::character varying, 'REVERSION_CXC'::character varying])::text[]))),
    CONSTRAINT devoluciones_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.devoluciones OWNER TO postgres;

--
-- TOC entry 290 (class 1259 OID 17317)
-- Name: devoluciones_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.devoluciones_detalles (
    nota_credito_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    lote_id bigint DEFAULT 0 NOT NULL,
    cantidad integer NOT NULL,
    monto_reembolsado numeric(12,2) NOT NULL,
    reingresado_a_inventario boolean DEFAULT true NOT NULL,
    CONSTRAINT devoluciones_detalles_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT devoluciones_detalles_monto_reembolsado_check CHECK ((monto_reembolsado >= (0)::numeric))
);


ALTER TABLE ventas.devoluciones_detalles OWNER TO postgres;

--
-- TOC entry 288 (class 1259 OID 17290)
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
-- TOC entry 6348 (class 0 OID 0)
-- Dependencies: 288
-- Name: devoluciones_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.devoluciones_id_seq OWNED BY ventas.devoluciones.id;


--
-- TOC entry 263 (class 1259 OID 16905)
-- Name: factura_detalle; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.factura_detalle (
    factura_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    cantidad_unidades integer NOT NULL,
    precio_unitario_his numeric(12,2) NOT NULL,
    codigo_barra_id bigint,
    factor_conversion integer DEFAULT 1,
    cantidad_presentacion integer DEFAULT 1 NOT NULL,
    costo_unitario_hist numeric(12,2) DEFAULT 0.00 NOT NULL,
    promocion_aplicada bigint,
    monto_descuento numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_impuestos numeric(12,2) DEFAULT 0.00 CONSTRAINT factura_detalle_monto_itbis_not_null NOT NULL,
    monto_subtotal numeric(12,2) DEFAULT 0.00 NOT NULL,
    monto_total numeric(12,2) GENERATED ALWAYS AS (((monto_subtotal - monto_descuento) + monto_impuestos)) STORED,
    CONSTRAINT chk_factdet_cantidad CHECK ((cantidad_unidades > 0)),
    CONSTRAINT factura_detalle_cantidad_presentacion_check CHECK ((cantidad_presentacion > 0)),
    CONSTRAINT factura_detalle_factor_conversion_check CHECK ((factor_conversion > 0))
);


ALTER TABLE ventas.factura_detalle OWNER TO postgres;

--
-- TOC entry 325 (class 1259 OID 26636)
-- Name: factura_detalle_impuestos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.factura_detalle_impuestos (
    factura_id bigint NOT NULL,
    producto_maestro_id bigint NOT NULL,
    impuesto_id integer NOT NULL,
    base_imponible numeric(12,2) NOT NULL,
    tipo_calculo_aplicado character varying(15) NOT NULL,
    tasa_aplicada numeric(12,4) NOT NULL,
    monto_impuesto numeric(12,2) NOT NULL,
    CONSTRAINT factura_detalle_impuestos_base_imponible_check CHECK ((base_imponible >= (0)::numeric)),
    CONSTRAINT factura_detalle_impuestos_monto_impuesto_check CHECK ((monto_impuesto >= (0)::numeric)),
    CONSTRAINT factura_detalle_impuestos_tasa_aplicada_check CHECK ((tasa_aplicada >= (0)::numeric)),
    CONSTRAINT factura_detalle_impuestos_tipo_calculo_aplicado_check CHECK (((tipo_calculo_aplicado)::text = ANY ((ARRAY['PORCENTAJE'::character varying, 'MONTO_FIJO'::character varying])::text[])))
);


ALTER TABLE ventas.factura_detalle_impuestos OWNER TO postgres;

--
-- TOC entry 264 (class 1259 OID 16927)
-- Name: factura_pagos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.factura_pagos (
    factura_id bigint NOT NULL,
    detalle_pago_id bigint NOT NULL
);


ALTER TABLE ventas.factura_pagos OWNER TO postgres;

--
-- TOC entry 262 (class 1259 OID 16871)
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
    monto_impuestos numeric(12,2) CONSTRAINT facturas_monto_itbis_not_null NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    estado character varying(20) DEFAULT 'EMITIDA'::character varying NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    cotizacion_origen bigint,
    rnc_cedula_cliente character varying(20),
    razon_social_cliente character varying(150),
    origen_venta character varying(20) DEFAULT 'MOSTRADOR'::character varying NOT NULL,
    cupon_aplicado_id bigint,
    supervisor_anulacion_id uuid,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_factura_descuento CHECK (((monto_descuento >= (0)::numeric) AND (monto_descuento <= monto_subtotal))),
    CONSTRAINT chk_factura_montos CHECK (((monto_subtotal >= (0)::numeric) AND (monto_total >= (0)::numeric))),
    CONSTRAINT chk_facturas_b01_datos CHECK ((((tipo_ncf)::text <> 'B01'::text) OR ((rnc_cedula_cliente IS NOT NULL) AND (razon_social_cliente IS NOT NULL)))),
    CONSTRAINT chk_facturas_estado CHECK (((estado)::text = ANY ((ARRAY['EMITIDA'::character varying, 'EN_TRANSITO'::character varying, 'ENTREGADA'::character varying, 'ANULADA'::character varying, 'PAGADA'::character varying])::text[]))),
    CONSTRAINT chk_facturas_origen_venta CHECK (((origen_venta)::text = ANY ((ARRAY['MOSTRADOR'::character varying, 'DELIVERY'::character varying, 'CONSIGNACION_LIQUIDADA'::character varying])::text[]))),
    CONSTRAINT chk_facturas_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT chk_facturas_tipo_ncf CHECK (((tipo_ncf)::text = ANY ((ARRAY['B01'::character varying, 'B02'::character varying, 'CONSUMO_INTERNO'::character varying])::text[]))),
    CONSTRAINT facturas_condicion_pago_check CHECK (((condicion_pago)::text = ANY ((ARRAY['CONTADO'::character varying, 'CREDITO'::character varying])::text[])))
);


ALTER TABLE ventas.facturas OWNER TO postgres;

--
-- TOC entry 261 (class 1259 OID 16870)
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
-- TOC entry 6349 (class 0 OID 0)
-- Dependencies: 261
-- Name: facturas_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.facturas_id_seq OWNED BY ventas.facturas.id;


--
-- TOC entry 269 (class 1259 OID 16983)
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
-- TOC entry 268 (class 1259 OID 16982)
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
-- TOC entry 6350 (class 0 OID 0)
-- Dependencies: 268
-- Name: metodos_pago_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.metodos_pago_id_seq OWNED BY ventas.metodos_pago.id;


--
-- TOC entry 271 (class 1259 OID 16999)
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
-- TOC entry 270 (class 1259 OID 16998)
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
-- TOC entry 6351 (class 0 OID 0)
-- Dependencies: 270
-- Name: movimiento_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.movimiento_caja_id_seq OWNED BY ventas.movimiento_caja.id;


--
-- TOC entry 327 (class 1259 OID 26688)
-- Name: nota_debito_detalle_impuestos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.nota_debito_detalle_impuestos (
    nota_debito_id bigint NOT NULL,
    descripcion_cargo character varying(150) NOT NULL,
    impuesto_id integer NOT NULL,
    base_imponible numeric(12,2) NOT NULL,
    tipo_calculo_aplicado character varying(15) NOT NULL,
    tasa_aplicada numeric(12,4) NOT NULL,
    monto_impuesto numeric(12,2) NOT NULL,
    CONSTRAINT nota_debito_detalle_impuestos_base_imponible_check CHECK ((base_imponible >= (0)::numeric)),
    CONSTRAINT nota_debito_detalle_impuestos_monto_impuesto_check CHECK ((monto_impuesto >= (0)::numeric)),
    CONSTRAINT nota_debito_detalle_impuestos_tasa_aplicada_check CHECK ((tasa_aplicada >= (0)::numeric)),
    CONSTRAINT nota_debito_detalle_impuestos_tipo_calculo_aplicado_check CHECK (((tipo_calculo_aplicado)::text = ANY ((ARRAY['PORCENTAJE'::character varying, 'MONTO_FIJO'::character varying])::text[])))
);


ALTER TABLE ventas.nota_debito_detalle_impuestos OWNER TO postgres;

--
-- TOC entry 293 (class 1259 OID 17369)
-- Name: nota_debito_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.nota_debito_detalles (
    nota_debito_id bigint NOT NULL,
    producto_maestro_id bigint,
    descripcion_cargo character varying(150) NOT NULL,
    cantidad integer DEFAULT 1 NOT NULL,
    precio_unitario numeric(12,2) NOT NULL,
    monto_impuestos numeric(12,2) CONSTRAINT nota_debito_detalles_monto_itbis_not_null NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    CONSTRAINT nota_debito_detalles_cantidad_check CHECK ((cantidad > 0)),
    CONSTRAINT nota_debito_detalles_monto_impuestos_check CHECK ((monto_impuestos >= (0)::numeric)),
    CONSTRAINT nota_debito_detalles_monto_total_check CHECK ((monto_total >= (0)::numeric)),
    CONSTRAINT nota_debito_detalles_precio_unitario_check CHECK ((precio_unitario >= (0)::numeric))
);


ALTER TABLE ventas.nota_debito_detalles OWNER TO postgres;

--
-- TOC entry 292 (class 1259 OID 17340)
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
    monto_impuestos numeric(12,2) CONSTRAINT notas_debito_monto_itbis_not_null NOT NULL,
    monto_total numeric(12,2) NOT NULL,
    usuario_emisor_id uuid NOT NULL,
    fecha_emision timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_notas_debito_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT notas_debito_monto_impuestos_check CHECK ((monto_impuestos >= (0)::numeric)),
    CONSTRAINT notas_debito_monto_subtotal_check CHECK ((monto_subtotal >= (0)::numeric)),
    CONSTRAINT notas_debito_monto_total_check CHECK ((monto_total >= (0)::numeric))
);


ALTER TABLE ventas.notas_debito OWNER TO postgres;

--
-- TOC entry 291 (class 1259 OID 17339)
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
-- TOC entry 6352 (class 0 OID 0)
-- Dependencies: 291
-- Name: notas_debito_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.notas_debito_id_seq OWNED BY ventas.notas_debito.id;


--
-- TOC entry 283 (class 1259 OID 17200)
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
-- TOC entry 282 (class 1259 OID 17199)
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
-- TOC entry 6353 (class 0 OID 0)
-- Dependencies: 282
-- Name: pedidos_delivery_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.pedidos_delivery_id_seq OWNED BY ventas.pedidos_delivery.id;


--
-- TOC entry 338 (class 1259 OID 26914)
-- Name: recibo_cxc_pagos; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.recibo_cxc_pagos (
    recibo_ingreso_id bigint NOT NULL,
    detalle_pago_id bigint NOT NULL
);


ALTER TABLE ventas.recibo_cxc_pagos OWNER TO postgres;

--
-- TOC entry 267 (class 1259 OID 16959)
-- Name: recibo_ingreso_detalles; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.recibo_ingreso_detalles (
    recibo_ingreso_id bigint NOT NULL,
    cuenta_por_cobrar_id bigint NOT NULL,
    monto_aplicado numeric(12,2) NOT NULL,
    saldo_anterior numeric(12,2) NOT NULL,
    saldo_restante numeric(12,2) NOT NULL,
    CONSTRAINT chk_recibodet_saldos CHECK (((saldo_anterior >= (0)::numeric) AND (saldo_restante >= (0)::numeric) AND (saldo_restante = (saldo_anterior - monto_aplicado)))),
    CONSTRAINT recibo_ingreso_detalles_monto_aplicado_check CHECK ((monto_aplicado > (0)::numeric))
);


ALTER TABLE ventas.recibo_ingreso_detalles OWNER TO postgres;

--
-- TOC entry 277 (class 1259 OID 17113)
-- Name: recibos_ingreso_cxc; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.recibos_ingreso_cxc (
    id bigint NOT NULL,
    numero_recibo character varying(30) NOT NULL,
    cliente_id uuid NOT NULL,
    sesion_caja_id bigint,
    cajero_id uuid NOT NULL,
    monto_total_recibido numeric(12,2) NOT NULL,
    fecha_recibo timestamp without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    saldo_a_favor_generado numeric(12,2) DEFAULT 0.00 NOT NULL,
    moneda_id integer DEFAULT 1 NOT NULL,
    tasa_cambio numeric(12,4) DEFAULT 1.0000 NOT NULL,
    CONSTRAINT chk_recibos_cxc_tasa CHECK ((tasa_cambio > (0)::numeric)),
    CONSTRAINT recibos_ingreso_cxc_monto_total_recibido_check CHECK ((monto_total_recibido > (0)::numeric)),
    CONSTRAINT recibos_ingreso_cxc_saldo_a_favor_generado_check CHECK ((saldo_a_favor_generado >= (0)::numeric))
);


ALTER TABLE ventas.recibos_ingreso_cxc OWNER TO postgres;

--
-- TOC entry 276 (class 1259 OID 17112)
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
-- TOC entry 6354 (class 0 OID 0)
-- Dependencies: 276
-- Name: recibos_ingreso_cxc_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.recibos_ingreso_cxc_id_seq OWNED BY ventas.recibos_ingreso_cxc.id;


--
-- TOC entry 281 (class 1259 OID 17184)
-- Name: repartidor_horarios; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.repartidor_horarios (
    repartidor_id uuid NOT NULL,
    dia_semana integer NOT NULL,
    hora_inicio time without time zone NOT NULL,
    hora_fin time without time zone NOT NULL,
    CONSTRAINT chk_horario_logico CHECK ((hora_fin > hora_inicio)),
    CONSTRAINT repartidor_horarios_dia_semana_check CHECK (((dia_semana >= 1) AND (dia_semana <= 7)))
);


ALTER TABLE ventas.repartidor_horarios OWNER TO postgres;

--
-- TOC entry 280 (class 1259 OID 17164)
-- Name: repartidor_zonas; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.repartidor_zonas (
    repartidor_id uuid NOT NULL,
    zona_id integer NOT NULL,
    es_ruta_principal boolean DEFAULT false NOT NULL
);


ALTER TABLE ventas.repartidor_zonas OWNER TO postgres;

--
-- TOC entry 256 (class 1259 OID 16820)
-- Name: secuencias_fiscales_dgii; Type: TABLE; Schema: ventas; Owner: postgres
--

CREATE TABLE ventas.secuencias_fiscales_dgii (
    id integer NOT NULL,
    tipo_comprobante character varying(10) NOT NULL,
    secuencia_desde bigint NOT NULL,
    secuencia_hasta bigint NOT NULL,
    secuencia_actual bigint NOT NULL,
    fecha_vencimiento date NOT NULL,
    estado character varying(20) DEFAULT 'ACTIVO'::character varying NOT NULL,
    CONSTRAINT chk_secuencias_dgii_estado CHECK (((estado)::text = ANY ((ARRAY['ACTIVO'::character varying, 'AGOTADO'::character varying, 'VENCIDO'::character varying])::text[]))),
    CONSTRAINT chk_secuencias_dgii_rango CHECK (((secuencia_desde > 0) AND (secuencia_hasta >= secuencia_desde) AND (secuencia_actual >= secuencia_desde))),
    CONSTRAINT chk_secuencias_dgii_tipo CHECK (((tipo_comprobante)::text = ANY ((ARRAY['B01'::character varying, 'B02'::character varying, 'B03'::character varying, 'B04'::character varying])::text[])))
);


ALTER TABLE ventas.secuencias_fiscales_dgii OWNER TO postgres;

--
-- TOC entry 255 (class 1259 OID 16819)
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
-- TOC entry 6355 (class 0 OID 0)
-- Dependencies: 255
-- Name: secuencias_fiscales_dgii_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.secuencias_fiscales_dgii_id_seq OWNED BY ventas.secuencias_fiscales_dgii.id;


--
-- TOC entry 260 (class 1259 OID 16848)
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
    monto_efectivo_esperado numeric(12,2) DEFAULT 0.00,
    monto_efectivo_declarado numeric(12,2),
    diferencia_descuadre numeric(12,2),
    CONSTRAINT chk_sesiones_caja_fechas CHECK (((fecha_cierre IS NULL) OR (fecha_cierre >= fecha_apertura))),
    CONSTRAINT sesiones_caja_estado_check CHECK (((estado)::text = ANY ((ARRAY['ABIERTA'::character varying, 'EN_CUADRE'::character varying, 'CERRADA'::character varying])::text[])))
);


ALTER TABLE ventas.sesiones_caja OWNER TO postgres;

--
-- TOC entry 259 (class 1259 OID 16847)
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
-- TOC entry 6356 (class 0 OID 0)
-- Dependencies: 259
-- Name: sesiones_caja_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.sesiones_caja_id_seq OWNED BY ventas.sesiones_caja.id;


--
-- TOC entry 279 (class 1259 OID 17148)
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
-- TOC entry 278 (class 1259 OID 17147)
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
-- TOC entry 6357 (class 0 OID 0)
-- Dependencies: 278
-- Name: zonas_delivery_id_seq; Type: SEQUENCE OWNED BY; Schema: ventas; Owner: postgres
--

ALTER SEQUENCE ventas.zonas_delivery_id_seq OWNED BY ventas.zonas_delivery.id;


--
-- TOC entry 5404 (class 2604 OID 27027)
-- Name: ajustes_cxp id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ajustes_cxp ALTER COLUMN id SET DEFAULT nextval('compras.ajustes_cxp_id_seq'::regclass);


--
-- TOC entry 5361 (class 2604 OID 17539)
-- Name: comprobantes_egreso_cxp id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp ALTER COLUMN id SET DEFAULT nextval('compras.comprobantes_egreso_cxp_id_seq'::regclass);


--
-- TOC entry 5357 (class 2604 OID 17510)
-- Name: cuentas_por_pagar id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar ALTER COLUMN id SET DEFAULT nextval('compras.cuentas_por_pagar_id_seq'::regclass);


--
-- TOC entry 5345 (class 2604 OID 17403)
-- Name: ordenes_compra id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra ALTER COLUMN id SET DEFAULT nextval('compras.ordenes_compra_id_seq'::regclass);


--
-- TOC entry 5352 (class 2604 OID 17454)
-- Name: recepciones_compra id; Type: DEFAULT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra ALTER COLUMN id SET DEFAULT nextval('compras.recepciones_compra_id_seq'::regclass);


--
-- TOC entry 5263 (class 2604 OID 16714)
-- Name: almacenes id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes ALTER COLUMN id SET DEFAULT nextval('inventario.almacenes_id_seq'::regclass);


--
-- TOC entry 5254 (class 2604 OID 16634)
-- Name: categoria_producto id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.categoria_producto ALTER COLUMN id SET DEFAULT nextval('inventario.categoria_producto_id_seq'::regclass);


--
-- TOC entry 5266 (class 2604 OID 16747)
-- Name: inventario_stock id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock ALTER COLUMN id SET DEFAULT nextval('inventario.inventario_stock_id_seq'::regclass);


--
-- TOC entry 5265 (class 2604 OID 16727)
-- Name: lotes id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes ALTER COLUMN id SET DEFAULT nextval('inventario.lotes_id_seq'::regclass);


--
-- TOC entry 5256 (class 2604 OID 16645)
-- Name: marca id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.marca ALTER COLUMN id SET DEFAULT nextval('inventario.marca_id_seq'::regclass);


--
-- TOC entry 5269 (class 2604 OID 16780)
-- Name: movimientos_inventario id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario ALTER COLUMN id SET DEFAULT nextval('inventario.movimientos_inventario_id_seq'::regclass);


--
-- TOC entry 5262 (class 2604 OID 16691)
-- Name: producto_codigos_barra id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra ALTER COLUMN id SET DEFAULT nextval('inventario.producto_codigos_barra_id_seq'::regclass);


--
-- TOC entry 5258 (class 2604 OID 16656)
-- Name: productos_maestro id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro ALTER COLUMN id SET DEFAULT nextval('inventario.productos_maestro_id_seq'::regclass);


--
-- TOC entry 5379 (class 2604 OID 17758)
-- Name: traslados_internos id; Type: DEFAULT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos ALTER COLUMN id SET DEFAULT nextval('inventario.traslados_internos_id_seq'::regclass);


--
-- TOC entry 5371 (class 2604 OID 17646)
-- Name: cupones_descuento id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento ALTER COLUMN id SET DEFAULT nextval('promociones.cupones_descuento_id_seq'::regclass);


--
-- TOC entry 5366 (class 2604 OID 17600)
-- Name: promociones_campanas id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas ALTER COLUMN id SET DEFAULT nextval('promociones.promociones_campanas_id_seq'::regclass);


--
-- TOC entry 5370 (class 2604 OID 26401)
-- Name: promociones_detalles id; Type: DEFAULT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles ALTER COLUMN id SET DEFAULT nextval('promociones.promociones_detalles_id_seq'::regclass);


--
-- TOC entry 5252 (class 2604 OID 16607)
-- Name: auditoria auditoria_id; Type: DEFAULT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria ALTER COLUMN auditoria_id SET DEFAULT nextval('seguridad.auditoria_auditoria_id_seq'::regclass);


--
-- TOC entry 5373 (class 2604 OID 17685)
-- Name: cuentas_bancarias id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias ALTER COLUMN id SET DEFAULT nextval('tesoreria.cuentas_bancarias_id_seq'::regclass);


--
-- TOC entry 5394 (class 2604 OID 26784)
-- Name: detalles_pago id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.detalles_pago ALTER COLUMN id SET DEFAULT nextval('tesoreria.detalles_pago_id_seq'::regclass);


--
-- TOC entry 5388 (class 2604 OID 26601)
-- Name: impuestos id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.impuestos ALTER COLUMN id SET DEFAULT nextval('tesoreria.impuestos_id_seq'::regclass);


--
-- TOC entry 5384 (class 2604 OID 26462)
-- Name: monedas id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.monedas ALTER COLUMN id SET DEFAULT nextval('tesoreria.monedas_id_seq'::regclass);


--
-- TOC entry 5376 (class 2604 OID 17704)
-- Name: movimientos_bancarios id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios ALTER COLUMN id SET DEFAULT nextval('tesoreria.movimientos_bancarios_id_seq'::regclass);


--
-- TOC entry 5392 (class 2604 OID 26718)
-- Name: retenciones id; Type: DEFAULT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.retenciones ALTER COLUMN id SET DEFAULT nextval('tesoreria.retenciones_id_seq'::regclass);


--
-- TOC entry 5400 (class 2604 OID 26977)
-- Name: ajustes_cxc id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc ALTER COLUMN id SET DEFAULT nextval('ventas.ajustes_cxc_id_seq'::regclass);


--
-- TOC entry 5274 (class 2604 OID 16839)
-- Name: cajas_pos id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cajas_pos ALTER COLUMN id SET DEFAULT nextval('ventas.cajas_pos_id_seq'::regclass);


--
-- TOC entry 5325 (class 2604 OID 17228)
-- Name: consignaciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones ALTER COLUMN id SET DEFAULT nextval('ventas.consignaciones_id_seq'::regclass);


--
-- TOC entry 5308 (class 2604 OID 17054)
-- Name: cotizaciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones ALTER COLUMN id SET DEFAULT nextval('ventas.cotizaciones_id_seq'::regclass);


--
-- TOC entry 5294 (class 2604 OID 16946)
-- Name: cuentas_por_cobrar id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar ALTER COLUMN id SET DEFAULT nextval('ventas.cuentas_por_cobrar_id_seq'::regclass);


--
-- TOC entry 5334 (class 2604 OID 17294)
-- Name: devoluciones id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones ALTER COLUMN id SET DEFAULT nextval('ventas.devoluciones_id_seq'::regclass);


--
-- TOC entry 5280 (class 2604 OID 16874)
-- Name: facturas id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas ALTER COLUMN id SET DEFAULT nextval('ventas.facturas_id_seq'::regclass);


--
-- TOC entry 5299 (class 2604 OID 16986)
-- Name: metodos_pago id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago ALTER COLUMN id SET DEFAULT nextval('ventas.metodos_pago_id_seq'::regclass);


--
-- TOC entry 5302 (class 2604 OID 17002)
-- Name: movimiento_caja id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja ALTER COLUMN id SET DEFAULT nextval('ventas.movimiento_caja_id_seq'::regclass);


--
-- TOC entry 5340 (class 2604 OID 17343)
-- Name: notas_debito id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito ALTER COLUMN id SET DEFAULT nextval('ventas.notas_debito_id_seq'::regclass);


--
-- TOC entry 5323 (class 2604 OID 17203)
-- Name: pedidos_delivery id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery ALTER COLUMN id SET DEFAULT nextval('ventas.pedidos_delivery_id_seq'::regclass);


--
-- TOC entry 5314 (class 2604 OID 17116)
-- Name: recibos_ingreso_cxc id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc ALTER COLUMN id SET DEFAULT nextval('ventas.recibos_ingreso_cxc_id_seq'::regclass);


--
-- TOC entry 5272 (class 2604 OID 16823)
-- Name: secuencias_fiscales_dgii id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.secuencias_fiscales_dgii ALTER COLUMN id SET DEFAULT nextval('ventas.secuencias_fiscales_dgii_id_seq'::regclass);


--
-- TOC entry 5276 (class 2604 OID 16851)
-- Name: sesiones_caja id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja ALTER COLUMN id SET DEFAULT nextval('ventas.sesiones_caja_id_seq'::regclass);


--
-- TOC entry 5319 (class 2604 OID 17151)
-- Name: zonas_delivery id; Type: DEFAULT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery ALTER COLUMN id SET DEFAULT nextval('ventas.zonas_delivery_id_seq'::regclass);


--
-- TOC entry 6312 (class 0 OID 27024)
-- Dependencies: 344
-- Data for Name: ajustes_cxp; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.ajustes_cxp (id, cuenta_por_pagar_id, tipo_ajuste, ncf_nota_credito_proveedor, moneda_id, tasa_cambio, monto_ajuste, saldo_anterior, saldo_restante, justificacion, supervisor_id, fecha_ajuste) FROM stdin;
\.


--
-- TOC entry 6272 (class 0 OID 17559)
-- Dependencies: 304
-- Data for Name: comprobante_egreso_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.comprobante_egreso_detalles (comprobante_egreso_id, cuenta_por_pagar_id, monto_aplicado, saldo_anterior, saldo_restante, monto_retenciones) FROM stdin;
\.


--
-- TOC entry 6299 (class 0 OID 26754)
-- Dependencies: 331
-- Data for Name: comprobante_egreso_retenciones; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.comprobante_egreso_retenciones (comprobante_egreso_id, cuenta_por_pagar_id, retencion_id, base_calculo_aplicada, monto_base, porcentaje_aplicado, monto_retenido) FROM stdin;
\.


--
-- TOC entry 6271 (class 0 OID 17536)
-- Dependencies: 303
-- Data for Name: comprobantes_egreso_cxp; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.comprobantes_egreso_cxp (id, numero_comprobante, proveedor_id, usuario_registro_id, monto_total_pagado, fecha_pago, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6269 (class 0 OID 17507)
-- Dependencies: 301
-- Data for Name: cuentas_por_pagar; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.cuentas_por_pagar (id, proveedor_id, recepcion_compra_id, monto_original, saldo_pendiente, fecha_emision, fecha_vencimiento, estado, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6307 (class 0 OID 26933)
-- Dependencies: 339
-- Data for Name: egreso_cxp_pagos; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.egreso_cxp_pagos (comprobante_egreso_id, detalle_pago_id) FROM stdin;
\.


--
-- TOC entry 6264 (class 0 OID 17425)
-- Dependencies: 296
-- Data for Name: orden_compra_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.orden_compra_detalles (orden_compra_id, producto_maestro_id, cantidad_ordenada, cantidad_recibida_total, costo_pactado_unitario) FROM stdin;
\.


--
-- TOC entry 6263 (class 0 OID 17400)
-- Dependencies: 295
-- Data for Name: ordenes_compra; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.ordenes_compra (id, numero_orden, proveedor_id, usuario_id, estado, monto_total_estimado, fecha_emision, fecha_esperada, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6267 (class 0 OID 17480)
-- Dependencies: 299
-- Data for Name: recepcion_compra_detalles; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.recepcion_compra_detalles (recepcion_id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_recibida_buena, costo_unitario_facturado, orden_compra_id) FROM stdin;
\.


--
-- TOC entry 6266 (class 0 OID 17451)
-- Dependencies: 298
-- Data for Name: recepciones_compra; Type: TABLE DATA; Schema: compras; Owner: postgres
--

COPY compras.recepciones_compra (id, orden_compra_id, proveedor_id, numero_factura_proveedor, ncf_proveedor, monto_total_factura, usuario_recepcion_id, fecha_recepcion, condicion_pago, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6196 (class 0 OID 16425)
-- Dependencies: 228
-- Data for Name: clientes; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.clientes (persona_id, codigo_cliente, limite_credito, creado_en, categoria_cliente, permite_credito) FROM stdin;
\.


--
-- TOC entry 6197 (class 0 OID 16451)
-- Dependencies: 229
-- Data for Name: empleados; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.empleados (persona_id, codigo_empleado, cargo, fecha_ingreso, estado_laboral, creado_en, empleado_id) FROM stdin;
5ca3f19a-ba8d-40a9-b3de-ea023baf5ab5	EMP-001	Gerente de Tienda	2026-11-06	ACTIVO	2026-11-12 18:40:50.226609-04	d803c358-b8c8-4ac5-99be-a1a058f6bdc3
2ba89c95-e476-4fb5-b168-d6e5b5e78bb7	EMP-002	Gerente de Tienda	2026-11-13	ACTIVO	2026-11-13 17:02:32.905186-04	58331488-f6ee-4bd4-bd0a-06115b6b2c44
f2b76264-3361-4941-9eaa-1ea81cd7ed5a	EMP-003	Repartidor Delivery	2026-11-13	ACTIVO	2026-11-13 18:58:15.04458-04	9f578670-1434-49dd-87ae-8906b4663c26
0034133e-6c83-437e-9764-4f8f5f411252	EMP-004	Repartidor Delivery	2026-11-13	ACTIVO	2026-11-13 19:03:18.590042-04	63d80fce-0766-42c8-9f4c-0ebf218cd35b
7b04aa99-1dad-49e1-a613-e88d1a616aae	EMP-005	Repartidor Delivery	2026-11-13	ACTIVO	2026-11-13 19:13:09.898344-04	b02cd382-0eab-4b7c-a1e1-a8ed581c76d7
\.


--
-- TOC entry 6195 (class 0 OID 16399)
-- Dependencies: 227
-- Data for Name: personas; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.personas (persona_id, tipo_documento, documento_identidad, nombre_razon_social, nombre_comercial, fecha_nacimiento, email, telefono_principal, creado_en, tipo_persona, estado) FROM stdin;
5ca3f19a-ba8d-40a9-b3de-ea023baf5ab5	CEDULA	402-2852532-6	Nathalia Melendez S.	\N	\N	nathaliams0305@gmail.com	(000) 000-0000	2026-11-12 18:40:50.226609-04	FISICA	t
2ba89c95-e476-4fb5-b168-d6e5b5e78bb7	CEDULA	402-2000000-0	Erickson Santos	\N	\N	ericksonsantos@gmail.com	(809) 878-2601	2026-11-13 17:02:32.905186-04	FISICA	t
f2b76264-3361-4941-9eaa-1ea81cd7ed5a	CEDULA	031-0489215-7	Carlos Manuel Rodríguez Peña	\N	\N	carlos.rodriguez@houseandice.do	(809) 582-4410	2026-11-13 18:58:15.04458-04	FISICA	t
0034133e-6c83-437e-9764-4f8f5f411252	CEDULA	402-2918473-9	Albert Salazar	\N	\N	alb.salazar@gmail.com	(829) 641-8825	2026-11-13 19:03:18.590042-04	FISICA	t
7b04aa99-1dad-49e1-a613-e88d1a616aae	CEDULA	000-0000000-0	Luis Tavares	\N	\N	luis@gmail.com	(849) 020-2020	2026-11-13 19:13:09.898344-04	FISICA	t
25e74dbe-6f9b-423e-b51d-5cc0b2b023c4	RNC	1-23-45678-9	Cerveceria Presidente	LICORES CAVA	\N	licorescava@gmail.com	(809) 739-0489	2026-11-13 19:33:21.54115-04	JURIDICA	t
\.


--
-- TOC entry 6298 (class 0 OID 26732)
-- Dependencies: 330
-- Data for Name: proveedor_retenciones; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.proveedor_retenciones (proveedor_id, retencion_id) FROM stdin;
\.


--
-- TOC entry 6198 (class 0 OID 16478)
-- Dependencies: 230
-- Data for Name: proveedores; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.proveedores (creado_en, persona_id, codigo_proveedor, dias_credito_habitual) FROM stdin;
2026-11-13 19:33:21.54115-04	25e74dbe-6f9b-423e-b51d-5cc0b2b023c4	PROV-001	30
\.


--
-- TOC entry 6282 (class 0 OID 17734)
-- Dependencies: 314
-- Data for Name: repartidores; Type: TABLE DATA; Schema: identidad; Owner: postgres
--

COPY identidad.repartidores (empleado_id, numero_licencia, tipo_vehiculo, placa_vehiculo, estado_logistico, capacidad_pedidos) FROM stdin;
9f578670-1434-49dd-87ae-8906b4663c26	031-0489215-7	MOTOCICLETA	K1489203	BASE	3
63d80fce-0766-42c8-9f4c-0ebf218cd35b	402-2918473-9	FURGONETA	L0394812	BASE	8
\.


--
-- TOC entry 6215 (class 0 OID 16711)
-- Dependencies: 247
-- Data for Name: almacenes; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.almacenes (id, nombre, ubicacion, activo) FROM stdin;
1	Bodega Principal	Pasillo 3	t
2	Bodega izquierda	Pasillo 1	t
3	Bodega abajo	Pasillo 4	t
4	Bodega de Nathalia	El edificio C que esta mal hecho	t
\.


--
-- TOC entry 6207 (class 0 OID 16631)
-- Dependencies: 239
-- Data for Name: categoria_producto; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.categoria_producto (id, nombre, estado) FROM stdin;
0	SIN CATEGORIA	ACTIVO
1	Ron	ACTIVO
2	Cerveza	ACTIVO
3	Refrescos	ACTIVO
4	Whisky	ACTIVO
\.


--
-- TOC entry 6219 (class 0 OID 16744)
-- Dependencies: 251
-- Data for Name: inventario_stock; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.inventario_stock (id, almacen_id, producto_maestro_id, lote_id, stock_fisico_unidades, stock_comprometido) FROM stdin;
\.


--
-- TOC entry 6217 (class 0 OID 16724)
-- Dependencies: 249
-- Data for Name: lotes; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.lotes (id, producto_maestro_id, numero_lote, fecha_vencimiento, cantidad_actual) FROM stdin;
0	0	GENERICO - SIN LOTE	2099-12-31	0
\.


--
-- TOC entry 6209 (class 0 OID 16642)
-- Dependencies: 241
-- Data for Name: marca; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.marca (id, nombre, estado) FROM stdin;
0	SIN MARCA	ACTIVO
1	Brugal	ACTIVO
2	Buzz ball	ACTIVO
3	Fireball	ACTIVO
4	Presidente	ACTIVO
5	Smirnoff	ACTIVO
\.


--
-- TOC entry 6222 (class 0 OID 16791)
-- Dependencies: 254
-- Data for Name: movimiento_inventario_detalles; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.movimiento_inventario_detalles (movimiento_id, producto_maestro_id, lote_id, cantidad_unidades, costo_unitario_referencia) FROM stdin;
\.


--
-- TOC entry 6221 (class 0 OID 16777)
-- Dependencies: 253
-- Data for Name: movimientos_inventario; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.movimientos_inventario (id, tipo_movimiento, justificacion, supervisor_id, documento_referencia_id, fecha_movimiento) FROM stdin;
\.


--
-- TOC entry 6213 (class 0 OID 16688)
-- Dependencies: 245
-- Data for Name: producto_codigos_barra; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.producto_codigos_barra (id, producto_maestro_id, codigo_barra, descripcion_presentacion, factor_multiplicador, precio_venta_presentacion) FROM stdin;
1	1	7460100123456	Ron Brugal Leyenda Botella 700ml	1	2450.00
\.


--
-- TOC entry 6292 (class 0 OID 26619)
-- Dependencies: 324
-- Data for Name: producto_impuestos; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.producto_impuestos (producto_maestro_id, impuesto_id) FROM stdin;
0	3
1	1
\.


--
-- TOC entry 6211 (class 0 OID 16653)
-- Dependencies: 243
-- Data for Name: productos_maestro; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.productos_maestro (id, sku, categoria_id, marca_id, requiere_lote, costo_promedio, precio_base_unidad, nombre) FROM stdin;
0	SKU-000	0	0	f	0.00	0.00	Producto sin nombre asignado
1	RON-BRU-LEY700	1	1	t	1850.00	2450.00	Producto sin nombre asignado
\.


--
-- TOC entry 6285 (class 0 OID 17785)
-- Dependencies: 317
-- Data for Name: traslado_detalles; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.traslado_detalles (traslado_id, producto_maestro_id, lote_id, cantidad_enviada, cantidad_recibida) FROM stdin;
\.


--
-- TOC entry 6284 (class 0 OID 17755)
-- Dependencies: 316
-- Data for Name: traslados_internos; Type: TABLE DATA; Schema: inventario; Owner: postgres
--

COPY inventario.traslados_internos (id, numero_traslado, almacen_origen_id, almacen_destino_id, usuario_emisor_id, usuario_receptor_id, estado, fecha_emision) FROM stdin;
\.


--
-- TOC entry 6277 (class 0 OID 17643)
-- Dependencies: 309
-- Data for Name: cupones_descuento; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.cupones_descuento (id, codigo_cupon, promocion_id, factura_origen_id, factura_canje_id, cliente_id, monto_credito, fecha_vencimiento, estado) FROM stdin;
\.


--
-- TOC entry 6274 (class 0 OID 17597)
-- Dependencies: 306
-- Data for Name: promociones_campanas; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.promociones_campanas (id, nombre_promocion, tipo_promocion, fecha_inicio, fecha_fin, hora_inicio, hora_fin, monto_minimo_factura, estado) FROM stdin;
\.


--
-- TOC entry 6275 (class 0 OID 17617)
-- Dependencies: 307
-- Data for Name: promociones_detalles; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.promociones_detalles (promocion_id, condicion_producto_id, condicion_categoria_id, cantidad_requerida, beneficio_producto_id, beneficio_porcentaje, beneficio_monto_fijo, condicion_marca_id, id) FROM stdin;
\.


--
-- TOC entry 6287 (class 0 OID 26427)
-- Dependencies: 319
-- Data for Name: promociones_dias; Type: TABLE DATA; Schema: promociones; Owner: postgres
--

COPY promociones.promociones_dias (promocion_id, dia_semana) FROM stdin;
\.


--
-- TOC entry 6205 (class 0 OID 16604)
-- Dependencies: 237
-- Data for Name: auditoria; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.auditoria (auditoria_id, usuario_id, accion, tabla_afectada, datos_anteriores, datos_nuevos, fecha_hora, supervisor_id) FROM stdin;
\.


--
-- TOC entry 6201 (class 0 OID 16550)
-- Dependencies: 233
-- Data for Name: permisos; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.permisos (permiso_id, clave_sistema, modulo, descripcion, creado_en, entidad, accion) FROM stdin;
9f842950-1a66-4611-9d0d-026f64623202	ANULAR_FACTURA	ventas	Este permiso es para el supervisor de turno	2026-11-11 23:39:46.54471-04	FACTURA	ANULAR
28818c07-13bb-4042-bbcb-e69a8565debe	CREAR_FACTURA	ventas	Creacion de factura	2026-11-13 00:04:51.426631-04	VENTAS	CREAR
c0e91bd0-3b2a-4713-acfa-609528f84efa	VER_PRODUCTOS	inventario	Consultar el catálogo maestro, precios y existencias disponibles en el mostrador.	2026-11-13 00:29:41.857887-04	PRODUCTOS	VER
dd06e918-513f-4f92-9aa5-6f24dd7ec18a	ABRIR_Y_CERRAR_CAJA	ventas	Iniciar el turno con el fondo de caja y realizar el arqueo ciego al finalizar para comparar el efectivo real con el sistema.	2026-11-13 00:29:41.857887-04	CAJA	ABRIR_Y_CERRAR
99671c4e-510f-4473-bf3a-38e2342ce0ff	REGISTRAR_CXC	cxc	Registrar abonos o pagos totales de clientes con créditos activos durante su turno.	2026-11-13 00:29:41.857887-04	CXC	REGISTRAR
d8379fd0-60a6-4656-bf75-f9d3082d07b7	CREAR_COTIZACION	cotizaciones	Generar presupuestos comerciales en pantalla antes de facturar.	2026-11-13 00:29:41.857887-04	COTIZACION	CREAR
8957a46f-37ed-4310-9098-f8a2658a649a	CANCELAR_COTIZACION	cotizaciones	Anular presupuestos comerciales o cotizaciones especiales con precios congelados si el cliente se arrepiente antes de facturar.	2026-11-13 00:29:41.857887-04	COTIZACION	CANCELAR
01940a7c-7d98-449a-82a9-552f5d5590e6	VER_PEDIDOS_ASIGNADOS	delivery	Consultar en su interfaz móvil las direcciones, los detalles de los productos y la ruta de entrega asignada.	2026-11-13 00:29:41.857887-04	PEDIDOS	VER_ASIGNADOS
597504d9-8cf8-48ff-a8d8-d53a949303fa	ACTUALIZAR_ESTADO_DELIVERY	delivery	Marcar los pedidos en ruta con los estados EN_CAMINO o ENTREGADO una vez finalizada la entrega en el destino del cliente.	2026-11-13 00:29:41.857887-04	DELIVERY	ACTUALIZAR_ESTADO
fe93dbb2-90b5-4b2d-b6f6-abccd2491022	AUTORIZAR_CON_PIN	seguridad	Validar acciones restringidas del cajero en tiempo real (anulaciones, descuentos especiales).	2026-11-13 00:29:41.857887-04	PIN	AUTORIZAR
ca267041-14ab-4eed-ad92-dfdb4ca288f9	HACER_CUADRE	ventas	Revisar los arqueos ciegos de los cajeros y liquidar las diferencias (faltantes o sobrantes de caja).	2026-11-13 00:29:41.857887-04	CAJA	HACER_CUADRE
0bc67d11-352b-4ff0-8e82-cb5dd3104ea5	AUTORIZAR_DEVOLUCIONES	ventas	Validar facturas originales para procesar Notas de Crédito (B04) y reingresar mercancía al inventario.	2026-11-13 00:29:41.857887-04	DEVOLUCIONES	AUTORIZAR
e33b7d6a-a47e-45bd-b6c3-75b9933c8eef	GESTIONAR_CONSIGNACIONES	ventas	Registrar el préstamo de mercancía para eventos y validar su retorno.	2026-11-13 00:29:41.857887-04	CONSIGNACIONES	GESTIONAR
ff8a0f06-e1c8-4c59-b11c-80da9b255f6b	CREAR_ORDEN_COMPRA	compras	Emitir solicitudes formales de mercancía a los suplidores.	2026-11-13 00:29:41.857887-04	ORDEN_COMPRA	CREAR
61db4689-9f57-4fbd-acc0-9c30b2bd7c4a	REGISTRAR_RECEPCION_COMPRA	compras	Dar entrada al camión, registrar la factura física, capturar lotes y fechas de vencimiento (FEFO) y calcular el backorder.	2026-11-13 00:29:41.857887-04	RECEPCION_COMPRA	REGISTRAR
9364992a-0a61-4f01-a252-1965894128c0	REALIZAR_TRASLADOS	inventario	Mover stock de manera interna entre almacenes (ej. de la bodega principal al mostrador).	2026-11-13 00:29:41.857887-04	TRASLADOS	REALIZAR
126ac8d2-005d-4cad-afaf-2994dff37f95	REGISTRAR_MERMAS	inventario	Ajustar el inventario por roturas, daños o pérdidas accidentales.	2026-11-13 00:29:41.857887-04	MERMAS	REGISTRAR
b763e037-8343-4df4-a043-18880c4a36d8	GESTIONAR_CXC	cxc	Administrar líneas de crédito corporativas, plazos y saldos a favor.	2026-11-13 00:29:41.857887-04	CXC	GESTIONAR
060c14cc-9ca7-4f29-aa31-41952a5134bd	EMITIR_NOTA_DEBITO	cxc	Generar comprobantes B03 para cobrar penalidades por cheques devueltos, moras o errores de sub-facturación.	2026-11-13 00:29:41.857887-04	NOTA_DEBITO	EMITIR
b2c393d2-4b49-4e4c-b652-921debffeadb	GESTIONAR_CXP	cxp	Aplicar pagos a suplidores y calcular retenciones fiscales.	2026-11-13 00:29:41.857887-04	CXP	GESTIONAR
74e15e3c-d382-40fd-89f4-929051f7ffb5	REGISTRAR_MOVIMIENTOS_BANCARIOS	tesoreria	Centralizar depósitos de caja, transferencias de clientes y pagos a bancos.	2026-11-13 00:29:41.857887-04	MOVIMIENTOS_BANCARIOS	REGISTRAR
17ec68e0-eb0b-449c-8153-59ae0e0b769d	EJECUTAR_CIERRES_FISCALES_PERIODO	tesoreria	Permite realizar el corte oficial de cierre de mes o de año fiscal ante la DGII, bloqueando la modificación de facturas o movimientos en periodos ya concluidos.	2026-11-13 00:29:41.857887-04	CIERRES_FISCALES	EJECUTAR
9add5598-2083-4587-b041-6bab41851c0b	GESTIONAR_USUARIOS_Y_ROLES	seguridad	Crear cuentas digitales, asignar etiquetas de roles y definir credenciales de acceso al sistema.	2026-11-13 00:29:41.857887-04	USUARIOS_ROLES	GESTIONAR
a2586bed-282b-4f4a-b393-7335538df30c	GESTIONAR_ALTAS_MAESTRAS	identidad	Permiso exclusivo del módulo maestro para dar de alta en la base de datos a nuevos empleados, repartidores y proveedores (configurando sus PINs de autorización, licencias y perfiles).	2026-11-13 00:29:41.857887-04	ALTAS_MAESTRAS	GESTIONAR
7e47fa62-092b-43d5-9102-564e3b2ccb91	CONFIGURAR_PROMOCIONES	promociones	Programar franjas horarias de Happy Hours (validadas por el servidor), descuentos por volumen y reglas de cashback.	2026-11-13 00:29:41.857887-04	PROMOCIONES	CONFIGURAR
708b9fad-4dbd-46b6-b3d1-b0d57ec5dac9	VER_AUDITORIA_FORENSE	auditoria	Consultar los logs de la caja negra (auditoria_logs) para rastrear eliminaciones lógicas (soft deletes) y modificaciones sensibles.	2026-11-13 00:29:41.857887-04	AUDITORIA_FORENSE	VER
87971ce3-269f-49b9-8c41-389b811f2af4	GESTIONAR_SECUENCIAS_FISCALES	configuracion	Cargar, auditar y bloquear los bloques de NCF autorizados por la DGII.	2026-11-13 00:29:41.857887-04	SECUENCIAS_FISCALES	GESTIONAR
39dcd047-33ad-49cc-879a-901ae501ef50	VER_REPORTES_FINANCIEROS	tesoreria	Acceder a la consulta global de liquidez en cuentas bancarias.	2026-11-13 00:29:41.857887-04	REPORTES_FINANCIEROS	VER
c4100001-0000-4000-8000-000000000001	GESTIONAR_METODOS_PAGO	configuracion	Acceso exclusivo del Administrador para crear, editar y activar/desactivar los Métodos de Pago.	2026-11-19 23:00:00-04	METODOS_PAGO	GESTIONAR
c4100002-0000-4000-8000-000000000002	GESTIONAR_LOTES_FEFO	inventario	Consultar existencias por caducidad, corregir fechas de vencimiento y dar de alta lotes iniciales (FEFO).	2026-11-19 23:00:00-04	LOTES	GESTIONAR
c4100003-0000-4000-8000-000000000003	GESTIONAR_PROVEEDORES	compras	Registrar y actualizar proveedores, días de crédito habitual y retenciones fiscales DGII.	2026-11-19 23:00:00-04	PROVEEDORES	GESTIONAR
c4100004-0000-4000-8000-000000000004	GESTIONAR_MONEDAS	tesoreria	Acceso exclusivo del Administrador para gestionar el mantenimiento de Monedas y Tasas de Cambio.	2026-11-19 23:00:00-04	MONEDAS	GESTIONAR
c4100005-0000-4000-8000-000000000005	GESTIONAR_IMPUESTOS	tesoreria	Acceso exclusivo del Administrador para gestionar el mantenimiento de Impuestos y Tributos Fiscales.	2026-11-19 23:00:00-04	IMPUESTOS	GESTIONAR
c4100006-0000-4000-8000-000000000006	GESTIONAR_RETENCIONES	tesoreria	Acceso exclusivo del Administrador para gestionar el mantenimiento de Retenciones Fiscales DGII.	2026-11-19 23:00:00-04	RETENCIONES	GESTIONAR
\.


--
-- TOC entry 6200 (class 0 OID 16534)
-- Dependencies: 232
-- Data for Name: roles; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.roles (rol_id, nombre, descripcion, es_activo, creado_en) FROM stdin;
1a703e97-3cf5-4944-b649-ac59e28a4742	Supervisor de turno		t	2026-11-11 23:40:34.696474-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	Administrador	Es el rol mas alto	t	2026-11-12 18:59:36.288985-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	Cajero	Operador de primera línea en la caja registradora. Su enfoque es la atención rápida al cliente, el cobro ágil y la emisión de facturas y tickets en el punto de venta.	t	2026-11-13 00:29:41.857887-04
10850c39-309c-4d9d-b0b9-12d993f7b3cf	Repartidor	Operador logístico en campo. Su enfoque es la entrega segura y puntual de los pedidos a domicilio asignados por el motor del servidor.	t	2026-11-13 00:29:41.857887-04
25419f15-97e2-4329-a72e-f6752f8de237	Encargado de Almacén y Compras	Administrador de la logística física, el inventario y las relaciones comerciales con los proveedores de la licorería.	t	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	Oficial de Crédito, Cobros y Tesorería	Gestor de la salud financiera corporativa, las carteras de crédito y el control de las cuentas bancarias de la empresa (sin tocar el POS).	t	2026-11-13 00:29:41.857887-04
d5200001-0000-4000-8000-000000000001	Almacenista	Encargado de bodega, control físico de existencias, lotes y vencimientos FEFO, recepción física de camiones, traslados internos y registro de mermas.	t	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	Gerente de Compras	Responsable de abastecimiento, negociación con proveedores y casas licoreras, emisión de órdenes de compra, recepción con NCF y seguimiento de cuentas por pagar.	t	2026-11-19 23:00:00-04
\.


--
-- TOC entry 6203 (class 0 OID 16584)
-- Dependencies: 235
-- Data for Name: roles_permisos; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.roles_permisos (rol_id, permiso_id, asignado_en) FROM stdin;
1a703e97-3cf5-4944-b649-ac59e28a4742	9f842950-1a66-4611-9d0d-026f64623202	2026-11-11 23:40:34.696474-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	28818c07-13bb-4042-bbcb-e69a8565debe	2026-11-13 00:29:41.857887-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	c0e91bd0-3b2a-4713-acfa-609528f84efa	2026-11-13 00:29:41.857887-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	dd06e918-513f-4f92-9aa5-6f24dd7ec18a	2026-11-13 00:29:41.857887-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	99671c4e-510f-4473-bf3a-38e2342ce0ff	2026-11-13 00:29:41.857887-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	d8379fd0-60a6-4656-bf75-f9d3082d07b7	2026-11-13 00:29:41.857887-04
c2396a51-7ed9-4693-962f-58c0b6e1792f	8957a46f-37ed-4310-9098-f8a2658a649a	2026-11-13 00:29:41.857887-04
10850c39-309c-4d9d-b0b9-12d993f7b3cf	01940a7c-7d98-449a-82a9-552f5d5590e6	2026-11-13 00:29:41.857887-04
10850c39-309c-4d9d-b0b9-12d993f7b3cf	597504d9-8cf8-48ff-a8d8-d53a949303fa	2026-11-13 00:29:41.857887-04
1a703e97-3cf5-4944-b649-ac59e28a4742	8957a46f-37ed-4310-9098-f8a2658a649a	2026-11-13 00:29:41.857887-04
1a703e97-3cf5-4944-b649-ac59e28a4742	fe93dbb2-90b5-4b2d-b6f6-abccd2491022	2026-11-13 00:29:41.857887-04
1a703e97-3cf5-4944-b649-ac59e28a4742	ca267041-14ab-4eed-ad92-dfdb4ca288f9	2026-11-13 00:29:41.857887-04
1a703e97-3cf5-4944-b649-ac59e28a4742	0bc67d11-352b-4ff0-8e82-cb5dd3104ea5	2026-11-13 00:29:41.857887-04
1a703e97-3cf5-4944-b649-ac59e28a4742	e33b7d6a-a47e-45bd-b6c3-75b9933c8eef	2026-11-13 00:29:41.857887-04
25419f15-97e2-4329-a72e-f6752f8de237	ff8a0f06-e1c8-4c59-b11c-80da9b255f6b	2026-11-13 00:29:41.857887-04
25419f15-97e2-4329-a72e-f6752f8de237	61db4689-9f57-4fbd-acc0-9c30b2bd7c4a	2026-11-13 00:29:41.857887-04
25419f15-97e2-4329-a72e-f6752f8de237	9364992a-0a61-4f01-a252-1965894128c0	2026-11-13 00:29:41.857887-04
25419f15-97e2-4329-a72e-f6752f8de237	126ac8d2-005d-4cad-afaf-2994dff37f95	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	b763e037-8343-4df4-a043-18880c4a36d8	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	060c14cc-9ca7-4f29-aa31-41952a5134bd	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	b2c393d2-4b49-4e4c-b652-921debffeadb	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	74e15e3c-d382-40fd-89f4-929051f7ffb5	2026-11-13 00:29:41.857887-04
59cb0d40-1302-487c-afd3-7257785878d8	17ec68e0-eb0b-449c-8153-59ae0e0b769d	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	9add5598-2083-4587-b041-6bab41851c0b	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	a2586bed-282b-4f4a-b393-7335538df30c	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	7e47fa62-092b-43d5-9102-564e3b2ccb91	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	708b9fad-4dbd-46b6-b3d1-b0d57ec5dac9	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	87971ce3-269f-49b9-8c41-389b811f2af4	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	39dcd047-33ad-49cc-879a-901ae501ef50	2026-11-13 00:29:41.857887-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	fe93dbb2-90b5-4b2d-b6f6-abccd2491022	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100001-0000-4000-8000-000000000001	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100002-0000-4000-8000-000000000002	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100003-0000-4000-8000-000000000003	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100004-0000-4000-8000-000000000004	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100005-0000-4000-8000-000000000005	2026-11-19 23:00:00-04
9f018e62-4810-46f4-8abb-fef7019ab4f2	c4100006-0000-4000-8000-000000000006	2026-11-19 23:00:00-04
1a703e97-3cf5-4944-b649-ac59e28a4742	87971ce3-269f-49b9-8c41-389b811f2af4	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	c0e91bd0-3b2a-4713-acfa-609528f84efa	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	c4100002-0000-4000-8000-000000000002	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	61db4689-9f57-4fbd-acc0-9c30b2bd7c4a	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	9364992a-0a61-4f01-a252-1965894128c0	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	126ac8d2-005d-4cad-afaf-2994dff37f95	2026-11-19 23:00:00-04
d5200001-0000-4000-8000-000000000001	e33b7d6a-a47e-45bd-b6c3-75b9933c8eef	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	c0e91bd0-3b2a-4713-acfa-609528f84efa	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	c4100003-0000-4000-8000-000000000003	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	ff8a0f06-e1c8-4c59-b11c-80da9b255f6b	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	61db4689-9f57-4fbd-acc0-9c30b2bd7c4a	2026-11-19 23:00:00-04
d5200002-0000-4000-8000-000000000002	b2c393d2-4b49-4e4c-b652-921debffeadb	2026-11-19 23:00:00-04
\.


--
-- TOC entry 6199 (class 0 OID 16506)
-- Dependencies: 231
-- Data for Name: usuarios; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.usuarios (usuario_id, persona_id, username, password_hash, ultimo_acceso, intentos_fallidos, creado_en, pin_autorizacion, estado) FROM stdin;
bbfb7afa-b430-4285-bceb-43858655fcee	5ca3f19a-ba8d-40a9-b3de-ea023baf5ab5	admin	$2y$10$6GGRIeh4l92s7sPnhYxQjO7DZWCQ8VEK0.KXuFP3UcokDCTky2euO	2026-11-14 01:15:29.581137-04	0	2026-11-12 19:00:16.937023-04	\N	ACTIVO
b7cf8960-ceca-4c76-abe1-678710139674	2ba89c95-e476-4fb5-b168-d6e5b5e78bb7	admin1	$2y$10$Qm.P8h4BgeMtNHZYTfAEMe7XrycvlQNdAVWDVsm7lqiVJow7nZy96	2026-11-18 16:33:13.068925-04	0	2026-11-13 17:05:11.161725-04	\N	ACTIVO
\.


--
-- TOC entry 6202 (class 0 OID 16565)
-- Dependencies: 234
-- Data for Name: usuarios_roles; Type: TABLE DATA; Schema: seguridad; Owner: postgres
--

COPY seguridad.usuarios_roles (usuario_id, rol_id, asignado_en) FROM stdin;
bbfb7afa-b430-4285-bceb-43858655fcee	9f018e62-4810-46f4-8abb-fef7019ab4f2	2026-11-12 19:00:16.937023-04
b7cf8960-ceca-4c76-abe1-678710139674	9f018e62-4810-46f4-8abb-fef7019ab4f2	2026-11-13 17:05:11.161725-04
\.


--
-- TOC entry 6279 (class 0 OID 17682)
-- Dependencies: 311
-- Data for Name: cuentas_bancarias; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.cuentas_bancarias (id, nombre_banco, numero_cuenta, tipo_cuenta, activa, moneda_id) FROM stdin;
1	Banco Popular Dominicano	7894512036	CORRIENTE	t	1
2	Banreservas	9601482570	CORRIENTE	t	1
3	Banco BHD	1428950318	AHORRO	t	2
\.


--
-- TOC entry 6301 (class 0 OID 26781)
-- Dependencies: 333
-- Data for Name: detalles_pago; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.detalles_pago (id, metodo_pago_id, moneda_id, tasa_cambio, monto_pagado, monto_devuelta, sesion_caja_id, fecha_pago) FROM stdin;
\.


--
-- TOC entry 6291 (class 0 OID 26598)
-- Dependencies: 323
-- Data for Name: impuestos; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.impuestos (id, siglas, nombre, tipo_calculo, tasa_o_monto, orden_calculo, activo) FROM stdin;
1	ITBIS_18	ITBIS Tasa General 18%	PORCENTAJE	18.0000	2	t
2	ITBIS_16	ITBIS Tasa Reducida 16%	PORCENTAJE	16.0000	2	t
3	EXENTO	Exento de Impuestos	PORCENTAJE	0.0000	1	t
4	ISC_ADVAL_10	ISC Ad Valorem Alcoholes 10%	PORCENTAJE	10.0000	1	t
\.


--
-- TOC entry 6289 (class 0 OID 26459)
-- Dependencies: 321
-- Data for Name: monedas; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.monedas (id, codigo_iso, nombre, simbolo, tasa_cambio_referencia, es_moneda_base, activa) FROM stdin;
1	DOP	Peso Dominicano	RD$	1.0000	t	t
2	USD	Dólar Estadounidense	US$	60.0000	f	t
3	EUR	Euro	€	65.0000	f	t
\.


--
-- TOC entry 6281 (class 0 OID 17701)
-- Dependencies: 313
-- Data for Name: movimientos_bancarios; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.movimientos_bancarios (id, cuenta_bancaria_id, tipo_movimiento, monto, referencia, concepto, fecha_movimiento, usuario_id, detalle_pago_id) FROM stdin;
\.


--
-- TOC entry 6304 (class 0 OID 26853)
-- Dependencies: 336
-- Data for Name: pago_cheque; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.pago_cheque (detalle_pago_id, numero_cheque, banco_emisor, titular_cheque, fecha_emision, fecha_deposito, cuenta_bancaria_id, estado_cheque) FROM stdin;
\.


--
-- TOC entry 6305 (class 0 OID 26876)
-- Dependencies: 337
-- Data for Name: pago_saldo_favor; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.pago_saldo_favor (detalle_pago_id, cliente_id, origen_saldo, documento_origen_id) FROM stdin;
\.


--
-- TOC entry 6302 (class 0 OID 26816)
-- Dependencies: 334
-- Data for Name: pago_tarjeta; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.pago_tarjeta (detalle_pago_id, tipo_tarjeta, marca_tarjeta, ultimos_4_digitos, numero_autorizacion, numero_lote) FROM stdin;
\.


--
-- TOC entry 6303 (class 0 OID 26833)
-- Dependencies: 335
-- Data for Name: pago_transferencia; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.pago_transferencia (detalle_pago_id, banco_origen, cuenta_bancaria_id, numero_referencia, fecha_transferencia, titular_origen) FROM stdin;
\.


--
-- TOC entry 6297 (class 0 OID 26715)
-- Dependencies: 329
-- Data for Name: retenciones; Type: TABLE DATA; Schema: tesoreria; Owner: postgres
--

COPY tesoreria.retenciones (id, siglas, nombre, base_calculo, porcentaje, activo) FROM stdin;
1	RET_ITBIS_30	Retención 30% ITBIS (Servicios Profesionales)	IMPUESTO	30.00	t
2	RET_ITBIS_100	Retención 100% ITBIS (Personas Físicas / Seguridad)	IMPUESTO	100.00	t
3	RET_ISR_2	Retención 2% ISR (Servicios Técnicos / Otros)	SUBTOTAL	2.00	t
4	RET_ISR_10	Retención 10% ISR (Servicios Profesionales / Alquileres)	SUBTOTAL	10.00	t
\.


--
-- TOC entry 6310 (class 0 OID 26974)
-- Dependencies: 342
-- Data for Name: ajustes_cxc; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.ajustes_cxc (id, cuenta_por_cobrar_id, tipo_ajuste, devolucion_id, moneda_id, tasa_cambio, monto_ajuste, saldo_anterior, saldo_restante, justificacion, supervisor_id, fecha_ajuste) FROM stdin;
\.


--
-- TOC entry 6240 (class 0 OID 17020)
-- Dependencies: 272
-- Data for Name: arqueo_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.arqueo_detalles (sesion_caja_id, metodo_pago_id, monto_declarado_cajero, monto_esperado_sistema, cantidad_vouchers, estado_deposito, monto_retenido_fondo) FROM stdin;
\.


--
-- TOC entry 6226 (class 0 OID 16836)
-- Dependencies: 258
-- Data for Name: cajas_pos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cajas_pos (id, nombre_equipo, estado, ubicacion) FROM stdin;
1	Caja	CERRADA	\N
\.


--
-- TOC entry 6254 (class 0 OID 17248)
-- Dependencies: 286
-- Data for Name: consignacion_facturas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignacion_facturas (consignacion_id, factura_id) FROM stdin;
\.


--
-- TOC entry 6308 (class 0 OID 26952)
-- Dependencies: 340
-- Data for Name: consignacion_garantia_pagos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignacion_garantia_pagos (consignacion_id, detalle_pago_id) FROM stdin;
\.


--
-- TOC entry 6253 (class 0 OID 17225)
-- Dependencies: 285
-- Data for Name: consignaciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignaciones (id, numero_consignacion, cliente_id, responsable_evento, nombre_evento, fecha_despacho, fecha_estimada_retorno, monto_deposito_garantia, estado, supervisor_id, sesion_caja_id, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6255 (class 0 OID 17266)
-- Dependencies: 287
-- Data for Name: consignaciones_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.consignaciones_detalle (consignacion_id, producto_maestro_id, lote_id, cantidad_despachada, cantidad_devuelta, precio_pactado_unitario) FROM stdin;
\.


--
-- TOC entry 6294 (class 0 OID 26662)
-- Dependencies: 326
-- Data for Name: cotizacion_detalle_impuestos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cotizacion_detalle_impuestos (cotizacion_id, producto_maestro_id, impuesto_id, base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto) FROM stdin;
\.


--
-- TOC entry 6242 (class 0 OID 17051)
-- Dependencies: 274
-- Data for Name: cotizaciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cotizaciones (id, numero_cotizacion, cliente_id, vendedor_id, fecha_emision, fecha_vencimiento, monto_subtotal, monto_impuestos, monto_total, estado, factura_generada_id, observaciones, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6243 (class 0 OID 17085)
-- Dependencies: 275
-- Data for Name: cotizaciones_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cotizaciones_detalle (cotizacion_id, producto_maestro_id, codigo_barra_id, cantidad, precio_unitario, monto_impuestos, monto_descuento, monto_total) FROM stdin;
\.


--
-- TOC entry 6234 (class 0 OID 16943)
-- Dependencies: 266
-- Data for Name: cuentas_por_cobrar; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.cuentas_por_cobrar (id, cliente_id, factura_id, monto_original, saldo_pendiente, estado, fecha_emision, fecha_vencimiento, nota_debito_id, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6257 (class 0 OID 17291)
-- Dependencies: 289
-- Data for Name: devoluciones; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.devoluciones (id, factura_id, secuencia_dgii_id, ncf_nota_credito, ncf_factura_modificada, monto_total, metodo_reembolso, justificacion_motivo, supervisor_id, fecha_emision, sesion_caja_id, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6258 (class 0 OID 17317)
-- Dependencies: 290
-- Data for Name: devoluciones_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.devoluciones_detalles (nota_credito_id, producto_maestro_id, lote_id, cantidad, monto_reembolsado, reingresado_a_inventario) FROM stdin;
\.


--
-- TOC entry 6231 (class 0 OID 16905)
-- Dependencies: 263
-- Data for Name: factura_detalle; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.factura_detalle (factura_id, producto_maestro_id, cantidad_unidades, precio_unitario_his, codigo_barra_id, factor_conversion, cantidad_presentacion, costo_unitario_hist, promocion_aplicada, monto_descuento, monto_impuestos, monto_subtotal) FROM stdin;
\.


--
-- TOC entry 6293 (class 0 OID 26636)
-- Dependencies: 325
-- Data for Name: factura_detalle_impuestos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.factura_detalle_impuestos (factura_id, producto_maestro_id, impuesto_id, base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto) FROM stdin;
\.


--
-- TOC entry 6232 (class 0 OID 16927)
-- Dependencies: 264
-- Data for Name: factura_pagos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.factura_pagos (factura_id, detalle_pago_id) FROM stdin;
\.


--
-- TOC entry 6230 (class 0 OID 16871)
-- Dependencies: 262
-- Data for Name: facturas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.facturas (id, numero_factura, sesion_caja_id, cliente_id, vendedor_id, secuencia_dgii_id, tipo_ncf, ncf, condicion_pago, monto_subtotal, monto_descuento, monto_impuestos, monto_total, estado, fecha_emision, cotizacion_origen, rnc_cedula_cliente, razon_social_cliente, origen_venta, cupon_aplicado_id, supervisor_anulacion_id, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6237 (class 0 OID 16983)
-- Dependencies: 269
-- Data for Name: metodos_pago; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.metodos_pago (id, codigo, descripcion, requiere_referencia, activo) FROM stdin;
1	EFECTIVO	Efectivo	f	t
2	TARJETA	Tarjeta de Crédito/Débito	t	t
3	TRANSFERENCIA	Transferencia Bancaria	t	t
4	CHEQUE	Cheque	t	t
5	SALDO_FAVOR	Saldo a Favor / Fianza	f	t
\.


--
-- TOC entry 6239 (class 0 OID 16999)
-- Dependencies: 271
-- Data for Name: movimiento_caja; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.movimiento_caja (id, sesion_caja_id, tipo_movimiento, monto, concepto, supervisor_id, fecha_hora) FROM stdin;
\.


--
-- TOC entry 6295 (class 0 OID 26688)
-- Dependencies: 327
-- Data for Name: nota_debito_detalle_impuestos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.nota_debito_detalle_impuestos (nota_debito_id, descripcion_cargo, impuesto_id, base_imponible, tipo_calculo_aplicado, tasa_aplicada, monto_impuesto) FROM stdin;
\.


--
-- TOC entry 6261 (class 0 OID 17369)
-- Dependencies: 293
-- Data for Name: nota_debito_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.nota_debito_detalles (nota_debito_id, producto_maestro_id, descripcion_cargo, cantidad, precio_unitario, monto_impuestos, monto_total) FROM stdin;
\.


--
-- TOC entry 6260 (class 0 OID 17340)
-- Dependencies: 292
-- Data for Name: notas_debito; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.notas_debito (id, factura_id, cliente_id, secuencia_dgii_id, ncf_nota_debito, ncf_factura_modificada, concepto_cargo, monto_subtotal, monto_impuestos, monto_total, usuario_emisor_id, fecha_emision, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6251 (class 0 OID 17200)
-- Dependencies: 283
-- Data for Name: pedidos_delivery; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.pedidos_delivery (id, factura_id, repartidor_id, direccion_destino, zona_id, estado_delivery, hora_despacho, hora_entrega) FROM stdin;
\.


--
-- TOC entry 6306 (class 0 OID 26914)
-- Dependencies: 338
-- Data for Name: recibo_cxc_pagos; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.recibo_cxc_pagos (recibo_ingreso_id, detalle_pago_id) FROM stdin;
\.


--
-- TOC entry 6235 (class 0 OID 16959)
-- Dependencies: 267
-- Data for Name: recibo_ingreso_detalles; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.recibo_ingreso_detalles (recibo_ingreso_id, cuenta_por_cobrar_id, monto_aplicado, saldo_anterior, saldo_restante) FROM stdin;
\.


--
-- TOC entry 6245 (class 0 OID 17113)
-- Dependencies: 277
-- Data for Name: recibos_ingreso_cxc; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.recibos_ingreso_cxc (id, numero_recibo, cliente_id, sesion_caja_id, cajero_id, monto_total_recibido, fecha_recibo, saldo_a_favor_generado, moneda_id, tasa_cambio) FROM stdin;
\.


--
-- TOC entry 6249 (class 0 OID 17184)
-- Dependencies: 281
-- Data for Name: repartidor_horarios; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.repartidor_horarios (repartidor_id, dia_semana, hora_inicio, hora_fin) FROM stdin;
63d80fce-0766-42c8-9f4c-0ebf218cd35b	1	16:00:00	23:00:00
63d80fce-0766-42c8-9f4c-0ebf218cd35b	2	16:00:00	23:00:00
63d80fce-0766-42c8-9f4c-0ebf218cd35b	3	16:00:00	23:00:00
63d80fce-0766-42c8-9f4c-0ebf218cd35b	4	16:00:00	23:00:00
63d80fce-0766-42c8-9f4c-0ebf218cd35b	5	16:00:00	23:00:00
\.


--
-- TOC entry 6248 (class 0 OID 17164)
-- Dependencies: 280
-- Data for Name: repartidor_zonas; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.repartidor_zonas (repartidor_id, zona_id, es_ruta_principal) FROM stdin;
\.


--
-- TOC entry 6224 (class 0 OID 16820)
-- Dependencies: 256
-- Data for Name: secuencias_fiscales_dgii; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.secuencias_fiscales_dgii (id, tipo_comprobante, secuencia_desde, secuencia_hasta, secuencia_actual, fecha_vencimiento, estado) FROM stdin;
1	B02	1	5000	1	2027-12-31	ACTIVO
2	B01	1	1000	1	2027-12-31	ACTIVO
3	B04	1	500	1	2027-12-31	ACTIVO
\.


--
-- TOC entry 6228 (class 0 OID 16848)
-- Dependencies: 260
-- Data for Name: sesiones_caja; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.sesiones_caja (id, caja_id, cajero_id, monto_inicial_efectivo, estado, fecha_apertura, fecha_cierre, monto_efectivo_esperado, monto_efectivo_declarado, diferencia_descuadre) FROM stdin;
\.


--
-- TOC entry 6247 (class 0 OID 17148)
-- Dependencies: 279
-- Data for Name: zonas_delivery; Type: TABLE DATA; Schema: ventas; Owner: postgres
--

COPY ventas.zonas_delivery (id, nombre, tarifa_envio, tiempo_promedio_min, activa) FROM stdin;
\.


--
-- TOC entry 6358 (class 0 OID 0)
-- Dependencies: 343
-- Name: ajustes_cxp_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.ajustes_cxp_id_seq', 1, false);


--
-- TOC entry 6359 (class 0 OID 0)
-- Dependencies: 302
-- Name: comprobantes_egreso_cxp_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.comprobantes_egreso_cxp_id_seq', 1, false);


--
-- TOC entry 6360 (class 0 OID 0)
-- Dependencies: 300
-- Name: cuentas_por_pagar_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.cuentas_por_pagar_id_seq', 1, false);


--
-- TOC entry 6361 (class 0 OID 0)
-- Dependencies: 294
-- Name: ordenes_compra_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.ordenes_compra_id_seq', 1, false);


--
-- TOC entry 6362 (class 0 OID 0)
-- Dependencies: 297
-- Name: recepciones_compra_id_seq; Type: SEQUENCE SET; Schema: compras; Owner: postgres
--

SELECT pg_catalog.setval('compras.recepciones_compra_id_seq', 1, false);


--
-- TOC entry 6363 (class 0 OID 0)
-- Dependencies: 246
-- Name: almacenes_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.almacenes_id_seq', 4, true);


--
-- TOC entry 6364 (class 0 OID 0)
-- Dependencies: 238
-- Name: categoria_producto_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.categoria_producto_id_seq', 1, false);


--
-- TOC entry 6365 (class 0 OID 0)
-- Dependencies: 250
-- Name: inventario_stock_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.inventario_stock_id_seq', 1, false);


--
-- TOC entry 6366 (class 0 OID 0)
-- Dependencies: 248
-- Name: lotes_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.lotes_id_seq', 1, false);


--
-- TOC entry 6367 (class 0 OID 0)
-- Dependencies: 240
-- Name: marca_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.marca_id_seq', 1, false);


--
-- TOC entry 6368 (class 0 OID 0)
-- Dependencies: 252
-- Name: movimientos_inventario_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.movimientos_inventario_id_seq', 1, false);


--
-- TOC entry 6369 (class 0 OID 0)
-- Dependencies: 244
-- Name: producto_codigos_barra_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.producto_codigos_barra_id_seq', 1, false);


--
-- TOC entry 6370 (class 0 OID 0)
-- Dependencies: 242
-- Name: productos_maestro_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.productos_maestro_id_seq', 1, false);


--
-- TOC entry 6371 (class 0 OID 0)
-- Dependencies: 315
-- Name: traslados_internos_id_seq; Type: SEQUENCE SET; Schema: inventario; Owner: postgres
--

SELECT pg_catalog.setval('inventario.traslados_internos_id_seq', 1, false);


--
-- TOC entry 6372 (class 0 OID 0)
-- Dependencies: 308
-- Name: cupones_descuento_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.cupones_descuento_id_seq', 1, false);


--
-- TOC entry 6373 (class 0 OID 0)
-- Dependencies: 305
-- Name: promociones_campanas_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.promociones_campanas_id_seq', 1, false);


--
-- TOC entry 6374 (class 0 OID 0)
-- Dependencies: 318
-- Name: promociones_detalles_id_seq; Type: SEQUENCE SET; Schema: promociones; Owner: postgres
--

SELECT pg_catalog.setval('promociones.promociones_detalles_id_seq', 1, false);


--
-- TOC entry 6375 (class 0 OID 0)
-- Dependencies: 236
-- Name: auditoria_auditoria_id_seq; Type: SEQUENCE SET; Schema: seguridad; Owner: postgres
--

SELECT pg_catalog.setval('seguridad.auditoria_auditoria_id_seq', 1, false);


--
-- TOC entry 6376 (class 0 OID 0)
-- Dependencies: 310
-- Name: cuentas_bancarias_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.cuentas_bancarias_id_seq', 3, true);


--
-- TOC entry 6377 (class 0 OID 0)
-- Dependencies: 332
-- Name: detalles_pago_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.detalles_pago_id_seq', 1, false);


--
-- TOC entry 6378 (class 0 OID 0)
-- Dependencies: 322
-- Name: impuestos_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.impuestos_id_seq', 4, true);


--
-- TOC entry 6379 (class 0 OID 0)
-- Dependencies: 320
-- Name: monedas_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.monedas_id_seq', 3, true);


--
-- TOC entry 6380 (class 0 OID 0)
-- Dependencies: 312
-- Name: movimientos_bancarios_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.movimientos_bancarios_id_seq', 1, false);


--
-- TOC entry 6381 (class 0 OID 0)
-- Dependencies: 328
-- Name: retenciones_id_seq; Type: SEQUENCE SET; Schema: tesoreria; Owner: postgres
--

SELECT pg_catalog.setval('tesoreria.retenciones_id_seq', 4, true);


--
-- TOC entry 6382 (class 0 OID 0)
-- Dependencies: 341
-- Name: ajustes_cxc_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.ajustes_cxc_id_seq', 1, false);


--
-- TOC entry 6383 (class 0 OID 0)
-- Dependencies: 257
-- Name: cajas_pos_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cajas_pos_id_seq', 1, true);


--
-- TOC entry 6384 (class 0 OID 0)
-- Dependencies: 284
-- Name: consignaciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.consignaciones_id_seq', 1, false);


--
-- TOC entry 6385 (class 0 OID 0)
-- Dependencies: 273
-- Name: cotizaciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cotizaciones_id_seq', 1, false);


--
-- TOC entry 6386 (class 0 OID 0)
-- Dependencies: 265
-- Name: cuentas_por_cobrar_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.cuentas_por_cobrar_id_seq', 1, false);


--
-- TOC entry 6387 (class 0 OID 0)
-- Dependencies: 288
-- Name: devoluciones_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.devoluciones_id_seq', 1, false);


--
-- TOC entry 6388 (class 0 OID 0)
-- Dependencies: 261
-- Name: facturas_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.facturas_id_seq', 1, false);


--
-- TOC entry 6389 (class 0 OID 0)
-- Dependencies: 268
-- Name: metodos_pago_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.metodos_pago_id_seq', 5, true);


--
-- TOC entry 6390 (class 0 OID 0)
-- Dependencies: 270
-- Name: movimiento_caja_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.movimiento_caja_id_seq', 1, false);


--
-- TOC entry 6391 (class 0 OID 0)
-- Dependencies: 291
-- Name: notas_debito_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.notas_debito_id_seq', 1, false);


--
-- TOC entry 6392 (class 0 OID 0)
-- Dependencies: 282
-- Name: pedidos_delivery_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.pedidos_delivery_id_seq', 1, false);


--
-- TOC entry 6393 (class 0 OID 0)
-- Dependencies: 276
-- Name: recibos_ingreso_cxc_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.recibos_ingreso_cxc_id_seq', 1, false);


--
-- TOC entry 6394 (class 0 OID 0)
-- Dependencies: 255
-- Name: secuencias_fiscales_dgii_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.secuencias_fiscales_dgii_id_seq', 3, true);


--
-- TOC entry 6395 (class 0 OID 0)
-- Dependencies: 259
-- Name: sesiones_caja_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.sesiones_caja_id_seq', 1, false);


--
-- TOC entry 6396 (class 0 OID 0)
-- Dependencies: 278
-- Name: zonas_delivery_id_seq; Type: SEQUENCE SET; Schema: ventas; Owner: postgres
--

SELECT pg_catalog.setval('ventas.zonas_delivery_id_seq', 1, false);


--
-- TOC entry 5874 (class 2606 OID 27050)
-- Name: ajustes_cxp ajustes_cxp_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ajustes_cxp
    ADD CONSTRAINT ajustes_cxp_pkey PRIMARY KEY (id);


--
-- TOC entry 5776 (class 2606 OID 17828)
-- Name: comprobante_egreso_detalles comprobante_egreso_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT comprobante_egreso_detalles_pkey PRIMARY KEY (comprobante_egreso_id, cuenta_por_pagar_id);


--
-- TOC entry 5842 (class 2606 OID 26769)
-- Name: comprobante_egreso_retenciones comprobante_egreso_retenciones_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_retenciones
    ADD CONSTRAINT comprobante_egreso_retenciones_pkey PRIMARY KEY (comprobante_egreso_id, cuenta_por_pagar_id, retencion_id);


--
-- TOC entry 5772 (class 2606 OID 17552)
-- Name: comprobantes_egreso_cxp comprobantes_egreso_cxp_numero_comprobante_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT comprobantes_egreso_cxp_numero_comprobante_key UNIQUE (numero_comprobante);


--
-- TOC entry 5774 (class 2606 OID 17550)
-- Name: comprobantes_egreso_cxp comprobantes_egreso_cxp_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT comprobantes_egreso_cxp_pkey PRIMARY KEY (id);


--
-- TOC entry 5766 (class 2606 OID 17522)
-- Name: cuentas_por_pagar cuentas_por_pagar_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_pkey PRIMARY KEY (id);


--
-- TOC entry 5768 (class 2606 OID 17524)
-- Name: cuentas_por_pagar cuentas_por_pagar_recepcion_compra_id_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_recepcion_compra_id_key UNIQUE (recepcion_compra_id);


--
-- TOC entry 5863 (class 2606 OID 26941)
-- Name: egreso_cxp_pagos egreso_cxp_pagos_detalle_pago_id_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.egreso_cxp_pagos
    ADD CONSTRAINT egreso_cxp_pagos_detalle_pago_id_key UNIQUE (detalle_pago_id);


--
-- TOC entry 5865 (class 2606 OID 26939)
-- Name: egreso_cxp_pagos egreso_cxp_pagos_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.egreso_cxp_pagos
    ADD CONSTRAINT egreso_cxp_pagos_pkey PRIMARY KEY (comprobante_egreso_id, detalle_pago_id);


--
-- TOC entry 5755 (class 2606 OID 17824)
-- Name: orden_compra_detalles orden_compra_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT orden_compra_detalles_pkey PRIMARY KEY (orden_compra_id, producto_maestro_id);


--
-- TOC entry 5751 (class 2606 OID 17418)
-- Name: ordenes_compra ordenes_compra_numero_orden_key; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT ordenes_compra_numero_orden_key UNIQUE (numero_orden);


--
-- TOC entry 5753 (class 2606 OID 17416)
-- Name: ordenes_compra ordenes_compra_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT ordenes_compra_pkey PRIMARY KEY (id);


--
-- TOC entry 5764 (class 2606 OID 17826)
-- Name: recepcion_compra_detalles recepcion_compra_detalles_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT recepcion_compra_detalles_pkey PRIMARY KEY (recepcion_id, producto_maestro_id, numero_lote);


--
-- TOC entry 5758 (class 2606 OID 17464)
-- Name: recepciones_compra recepciones_compra_pkey; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT recepciones_compra_pkey PRIMARY KEY (id);


--
-- TOC entry 5760 (class 2606 OID 17468)
-- Name: recepciones_compra uk_recepcion_factura; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT uk_recepcion_factura UNIQUE (proveedor_id, numero_factura_proveedor);


--
-- TOC entry 5762 (class 2606 OID 17466)
-- Name: recepciones_compra uk_recepcion_ncf; Type: CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT uk_recepcion_ncf UNIQUE (proveedor_id, ncf_proveedor);


--
-- TOC entry 5576 (class 2606 OID 18122)
-- Name: clientes clientes_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT clientes_pkey PRIMARY KEY (persona_id);


--
-- TOC entry 5582 (class 2606 OID 18324)
-- Name: empleados empleados_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT empleados_pkey PRIMARY KEY (empleado_id);


--
-- TOC entry 5572 (class 2606 OID 16420)
-- Name: personas personas_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.personas
    ADD CONSTRAINT personas_pkey PRIMARY KEY (persona_id);


--
-- TOC entry 5840 (class 2606 OID 26738)
-- Name: proveedor_retenciones proveedor_retenciones_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedor_retenciones
    ADD CONSTRAINT proveedor_retenciones_pkey PRIMARY KEY (proveedor_id, retencion_id);


--
-- TOC entry 5588 (class 2606 OID 18126)
-- Name: proveedores proveedores_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT proveedores_pkey PRIMARY KEY (persona_id);


--
-- TOC entry 5807 (class 2606 OID 17748)
-- Name: repartidores repartidores_pkey; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.repartidores
    ADD CONSTRAINT repartidores_pkey PRIMARY KEY (empleado_id);


--
-- TOC entry 5578 (class 2606 OID 16445)
-- Name: clientes uk_clientes_codigo; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT uk_clientes_codigo UNIQUE (codigo_cliente);


--
-- TOC entry 5580 (class 2606 OID 16443)
-- Name: clientes uk_clientes_persona; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT uk_clientes_persona UNIQUE (persona_id);


--
-- TOC entry 5584 (class 2606 OID 16472)
-- Name: empleados uk_empleados_codigo; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT uk_empleados_codigo UNIQUE (codigo_empleado);


--
-- TOC entry 5586 (class 2606 OID 16470)
-- Name: empleados uk_empleados_persona; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT uk_empleados_persona UNIQUE (persona_id);


--
-- TOC entry 5574 (class 2606 OID 18848)
-- Name: personas uk_personas_documento_identidad; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.personas
    ADD CONSTRAINT uk_personas_documento_identidad UNIQUE (documento_identidad);


--
-- TOC entry 5590 (class 2606 OID 18866)
-- Name: proveedores uk_proveedores_codigo; Type: CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT uk_proveedores_codigo UNIQUE (codigo_proveedor);


--
-- TOC entry 5634 (class 2606 OID 16722)
-- Name: almacenes almacenes_nombre_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes
    ADD CONSTRAINT almacenes_nombre_key UNIQUE (nombre);


--
-- TOC entry 5636 (class 2606 OID 16720)
-- Name: almacenes almacenes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.almacenes
    ADD CONSTRAINT almacenes_pkey PRIMARY KEY (id);


--
-- TOC entry 5616 (class 2606 OID 16640)
-- Name: categoria_producto categoria_producto_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.categoria_producto
    ADD CONSTRAINT categoria_producto_pkey PRIMARY KEY (id);


--
-- TOC entry 5645 (class 2606 OID 16758)
-- Name: inventario_stock inventario_stock_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT inventario_stock_pkey PRIMARY KEY (id);


--
-- TOC entry 5640 (class 2606 OID 16735)
-- Name: lotes lotes_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT lotes_pkey PRIMARY KEY (id);


--
-- TOC entry 5618 (class 2606 OID 16651)
-- Name: marca marca_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.marca
    ADD CONSTRAINT marca_pkey PRIMARY KEY (id);


--
-- TOC entry 5653 (class 2606 OID 17830)
-- Name: movimiento_inventario_detalles movimiento_inventario_detalles_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT movimiento_inventario_detalles_pkey PRIMARY KEY (movimiento_id, producto_maestro_id, lote_id);


--
-- TOC entry 5650 (class 2606 OID 16789)
-- Name: movimientos_inventario movimientos_inventario_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT movimientos_inventario_pkey PRIMARY KEY (id);


--
-- TOC entry 5628 (class 2606 OID 16702)
-- Name: producto_codigos_barra producto_codigos_barra_codigo_barra_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT producto_codigos_barra_codigo_barra_key UNIQUE (codigo_barra);


--
-- TOC entry 5630 (class 2606 OID 16700)
-- Name: producto_codigos_barra producto_codigos_barra_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT producto_codigos_barra_pkey PRIMARY KEY (id);


--
-- TOC entry 5827 (class 2606 OID 26625)
-- Name: producto_impuestos producto_impuestos_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_impuestos
    ADD CONSTRAINT producto_impuestos_pkey PRIMARY KEY (producto_maestro_id, impuesto_id);


--
-- TOC entry 5623 (class 2606 OID 16674)
-- Name: productos_maestro productos_maestro_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT productos_maestro_pkey PRIMARY KEY (id);


--
-- TOC entry 5625 (class 2606 OID 16676)
-- Name: productos_maestro productos_maestro_sku_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT productos_maestro_sku_key UNIQUE (sku);


--
-- TOC entry 5813 (class 2606 OID 17832)
-- Name: traslado_detalles traslado_detalles_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT traslado_detalles_pkey PRIMARY KEY (traslado_id, producto_maestro_id, lote_id);


--
-- TOC entry 5809 (class 2606 OID 17773)
-- Name: traslados_internos traslados_internos_numero_traslado_key; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT traslados_internos_numero_traslado_key UNIQUE (numero_traslado);


--
-- TOC entry 5811 (class 2606 OID 17771)
-- Name: traslados_internos traslados_internos_pkey; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT traslados_internos_pkey PRIMARY KEY (id);


--
-- TOC entry 5642 (class 2606 OID 16737)
-- Name: lotes uk_lote_producto; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT uk_lote_producto UNIQUE (producto_maestro_id, numero_lote);


--
-- TOC entry 5632 (class 2606 OID 16704)
-- Name: producto_codigos_barra uk_prod_factor; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT uk_prod_factor UNIQUE (producto_maestro_id, factor_multiplicador);


--
-- TOC entry 5647 (class 2606 OID 18966)
-- Name: inventario_stock uk_stock_alm_prod_lote; Type: CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT uk_stock_alm_prod_lote UNIQUE NULLS NOT DISTINCT (almacen_id, producto_maestro_id, lote_id);


--
-- TOC entry 5790 (class 2606 OID 17660)
-- Name: cupones_descuento cupones_descuento_codigo_cupon_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_codigo_cupon_key UNIQUE (codigo_cupon);


--
-- TOC entry 5792 (class 2606 OID 17662)
-- Name: cupones_descuento cupones_descuento_factura_canje_id_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_factura_canje_id_key UNIQUE (factura_canje_id);


--
-- TOC entry 5794 (class 2606 OID 17658)
-- Name: cupones_descuento cupones_descuento_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT cupones_descuento_pkey PRIMARY KEY (id);


--
-- TOC entry 5780 (class 2606 OID 17615)
-- Name: promociones_campanas promociones_campanas_nombre_promocion_key; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas
    ADD CONSTRAINT promociones_campanas_nombre_promocion_key UNIQUE (nombre_promocion);


--
-- TOC entry 5782 (class 2606 OID 17613)
-- Name: promociones_campanas promociones_campanas_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_campanas
    ADD CONSTRAINT promociones_campanas_pkey PRIMARY KEY (id);


--
-- TOC entry 5788 (class 2606 OID 26404)
-- Name: promociones_detalles promociones_detalles_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT promociones_detalles_pkey PRIMARY KEY (id);


--
-- TOC entry 5815 (class 2606 OID 26434)
-- Name: promociones_dias promociones_dias_pkey; Type: CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_dias
    ADD CONSTRAINT promociones_dias_pkey PRIMARY KEY (promocion_id, dia_semana);


--
-- TOC entry 5612 (class 2606 OID 16618)
-- Name: auditoria auditoria_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria
    ADD CONSTRAINT auditoria_pkey PRIMARY KEY (auditoria_id);


--
-- TOC entry 5604 (class 2606 OID 16562)
-- Name: permisos permisos_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.permisos
    ADD CONSTRAINT permisos_pkey PRIMARY KEY (permiso_id);


--
-- TOC entry 5610 (class 2606 OID 16592)
-- Name: roles_permisos roles_permisos_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT roles_permisos_pkey PRIMARY KEY (rol_id, permiso_id);


--
-- TOC entry 5599 (class 2606 OID 16547)
-- Name: roles roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT roles_pkey PRIMARY KEY (rol_id);


--
-- TOC entry 5606 (class 2606 OID 16564)
-- Name: permisos uk_permisos_clave_sistema; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.permisos
    ADD CONSTRAINT uk_permisos_clave_sistema UNIQUE (clave_sistema);


--
-- TOC entry 5601 (class 2606 OID 16549)
-- Name: roles uk_roles_nombre; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles
    ADD CONSTRAINT uk_roles_nombre UNIQUE (nombre);


--
-- TOC entry 5593 (class 2606 OID 16526)
-- Name: usuarios uk_usuarios_persona; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT uk_usuarios_persona UNIQUE (persona_id);


--
-- TOC entry 5595 (class 2606 OID 16528)
-- Name: usuarios uk_usuarios_username; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT uk_usuarios_username UNIQUE (username);


--
-- TOC entry 5597 (class 2606 OID 16524)
-- Name: usuarios usuarios_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT usuarios_pkey PRIMARY KEY (usuario_id);


--
-- TOC entry 5608 (class 2606 OID 16573)
-- Name: usuarios_roles usuarios_roles_pkey; Type: CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT usuarios_roles_pkey PRIMARY KEY (usuario_id, rol_id);


--
-- TOC entry 5796 (class 2606 OID 17699)
-- Name: cuentas_bancarias cuentas_bancarias_numero_cuenta_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias
    ADD CONSTRAINT cuentas_bancarias_numero_cuenta_key UNIQUE (numero_cuenta);


--
-- TOC entry 5798 (class 2606 OID 17697)
-- Name: cuentas_bancarias cuentas_bancarias_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias
    ADD CONSTRAINT cuentas_bancarias_pkey PRIMARY KEY (id);


--
-- TOC entry 5845 (class 2606 OID 26800)
-- Name: detalles_pago detalles_pago_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.detalles_pago
    ADD CONSTRAINT detalles_pago_pkey PRIMARY KEY (id);


--
-- TOC entry 5822 (class 2606 OID 26616)
-- Name: impuestos impuestos_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.impuestos
    ADD CONSTRAINT impuestos_pkey PRIMARY KEY (id);


--
-- TOC entry 5824 (class 2606 OID 26618)
-- Name: impuestos impuestos_siglas_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.impuestos
    ADD CONSTRAINT impuestos_siglas_key UNIQUE (siglas);


--
-- TOC entry 5817 (class 2606 OID 26477)
-- Name: monedas monedas_codigo_iso_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.monedas
    ADD CONSTRAINT monedas_codigo_iso_key UNIQUE (codigo_iso);


--
-- TOC entry 5819 (class 2606 OID 26475)
-- Name: monedas monedas_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.monedas
    ADD CONSTRAINT monedas_pkey PRIMARY KEY (id);


--
-- TOC entry 5801 (class 2606 OID 26897)
-- Name: movimientos_bancarios movimientos_bancarios_detalle_pago_id_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT movimientos_bancarios_detalle_pago_id_key UNIQUE (detalle_pago_id);


--
-- TOC entry 5803 (class 2606 OID 17716)
-- Name: movimientos_bancarios movimientos_bancarios_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT movimientos_bancarios_pkey PRIMARY KEY (id);


--
-- TOC entry 5854 (class 2606 OID 26865)
-- Name: pago_cheque pago_cheque_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_cheque
    ADD CONSTRAINT pago_cheque_pkey PRIMARY KEY (detalle_pago_id);


--
-- TOC entry 5857 (class 2606 OID 26885)
-- Name: pago_saldo_favor pago_saldo_favor_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_saldo_favor
    ADD CONSTRAINT pago_saldo_favor_pkey PRIMARY KEY (detalle_pago_id);


--
-- TOC entry 5848 (class 2606 OID 26827)
-- Name: pago_tarjeta pago_tarjeta_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_tarjeta
    ADD CONSTRAINT pago_tarjeta_pkey PRIMARY KEY (detalle_pago_id);


--
-- TOC entry 5851 (class 2606 OID 26842)
-- Name: pago_transferencia pago_transferencia_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_transferencia
    ADD CONSTRAINT pago_transferencia_pkey PRIMARY KEY (detalle_pago_id);


--
-- TOC entry 5835 (class 2606 OID 26729)
-- Name: retenciones retenciones_pkey; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.retenciones
    ADD CONSTRAINT retenciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5837 (class 2606 OID 26731)
-- Name: retenciones retenciones_siglas_key; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.retenciones
    ADD CONSTRAINT retenciones_siglas_key UNIQUE (siglas);


--
-- TOC entry 5805 (class 2606 OID 17720)
-- Name: movimientos_bancarios uk_movbanco_ref; Type: CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT uk_movbanco_ref UNIQUE (cuenta_bancaria_id, referencia);


--
-- TOC entry 5871 (class 2606 OID 27000)
-- Name: ajustes_cxc ajustes_cxc_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc
    ADD CONSTRAINT ajustes_cxc_pkey PRIMARY KEY (id);


--
-- TOC entry 5695 (class 2606 OID 17836)
-- Name: arqueo_detalles arqueo_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT arqueo_detalles_pkey PRIMARY KEY (sesion_caja_id, metodo_pago_id);


--
-- TOC entry 5658 (class 2606 OID 16846)
-- Name: cajas_pos cajas_pos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cajas_pos
    ADD CONSTRAINT cajas_pos_pkey PRIMARY KEY (id);


--
-- TOC entry 5732 (class 2606 OID 17254)
-- Name: consignacion_facturas consignacion_facturas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT consignacion_facturas_pkey PRIMARY KEY (consignacion_id, factura_id);


--
-- TOC entry 5867 (class 2606 OID 26960)
-- Name: consignacion_garantia_pagos consignacion_garantia_pagos_detalle_pago_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_garantia_pagos
    ADD CONSTRAINT consignacion_garantia_pagos_detalle_pago_id_key UNIQUE (detalle_pago_id);


--
-- TOC entry 5869 (class 2606 OID 26958)
-- Name: consignacion_garantia_pagos consignacion_garantia_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_garantia_pagos
    ADD CONSTRAINT consignacion_garantia_pagos_pkey PRIMARY KEY (consignacion_id, detalle_pago_id);


--
-- TOC entry 5735 (class 2606 OID 17842)
-- Name: consignaciones_detalle consignaciones_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT consignaciones_detalle_pkey PRIMARY KEY (consignacion_id, producto_maestro_id, lote_id);


--
-- TOC entry 5727 (class 2606 OID 17247)
-- Name: consignaciones consignaciones_numero_consignacion_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT consignaciones_numero_consignacion_key UNIQUE (numero_consignacion);


--
-- TOC entry 5729 (class 2606 OID 17245)
-- Name: consignaciones consignaciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT consignaciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5831 (class 2606 OID 26677)
-- Name: cotizacion_detalle_impuestos cotizacion_detalle_impuestos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizacion_detalle_impuestos
    ADD CONSTRAINT cotizacion_detalle_impuestos_pkey PRIMARY KEY (cotizacion_id, producto_maestro_id, impuesto_id);


--
-- TOC entry 5706 (class 2606 OID 17840)
-- Name: cotizaciones_detalle cotizaciones_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT cotizaciones_detalle_pkey PRIMARY KEY (cotizacion_id, producto_maestro_id);


--
-- TOC entry 5698 (class 2606 OID 17078)
-- Name: cotizaciones cotizaciones_factura_generada_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_factura_generada_id_key UNIQUE (factura_generada_id);


--
-- TOC entry 5700 (class 2606 OID 17076)
-- Name: cotizaciones cotizaciones_numero_cotizacion_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_numero_cotizacion_key UNIQUE (numero_cotizacion);


--
-- TOC entry 5702 (class 2606 OID 17074)
-- Name: cotizaciones cotizaciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5679 (class 2606 OID 16957)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_factura_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_factura_id_key UNIQUE (factura_id);


--
-- TOC entry 5681 (class 2606 OID 18345)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_nota_debito_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_nota_debito_id_key UNIQUE (nota_debito_id);


--
-- TOC entry 5683 (class 2606 OID 16955)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_pkey PRIMARY KEY (id);


--
-- TOC entry 5742 (class 2606 OID 17844)
-- Name: devoluciones_detalles devoluciones_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT devoluciones_detalles_pkey PRIMARY KEY (nota_credito_id, producto_maestro_id, lote_id);


--
-- TOC entry 5737 (class 2606 OID 17310)
-- Name: devoluciones devoluciones_ncf_nota_credito_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_ncf_nota_credito_key UNIQUE (ncf_nota_credito);


--
-- TOC entry 5739 (class 2606 OID 17308)
-- Name: devoluciones devoluciones_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_pkey PRIMARY KEY (id);


--
-- TOC entry 5829 (class 2606 OID 26651)
-- Name: factura_detalle_impuestos factura_detalle_impuestos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle_impuestos
    ADD CONSTRAINT factura_detalle_impuestos_pkey PRIMARY KEY (factura_id, producto_maestro_id, impuesto_id);


--
-- TOC entry 5672 (class 2606 OID 17838)
-- Name: factura_detalle factura_detalle_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT factura_detalle_pkey PRIMARY KEY (factura_id, producto_maestro_id);


--
-- TOC entry 5675 (class 2606 OID 26906)
-- Name: factura_pagos factura_pagos_detalle_pago_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT factura_pagos_detalle_pago_id_key UNIQUE (detalle_pago_id);


--
-- TOC entry 5677 (class 2606 OID 26908)
-- Name: factura_pagos factura_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT factura_pagos_pkey PRIMARY KEY (factura_id, detalle_pago_id);


--
-- TOC entry 5664 (class 2606 OID 16897)
-- Name: facturas facturas_numero_factura_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT facturas_numero_factura_key UNIQUE (numero_factura);


--
-- TOC entry 5666 (class 2606 OID 16895)
-- Name: facturas facturas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT facturas_pkey PRIMARY KEY (id);


--
-- TOC entry 5689 (class 2606 OID 16997)
-- Name: metodos_pago metodos_pago_codigo_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago
    ADD CONSTRAINT metodos_pago_codigo_key UNIQUE (codigo);


--
-- TOC entry 5691 (class 2606 OID 16995)
-- Name: metodos_pago metodos_pago_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.metodos_pago
    ADD CONSTRAINT metodos_pago_pkey PRIMARY KEY (id);


--
-- TOC entry 5693 (class 2606 OID 17013)
-- Name: movimiento_caja movimiento_caja_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja
    ADD CONSTRAINT movimiento_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5833 (class 2606 OID 26703)
-- Name: nota_debito_detalle_impuestos nota_debito_detalle_impuestos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalle_impuestos
    ADD CONSTRAINT nota_debito_detalle_impuestos_pkey PRIMARY KEY (nota_debito_id, descripcion_cargo, impuesto_id);


--
-- TOC entry 5748 (class 2606 OID 17850)
-- Name: nota_debito_detalles nota_debito_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles
    ADD CONSTRAINT nota_debito_detalles_pkey PRIMARY KEY (nota_debito_id, descripcion_cargo);


--
-- TOC entry 5744 (class 2606 OID 17362)
-- Name: notas_debito notas_debito_ncf_nota_debito_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT notas_debito_ncf_nota_debito_key UNIQUE (ncf_nota_debito);


--
-- TOC entry 5746 (class 2606 OID 17360)
-- Name: notas_debito notas_debito_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT notas_debito_pkey PRIMARY KEY (id);


--
-- TOC entry 5723 (class 2606 OID 17218)
-- Name: pedidos_delivery pedidos_delivery_factura_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT pedidos_delivery_factura_id_key UNIQUE (factura_id);


--
-- TOC entry 5725 (class 2606 OID 17216)
-- Name: pedidos_delivery pedidos_delivery_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT pedidos_delivery_pkey PRIMARY KEY (id);


--
-- TOC entry 5859 (class 2606 OID 26922)
-- Name: recibo_cxc_pagos recibo_cxc_pagos_detalle_pago_id_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_cxc_pagos
    ADD CONSTRAINT recibo_cxc_pagos_detalle_pago_id_key UNIQUE (detalle_pago_id);


--
-- TOC entry 5861 (class 2606 OID 26920)
-- Name: recibo_cxc_pagos recibo_cxc_pagos_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_cxc_pagos
    ADD CONSTRAINT recibo_cxc_pagos_pkey PRIMARY KEY (recibo_ingreso_id, detalle_pago_id);


--
-- TOC entry 5687 (class 2606 OID 17846)
-- Name: recibo_ingreso_detalles recibo_ingreso_detalles_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT recibo_ingreso_detalles_pkey PRIMARY KEY (recibo_ingreso_id, cuenta_por_cobrar_id);


--
-- TOC entry 5709 (class 2606 OID 17136)
-- Name: recibos_ingreso_cxc recibos_ingreso_cxc_numero_recibo_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT recibos_ingreso_cxc_numero_recibo_key UNIQUE (numero_recibo);


--
-- TOC entry 5711 (class 2606 OID 17134)
-- Name: recibos_ingreso_cxc recibos_ingreso_cxc_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT recibos_ingreso_cxc_pkey PRIMARY KEY (id);


--
-- TOC entry 5719 (class 2606 OID 17848)
-- Name: repartidor_horarios repartidor_horarios_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_horarios
    ADD CONSTRAINT repartidor_horarios_pkey PRIMARY KEY (repartidor_id, dia_semana, hora_inicio);


--
-- TOC entry 5717 (class 2606 OID 17172)
-- Name: repartidor_zonas repartidor_zonas_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT repartidor_zonas_pkey PRIMARY KEY (repartidor_id, zona_id);


--
-- TOC entry 5655 (class 2606 OID 16833)
-- Name: secuencias_fiscales_dgii secuencias_fiscales_dgii_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.secuencias_fiscales_dgii
    ADD CONSTRAINT secuencias_fiscales_dgii_pkey PRIMARY KEY (id);


--
-- TOC entry 5660 (class 2606 OID 16862)
-- Name: sesiones_caja sesiones_caja_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja
    ADD CONSTRAINT sesiones_caja_pkey PRIMARY KEY (id);


--
-- TOC entry 5713 (class 2606 OID 17163)
-- Name: zonas_delivery zonas_delivery_nombre_key; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery
    ADD CONSTRAINT zonas_delivery_nombre_key UNIQUE (nombre);


--
-- TOC entry 5715 (class 2606 OID 17161)
-- Name: zonas_delivery zonas_delivery_pkey; Type: CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.zonas_delivery
    ADD CONSTRAINT zonas_delivery_pkey PRIMARY KEY (id);


--
-- TOC entry 5875 (class 1259 OID 27085)
-- Name: idx_ajustecxp_cxp; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_ajustecxp_cxp ON compras.ajustes_cxp USING btree (cuenta_por_pagar_id);


--
-- TOC entry 5769 (class 1259 OID 27022)
-- Name: idx_cxp_pendientes; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_cxp_pendientes ON compras.cuentas_por_pagar USING btree (proveedor_id) WHERE ((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying])::text[]));


--
-- TOC entry 5770 (class 1259 OID 17592)
-- Name: idx_cxp_vencimiento; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_cxp_vencimiento ON compras.cuentas_por_pagar USING btree (fecha_vencimiento) WHERE ((estado)::text = 'PENDIENTE'::text);


--
-- TOC entry 5777 (class 1259 OID 26415)
-- Name: idx_egresodet_cxp; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_egresodet_cxp ON compras.comprobante_egreso_detalles USING btree (cuenta_por_pagar_id);


--
-- TOC entry 5843 (class 1259 OID 27079)
-- Name: idx_egresoret_retencion; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_egresoret_retencion ON compras.comprobante_egreso_retenciones USING btree (retencion_id);


--
-- TOC entry 5749 (class 1259 OID 17594)
-- Name: idx_ordenes_estado; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_ordenes_estado ON compras.ordenes_compra USING btree (estado) WHERE ((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'RECIBIDA_PARCIAL'::character varying])::text[]));


--
-- TOC entry 5756 (class 1259 OID 17593)
-- Name: idx_recepciones_fecha; Type: INDEX; Schema: compras; Owner: postgres
--

CREATE INDEX idx_recepciones_fecha ON compras.recepciones_compra USING btree (fecha_recepcion);


--
-- TOC entry 5569 (class 1259 OID 26421)
-- Name: idx_personas_email_lower; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_personas_email_lower ON identidad.personas USING btree (lower((email)::text)) WHERE (email IS NOT NULL);


--
-- TOC entry 5570 (class 1259 OID 26420)
-- Name: idx_personas_nombre; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_personas_nombre ON identidad.personas USING btree (nombre_razon_social);


--
-- TOC entry 5838 (class 1259 OID 27078)
-- Name: idx_provret_retencion; Type: INDEX; Schema: identidad; Owner: postgres
--

CREATE INDEX idx_provret_retencion ON identidad.proveedor_retenciones USING btree (retencion_id);


--
-- TOC entry 5626 (class 1259 OID 16812)
-- Name: idx_codigos_producto; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_codigos_producto ON inventario.producto_codigos_barra USING btree (producto_maestro_id);


--
-- TOC entry 5637 (class 1259 OID 26418)
-- Name: idx_lotes_alerta_vencimiento; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_lotes_alerta_vencimiento ON inventario.lotes USING btree (fecha_vencimiento) WHERE (cantidad_actual > 0);


--
-- TOC entry 5638 (class 1259 OID 26417)
-- Name: idx_lotes_fefo_vencimiento; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_lotes_fefo_vencimiento ON inventario.lotes USING btree (producto_maestro_id, fecha_vencimiento) WHERE (cantidad_actual > 0);


--
-- TOC entry 5651 (class 1259 OID 16815)
-- Name: idx_movdet_producto_lote; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_movdet_producto_lote ON inventario.movimiento_inventario_detalles USING btree (producto_maestro_id, lote_id);


--
-- TOC entry 5648 (class 1259 OID 16816)
-- Name: idx_movinv_supervisor; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_movinv_supervisor ON inventario.movimientos_inventario USING btree (supervisor_id) WHERE (supervisor_id IS NOT NULL);


--
-- TOC entry 5825 (class 1259 OID 27077)
-- Name: idx_prodimp_impuesto; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_prodimp_impuesto ON inventario.producto_impuestos USING btree (impuesto_id);


--
-- TOC entry 5619 (class 1259 OID 16813)
-- Name: idx_productos_categoria; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_productos_categoria ON inventario.productos_maestro USING btree (categoria_id);


--
-- TOC entry 5620 (class 1259 OID 16814)
-- Name: idx_productos_marca; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_productos_marca ON inventario.productos_maestro USING btree (marca_id);


--
-- TOC entry 5621 (class 1259 OID 18959)
-- Name: idx_productos_nombre; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_productos_nombre ON inventario.productos_maestro USING btree (nombre);


--
-- TOC entry 5643 (class 1259 OID 26419)
-- Name: idx_stock_producto_lote; Type: INDEX; Schema: inventario; Owner: postgres
--

CREATE INDEX idx_stock_producto_lote ON inventario.inventario_stock USING btree (producto_maestro_id, lote_id);


--
-- TOC entry 5783 (class 1259 OID 17678)
-- Name: idx_promo_producto; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promo_producto ON promociones.promociones_detalles USING btree (condicion_producto_id);


--
-- TOC entry 5778 (class 1259 OID 26423)
-- Name: idx_promocamp_activas_fechas; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promocamp_activas_fechas ON promociones.promociones_campanas USING btree (fecha_inicio, fecha_fin) WHERE ((estado)::text = 'ACTIVA'::text);


--
-- TOC entry 5784 (class 1259 OID 26411)
-- Name: idx_promodet_categoria; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promodet_categoria ON promociones.promociones_detalles USING btree (condicion_categoria_id) WHERE (condicion_categoria_id IS NOT NULL);


--
-- TOC entry 5785 (class 1259 OID 26412)
-- Name: idx_promodet_marca; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promodet_marca ON promociones.promociones_detalles USING btree (condicion_marca_id) WHERE (condicion_marca_id IS NOT NULL);


--
-- TOC entry 5786 (class 1259 OID 26410)
-- Name: idx_promodet_promocion; Type: INDEX; Schema: promociones; Owner: postgres
--

CREATE INDEX idx_promodet_promocion ON promociones.promociones_detalles USING btree (promocion_id);


--
-- TOC entry 5613 (class 1259 OID 16628)
-- Name: idx_auditoria_gin_datos_nuevos; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_auditoria_gin_datos_nuevos ON seguridad.auditoria USING gin (datos_nuevos);


--
-- TOC entry 5614 (class 1259 OID 16626)
-- Name: idx_auditoria_usuario_fecha; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_auditoria_usuario_fecha ON seguridad.auditoria USING btree (usuario_id, fecha_hora DESC);


--
-- TOC entry 5602 (class 1259 OID 16625)
-- Name: idx_permisos_modulo; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_permisos_modulo ON seguridad.permisos USING btree (modulo);


--
-- TOC entry 5591 (class 1259 OID 26422)
-- Name: idx_usuarios_username_lower; Type: INDEX; Schema: seguridad; Owner: postgres
--

CREATE INDEX idx_usuarios_username_lower ON seguridad.usuarios USING btree (lower((username)::text));


--
-- TOC entry 5799 (class 1259 OID 17731)
-- Name: idx_bancos_cuenta_fecha; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_bancos_cuenta_fecha ON tesoreria.movimientos_bancarios USING btree (cuenta_bancaria_id, fecha_movimiento);


--
-- TOC entry 5846 (class 1259 OID 27080)
-- Name: idx_detpago_sesion_metodo; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_detpago_sesion_metodo ON tesoreria.detalles_pago USING btree (sesion_caja_id, metodo_pago_id) WHERE (sesion_caja_id IS NOT NULL);


--
-- TOC entry 5852 (class 1259 OID 27082)
-- Name: idx_pagocheque_estado; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_pagocheque_estado ON tesoreria.pago_cheque USING btree (estado_cheque) WHERE ((estado_cheque)::text = ANY ((ARRAY['RECIBIDO'::character varying, 'DEPOSITADO'::character varying])::text[]));


--
-- TOC entry 5855 (class 1259 OID 27083)
-- Name: idx_pagosaldofavor_cliente; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_pagosaldofavor_cliente ON tesoreria.pago_saldo_favor USING btree (cliente_id);


--
-- TOC entry 5849 (class 1259 OID 27081)
-- Name: idx_pagotransfer_cuenta; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE INDEX idx_pagotransfer_cuenta ON tesoreria.pago_transferencia USING btree (cuenta_bancaria_id);


--
-- TOC entry 5820 (class 1259 OID 26478)
-- Name: uk_moneda_base_unica; Type: INDEX; Schema: tesoreria; Owner: postgres
--

CREATE UNIQUE INDEX uk_moneda_base_unica ON tesoreria.monedas USING btree (es_moneda_base) WHERE (es_moneda_base = true);


--
-- TOC entry 5872 (class 1259 OID 27084)
-- Name: idx_ajustecxc_cxc; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_ajustecxc_cxc ON ventas.ajustes_cxc USING btree (cuenta_por_cobrar_id);


--
-- TOC entry 5696 (class 1259 OID 26425)
-- Name: idx_arqueos_pendientes; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_arqueos_pendientes ON ventas.arqueo_detalles USING btree (sesion_caja_id) WHERE ((estado_deposito)::text = 'PENDIENTE'::text);


--
-- TOC entry 5730 (class 1259 OID 17395)
-- Name: idx_consig_estado; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_consig_estado ON ventas.consignaciones USING btree (estado) WHERE ((estado)::text = 'DESPACHADA'::text);


--
-- TOC entry 5733 (class 1259 OID 26416)
-- Name: idx_consigfac_factura; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_consigfac_factura ON ventas.consignacion_facturas USING btree (factura_id);


--
-- TOC entry 5703 (class 1259 OID 17392)
-- Name: idx_cotiz_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_cotiz_cliente ON ventas.cotizaciones USING btree (cliente_id);


--
-- TOC entry 5704 (class 1259 OID 26424)
-- Name: idx_cotizaciones_pendientes; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_cotizaciones_pendientes ON ventas.cotizaciones USING btree (fecha_vencimiento) WHERE ((estado)::text = 'PENDIENTE'::text);


--
-- TOC entry 5684 (class 1259 OID 26972)
-- Name: idx_cxc_pendientes; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_cxc_pendientes ON ventas.cuentas_por_cobrar USING btree (cliente_id) WHERE ((estado)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'PAGADA_PARCIAL'::character varying])::text[]));


--
-- TOC entry 5740 (class 1259 OID 17396)
-- Name: idx_devoluciones_fecha; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_devoluciones_fecha ON ventas.devoluciones USING btree (fecha_emision);


--
-- TOC entry 5673 (class 1259 OID 26413)
-- Name: idx_factdet_producto; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_factdet_producto ON ventas.factura_detalle USING btree (producto_maestro_id);


--
-- TOC entry 5667 (class 1259 OID 16980)
-- Name: idx_facturas_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_cliente ON ventas.facturas USING btree (cliente_id);


--
-- TOC entry 5668 (class 1259 OID 16979)
-- Name: idx_facturas_fecha; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_fecha ON ventas.facturas USING btree (fecha_emision);


--
-- TOC entry 5669 (class 1259 OID 16977)
-- Name: idx_facturas_sesion; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_facturas_sesion ON ventas.facturas USING btree (sesion_caja_id);


--
-- TOC entry 5720 (class 1259 OID 17394)
-- Name: idx_pedidos_estado; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_pedidos_estado ON ventas.pedidos_delivery USING btree (estado_delivery) WHERE ((estado_delivery)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_CAMINO'::character varying])::text[]));


--
-- TOC entry 5721 (class 1259 OID 26426)
-- Name: idx_pedidos_repartidor_activos; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_pedidos_repartidor_activos ON ventas.pedidos_delivery USING btree (repartidor_id, estado_delivery) WHERE ((estado_delivery)::text = ANY ((ARRAY['PENDIENTE'::character varying, 'EN_CAMINO'::character varying])::text[]));


--
-- TOC entry 5685 (class 1259 OID 26414)
-- Name: idx_recibodet_cxc; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_recibodet_cxc ON ventas.recibo_ingreso_detalles USING btree (cuenta_por_cobrar_id);


--
-- TOC entry 5707 (class 1259 OID 17393)
-- Name: idx_recibos_cliente; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE INDEX idx_recibos_cliente ON ventas.recibos_ingreso_cxc USING btree (cliente_id);


--
-- TOC entry 5661 (class 1259 OID 16868)
-- Name: uk_caja_abierta; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_caja_abierta ON ventas.sesiones_caja USING btree (caja_id) WHERE ((estado)::text = 'ABIERTA'::text);


--
-- TOC entry 5662 (class 1259 OID 16869)
-- Name: uk_cajero_abierto; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_cajero_abierto ON ventas.sesiones_caja USING btree (cajero_id) WHERE ((estado)::text = 'ABIERTA'::text);


--
-- TOC entry 5670 (class 1259 OID 16903)
-- Name: uk_factura_ncf; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_factura_ncf ON ventas.facturas USING btree (ncf) WHERE (ncf IS NOT NULL);


--
-- TOC entry 5656 (class 1259 OID 16834)
-- Name: uk_secuencia_activa; Type: INDEX; Schema: ventas; Owner: postgres
--

CREATE UNIQUE INDEX uk_secuencia_activa ON ventas.secuencias_fiscales_dgii USING btree (tipo_comprobante) WHERE ((estado)::text = 'ACTIVO'::text);


--
-- TOC entry 6042 (class 2620 OID 27076)
-- Name: cuentas_por_pagar trg_irreversibilidad_cxp; Type: TRIGGER; Schema: compras; Owner: postgres
--

CREATE TRIGGER trg_irreversibilidad_cxp BEFORE UPDATE ON compras.cuentas_por_pagar FOR EACH ROW EXECUTE FUNCTION compras.fn_bloquear_reapertura_cxp();


--
-- TOC entry 6043 (class 2620 OID 27072)
-- Name: detalles_pago trg_inmutabilidad_metodo_pago; Type: TRIGGER; Schema: tesoreria; Owner: postgres
--

CREATE TRIGGER trg_inmutabilidad_metodo_pago BEFORE UPDATE ON tesoreria.detalles_pago FOR EACH ROW EXECUTE FUNCTION tesoreria.fn_bloquear_cambio_metodo_pago();


--
-- TOC entry 6046 (class 2620 OID 27069)
-- Name: pago_cheque trg_validar_pago_cheque; Type: TRIGGER; Schema: tesoreria; Owner: postgres
--

CREATE TRIGGER trg_validar_pago_cheque BEFORE INSERT OR UPDATE ON tesoreria.pago_cheque FOR EACH ROW EXECUTE FUNCTION tesoreria.fn_validar_subtipo_pago();


--
-- TOC entry 6047 (class 2620 OID 27070)
-- Name: pago_saldo_favor trg_validar_pago_saldo_favor; Type: TRIGGER; Schema: tesoreria; Owner: postgres
--

CREATE TRIGGER trg_validar_pago_saldo_favor BEFORE INSERT OR UPDATE ON tesoreria.pago_saldo_favor FOR EACH ROW EXECUTE FUNCTION tesoreria.fn_validar_subtipo_pago();


--
-- TOC entry 6044 (class 2620 OID 27067)
-- Name: pago_tarjeta trg_validar_pago_tarjeta; Type: TRIGGER; Schema: tesoreria; Owner: postgres
--

CREATE TRIGGER trg_validar_pago_tarjeta BEFORE INSERT OR UPDATE ON tesoreria.pago_tarjeta FOR EACH ROW EXECUTE FUNCTION tesoreria.fn_validar_subtipo_pago();


--
-- TOC entry 6045 (class 2620 OID 27068)
-- Name: pago_transferencia trg_validar_pago_transferencia; Type: TRIGGER; Schema: tesoreria; Owner: postgres
--

CREATE TRIGGER trg_validar_pago_transferencia BEFORE INSERT OR UPDATE ON tesoreria.pago_transferencia FOR EACH ROW EXECUTE FUNCTION tesoreria.fn_validar_subtipo_pago();


--
-- TOC entry 6041 (class 2620 OID 27074)
-- Name: cuentas_por_cobrar trg_irreversibilidad_cxc; Type: TRIGGER; Schema: ventas; Owner: postgres
--

CREATE TRIGGER trg_irreversibilidad_cxc BEFORE UPDATE ON ventas.cuentas_por_cobrar FOR EACH ROW EXECUTE FUNCTION ventas.fn_bloquear_reapertura_cxc();


--
-- TOC entry 6038 (class 2606 OID 27051)
-- Name: ajustes_cxp ajustes_cxp_cuenta_por_pagar_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ajustes_cxp
    ADD CONSTRAINT ajustes_cxp_cuenta_por_pagar_id_fkey FOREIGN KEY (cuenta_por_pagar_id) REFERENCES compras.cuentas_por_pagar(id) ON DELETE RESTRICT;


--
-- TOC entry 6039 (class 2606 OID 27056)
-- Name: ajustes_cxp ajustes_cxp_moneda_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ajustes_cxp
    ADD CONSTRAINT ajustes_cxp_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6040 (class 2606 OID 27061)
-- Name: ajustes_cxp ajustes_cxp_supervisor_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ajustes_cxp
    ADD CONSTRAINT ajustes_cxp_supervisor_id_fkey FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 6016 (class 2606 OID 26770)
-- Name: comprobante_egreso_retenciones comprobante_egreso_retenciones_retencion_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_retenciones
    ADD CONSTRAINT comprobante_egreso_retenciones_retencion_id_fkey FOREIGN KEY (retencion_id) REFERENCES tesoreria.retenciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5979 (class 2606 OID 26591)
-- Name: comprobantes_egreso_cxp comprobantes_egreso_cxp_moneda_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT comprobantes_egreso_cxp_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5976 (class 2606 OID 26581)
-- Name: cuentas_por_pagar cuentas_por_pagar_moneda_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT cuentas_por_pagar_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6030 (class 2606 OID 26942)
-- Name: egreso_cxp_pagos egreso_cxp_pagos_comprobante_egreso_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.egreso_cxp_pagos
    ADD CONSTRAINT egreso_cxp_pagos_comprobante_egreso_id_fkey FOREIGN KEY (comprobante_egreso_id) REFERENCES compras.comprobantes_egreso_cxp(id) ON DELETE CASCADE;


--
-- TOC entry 6031 (class 2606 OID 26947)
-- Name: egreso_cxp_pagos egreso_cxp_pagos_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.egreso_cxp_pagos
    ADD CONSTRAINT egreso_cxp_pagos_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 5977 (class 2606 OID 18172)
-- Name: cuentas_por_pagar fk_cxp_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT fk_cxp_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5978 (class 2606 OID 17530)
-- Name: cuentas_por_pagar fk_cxp_recepcion; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.cuentas_por_pagar
    ADD CONSTRAINT fk_cxp_recepcion FOREIGN KEY (recepcion_compra_id) REFERENCES compras.recepciones_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5980 (class 2606 OID 18177)
-- Name: comprobantes_egreso_cxp fk_egreso_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT fk_egreso_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5981 (class 2606 OID 18351)
-- Name: comprobantes_egreso_cxp fk_egreso_usuario; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobantes_egreso_cxp
    ADD CONSTRAINT fk_egreso_usuario FOREIGN KEY (usuario_registro_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5982 (class 2606 OID 17586)
-- Name: comprobante_egreso_detalles fk_egresodet_cxp; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT fk_egresodet_cxp FOREIGN KEY (cuenta_por_pagar_id) REFERENCES compras.cuentas_por_pagar(id) ON DELETE RESTRICT;


--
-- TOC entry 5983 (class 2606 OID 17581)
-- Name: comprobante_egreso_detalles fk_egresodet_egreso; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_detalles
    ADD CONSTRAINT fk_egresodet_egreso FOREIGN KEY (comprobante_egreso_id) REFERENCES compras.comprobantes_egreso_cxp(id) ON DELETE RESTRICT;


--
-- TOC entry 6017 (class 2606 OID 26775)
-- Name: comprobante_egreso_retenciones fk_egresoret_detalle; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.comprobante_egreso_retenciones
    ADD CONSTRAINT fk_egresoret_detalle FOREIGN KEY (comprobante_egreso_id, cuenta_por_pagar_id) REFERENCES compras.comprobante_egreso_detalles(comprobante_egreso_id, cuenta_por_pagar_id) ON DELETE CASCADE;


--
-- TOC entry 5964 (class 2606 OID 18085)
-- Name: ordenes_compra fk_orden_comprador; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT fk_orden_comprador FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5965 (class 2606 OID 18162)
-- Name: ordenes_compra fk_orden_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT fk_orden_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5967 (class 2606 OID 17440)
-- Name: orden_compra_detalles fk_ordendet_orden; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT fk_ordendet_orden FOREIGN KEY (orden_compra_id) REFERENCES compras.ordenes_compra(id) ON DELETE CASCADE;


--
-- TOC entry 5968 (class 2606 OID 17445)
-- Name: orden_compra_detalles fk_ordendet_prod; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.orden_compra_detalles
    ADD CONSTRAINT fk_ordendet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5973 (class 2606 OID 17856)
-- Name: recepcion_compra_detalles fk_recdet_orden_linea; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT fk_recdet_orden_linea FOREIGN KEY (orden_compra_id, producto_maestro_id) REFERENCES compras.orden_compra_detalles(orden_compra_id, producto_maestro_id) ON DELETE RESTRICT;


--
-- TOC entry 5974 (class 2606 OID 17501)
-- Name: recepcion_compra_detalles fk_recdet_prod; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT fk_recdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5975 (class 2606 OID 17496)
-- Name: recepcion_compra_detalles fk_recdet_recepcion; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepcion_compra_detalles
    ADD CONSTRAINT fk_recdet_recepcion FOREIGN KEY (recepcion_id) REFERENCES compras.recepciones_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5969 (class 2606 OID 17469)
-- Name: recepciones_compra fk_recepcion_orden; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT fk_recepcion_orden FOREIGN KEY (orden_compra_id) REFERENCES compras.ordenes_compra(id) ON DELETE RESTRICT;


--
-- TOC entry 5970 (class 2606 OID 18167)
-- Name: recepciones_compra fk_recepcion_proveedor; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT fk_recepcion_proveedor FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5971 (class 2606 OID 18090)
-- Name: recepciones_compra fk_recepcion_usuario; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT fk_recepcion_usuario FOREIGN KEY (usuario_recepcion_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5966 (class 2606 OID 26561)
-- Name: ordenes_compra ordenes_compra_moneda_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.ordenes_compra
    ADD CONSTRAINT ordenes_compra_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5972 (class 2606 OID 26571)
-- Name: recepciones_compra recepciones_compra_moneda_id_fkey; Type: FK CONSTRAINT; Schema: compras; Owner: postgres
--

ALTER TABLE ONLY compras.recepciones_compra
    ADD CONSTRAINT recepciones_compra_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5876 (class 2606 OID 16446)
-- Name: clientes fk_clientes_personas; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.clientes
    ADD CONSTRAINT fk_clientes_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5877 (class 2606 OID 18330)
-- Name: empleados fk_empleados_personas; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.empleados
    ADD CONSTRAINT fk_empleados_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5878 (class 2606 OID 18116)
-- Name: proveedores fk_proveedores_personas; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedores
    ADD CONSTRAINT fk_proveedores_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5997 (class 2606 OID 18325)
-- Name: repartidores fk_repartidor_empleado; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.repartidores
    ADD CONSTRAINT fk_repartidor_empleado FOREIGN KEY (empleado_id) REFERENCES identidad.empleados(empleado_id) ON DELETE RESTRICT;


--
-- TOC entry 6014 (class 2606 OID 26739)
-- Name: proveedor_retenciones proveedor_retenciones_proveedor_id_fkey; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedor_retenciones
    ADD CONSTRAINT proveedor_retenciones_proveedor_id_fkey FOREIGN KEY (proveedor_id) REFERENCES identidad.proveedores(persona_id) ON DELETE CASCADE;


--
-- TOC entry 6015 (class 2606 OID 26744)
-- Name: proveedor_retenciones proveedor_retenciones_retencion_id_fkey; Type: FK CONSTRAINT; Schema: identidad; Owner: postgres
--

ALTER TABLE ONLY identidad.proveedor_retenciones
    ADD CONSTRAINT proveedor_retenciones_retencion_id_fkey FOREIGN KEY (retencion_id) REFERENCES tesoreria.retenciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5888 (class 2606 OID 16705)
-- Name: producto_codigos_barra fk_cod_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_codigos_barra
    ADD CONSTRAINT fk_cod_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE CASCADE;


--
-- TOC entry 5889 (class 2606 OID 16738)
-- Name: lotes fk_lote_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.lotes
    ADD CONSTRAINT fk_lote_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5894 (class 2606 OID 17995)
-- Name: movimiento_inventario_detalles fk_movdet_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT fk_movdet_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 5895 (class 2606 OID 16802)
-- Name: movimiento_inventario_detalles fk_movdet_mov; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT fk_movdet_mov FOREIGN KEY (movimiento_id) REFERENCES inventario.movimientos_inventario(id) ON DELETE RESTRICT;


--
-- TOC entry 5896 (class 2606 OID 16807)
-- Name: movimiento_inventario_detalles fk_movdet_prod; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimiento_inventario_detalles
    ADD CONSTRAINT fk_movdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5893 (class 2606 OID 18188)
-- Name: movimientos_inventario fk_movinv_supervisor; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.movimientos_inventario
    ADD CONSTRAINT fk_movinv_supervisor FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5886 (class 2606 OID 16677)
-- Name: productos_maestro fk_prod_categoria; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT fk_prod_categoria FOREIGN KEY (categoria_id) REFERENCES inventario.categoria_producto(id) ON DELETE RESTRICT;


--
-- TOC entry 5887 (class 2606 OID 16682)
-- Name: productos_maestro fk_prod_marca; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.productos_maestro
    ADD CONSTRAINT fk_prod_marca FOREIGN KEY (marca_id) REFERENCES inventario.marca(id) ON DELETE RESTRICT;


--
-- TOC entry 5890 (class 2606 OID 16761)
-- Name: inventario_stock fk_stock_almacen; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_almacen FOREIGN KEY (almacen_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 5891 (class 2606 OID 16771)
-- Name: inventario_stock fk_stock_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 5892 (class 2606 OID 16766)
-- Name: inventario_stock fk_stock_producto; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.inventario_stock
    ADD CONSTRAINT fk_stock_producto FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5998 (class 2606 OID 17779)
-- Name: traslados_internos fk_traslado_destino; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_destino FOREIGN KEY (almacen_destino_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 5999 (class 2606 OID 18193)
-- Name: traslados_internos fk_traslado_emisor; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_emisor FOREIGN KEY (usuario_emisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 6000 (class 2606 OID 17774)
-- Name: traslados_internos fk_traslado_origen; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_origen FOREIGN KEY (almacen_origen_id) REFERENCES inventario.almacenes(id) ON DELETE RESTRICT;


--
-- TOC entry 6001 (class 2606 OID 18198)
-- Name: traslados_internos fk_traslado_receptor; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslados_internos
    ADD CONSTRAINT fk_traslado_receptor FOREIGN KEY (usuario_receptor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 6002 (class 2606 OID 18000)
-- Name: traslado_detalles fk_trasladodet_lote; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT fk_trasladodet_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 6003 (class 2606 OID 17806)
-- Name: traslado_detalles fk_trasladodet_prod; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT fk_trasladodet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 6004 (class 2606 OID 17801)
-- Name: traslado_detalles fk_trasladodet_traslado; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.traslado_detalles
    ADD CONSTRAINT fk_trasladodet_traslado FOREIGN KEY (traslado_id) REFERENCES inventario.traslados_internos(id) ON DELETE CASCADE;


--
-- TOC entry 6006 (class 2606 OID 26631)
-- Name: producto_impuestos producto_impuestos_impuesto_id_fkey; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_impuestos
    ADD CONSTRAINT producto_impuestos_impuesto_id_fkey FOREIGN KEY (impuesto_id) REFERENCES tesoreria.impuestos(id) ON DELETE RESTRICT;


--
-- TOC entry 6007 (class 2606 OID 26626)
-- Name: producto_impuestos producto_impuestos_producto_maestro_id_fkey; Type: FK CONSTRAINT; Schema: inventario; Owner: postgres
--

ALTER TABLE ONLY inventario.producto_impuestos
    ADD CONSTRAINT producto_impuestos_producto_maestro_id_fkey FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE CASCADE;


--
-- TOC entry 5989 (class 2606 OID 17673)
-- Name: cupones_descuento fk_cupon_canje; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_canje FOREIGN KEY (factura_canje_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5990 (class 2606 OID 18931)
-- Name: cupones_descuento fk_cupon_cliente; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5991 (class 2606 OID 17668)
-- Name: cupones_descuento fk_cupon_origen; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_origen FOREIGN KEY (factura_origen_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5992 (class 2606 OID 17663)
-- Name: cupones_descuento fk_cupon_promo; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.cupones_descuento
    ADD CONSTRAINT fk_cupon_promo FOREIGN KEY (promocion_id) REFERENCES promociones.promociones_campanas(id) ON DELETE RESTRICT;


--
-- TOC entry 5984 (class 2606 OID 18110)
-- Name: promociones_detalles fk_promodet_beneprod; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_beneprod FOREIGN KEY (beneficio_producto_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5985 (class 2606 OID 18105)
-- Name: promociones_detalles fk_promodet_categoria; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_categoria FOREIGN KEY (condicion_categoria_id) REFERENCES inventario.categoria_producto(id) ON DELETE RESTRICT;


--
-- TOC entry 5986 (class 2606 OID 26395)
-- Name: promociones_detalles fk_promodet_marca; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_marca FOREIGN KEY (condicion_marca_id) REFERENCES inventario.marca(id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5987 (class 2606 OID 17637)
-- Name: promociones_detalles fk_promodet_prod; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_prod FOREIGN KEY (condicion_producto_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5988 (class 2606 OID 17632)
-- Name: promociones_detalles fk_promodet_promo; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_detalles
    ADD CONSTRAINT fk_promodet_promo FOREIGN KEY (promocion_id) REFERENCES promociones.promociones_campanas(id) ON DELETE CASCADE;


--
-- TOC entry 6005 (class 2606 OID 26435)
-- Name: promociones_dias fk_promodia_campana; Type: FK CONSTRAINT; Schema: promociones; Owner: postgres
--

ALTER TABLE ONLY promociones.promociones_dias
    ADD CONSTRAINT fk_promodia_campana FOREIGN KEY (promocion_id) REFERENCES promociones.promociones_campanas(id) ON DELETE CASCADE;


--
-- TOC entry 5884 (class 2606 OID 18183)
-- Name: auditoria fk_auditoria_supervisor; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria
    ADD CONSTRAINT fk_auditoria_supervisor FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5885 (class 2606 OID 16619)
-- Name: auditoria fk_auditoria_usuarios; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.auditoria
    ADD CONSTRAINT fk_auditoria_usuarios FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5882 (class 2606 OID 16598)
-- Name: roles_permisos fk_rolperm_permisos; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT fk_rolperm_permisos FOREIGN KEY (permiso_id) REFERENCES seguridad.permisos(permiso_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5883 (class 2606 OID 16593)
-- Name: roles_permisos fk_rolperm_roles; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.roles_permisos
    ADD CONSTRAINT fk_rolperm_roles FOREIGN KEY (rol_id) REFERENCES seguridad.roles(rol_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5880 (class 2606 OID 18951)
-- Name: usuarios_roles fk_usrrol_roles; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT fk_usrrol_roles FOREIGN KEY (rol_id) REFERENCES seguridad.roles(rol_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 5881 (class 2606 OID 18946)
-- Name: usuarios_roles fk_usrrol_usuarios; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios_roles
    ADD CONSTRAINT fk_usrrol_usuarios FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON UPDATE CASCADE ON DELETE CASCADE;


--
-- TOC entry 5879 (class 2606 OID 16529)
-- Name: usuarios fk_usuarios_personas; Type: FK CONSTRAINT; Schema: seguridad; Owner: postgres
--

ALTER TABLE ONLY seguridad.usuarios
    ADD CONSTRAINT fk_usuarios_personas FOREIGN KEY (persona_id) REFERENCES identidad.personas(persona_id) ON UPDATE CASCADE ON DELETE RESTRICT;


--
-- TOC entry 6018 (class 2606 OID 26801)
-- Name: detalles_pago detalles_pago_metodo_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.detalles_pago
    ADD CONSTRAINT detalles_pago_metodo_pago_id_fkey FOREIGN KEY (metodo_pago_id) REFERENCES ventas.metodos_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 6019 (class 2606 OID 26806)
-- Name: detalles_pago detalles_pago_moneda_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.detalles_pago
    ADD CONSTRAINT detalles_pago_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6020 (class 2606 OID 26811)
-- Name: detalles_pago detalles_pago_sesion_caja_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.detalles_pago
    ADD CONSTRAINT detalles_pago_sesion_caja_id_fkey FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5993 (class 2606 OID 26481)
-- Name: cuentas_bancarias fk_cuenta_bancaria_moneda; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.cuentas_bancarias
    ADD CONSTRAINT fk_cuenta_bancaria_moneda FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5994 (class 2606 OID 17721)
-- Name: movimientos_bancarios fk_movbanco_cuenta; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT fk_movbanco_cuenta FOREIGN KEY (cuenta_bancaria_id) REFERENCES tesoreria.cuentas_bancarias(id) ON DELETE RESTRICT;


--
-- TOC entry 5995 (class 2606 OID 17726)
-- Name: movimientos_bancarios fk_movbanco_usuario; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT fk_movbanco_usuario FOREIGN KEY (usuario_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5996 (class 2606 OID 26898)
-- Name: movimientos_bancarios movimientos_bancarios_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.movimientos_bancarios
    ADD CONSTRAINT movimientos_bancarios_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 6024 (class 2606 OID 26871)
-- Name: pago_cheque pago_cheque_cuenta_bancaria_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_cheque
    ADD CONSTRAINT pago_cheque_cuenta_bancaria_id_fkey FOREIGN KEY (cuenta_bancaria_id) REFERENCES tesoreria.cuentas_bancarias(id) ON DELETE RESTRICT;


--
-- TOC entry 6025 (class 2606 OID 26866)
-- Name: pago_cheque pago_cheque_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_cheque
    ADD CONSTRAINT pago_cheque_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE CASCADE;


--
-- TOC entry 6026 (class 2606 OID 26891)
-- Name: pago_saldo_favor pago_saldo_favor_cliente_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_saldo_favor
    ADD CONSTRAINT pago_saldo_favor_cliente_id_fkey FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 6027 (class 2606 OID 26886)
-- Name: pago_saldo_favor pago_saldo_favor_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_saldo_favor
    ADD CONSTRAINT pago_saldo_favor_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE CASCADE;


--
-- TOC entry 6021 (class 2606 OID 26828)
-- Name: pago_tarjeta pago_tarjeta_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_tarjeta
    ADD CONSTRAINT pago_tarjeta_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE CASCADE;


--
-- TOC entry 6022 (class 2606 OID 26848)
-- Name: pago_transferencia pago_transferencia_cuenta_bancaria_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_transferencia
    ADD CONSTRAINT pago_transferencia_cuenta_bancaria_id_fkey FOREIGN KEY (cuenta_bancaria_id) REFERENCES tesoreria.cuentas_bancarias(id) ON DELETE RESTRICT;


--
-- TOC entry 6023 (class 2606 OID 26843)
-- Name: pago_transferencia pago_transferencia_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: tesoreria; Owner: postgres
--

ALTER TABLE ONLY tesoreria.pago_transferencia
    ADD CONSTRAINT pago_transferencia_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE CASCADE;


--
-- TOC entry 6034 (class 2606 OID 27001)
-- Name: ajustes_cxc ajustes_cxc_cuenta_por_cobrar_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc
    ADD CONSTRAINT ajustes_cxc_cuenta_por_cobrar_id_fkey FOREIGN KEY (cuenta_por_cobrar_id) REFERENCES ventas.cuentas_por_cobrar(id) ON DELETE RESTRICT;


--
-- TOC entry 6035 (class 2606 OID 27006)
-- Name: ajustes_cxc ajustes_cxc_devolucion_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc
    ADD CONSTRAINT ajustes_cxc_devolucion_id_fkey FOREIGN KEY (devolucion_id) REFERENCES ventas.devoluciones(id) ON DELETE RESTRICT;


--
-- TOC entry 6036 (class 2606 OID 27011)
-- Name: ajustes_cxc ajustes_cxc_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc
    ADD CONSTRAINT ajustes_cxc_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6037 (class 2606 OID 27016)
-- Name: ajustes_cxc ajustes_cxc_supervisor_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.ajustes_cxc
    ADD CONSTRAINT ajustes_cxc_supervisor_id_fkey FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 6032 (class 2606 OID 26961)
-- Name: consignacion_garantia_pagos consignacion_garantia_pagos_consignacion_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_garantia_pagos
    ADD CONSTRAINT consignacion_garantia_pagos_consignacion_id_fkey FOREIGN KEY (consignacion_id) REFERENCES ventas.consignaciones(id) ON DELETE CASCADE;


--
-- TOC entry 6033 (class 2606 OID 26966)
-- Name: consignacion_garantia_pagos consignacion_garantia_pagos_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_garantia_pagos
    ADD CONSTRAINT consignacion_garantia_pagos_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 5940 (class 2606 OID 26511)
-- Name: consignaciones consignaciones_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT consignaciones_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6010 (class 2606 OID 26678)
-- Name: cotizacion_detalle_impuestos cotizacion_detalle_impuestos_impuesto_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizacion_detalle_impuestos
    ADD CONSTRAINT cotizacion_detalle_impuestos_impuesto_id_fkey FOREIGN KEY (impuesto_id) REFERENCES tesoreria.impuestos(id) ON DELETE RESTRICT;


--
-- TOC entry 5923 (class 2606 OID 26501)
-- Name: cotizaciones cotizaciones_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT cotizaciones_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5913 (class 2606 OID 26541)
-- Name: cuentas_por_cobrar cuentas_por_cobrar_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT cuentas_por_cobrar_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5949 (class 2606 OID 26521)
-- Name: devoluciones devoluciones_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT devoluciones_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6008 (class 2606 OID 26652)
-- Name: factura_detalle_impuestos factura_detalle_impuestos_impuesto_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle_impuestos
    ADD CONSTRAINT factura_detalle_impuestos_impuesto_id_fkey FOREIGN KEY (impuesto_id) REFERENCES tesoreria.impuestos(id) ON DELETE RESTRICT;


--
-- TOC entry 5911 (class 2606 OID 26909)
-- Name: factura_pagos factura_pagos_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT factura_pagos_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 5899 (class 2606 OID 26491)
-- Name: facturas facturas_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT facturas_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 5921 (class 2606 OID 17045)
-- Name: arqueo_detalles fk_arqueo_metodo; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT fk_arqueo_metodo FOREIGN KEY (metodo_pago_id) REFERENCES ventas.metodos_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 5922 (class 2606 OID 17040)
-- Name: arqueo_detalles fk_arqueo_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.arqueo_detalles
    ADD CONSTRAINT fk_arqueo_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5941 (class 2606 OID 18137)
-- Name: consignaciones fk_consig_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT fk_consig_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5942 (class 2606 OID 26447)
-- Name: consignaciones fk_consig_sesion_caja; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT fk_consig_sesion_caja FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5943 (class 2606 OID 18055)
-- Name: consignaciones fk_consig_supervisor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones
    ADD CONSTRAINT fk_consig_supervisor FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5946 (class 2606 OID 17285)
-- Name: consignaciones_detalle fk_consigdet_consig; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT fk_consigdet_consig FOREIGN KEY (consignacion_id) REFERENCES ventas.consignaciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5947 (class 2606 OID 17970)
-- Name: consignaciones_detalle fk_consigdet_lote; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT fk_consigdet_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 5948 (class 2606 OID 17965)
-- Name: consignaciones_detalle fk_consigdet_prod; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignaciones_detalle
    ADD CONSTRAINT fk_consigdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5944 (class 2606 OID 17255)
-- Name: consignacion_facturas fk_consigfac_con; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT fk_consigfac_con FOREIGN KEY (consignacion_id) REFERENCES ventas.consignaciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5945 (class 2606 OID 17260)
-- Name: consignacion_facturas fk_consigfac_fac; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.consignacion_facturas
    ADD CONSTRAINT fk_consigfac_fac FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5924 (class 2606 OID 18127)
-- Name: cotizaciones fk_cotiz_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT fk_cotiz_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5925 (class 2606 OID 18213)
-- Name: cotizaciones fk_cotiz_facturagenerada; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT fk_cotiz_facturagenerada FOREIGN KEY (factura_generada_id) REFERENCES ventas.facturas(id) ON DELETE SET NULL;


--
-- TOC entry 5926 (class 2606 OID 18045)
-- Name: cotizaciones fk_cotiz_vendedor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones
    ADD CONSTRAINT fk_cotiz_vendedor FOREIGN KEY (vendedor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5927 (class 2606 OID 17960)
-- Name: cotizaciones_detalle fk_cotizdet_codbarra; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT fk_cotizdet_codbarra FOREIGN KEY (codigo_barra_id) REFERENCES inventario.producto_codigos_barra(id) ON DELETE RESTRICT;


--
-- TOC entry 5928 (class 2606 OID 17107)
-- Name: cotizaciones_detalle fk_cotizdet_cotiz; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT fk_cotizdet_cotiz FOREIGN KEY (cotizacion_id) REFERENCES ventas.cotizaciones(id) ON DELETE CASCADE;


--
-- TOC entry 5929 (class 2606 OID 17955)
-- Name: cotizaciones_detalle fk_cotizdet_prod; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizaciones_detalle
    ADD CONSTRAINT fk_cotizdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 6011 (class 2606 OID 26683)
-- Name: cotizacion_detalle_impuestos fk_cotizdetimp_detalle; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cotizacion_detalle_impuestos
    ADD CONSTRAINT fk_cotizdetimp_detalle FOREIGN KEY (cotizacion_id, producto_maestro_id) REFERENCES ventas.cotizaciones_detalle(cotizacion_id, producto_maestro_id) ON DELETE CASCADE;


--
-- TOC entry 5914 (class 2606 OID 18142)
-- Name: cuentas_por_cobrar fk_cxc_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT fk_cxc_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5915 (class 2606 OID 18025)
-- Name: cuentas_por_cobrar fk_cxc_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT fk_cxc_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5916 (class 2606 OID 18346)
-- Name: cuentas_por_cobrar fk_cxc_notadebito; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.cuentas_por_cobrar
    ADD CONSTRAINT fk_cxc_notadebito FOREIGN KEY (nota_debito_id) REFERENCES ventas.notas_debito(id) ON DELETE RESTRICT;


--
-- TOC entry 5954 (class 2606 OID 17980)
-- Name: devoluciones_detalles fk_devdet_lote; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT fk_devdet_lote FOREIGN KEY (lote_id) REFERENCES inventario.lotes(id) ON DELETE RESTRICT;


--
-- TOC entry 5955 (class 2606 OID 17334)
-- Name: devoluciones_detalles fk_devdet_nc; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT fk_devdet_nc FOREIGN KEY (nota_credito_id) REFERENCES ventas.devoluciones(id) ON DELETE RESTRICT;


--
-- TOC entry 5956 (class 2606 OID 17975)
-- Name: devoluciones_detalles fk_devdet_prod; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones_detalles
    ADD CONSTRAINT fk_devdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5950 (class 2606 OID 17311)
-- Name: devoluciones fk_devol_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT fk_devol_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5951 (class 2606 OID 18080)
-- Name: devoluciones fk_devol_secuencia; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT fk_devol_secuencia FOREIGN KEY (secuencia_dgii_id) REFERENCES ventas.secuencias_fiscales_dgii(id) ON DELETE RESTRICT;


--
-- TOC entry 5952 (class 2606 OID 26442)
-- Name: devoluciones fk_devol_sesion_caja; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT fk_devol_sesion_caja FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5953 (class 2606 OID 18075)
-- Name: devoluciones fk_devol_supervisor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.devoluciones
    ADD CONSTRAINT fk_devol_supervisor FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5907 (class 2606 OID 17935)
-- Name: factura_detalle fk_facdet_codigo_barra; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT fk_facdet_codigo_barra FOREIGN KEY (codigo_barra_id) REFERENCES inventario.producto_codigos_barra(id);


--
-- TOC entry 5908 (class 2606 OID 17945)
-- Name: factura_detalle fk_facdet_prod; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT fk_facdet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 5909 (class 2606 OID 17940)
-- Name: factura_detalle fk_facdet_promocion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT fk_facdet_promocion FOREIGN KEY (promocion_aplicada) REFERENCES promociones.promociones_campanas(id);


--
-- TOC entry 6009 (class 2606 OID 26657)
-- Name: factura_detalle_impuestos fk_facdetimp_detalle; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle_impuestos
    ADD CONSTRAINT fk_facdetimp_detalle FOREIGN KEY (factura_id, producto_maestro_id) REFERENCES ventas.factura_detalle(factura_id, producto_maestro_id) ON DELETE CASCADE;


--
-- TOC entry 5910 (class 2606 OID 16921)
-- Name: factura_detalle fk_factdet_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_detalle
    ADD CONSTRAINT fk_factdet_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5900 (class 2606 OID 18132)
-- Name: facturas fk_factura_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5901 (class 2606 OID 18218)
-- Name: facturas fk_factura_cotizacionorigen; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_cotizacionorigen FOREIGN KEY (cotizacion_origen) REFERENCES ventas.cotizaciones(id) ON DELETE SET NULL;


--
-- TOC entry 5902 (class 2606 OID 18900)
-- Name: facturas fk_factura_cupon; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_cupon FOREIGN KEY (cupon_aplicado_id) REFERENCES promociones.cupones_descuento(id) ON DELETE RESTRICT;


--
-- TOC entry 5903 (class 2606 OID 18015)
-- Name: facturas fk_factura_secuencia; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_secuencia FOREIGN KEY (secuencia_dgii_id) REFERENCES ventas.secuencias_fiscales_dgii(id) ON DELETE RESTRICT;


--
-- TOC entry 5904 (class 2606 OID 16898)
-- Name: facturas fk_factura_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5905 (class 2606 OID 18905)
-- Name: facturas fk_factura_supervisor_anulacion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_supervisor_anulacion FOREIGN KEY (supervisor_anulacion_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5906 (class 2606 OID 18010)
-- Name: facturas fk_factura_vendedor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.facturas
    ADD CONSTRAINT fk_factura_vendedor FOREIGN KEY (vendedor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5919 (class 2606 OID 17014)
-- Name: movimiento_caja fk_movcaja_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja
    ADD CONSTRAINT fk_movcaja_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5920 (class 2606 OID 18208)
-- Name: movimiento_caja fk_movcaja_supervisor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.movimiento_caja
    ADD CONSTRAINT fk_movcaja_supervisor FOREIGN KEY (supervisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5957 (class 2606 OID 18152)
-- Name: notas_debito fk_nd_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT fk_nd_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5958 (class 2606 OID 18065)
-- Name: notas_debito fk_nd_emisor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT fk_nd_emisor FOREIGN KEY (usuario_emisor_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5959 (class 2606 OID 17363)
-- Name: notas_debito fk_nd_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT fk_nd_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5960 (class 2606 OID 18070)
-- Name: notas_debito fk_nd_secuencia; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT fk_nd_secuencia FOREIGN KEY (secuencia_dgii_id) REFERENCES ventas.secuencias_fiscales_dgii(id) ON DELETE RESTRICT;


--
-- TOC entry 5962 (class 2606 OID 17387)
-- Name: nota_debito_detalles fk_nddet_nd; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles
    ADD CONSTRAINT fk_nddet_nd FOREIGN KEY (nota_debito_id) REFERENCES ventas.notas_debito(id) ON DELETE RESTRICT;


--
-- TOC entry 5963 (class 2606 OID 17985)
-- Name: nota_debito_detalles fk_nddet_prod; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalles
    ADD CONSTRAINT fk_nddet_prod FOREIGN KEY (producto_maestro_id) REFERENCES inventario.productos_maestro(id) ON DELETE RESTRICT;


--
-- TOC entry 6012 (class 2606 OID 26709)
-- Name: nota_debito_detalle_impuestos fk_nddetimp_detalle; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalle_impuestos
    ADD CONSTRAINT fk_nddetimp_detalle FOREIGN KEY (nota_debito_id, descripcion_cargo) REFERENCES ventas.nota_debito_detalles(nota_debito_id, descripcion_cargo) ON DELETE CASCADE;


--
-- TOC entry 5912 (class 2606 OID 16937)
-- Name: factura_pagos fk_pago_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.factura_pagos
    ADD CONSTRAINT fk_pago_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE CASCADE;


--
-- TOC entry 5937 (class 2606 OID 17219)
-- Name: pedidos_delivery fk_peddel_factura; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT fk_peddel_factura FOREIGN KEY (factura_id) REFERENCES ventas.facturas(id) ON DELETE RESTRICT;


--
-- TOC entry 5938 (class 2606 OID 18095)
-- Name: pedidos_delivery fk_peddel_repartidor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT fk_peddel_repartidor FOREIGN KEY (repartidor_id) REFERENCES identidad.repartidores(empleado_id) ON DELETE RESTRICT;


--
-- TOC entry 5939 (class 2606 OID 18100)
-- Name: pedidos_delivery fk_peddel_zona; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.pedidos_delivery
    ADD CONSTRAINT fk_peddel_zona FOREIGN KEY (zona_id) REFERENCES ventas.zonas_delivery(id) ON DELETE RESTRICT;


--
-- TOC entry 5917 (class 2606 OID 16972)
-- Name: recibo_ingreso_detalles fk_recdet_cxc; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT fk_recdet_cxc FOREIGN KEY (cuenta_por_cobrar_id) REFERENCES ventas.cuentas_por_cobrar(id) ON DELETE RESTRICT;


--
-- TOC entry 5918 (class 2606 OID 17990)
-- Name: recibo_ingreso_detalles fk_recdet_recibo; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_ingreso_detalles
    ADD CONSTRAINT fk_recdet_recibo FOREIGN KEY (recibo_ingreso_id) REFERENCES ventas.recibos_ingreso_cxc(id) ON DELETE CASCADE;


--
-- TOC entry 5930 (class 2606 OID 18030)
-- Name: recibos_ingreso_cxc fk_recibo_cajero; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT fk_recibo_cajero FOREIGN KEY (cajero_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 5931 (class 2606 OID 18147)
-- Name: recibos_ingreso_cxc fk_recibo_cliente; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT fk_recibo_cliente FOREIGN KEY (cliente_id) REFERENCES identidad.clientes(persona_id) ON DELETE RESTRICT;


--
-- TOC entry 5932 (class 2606 OID 17142)
-- Name: recibos_ingreso_cxc fk_recibo_sesion; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT fk_recibo_sesion FOREIGN KEY (sesion_caja_id) REFERENCES ventas.sesiones_caja(id) ON DELETE RESTRICT;


--
-- TOC entry 5936 (class 2606 OID 18335)
-- Name: repartidor_horarios fk_repartidor_horarios_repartidor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_horarios
    ADD CONSTRAINT fk_repartidor_horarios_repartidor FOREIGN KEY (repartidor_id) REFERENCES identidad.repartidores(empleado_id) ON DELETE CASCADE;


--
-- TOC entry 5934 (class 2606 OID 18223)
-- Name: repartidor_zonas fk_repzon_repartidor; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT fk_repzon_repartidor FOREIGN KEY (repartidor_id) REFERENCES identidad.repartidores(empleado_id) ON DELETE CASCADE;


--
-- TOC entry 5935 (class 2606 OID 17178)
-- Name: repartidor_zonas fk_repzon_zon; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.repartidor_zonas
    ADD CONSTRAINT fk_repzon_zon FOREIGN KEY (zona_id) REFERENCES ventas.zonas_delivery(id) ON DELETE CASCADE;


--
-- TOC entry 5897 (class 2606 OID 16863)
-- Name: sesiones_caja fk_sesion_caja; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja
    ADD CONSTRAINT fk_sesion_caja FOREIGN KEY (caja_id) REFERENCES ventas.cajas_pos(id) ON DELETE RESTRICT;


--
-- TOC entry 5898 (class 2606 OID 18203)
-- Name: sesiones_caja fk_sesion_cajero; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.sesiones_caja
    ADD CONSTRAINT fk_sesion_cajero FOREIGN KEY (cajero_id) REFERENCES seguridad.usuarios(usuario_id) ON DELETE RESTRICT;


--
-- TOC entry 6013 (class 2606 OID 26704)
-- Name: nota_debito_detalle_impuestos nota_debito_detalle_impuestos_impuesto_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.nota_debito_detalle_impuestos
    ADD CONSTRAINT nota_debito_detalle_impuestos_impuesto_id_fkey FOREIGN KEY (impuesto_id) REFERENCES tesoreria.impuestos(id) ON DELETE RESTRICT;


--
-- TOC entry 5961 (class 2606 OID 26531)
-- Name: notas_debito notas_debito_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.notas_debito
    ADD CONSTRAINT notas_debito_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


--
-- TOC entry 6028 (class 2606 OID 26928)
-- Name: recibo_cxc_pagos recibo_cxc_pagos_detalle_pago_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_cxc_pagos
    ADD CONSTRAINT recibo_cxc_pagos_detalle_pago_id_fkey FOREIGN KEY (detalle_pago_id) REFERENCES tesoreria.detalles_pago(id) ON DELETE RESTRICT;


--
-- TOC entry 6029 (class 2606 OID 26923)
-- Name: recibo_cxc_pagos recibo_cxc_pagos_recibo_ingreso_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibo_cxc_pagos
    ADD CONSTRAINT recibo_cxc_pagos_recibo_ingreso_id_fkey FOREIGN KEY (recibo_ingreso_id) REFERENCES ventas.recibos_ingreso_cxc(id) ON DELETE CASCADE;


--
-- TOC entry 5933 (class 2606 OID 26551)
-- Name: recibos_ingreso_cxc recibos_ingreso_cxc_moneda_id_fkey; Type: FK CONSTRAINT; Schema: ventas; Owner: postgres
--

ALTER TABLE ONLY ventas.recibos_ingreso_cxc
    ADD CONSTRAINT recibos_ingreso_cxc_moneda_id_fkey FOREIGN KEY (moneda_id) REFERENCES tesoreria.monedas(id) ON DELETE RESTRICT;


-- Completed on 2026-11-18 17:25:11

--
-- PostgreSQL database dump complete
--

\unrestrict yUsgli8KFcP8pXdPEzLuDbeThf77czfZHujKIupFIkg25DkmgoO4rON8ap2cDfJ

