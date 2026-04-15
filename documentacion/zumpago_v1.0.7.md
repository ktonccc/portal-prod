# Botón de Pago Multimedios -- Documento de Integración (Zumpago) v1.0.7

## 1. Introducción

Este documento describe la integración del botón de pago multimedios de
Zumpago, incluyendo envío de datos, respuesta, encriptación,
conciliación y anexos.

------------------------------------------------------------------------

## 2. Características de los Mensajes

-   Integración vía XML usando GET o POST
-   Encriptación: 3DES + Base64
-   Padding: PKCS7
-   Modo: ECB

### Tipos de Datos

  Tipo       Descripción
  ---------- -----------------------
  a(n)       Alfanumérico
  b(n)       Binario (hexadecimal)
  n(n)       Numérico
  VAR        Variable
  YY/MM/DD   Fecha
  hh/mm/ss   Hora

------------------------------------------------------------------------

## 3. Parámetros de Entrada (Envio)

  Campo                Tipo     Descripción                  Observaciones
  -------------------- -------- ---------------------------- --------------------
  IdComercio           n(6)     Identificador del comercio   
  IdTransaccion        n(13)    ID transacción               Único
  Fecha                n(8)     Fecha                        YYYYMMDD
  Hora                 n(6)     Hora                         HHMMSS
  NumCliente           a(12)    Cliente                      Opcional
  MontoTotal           n(12)    Monto                        Sin puntos
  MediosPago           a(47)    Medios                       Separados por coma
  CodigoVerificacion   a(100)   Encriptado                   

### Ejemplo

``` xml
<Envio>
  <IdComercio>000001</IdComercio>
  <IdTransaccion>123</IdTransaccion>
  <Fecha>20140313</Fecha>
  <Hora>113250</Hora>
  <MontoTotal>720627</MontoTotal>
  <MediosPago>001,002,003</MediosPago>
  <CodigoVerificacion>0123654789655484</CodigoVerificacion>
</Envio>
```

------------------------------------------------------------------------

## 4. Parámetros de Salida (Respuesta)

  Campo                  Tipo     Descripción
  ---------------------- -------- ----------------
  IdComercio             n(6)     Comercio
  IdTransaccion          n(13)    Transacción
  Fecha                  n(8)     Fecha
  Hora                   n(6)     Hora
  NumCliente             a(12)    Cliente
  FechaAbono             n(8)     Abono
  MontoTotal             n(12)    Monto
  MedioPagoAutorizado    a(13)    Medio
  CodigoRespuesta        n(3)     Código
  DescripcionRespuesta   a(200)   Descripción
  CodigoAutorizacion     a(6)     Autorización
  FechaProcesamiento     n(14)    YYYYMMDDHHMMSS
  CodigoVerificacion     a(100)   Encriptado

### Ejemplo

``` xml
<Respuesta>
  <IdTransaccion>123</IdTransaccion>
  <IdComercio>000001</IdComercio>
  <Fecha>20140313</Fecha>
  <Hora>113250</Hora>
  <MontoTotal>720627</MontoTotal>
  <MedioPagoAutorizado>001</MedioPagoAutorizado>
  <CodigoRespuesta>000</CodigoRespuesta>
  <DescripcionRespuesta>Transacción Aprobada</DescripcionRespuesta>
  <CodigoAutorizacion>ylEsHP</CodigoAutorizacion>
  <FechaProcesamiento>20140625123025</FechaProcesamiento>
  <CodigoVerificacion>0123654789655484</CodigoVerificacion>
</Respuesta>
```

------------------------------------------------------------------------

## 5. Encriptación

### XML

-   3DES + Base64

### Campos Envío

-   IdComercio
-   IdTransaccion
-   Fecha
-   Hora
-   MontoTotal

### Campos Respuesta

-   IdComercio
-   IdTransaccion
-   Fecha
-   Hora
-   MontoTotal
-   CodigoRespuesta
-   FechaProcesamiento

**Notas:** - Completar con ceros a la izquierda - Clave definida con
Zumpago

------------------------------------------------------------------------

## 6. URLs

-   Certificación: http://20.157.19.107:8091/BPZumPago/pago.aspx
-   Producción: https://www.zumpago.cl/BPZumpago/pago.aspx

------------------------------------------------------------------------

## 7. Notificación Asíncrona

-   Método POST
-   XML encriptado
-   Código 010: en validación

------------------------------------------------------------------------

## 8. Conciliación

### Archivo TRXCom

  Campo                Tipo
  -------------------- -------
  IdComercio           n(6)
  IdTransaccion        n(13)
  MedioPago            a(3)
  Fecha                n(8)
  Hora                 n(6)
  CodigoRespuesta      n(3)
  Monto                n(12)
  CodigoAutorizacion   a(6)
  NumeroCliente        a(12)

------------------------------------------------------------------------

## 9. Nomenclatura Archivo

    TRXCom + CodigoComercio + Fecha + FechaGeneracion + .com

Ejemplo:

    TRXCom0000012012011420120115.com

------------------------------------------------------------------------

## 10. Contacto

-   Néstor Silva
-   nsilva@zumpago.cl
-   25834093

------------------------------------------------------------------------

## 11. Anexo A

  Medio    Código
  -------- --------
  Webpay   006
  Khipu    018
  ETPAY    026
  Hites    022
  MACH     024

------------------------------------------------------------------------

## 12. Anexo B

  Código   Descripción
  -------- ---------------
  000      Aprobada
  001      Anulada
  005      Rechazada
  010      En validación
