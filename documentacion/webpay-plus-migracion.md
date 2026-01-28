# Migracion a Webpay Plus REST

Esta guia resume los cambios aplicados para reemplazar Webpay SOAP por Webpay Plus REST.

## Configuracion requerida
- `webpay.commerce_code`: codigo de comercio productivo.
- `webpay.api_key`: apiKey productiva entregada por Transbank.
- `webpay.environment`: `PRODUCCION` o `INTEGRACION`.
- `webpay.return_url`: URL de retorno que recibe `token_ws`.
- `webpay.final_url`: URL final del comprobante.

## Cambios tecnicos aplicados
1) Autoload del SDK REST local
- Archivo: `app/bootstrap.php`
- Se registra un autoload PSR-4 para `Transbank\\` apuntando a `actualizacion webpay/transbank-sdk-php/src`.
- Objetivo: priorizar clases REST sobre SOAP.

2) Servicio REST
- Archivo: `app/Services/WebpayPlusService.php`
- Metodos:
  - `createTransaction(...)` -> crea transaccion REST.
  - `commitTransaction(token)` -> confirma el pago.
  - `getTransactionStatus(token)` -> consulta estado (abortos).
- Logging adicional en `app/logs/webpay.log` para create/commit/status.

3) Flujo de inicio de pago
- Archivo: `pay.php`
- Reemplazo de SOAP por REST usando `WebpayPlusService`.
- Se mantiene el post `token_ws` hacia `url` retornada por Webpay.

4) Retorno y confirmacion
- Archivo: `return.php`
- `commitTransaction(token_ws)` para confirmar el pago.
- Si `responseCode === 0`, redirige a `final_url`.
- Se guarda respuesta en `app/storage/webpay/*.json`.

5) Limpieza SOAP de Webpay
- Se elimino `app/Services/WebpayNormalService.php`.
- Se removieron `private_key_path` y `public_cert_path` del config de Webpay.

## Verificacion posterior a una transaccion real
- Revisar `app/logs/webpay.log`:
  - `WebpayPlus create raw response`
  - `WebpayPlus commit raw response`
  - `WebpayPlus status raw response`
- Revisar `app/storage/webpay/*.json` para `response_code` y datos del pago.

## Prompt detallado para replicar en otro proyecto
Objetivo: migrar Webpay SOAP a Webpay Plus REST usando apiKey.

1) Configurar:
- `webpay.commerce_code = "<CODIGO_COMERCIO>"`
- `webpay.api_key = "<API_KEY_PRODUCTIVA>"`
- `webpay.environment = "PRODUCCION"`
- `webpay.return_url = "https://tu-dominio/return.php"`
- `webpay.final_url = "https://tu-dominio/final.php"`

2) Autoload SDK REST:
- En `app/bootstrap.php` registrar autoload PSR-4 para `Transbank\\` hacia `actualizacion webpay/transbank-sdk-php/src`.

3) Crear servicio REST:
- Clase `WebpayPlusService` con `createTransaction`, `commitTransaction`, `getTransactionStatus`.
- Validar apiKey y commerceCode.
- Loguear respuestas en `app/logs/webpay.log`.

4) Reemplazar flujo de pago:
- `pay.php`: usar `createTransaction` y seguir enviando `token_ws` a la URL de Webpay.
- `return.php`: usar `commitTransaction(token_ws)` y validar `responseCode === 0`.
- Aborto: usar `getTransactionStatus(TBK_TOKEN)` si aplica.

5) Limpieza SOAP:
- Eliminar o dejar sin uso el servicio SOAP.
- Remover certificados si solo se usa REST.

## Prompt breve para aplicar en otro proyecto
```
Necesito migrar Webpay SOAP a Webpay Plus REST. Aplica estos cambios:
1) app/Config/app.php: agrega commerce_code, api_key, environment, return_url, final_url.
2) app/bootstrap.php: registra autoload PSR-4 de Transbank apuntando a actualizacion webpay/transbank-sdk-php/src.
3) app/Services/WebpayPlusService.php: crea el servicio REST con create/commit/status usando Transaction::buildForProduction.
4) pay.php: reemplaza creacion SOAP por REST y mantiene el POST con token_ws.
5) return.php: usa commit(token_ws) y valida responseCode === 0; loguea respuestas.
6) app/Services/WebpayNormalService.php: elimina el servicio SOAP.
7) app/Config/app.php: elimina private_key_path y public_cert_path si ya no usas SOAP.
```
