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

3) Logs extra para diagnostico
- Archivo: `pay.php`
  - Log de error si falla `createTransaction` (`[WebpayPlus][create-error]`).
- Archivo: `return.php`
  - Log al recibir retorno (`[WebpayPlus][return-received]`) con `token_ws` o `TBK_TOKEN`.
  - Log de error si falla `commit` (`[WebpayPlus][commit-error]`).

4) Flujo de inicio de pago
- Archivo: `pay.php`
- Reemplazo de SOAP por REST usando `WebpayPlusService`.
- Se mantiene el post `token_ws` hacia `url` retornada por Webpay.

5) Retorno y confirmacion
- Archivo: `return.php`
- `commitTransaction(token_ws)` para confirmar el pago.
- Si `responseCode === 0`, redirige a `final_url`.
- Se guarda respuesta en `app/storage/webpay/*.json`.

6) Limpieza SOAP de Webpay
- Se elimino `app/Services/WebpayNormalService.php`.
- Se removieron `private_key_path` y `public_cert_path` del config de Webpay.

7) Configuracion multi-empresa (secrets por compania)
- Se agrego `app/Services/WebpayConfigResolver.php` para resolver credenciales por `idempresa`.
- `app/Config/app.php` ahora soporta `webpay.default_company_id` y `webpay.companies`.
- `pay.php` resuelve el perfil segun la primera deuda seleccionada.
- `return.php` resuelve el perfil segun el `company_id` guardado en storage.

8) Configuracion y permisos de logs
- Se habilito escritura en `app/logs` para el usuario del webserver.
- Es obligatorio para ver `WebpayPlus create/commit/status` y `return-received`.

9) Gitignore
- Se agrego `app/storage/webpay/` y `app/logs/` al `.gitignore`.
- Si ya estaban trackeados, ejecutar `git rm -r --cached app/storage/webpay app/logs`.

## Verificacion posterior a una transaccion real
- Revisar `app/logs/webpay.log`:
  - `WebpayPlus create raw response`
  - `WebpayPlus commit raw response`
  - `WebpayPlus status raw response`
- Revisar `app/storage/webpay/*.json` para `response_code` y datos del pago.

## Ultima configuracion aplicada en Homenet
- Ambiente: `PRODUCCION`
- `commerce_code`: `597035425993`
- `api_key`: `273b6e1b0cd31094898403bddca70f5b`
- `return_url`: `https://pagos.homenet.cl/return.php`
- `final_url`: `https://pagos.homenet.cl/final.php`

## Prompt detallado para replicar en otro proyecto
Objetivo: migrar Webpay SOAP a Webpay Plus REST usando apiKey.

1) Configurar:
- `webpay.commerce_code = "<CODIGO_COMERCIO>"`
- `webpay.api_key = "<API_KEY_PRODUCTIVA>"`
- `webpay.environment = "PRODUCCION"`
- `webpay.return_url = "https://tu-dominio/return.php"`
- `webpay.final_url = "https://tu-dominio/final.php"`
- (opcional) `webpay.default_company_id` y `webpay.companies` para múltiples empresas.

2) Autoload SDK REST:
- En `app/bootstrap.php` registrar autoload PSR-4 para `Transbank\\` hacia `actualizacion webpay/transbank-sdk-php/src`.

3) Crear servicio REST:
- Clase `WebpayPlusService` con `createTransaction`, `commitTransaction`, `getTransactionStatus`.
- Validar apiKey y commerceCode.
- Loguear respuestas en `app/logs/webpay.log`.

4) Agregar logs de diagnostico:
- `pay.php`: log de error si falla create.
- `return.php`: log de recepcion de token y log de error si falla commit.

5) Configurar multi-empresa (opcional):
- Agregar `app/Services/WebpayConfigResolver.php`.
- En `pay.php`, resolver config por `idempresa`.
- En `return.php`, resolver config por `company_id` almacenado.

6) Reemplazar flujo de pago:
- `pay.php`: usar `createTransaction` y seguir enviando `token_ws` a la URL de Webpay.
- `return.php`: usar `commitTransaction(token_ws)` y validar `responseCode === 0`.
- Aborto: usar `getTransactionStatus(TBK_TOKEN)` si aplica.

7) Limpieza SOAP:
- Eliminar o dejar sin uso el servicio SOAP.
- Remover certificados si solo se usa REST.

8) Permisos de logs:
- Asegurar escritura en `app/logs`.

9) Gitignore:
- Ignorar `app/logs/` y `app/storage/webpay/` y eliminar del indice si estaban trackeados.

## Prompt breve para aplicar en otro proyecto
```
Necesito migrar Webpay SOAP a Webpay Plus REST. Aplica estos cambios:
1) app/Config/app.php: agrega commerce_code, api_key, environment, return_url, final_url.
2) app/bootstrap.php: registra autoload PSR-4 de Transbank apuntando a actualizacion webpay/transbank-sdk-php/src.
3) app/Services/WebpayPlusService.php: crea el servicio REST con create/commit/status usando Transaction::buildForProduction.
4) pay.php: reemplaza creacion SOAP por REST y mantiene el POST con token_ws.
5) return.php: usa commit(token_ws) y valida responseCode === 0; loguea respuestas.
6) pay.php y return.php: agrega logs de diagnostico (return-received, create-error, commit-error).
7) app/Services/WebpayConfigResolver.php: agrega soporte multi-empresa por idempresa.
8) app/Services/WebpayNormalService.php: elimina el servicio SOAP.
9) app/Config/app.php: elimina private_key_path y public_cert_path si ya no usas SOAP y agrega companies/default_company_id si aplica.
10) .gitignore: ignora app/logs/ y app/storage/webpay/ y elimina del indice si ya estaban trackeados.
```

## Prompt para aplicar en otra empresa (misma migracion)
```
Aplica la misma migracion de SOAP a Webpay Plus REST que en este proyecto, tocando estos archivos:
- app/Config/app.php: commerce_code, api_key, environment, return_url, final_url. Quitar certificados.
- app/bootstrap.php: autoload REST desde actualizacion webpay/transbank-sdk-php/src.
- app/Services/WebpayPlusService.php: crear/actualizar servicio REST con logs create/commit/status.
- app/Services/WebpayConfigResolver.php: resolver config por empresa.
- pay.php: usar createTransaction y log de error si falla.
- return.php: usar commitTransaction, log return-received y commit-error.
- app/Services/WebpayNormalService.php: eliminar.
- .gitignore: ignorar app/logs/ y app/storage/webpay/.
Tambien asegurar permisos de escritura en app/logs para el usuario del webserver.
```
