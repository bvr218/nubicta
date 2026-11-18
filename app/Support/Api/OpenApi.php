<?php

namespace App\Support\Api;

use App\Http\Controllers\ApiV1\ApiPresenter;
use App\Http\Controllers\ApiV1\PurchaseApiController;
use App\Http\Controllers\ApiV1\SalesApiController;
use App\Models\Contact;
use App\Services\Api\Webhooks;
use App\Services\Dian\CorrectionConcepts;

/** Especificación OpenAPI 3 de /api/v1; la usan /api/v1/openapi.json y el visor de la documentación. Los ejemplos son ficticios. */
final class OpenApi
{
    private array $paths = [];

    public static function spec(string $server): array
    {
        return (new self)->build($server);
    }

    private function build(string $server): array
    {
        $this->catalogs();
        $this->contacts();
        $this->products();
        $this->sales();
        $this->purchases();
        $this->treasury();
        $this->reports();
        $this->webhooks();

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'API de Nubicta',
                'version' => '1.0',
                'description' => $this->intro(),
                'contact' => ['name' => 'Nubicta', 'url' => 'https://nubicta.online'],
                'license' => ['name' => 'MIT', 'url' => 'https://opensource.org/licenses/MIT'],
            ],
            'servers' => [['url' => rtrim($server, '/'), 'description' => 'URL base de tu instalación']],
            'security' => [['llave' => []]],
            'tags' => $this->tags(),
            'x-tagGroups' => [
                ['name' => 'Catálogos', 'tags' => ['Catálogos']],
                ['name' => 'Terceros y productos', 'tags' => ['Terceros', 'Productos', 'Existencias']],
                ['name' => 'Ventas', 'tags' => ['Facturas de venta', 'Notas crédito', 'Notas débito', 'Cotizaciones']],
                ['name' => 'Compras', 'tags' => ['Facturas de compra', 'Documentos soporte']],
                ['name' => 'Tesorería y contabilidad', 'tags' => ['Recibos de caja', 'Recibos de pago', 'Comprobantes contables']],
                ['name' => 'Reportes', 'tags' => ['Reportes']],
                ['name' => 'Webhooks', 'tags' => ['Webhooks']],
            ],
            'paths' => $this->paths,
            'components' => [
                'securitySchemes' => ['llave' => [
                    'type' => 'http', 'scheme' => 'bearer',
                    'description' => 'Llave generada en Configuración > Credenciales de integración a plataformas digitales (API). Envíala como `Authorization: Bearer TU_LLAVE`.',
                ]],
                'schemas' => $this->schemas(),
                'responses' => $this->responses(),
            ],
        ];
    }

    private function intro(): string
    {
        $nc = collect(CorrectionConcepts::NC)->map(fn ($v, $k) => "| `$k` | $v |")->implode("\n");
        $nd = collect(CorrectionConcepts::ND)->map(fn ($v, $k) => "| `$k` | $v |")->implode("\n");

        return <<<MD
Conecta tu tienda en línea, punto de venta, CRM u otra plataforma con tu empresa en Nubicta. La API trabaja solo con los datos de tu propia empresa, que siempre son tuyos, y aplica las mismas reglas contables y tributarias que la aplicación: lo que creas por API queda igual que si lo hubieras hecho en pantalla (contabilidad, inventario, cartera y envío a la DIAN).

# Autenticación

1. En Nubicta entra a **Configuración > Credenciales de integración a plataformas digitales (API)**.
2. Activa **Habilitar API** y elige los permisos: *Solo lectura* (solo `GET`) o *Lectura y escritura*.
3. Si quieres, escribe las IP permitidas separadas por coma. Vacío permite cualquier origen.
4. Pulsa **Generar nueva llave**. Se muestra una sola vez; al generar otra la anterior deja de funcionar.

Envía la llave en cada petición:

```http
GET /api/v1/productos HTTP/1.1
Authorization: Bearer TU_LLAVE
Accept: application/json
```

La llave actúa con los permisos del usuario que la generó (por ejemplo, para crear facturas el usuario necesita el permiso de ventas). Nubicta guarda solo la huella SHA-256 de la llave.

**Límite:** 120 peticiones por minuto por llave; si lo superas recibes `429` y la cabecera `Retry-After`.

# Formato y paginación

* JSON en UTF-8, campos en español. Fechas `AAAA-MM-DD`; fechas y horas en ISO 8601.
* Los valores monetarios son números con hasta dos decimales (`23800.5`).
* Los listados devuelven 50 registros por página; cambia con `por_pagina` (1 a 100) y avanza con `page`.

```json
{ "data": [ ... ], "pagina": 1, "por_pagina": 50, "total": 134, "paginas": 3 }
```

Para sincronizar solo lo nuevo usa `creado_desde` o `actualizado_desde` (fecha u hora ISO), o suscríbete a los [webhooks](#tag/Webhooks).

# Errores

| Código | Significado |
|---|---|
| `401` | Falta la llave, es inválida o fue revocada. |
| `402` | La suscripción de la empresa venció (Nubicta en la nube). |
| `403` | API deshabilitada, IP no permitida, llave de solo lectura o el usuario no tiene el permiso. |
| `404` | El registro no existe en tu empresa. |
| `409` | La operación no se puede hacer por el estado del documento (por ejemplo, la DIAN ya lo aceptó o tiene pagos). |
| `422` | Datos inválidos; `errors` indica cada campo. |
| `429` | Superaste el límite de peticiones. |

```json
{
  "message": "La fecha debe tener el formato AAAA-MM-DD.",
  "errors": { "fecha": ["La fecha debe tener el formato AAAA-MM-DD."] }
}
```

# Cómo facturar paso a paso

1. Consulta una vez los catálogos que vas a usar: `GET /tipos-documento?tipo=FV`, `GET /formas-pago?tipo_documento=FV`, `GET /impuestos`, `GET /bodegas`.
2. Busca al cliente con `GET /terceros?identificacion=900555123`; si no existe créalo con `POST /terceros`.
3. Crea la factura con `POST /facturas`. Puedes referenciar cliente y productos por id o por identificación y código. Si no envías `precio` se usa el de la lista de precios del cliente, y si no envías `impuesto_id` el IVA del producto.
4. Si el tipo de documento es electrónico, Nubicta la envía a la DIAN de inmediato (desactívalo con `"enviar_dian": false`) y la respuesta trae el resultado en `dian`. Consulta el estado y los errores con `GET /facturas/{id}/dian`.
5. Descarga el PDF (`GET /facturas/{id}/pdf`) o el XML (`GET /facturas/{id}/xml`), o envíala por correo con `POST /facturas/{id}/correo`.
6. Cuando el cliente pague, registra el recibo con `POST /recibos-caja`.

# Motivos DIAN de las notas

Las notas crédito y débito llevan `motivo` con el código DIAN y, si son electrónicas, la factura que corrigen (`factura_id` o `factura`).

| Nota crédito | Motivo |
|---|---|
$nc

| Nota débito | Motivo |
|---|---|
$nd

# Equivalencias con la API de Siigo

Si vienes de la API de Siigo, estas son las rutas equivalentes. En Nubicta no hace falta pedir un token temporal: la llave se envía directamente en cada petición.

| Siigo | Nubicta |
|---|---|
| `GET/POST /v1/products`, `GET/PUT/DELETE /v1/products/{id}` | `GET/POST /productos`, `GET/PUT/DELETE /productos/{id}` |
| `GET/POST /v1/customers`, `GET/PUT/DELETE /v1/customers/{id}` | `GET/POST /terceros`, `GET/PUT/DELETE /terceros/{id}` |
| `POST /v1/invoices`, `GET /v1/invoices`, `GET/PUT/DELETE /v1/invoices/{id}` | `POST /facturas`, `GET /facturas`, `GET/PUT/DELETE /facturas/{id}` |
| `POST /v1/invoices/{id}/annul` | `POST /facturas/{id}/anular` |
| `GET /v1/invoices/{id}/pdf`, `GET /v1/invoices/{id}/xml` | `GET /facturas/{id}/pdf`, `GET /facturas/{id}/xml` (archivo directo) |
| `POST /v1/invoices/{id}/mail` | `POST /facturas/{id}/correo` |
| `GET /v1/invoices/{id}/stamp/errors` | `GET /facturas/{id}/dian` |
| `POST /v1/credit-notes`, `GET /v1/credit-notes` | `POST /notas-credito`, `GET /notas-credito` |
| `POST /v1/vouchers` | `POST /recibos-caja` |
| `POST /v1/purchases`, `GET /v1/purchases` | `POST /compras`, `GET /compras` |
| `POST /v1/payment-receipts` | `POST /recibos-pago` |
| `POST /v1/journals`, `GET /v1/journals` | `POST /comprobantes`, `GET /comprobantes` |
| `GET /v1/document-types?type=FV` | `GET /tipos-documento?tipo=FV` |
| `GET /v1/payment-types` | `GET /formas-pago` |
| `GET /v1/taxes`, `GET /v1/price-lists`, `GET /v1/warehouses` | `GET /impuestos`, `GET /listas-precios`, `GET /bodegas` |
| `GET /v1/users`, `GET /v1/account-groups`, `GET /v1/cost-centers`, `GET /v1/fixed-assets` | `GET /usuarios`, `GET /grupos-inventario`, `GET /centros-costo`, `GET /activos-fijos` |
| `POST /v1/test-balance-report` | `GET /reportes/balance-prueba` |
| `POST /v1/test-balance-report-by-thirdparty` | `GET /reportes/balance-prueba-terceros` |
| `GET /v1/accounts-payable` | `GET /cuentas-por-pagar` (y `GET /cuentas-por-cobrar`) |
| Webhooks de productos e inventario | `POST /webhooks` con los eventos de Nubicta |

# Webhooks: verificar la firma

Cada envío es un `POST` JSON con las cabeceras `X-Nubicta-Evento` y `X-Nubicta-Firma: sha256=<hmac>`, donde el HMAC-SHA256 se calcula sobre el cuerpo exacto con el `secreto` que recibiste al crear el webhook. Responde `2xx` en menos de 10 segundos; si falla, Nubicta reintenta hasta 5 veces y desactiva el webhook tras 50 fallas seguidas.

```php
\$body = file_get_contents('php://input');
\$firma = 'sha256=' . hash_hmac('sha256', \$body, getenv('NUBICTA_SECRETO'));
if (! hash_equals(\$firma, \$_SERVER['HTTP_X_NUBICTA_FIRMA'] ?? '')) {
    http_response_code(401);
    exit;
}
\$evento = json_decode(\$body, true); // ["evento" => "factura.creada", "id" => "...", "fecha" => "...", "data" => [...]]
```

```javascript
const crypto = require('crypto');
const firma = 'sha256=' + crypto.createHmac('sha256', process.env.NUBICTA_SECRETO).update(rawBody).digest('hex');
const valida = crypto.timingSafeEqual(Buffer.from(firma), Buffer.from(req.get('X-Nubicta-Firma') || ''));
```
MD;
    }

    private function tags(): array
    {
        return [
            ['name' => 'Catálogos', 'description' => 'Ids que necesitas para armar documentos: tipos de documento, formas de pago, impuestos, listas de precios, bodegas, vendedores, centros de costo, cuentas contables y ciudades.'],
            ['name' => 'Terceros', 'description' => 'Clientes, proveedores, empleados y otros. Un mismo tercero puede tener varios roles. Equivale a *customers* en Siigo.'],
            ['name' => 'Productos', 'description' => 'Productos, servicios y combos con sus precios por lista, impuestos y existencias por bodega.'],
            ['name' => 'Existencias', 'description' => 'Cantidades por producto y bodega.'],
            ['name' => 'Facturas de venta', 'description' => 'Facturas de venta (FV). Si el tipo es electrónico se envían a la DIAN al crearlas.'],
            ['name' => 'Notas crédito', 'description' => 'Notas crédito (NC): devoluciones, descuentos o anulación de una factura.'],
            ['name' => 'Notas débito', 'description' => 'Notas débito (ND): intereses, gastos o mayor valor de una factura.'],
            ['name' => 'Cotizaciones', 'description' => 'Cotizaciones (CT). No mueven contabilidad ni inventario.'],
            ['name' => 'Facturas de compra', 'description' => 'Facturas de compra y gastos (FC). Los ítems pueden ser productos, cuentas contables o activos fijos.'],
            ['name' => 'Documentos soporte', 'description' => 'Documento soporte (DS) para compras a no obligados a facturar; si es electrónico se envía a la DIAN.'],
            ['name' => 'Recibos de caja', 'description' => 'Pagos de clientes (RC): abonos a facturas, anticipos u otros ingresos. Equivale a *vouchers* en Siigo.'],
            ['name' => 'Recibos de pago', 'description' => 'Pagos a proveedores o egresos (RP): abonos a compras, anticipos, otros pagos o modo avanzado con líneas contables.'],
            ['name' => 'Comprobantes contables', 'description' => 'Comprobantes contables (CC) con débitos y créditos; permiten cruzar cartera, crear vencimientos y mover inventario.'],
            ['name' => 'Reportes', 'description' => 'Balance de prueba general y por tercero, cartera por cobrar y cuentas por pagar.'],
            ['name' => 'Webhooks', 'description' => 'Recibe en tu servidor un POST firmado cada vez que ocurre un evento en Nubicta.'],
        ];
    }

    // ---------------------------------------------------------------- rutas

    private function catalogs(): void
    {
        $list = fn (string $path, string $summary, string $desc, string $schema, array $params = [], array $example = []) => $this->op('get', $path, 'Catálogos', $summary, $desc, [
            'parameters' => $params,
            'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $this->ref($schema)]]], $example ? ['data' => [$example]] : null, 'Listado')],
        ]);

        $list('/tipos-documento', 'Tipos de documento', 'Tipos configurados (comprobantes). Usa el `id` en `tipo_documento_id` al crear documentos; si no lo envías se usa el primero activo, prefiriendo el electrónico.', 'TipoDocumento',
            [$this->q('tipo', 'Filtra por clase: FV, NC, ND, CT, FC, DS, RC, CE, CC.', 'FV'), $this->q('incluir_inactivos', 'Incluye los tipos inactivos.', null, 'boolean')],
            ['id' => 1, 'tipo' => 'FV', 'codigo' => '2', 'nombre' => 'Factura electrónica de venta', 'prefijo' => 'FE', 'consecutivo' => 1534, 'electronico' => true, 'numeracion_automatica' => true, 'centro_costo_obligatorio' => false,
                'resolucion' => ['numero' => '18760000000000', 'desde' => 1, 'hasta' => 5000, 'vigencia_desde' => '2026-01-15', 'vigencia_hasta' => '2028-01-15'], 'activo' => true]);
        $list('/formas-pago', 'Formas de pago', 'Medios de pago (efectivo, transferencia, crédito...). Usa el `id` en `pagos[].forma_pago_id`.', 'FormaPago',
            [$this->q('tipo_documento', 'Solo las habilitadas para esa clase de documento (FV, FC, RC, CE...).', 'FV')],
            ['id' => 8, 'nombre' => 'Efectivo', 'tipo' => 'Cartera', 'codigo_dian' => '10', 'cuenta_id' => 3790, 'maneja_vencimiento' => false, 'tipos_documento' => ['FV', 'RC']]);
        $list('/impuestos', 'Impuestos y retenciones', 'IVA, impoconsumo, retención en la fuente, ReteIVA y ReteICA.', 'Impuesto',
            [$this->q('tipo', 'IVA, Impoconsumo, Retefuente, ReteIVA o ReteICA.', 'IVA')],
            ['id' => 1, 'codigo' => 1, 'nombre' => 'IVA 19%', 'tipo' => 'IVA', 'porcentaje' => 19, 'valor_fijo' => 0, 'base_minima' => 0, 'codigo_dian' => '01']);
        $list('/listas-precios', 'Listas de precios', 'Listas de precios de venta.', 'ListaPrecio', [], ['id' => 1, 'posicion' => 1, 'nombre' => 'Precio de venta 1']);
        $list('/bodegas', 'Bodegas', 'Bodegas activas.', 'Bodega', [], ['id' => 1, 'nombre' => 'Principal', 'direccion' => 'Calle 10 # 5-20', 'responsable' => null, 'principal' => true]);
        $list('/usuarios', 'Usuarios', 'Usuarios activos de la empresa.', 'Usuario', [], ['id' => 3, 'nombre' => 'Laura Gómez', 'identificacion' => '1020304050', 'correo' => 'laura@ejemplo.com', 'vendedor' => true]);
        $list('/vendedores', 'Vendedores', 'Vendedores para `vendedor_id` en facturas y terceros.', 'Vendedor', [], ['id' => 2, 'nombre' => 'Laura Gómez', 'identificacion' => '1020304050', 'correo' => 'laura@ejemplo.com', 'comision' => 3, 'usuario_id' => 3]);
        $list('/centros-costo', 'Centros de costo', 'Centros de costo activos.', 'CentroCosto', [], ['id' => 4, 'codigo' => '01', 'nombre' => 'Administración', 'padre_id' => null]);
        $list('/grupos-inventario', 'Grupos de inventario', 'Grupos contables de productos (obligatorio al crear un producto).', 'GrupoInventario', [], ['id' => 3, 'nombre' => 'Productos gravados', 'cuenta_inventario_id' => 3873, 'cuenta_ingreso_id' => 4058, 'cuenta_costo_id' => 4381]);
        $list('/activos-fijos', 'Activos fijos', 'Activos fijos, para comprarlos como ítem de una factura de compra.', 'ActivoFijo', [], ['id' => 1, 'codigo' => 'AF-001', 'nombre' => 'Portátil', 'grupo_id' => 1]);
        $list('/ciudades', 'Ciudades', 'Municipios con el código DANE de 5 dígitos que va en `ciudad` de los terceros.', 'Ciudad',
            [$this->q('departamento', 'Código DANE del departamento (2 dígitos).', '11'), $this->q('q', 'Busca por nombre.', 'Bogotá')], ['codigo' => '11001', 'departamento' => '11', 'nombre' => 'BOGOTÁ, D.C.']);
        $list('/conceptos', 'Conceptos frecuentes', 'Conceptos para recibos tipo "otro" (`concepto_id`) y descuentos de recibos.', 'Concepto', [$this->q('tipo', 'Clase de concepto.')], ['id' => 2, 'tipo' => 'ingreso', 'nombre' => 'Intereses recibidos', 'cuenta_id' => 4210]);
        $list('/cuentas-bancarias', 'Cuentas bancarias', 'Cuentas de bancos y cajas, para `cuenta_bancaria_id` en recibos de pago.', 'CuentaBancaria', [], ['id' => 1, 'nombre' => 'Cuenta corriente', 'tipo' => 'banco', 'banco' => 'Banco de ejemplo', 'cuenta_id' => 3795]);

        $this->op('get', '/cuentas', 'Catálogos', 'Plan de cuentas', 'Cuentas contables (PUC). En facturas de compra y comprobantes puedes indicar la cuenta por su código (`"cuenta": "513530"`).', [
            'parameters' => [...$this->pageParams(), $this->q('codigo', 'Cuentas que empiezan por este código.', '5135'), $this->q('q', 'Busca por código o nombre.'), $this->q('solo_movimiento', 'Solo cuentas auxiliares que reciben movimiento.', null, 'boolean')],
            'responses' => ['200' => $this->json($this->paged('Cuenta'), null, 'Listado paginado')],
        ]);
    }

    private function contacts(): void
    {
        $example = $this->exampleContact();
        $this->op('get', '/terceros', 'Terceros', 'Listar terceros', 'Busca por nombre, identificación o correo con `q`, o filtra por rol.', [
            'parameters' => [...$this->pageParams(), $this->q('q', 'Texto a buscar.', 'andina'), $this->q('tipo', 'Rol: '.implode(', ', array_keys(Contact::ROLES)).'.', 'cliente'),
                $this->q('identificacion', 'Identificación exacta (sin DV).', '900555123'), $this->q('activo', 'true o false.', null, 'boolean'), ...$this->syncParams()],
            'responses' => ['200' => $this->json($this->paged('Tercero'), ['data' => [$example], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
        ]);
        $this->op('post', '/terceros', 'Terceros', 'Crear tercero', 'Crea un cliente, proveedor, empleado u otro. El dígito de verificación del NIT y de la cédula se calcula solo. Si el rol incluye `empleado`, el tercero queda disponible en Nómina con un contrato base que debes completar.', [
            'requestBody' => $this->body('TerceroEntrada', ['tipo_persona' => 'juridica', 'tipo_identificacion' => '31', 'identificacion' => '900555123', 'razon_social' => 'Distribuciones Andina S.A.S.',
                'correo' => 'compras@andina.example', 'telefono' => '3001234567', 'direccion' => 'Carrera 7 # 45-10', 'ciudad' => '11001', 'responsable_iva' => true, 'responsabilidades_fiscales' => ['O-13'], 'roles' => ['cliente'], 'dias_credito' => 30]),
            'responses' => ['201' => $this->json($this->ref('Tercero'), $example, 'Tercero creado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('get', '/terceros/{id}', 'Terceros', 'Ver tercero', 'Datos completos de un tercero.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Tercero'), $example), '404' => $this->r('NoEncontrado')],
        ]);
        $this->op('put', '/terceros/{id}', 'Terceros', 'Actualizar tercero', 'Envía solo los campos que cambian; los demás se conservan. `roles` reemplaza la lista completa de roles.', [
            'parameters' => [$this->id()], 'requestBody' => $this->body('TerceroCambios', ['correo' => 'facturacion@andina.example', 'dias_credito' => 45]),
            'responses' => ['200' => $this->json($this->ref('Tercero'), $example), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('delete', '/terceros/{id}', 'Terceros', 'Eliminar tercero', 'Solo se puede eliminar si no tiene documentos ni movimientos; si los tiene responde `409` y debes inactivarlo con `"activo": false`.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => 'Se eliminó el tercero DISTRIBUCIONES ANDINA S.A.S.']), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto')],
        ]);
    }

    private function products(): void
    {
        $example = $this->exampleProduct();
        $this->op('get', '/productos', 'Productos', 'Listar productos', 'Incluye precios, impuestos y existencias por bodega.', [
            'parameters' => [...$this->pageParams(), $this->q('q', 'Busca por código, nombre, referencia o código de barras.', 'cafe'), $this->q('codigo', 'Código exacto.', 'CAF-500'),
                $this->q('tipo', 'producto, servicio o combo.', 'producto'), $this->q('grupo_inventario_id', 'Grupo de inventario.', null, 'integer'), $this->q('activo', 'true o false.', null, 'boolean'), ...$this->syncParams()],
            'responses' => ['200' => $this->json($this->paged('Producto'), ['data' => [$example], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
        ]);
        $this->op('post', '/productos', 'Productos', 'Crear producto', 'Crea un producto, servicio o combo. Las existencias no se crean aquí: entran con compras, ajustes de inventario o comprobantes con inventario.', [
            'requestBody' => $this->body('ProductoEntrada', ['codigo' => 'CAF-500', 'nombre' => 'Café molido 500 g', 'tipo' => 'producto', 'grupo_inventario_id' => 3, 'unidad_dian' => '94',
                'referencia' => 'CM500', 'codigo_barras' => '7700000000017', 'impuestos' => [1], 'precios' => [['lista_precio_id' => 1, 'valor' => 18000], ['lista_precio_id' => 2, 'valor' => 16500]]]),
            'responses' => ['201' => $this->json($this->ref('Producto'), $example, 'Producto creado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('get', '/productos/{id}', 'Productos', 'Ver producto', 'Producto con precios, impuestos y existencias por bodega.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Producto'), $example), '404' => $this->r('NoEncontrado')],
        ]);
        $this->op('put', '/productos/{id}', 'Productos', 'Actualizar producto', 'Envía solo lo que cambia. `impuestos`, `precios` y `componentes` reemplazan la lista completa solo si los envías.', [
            'parameters' => [$this->id()], 'requestBody' => $this->body('ProductoCambios', ['precios' => [['lista_precio_id' => 1, 'valor' => 19500]]]),
            'responses' => ['200' => $this->json($this->ref('Producto'), $example), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('delete', '/productos/{id}', 'Productos', 'Eliminar producto', 'Solo si no tiene documentos ni movimientos de inventario; si los tiene responde `409` y debes inactivarlo con `"activo": false`.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => 'Se eliminó el producto CAF-500.']), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto')],
        ]);
        $this->op('get', '/existencias', 'Existencias', 'Existencias por bodega', 'Cantidad de cada producto inventariable en cada bodega. Por defecto omite las cantidades en cero.', [
            'parameters' => [...$this->pageParams(), $this->q('bodega_id', 'Bodega.', null, 'integer'), $this->q('codigo', 'Código del producto.', 'CAF-500'), $this->q('producto_id', 'Id del producto.', null, 'integer'), $this->q('incluir_ceros', 'Incluye cantidades en cero.', null, 'boolean')],
            'responses' => ['200' => $this->json($this->paged('Existencia'), ['data' => [['producto_id' => 15, 'codigo' => 'CAF-500', 'nombre' => 'Café molido 500 g', 'bodega_id' => 1, 'bodega' => 'Principal', 'cantidad' => 42]], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
        ]);
    }

    private function sales(): void
    {
        $tags = ['facturas' => 'Facturas de venta', 'notas-credito' => 'Notas crédito', 'notas-debito' => 'Notas débito', 'cotizaciones' => 'Cotizaciones'];
        foreach (SalesApiController::KINDS as $slug => $kind) {
            $tag = $tags[$slug];
            $label = $kind['label'];
            $plural = ['FV' => 'facturas', 'NC' => 'notas crédito', 'ND' => 'notas débito', 'CT' => 'cotizaciones'][$kind['doc']];
            $base = "/$slug";
            $note = in_array($kind['doc'], ['NC', 'ND'], true);
            $quote = $kind['doc'] === 'CT';
            $example = $this->exampleSale($kind['doc']);
            $input = $this->exampleSaleInput($kind['doc']);
            $created = ['mensaje' => "Se creó {$example['numero']}.", 'dian' => $quote ? null : 'DIAN: Procesado Correctamente. El envío por correo al cliente quedó en cola.', 'data' => $example];

            $this->op('get', $base, $tag, 'Listar '.$plural, "Listado de {$plural}, del más reciente al más antiguo.".($slug === 'facturas' ? ' Con `tipo=NC` o `tipo=ND` lista notas (compatibilidad con la primera versión).' : ''), [
                'parameters' => [...$this->pageParams(), $this->q('desde', 'Fecha inicial.', '2026-10-01', 'string', 'date'), $this->q('hasta', 'Fecha final.', '2026-10-31', 'string', 'date'),
                    $this->q('numero', 'Número completo.', $example['numero']), $this->q('tipo_documento_id', 'Tipo de documento.', null, 'integer'),
                    $this->q('cliente_id', 'Id del cliente.', null, 'integer'), $this->q('identificacion', 'Identificación del cliente.', '900555123'),
                    $this->q('estado', 'emitido o anulado.', 'emitido'), $this->q('estado_dian', 'Draft, Pending, Accepted o Rejected.', 'Accepted'),
                    $this->q('con_saldo', 'Solo con saldo pendiente por cobrar.', null, 'boolean'),
                    ...($slug === 'facturas' || $slug === 'notas-credito' ? [$this->q('referencia_externa', 'Referencia de quien llama la API.', 'pedido-10045')] : []),
                    ...$this->syncParams()],
                'responses' => ['200' => $this->json($this->paged('Venta'), ['data' => [array_diff_key($example, array_flip(['items', 'pagos', 'retenciones_detalle']))], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
            ]);
            $createdResponses = ['201' => $this->json($this->ref('VentaCreada'), $created, ucfirst($label).' creada'), '422' => $this->r('Validacion')];
            if (in_array($kind['doc'], ['FV', 'NC'], true)) {
                $createdResponses['200'] = $this->json($this->ref('VentaCreada'), ['mensaje' => "El documento {$example['numero']} ya estaba registrado.", 'dian' => null, 'data' => $example], 'La misma referencia externa y el mismo cuerpo. No crea otro consecutivo ni reenvía si la DIAN ya aceptó.');
                $createdResponses['409'] = $this->r('Conflicto');
            }
            $this->op('post', $base, $tag, 'Crear '.$label, $this->saleCreateText($kind['doc']), [
                'requestBody' => $this->body($note ? 'NotaEntrada' : 'VentaEntrada', $input),
                'responses' => $createdResponses,
            ]);
            $this->op('get', "$base/{id}", $tag, 'Ver '.$label, 'Detalle con ítems, formas de pago y retenciones.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Venta'), $example), '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('put', "$base/{id}", $tag, 'Editar '.$label, 'Reemplaza el documento completo conservando su número (envía el mismo cuerpo que al crear). Solo si la DIAN no lo ha aceptado y no tiene pagos ni notas; el documento editado recibe un `id` nuevo que viene en la respuesta.', [
                'parameters' => [$this->id()], 'requestBody' => $this->body($note ? 'NotaEntrada' : 'VentaEntrada', $input),
                'responses' => ['200' => $this->json($this->ref('VentaCreada'), ['mensaje' => "Se actualizó {$example['numero']}."] + $created), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto'), '422' => $this->r('Validacion')],
            ]);
            $this->op('delete', "$base/{id}", $tag, 'Eliminar '.$label, 'Elimina el documento y libera su consecutivo; reversa contabilidad, inventario y cartera. No se puede si la DIAN ya lo aceptó (usa una nota crédito) o si tiene pagos o notas.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => "Se eliminó {$example['numero']}; su consecutivo queda disponible."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
            $this->op('post', "$base/{id}/anular", $tag, 'Anular '.$label, 'Anula el documento conservando su número; reversa contabilidad, inventario y cartera. Un documento aceptado por la DIAN no se anula: elabora una nota crédito.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Venta')]], ['mensaje' => "Se anuló {$example['numero']}."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
            $this->op('get', "$base/{id}/pdf", $tag, 'Descargar PDF', 'Representación gráfica en PDF (archivo binario).', [
                'parameters' => [$this->id()], 'responses' => ['200' => ['description' => 'Archivo PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]], '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('post', "$base/{id}/correo", $tag, 'Enviar por correo', 'Envía el PDF (y el XML si es electrónico) al correo del cliente o al que indiques.', [
                'parameters' => [$this->id()],
                'requestBody' => ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['correo' => ['type' => 'string', 'format' => 'email', 'description' => 'Si no lo envías se usa el correo del cliente.']]], 'example' => ['correo' => 'compras@andina.example']]]],
                'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => "Se envió {$example['numero']} a compras@andina.example."]), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto'), '422' => $this->r('Validacion')],
            ]);
            if ($quote) {
                continue;
            }
            $this->op('get', "$base/{id}/xml", $tag, 'Descargar XML', 'XML del documento electrónico. Con `tipo=respuesta` devuelve la respuesta de la DIAN y con `tipo=contenedor` el AttachedDocument que se envía al cliente.', [
                'parameters' => [$this->id(), $this->q('tipo', 'documento (por defecto), respuesta o contenedor.', 'documento')],
                'responses' => ['200' => ['description' => 'Archivo XML', 'content' => ['application/xml' => ['schema' => ['type' => 'string', 'format' => 'binary']]]], '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('get', "$base/{id}/dian", $tag, 'Estado DIAN y errores', 'Estado ante la DIAN, CUFE, errores de validación y el historial de envíos. Con `consultar=true` vuelve a consultar el estado en la DIAN.', [
                'parameters' => [$this->id(), $this->q('consultar', 'Consulta de nuevo el estado en la DIAN.', null, 'boolean')],
                'responses' => ['200' => $this->json($this->ref('EstadoDian'), $this->exampleDian($example['numero'])), '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('post', "$base/{id}/dian", $tag, 'Enviar a la DIAN', 'Envía (o reenvía) un documento electrónico en borrador, pendiente o rechazado. Si la DIAN no responde, queda en cola y se reintenta solo.', [
                'parameters' => [$this->id()],
                'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('EstadoDian')]], ['mensaje' => 'DIAN: Procesado Correctamente.', 'data' => $this->exampleDian($example['numero'])]),
                    '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto'), '422' => $this->r('Validacion')],
            ]);
        }
    }

    private function purchases(): void
    {
        $tags = ['compras' => 'Facturas de compra', 'documentos-soporte' => 'Documentos soporte'];
        foreach (PurchaseApiController::KINDS as $slug => $kind) {
            $tag = $tags[$slug];
            $base = "/$slug";
            $ds = $kind['doc'] === 'DS';
            $label = $kind['label'];
            $plural = $ds ? 'documentos soporte' : 'facturas de compra';
            $example = $this->examplePurchase($kind['doc']);
            $input = $this->examplePurchaseInput($kind['doc']);

            $this->op('get', $base, $tag, 'Listar '.$plural, 'Listado del más reciente al más antiguo.', [
                'parameters' => [...$this->pageParams(), $this->q('desde', 'Fecha inicial.', '2026-10-01', 'string', 'date'), $this->q('hasta', 'Fecha final.', '2026-10-31', 'string', 'date'),
                    $this->q('numero', 'Número interno.', $example['numero']), $this->q('proveedor_id', 'Id del proveedor.', null, 'integer'), $this->q('identificacion', 'Identificación del proveedor.', '800777321'),
                    $this->q('factura_proveedor', 'Número de la factura del proveedor.', '4581'), $this->q('estado', 'emitido o anulado.'), $this->q('con_saldo', 'Solo con saldo por pagar.', null, 'boolean'), ...$this->syncParams()],
                'responses' => ['200' => $this->json($this->paged('Compra'), ['data' => [array_diff_key($example, array_flip(['items', 'pagos']))], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
            ]);
            $this->op('post', $base, $tag, 'Crear '.$label, $ds
                ? 'Crea un documento soporte. Si el tipo es electrónico se envía a la DIAN (desactívalo con `"enviar_dian": false`). Los ítems pueden ser productos (entran al inventario), cuentas contables o activos fijos.'
                : 'Registra una factura de compra o gasto. Los ítems pueden ser productos (entran al inventario y actualizan el costo promedio), cuentas contables de gasto o activos fijos. Sin `pagos` queda a crédito.', [
                    'requestBody' => $this->body('CompraEntrada', $input),
                    'responses' => ['201' => $this->json($this->ref('CompraCreada'), ['mensaje' => "Se creó {$example['numero']}.", 'dian' => $ds ? 'DIAN: Procesado Correctamente.' : null, 'data' => $example], 'Documento creado'), '422' => $this->r('Validacion')],
                ]);
            $this->op('get', "$base/{id}", $tag, 'Ver '.$label, 'Detalle con ítems y formas de pago.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Compra'), $example), '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('put', "$base/{id}", $tag, 'Editar '.$label, 'Reemplaza el documento conservando su número. Solo si no tiene pagos ni fue aceptado por la DIAN; el documento editado recibe un `id` nuevo.', [
                'parameters' => [$this->id()], 'requestBody' => $this->body('CompraEntrada', $input),
                'responses' => ['200' => $this->json($this->ref('CompraCreada'), ['mensaje' => "Se actualizó {$example['numero']}.", 'dian' => null, 'data' => $example]), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto'), '422' => $this->r('Validacion')],
            ]);
            $this->op('post', "$base/{id}/anular", $tag, 'Anular '.$label, 'Anula el documento y reversa contabilidad, inventario y cuentas por pagar.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Compra')]], ['mensaje' => "Se anuló {$example['numero']}."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
            if ($ds) {
                $this->op('get', "$base/{id}/xml", $tag, 'Descargar XML', 'XML del documento soporte electrónico; con `tipo=respuesta` la respuesta de la DIAN.', [
                    'parameters' => [$this->id(), $this->q('tipo', 'documento (por defecto) o respuesta.', 'documento')],
                    'responses' => ['200' => ['description' => 'Archivo XML', 'content' => ['application/xml' => ['schema' => ['type' => 'string', 'format' => 'binary']]]], '404' => $this->r('NoEncontrado')],
                ]);
                $this->op('post', "$base/{id}/dian", $tag, 'Enviar a la DIAN', 'Envía o reenvía el documento soporte electrónico.', [
                    'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Compra')]], ['mensaje' => 'DIAN: Procesado Correctamente.']), '404' => $this->r('NoEncontrado'), '409' => $this->r('Conflicto')],
                ]);
            }
        }
    }

    private function treasury(): void
    {
        foreach (['recibos-caja' => ['Recibos de caja', 'RC', 'cliente', 'facturas de venta'], 'recibos-pago' => ['Recibos de pago', 'RP', 'proveedor', 'facturas de compra']] as $slug => [$tag, $type, $party, $docs]) {
            $base = "/$slug";
            $label = $type === 'RC' ? 'recibo de caja' : 'recibo de pago';
            $plural = $type === 'RC' ? 'recibos de caja' : 'recibos de pago';
            $example = $this->exampleReceipt($type);
            $this->op('get', $base, $tag, 'Listar '.$plural, 'Listado del más reciente al más antiguo.', [
                'parameters' => [...$this->pageParams(), $this->q('desde', 'Fecha inicial.', '2026-10-01', 'string', 'date'), $this->q('hasta', 'Fecha final.', '2026-10-31', 'string', 'date'),
                    $this->q('numero', 'Número.', $example['numero']), $this->q('tercero_id', 'Id del tercero.', null, 'integer'), $this->q('identificacion', 'Identificación del tercero.'), $this->q('estado', 'emitido o anulado.'), $this->q('creado_desde', 'Creados desde esta fecha u hora.')],
                'responses' => ['200' => $this->json($this->paged('Recibo'), ['data' => [array_diff_key($example, ['items' => 1])], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
            ]);
            $this->op('post', $base, $tag, 'Crear '.$label, "Registra un pago. Con `tipo: abono` (por defecto) indica en `documentos` las {$docs} que se pagan y cuánto a cada una (consulta los saldos en `GET /cuentas-por-".($type === 'RC' ? 'cobrar' : 'pagar').'`); puedes descontar retenciones por documento. Con `anticipo` u `otro` envía `valor`.'.($type === 'RP' ? ' Con `avanzado` envía `lineas` contables libres.' : ''), [
                'requestBody' => $this->body($type === 'RC' ? 'ReciboCajaEntrada' : 'ReciboPagoEntrada', $type === 'RC'
                    ? ['fecha' => '2026-10-20', 'cliente' => ['identificacion' => '900555123'], 'forma_pago_id' => 8, 'documentos' => [['documento_id' => 1201, 'valor' => 120000, 'retenciones' => [['impuesto_id' => 6, 'base' => 100840.34, 'valor' => 2521.01]]]], 'observaciones' => 'Pago transferencia']
                    : ['fecha' => '2026-10-20', 'proveedor_id' => 58, 'forma_pago_id' => 11, 'documentos' => [['documento_id' => 310, 'valor' => 357000]]]),
                'responses' => ['201' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Recibo')]], ['mensaje' => "Se creó {$example['numero']}.", 'data' => $example], 'Recibo creado'), '422' => $this->r('Validacion')],
            ]);
            $this->op('get', "$base/{id}", $tag, 'Ver '.$label, 'Detalle con los documentos abonados.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Recibo'), $example), '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('delete', "$base/{id}", $tag, 'Eliminar '.$label, 'Elimina el recibo y restablece el saldo de los documentos abonados.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => "Se eliminó {$example['numero']}; los saldos de los documentos abonados se restablecieron."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
            $this->op('post', "$base/{id}/anular", $tag, 'Anular '.$label, 'Anula el recibo conservando su número y restablece los saldos.', [
                'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Recibo')]], ['mensaje' => "Se anuló {$example['numero']}."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
            $this->op('get', "$base/{id}/pdf", $tag, 'Descargar PDF', 'Recibo en PDF.', [
                'parameters' => [$this->id()], 'responses' => ['200' => ['description' => 'Archivo PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]], '404' => $this->r('NoEncontrado')],
            ]);
            $this->op('post', "$base/{id}/correo", $tag, 'Enviar por correo', 'Envía el recibo en PDF al tercero o al correo indicado.', [
                'parameters' => [$this->id()],
                'requestBody' => ['required' => false, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['correo' => ['type' => 'string', 'format' => 'email']]]]]],
                'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => "Se envió {$example['numero']} a pagos@ejemplo.com."]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
            ]);
        }

        $journal = $this->exampleJournal();
        $tag = 'Comprobantes contables';
        $this->op('get', '/comprobantes', $tag, 'Listar comprobantes', 'Comprobantes contables elaborados (tipo CC). No incluye los asientos automáticos de facturas, recibos o nómina.', [
            'parameters' => [...$this->pageParams(), $this->q('desde', 'Fecha inicial.', '2026-10-01', 'string', 'date'), $this->q('hasta', 'Fecha final.', '2026-10-31', 'string', 'date'),
                $this->q('tipo_documento_id', 'Tipo de comprobante.', null, 'integer'), $this->q('numero', 'Número.', 'CC-1-15'), $this->q('estado', 'contabilizado o anulado.')],
            'responses' => ['200' => $this->json($this->paged('Comprobante'), ['data' => [array_diff_key($journal, ['lineas' => 1])], 'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1])],
        ]);
        $this->op('post', '/comprobantes', $tag, 'Crear comprobante', 'Crea un comprobante contable. Débitos y créditos deben sumar igual. Las cuentas que lo exigen piden tercero o centro de costo; las de cartera y proveedores permiten cruzar un documento (`cruzar_documento_id`) o crear un vencimiento nuevo (`vencimiento`), y las de inventario mueven existencias (`inventario`).', [
            'requestBody' => $this->body('ComprobanteEntrada', ['fecha' => '2026-10-31', 'observaciones' => 'Causación arriendo octubre', 'lineas' => [
                ['cuenta' => '512010', 'debito' => 2500000, 'tercero' => ['identificacion' => '79111222'], 'descripcion' => 'Arriendo oficina'],
                ['cuenta' => '23359501', 'credito' => 2500000, 'tercero' => ['identificacion' => '79111222'], 'vencimiento' => ['prefijo' => 'CC', 'numero' => 15, 'fecha' => '2026-11-05']],
            ]]),
            'responses' => ['201' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Comprobante')]], ['mensaje' => 'Se creó el comprobante CC-1-15.', 'data' => $journal], 'Comprobante creado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('get', '/comprobantes/{id}', $tag, 'Ver comprobante', 'Comprobante con sus líneas.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Comprobante'), $journal), '404' => $this->r('NoEncontrado')],
        ]);
        $this->op('delete', '/comprobantes/{id}', $tag, 'Eliminar comprobante', 'Elimina el comprobante y reversa sus efectos.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => 'Se eliminó el comprobante CC-1-15.']), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('post', '/comprobantes/{id}/anular', $tag, 'Anular comprobante', 'Anula el comprobante conservando su número.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Comprobante')]], ['mensaje' => 'Se anuló el comprobante CC-1-15.']), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
        ]);
    }

    private function reports(): void
    {
        $range = [$this->q('desde', 'Fecha inicial (por defecto 1 de enero del año actual).', '2026-01-01', 'string', 'date'), $this->q('hasta', 'Fecha final (por defecto hoy).', '2026-10-31', 'string', 'date'),
            $this->q('cuenta_desde', 'Código de cuenta inicial.', '1'), $this->q('cuenta_hasta', 'Código de cuenta final.', '9')];
        $row = fn (bool $byContact) => ['type' => 'object', 'properties' => [
            'desde' => ['type' => 'string', 'format' => 'date'], 'hasta' => ['type' => 'string', 'format' => 'date'], 'nivel' => ['type' => 'integer'],
            'data' => ['type' => 'array', 'items' => $this->ref($byContact ? 'FilaBalanceTercero' : 'FilaBalance')],
        ]];
        $this->op('get', '/reportes/balance-prueba', 'Reportes', 'Balance de prueba', 'Saldo inicial, débitos, créditos y saldo final por cuenta. Con `nivel` eliges el detalle: 1 clase, 2 grupo, 3 cuenta, 4 subcuenta (por defecto) o 5 auxiliar; incluye los niveles superiores como totales.', [
            'parameters' => [...$range, $this->q('nivel', 'Nivel de detalle de 1 a 5.', 4, 'integer'), $this->q('tipo', 'Solo movimientos de esta clase de documento (FV, RC, CC...).')],
            'responses' => ['200' => $this->json($row(false), ['desde' => '2026-01-01', 'hasta' => '2026-10-31', 'nivel' => 2, 'data' => [
                ['cuenta' => '11', 'nombre' => 'Efectivo y equivalentes de efectivo', 'nivel' => 2, 'saldo_inicial' => 5000000, 'debitos' => 18450000, 'creditos' => 15200000, 'saldo_final' => 8250000],
                ['cuenta' => '13', 'nombre' => 'Deudores comerciales y otras cuentas por cobrar', 'nivel' => 2, 'saldo_inicial' => 2300000, 'debitos' => 21420000, 'creditos' => 19800000, 'saldo_final' => 3920000],
            ]])],
        ]);
        $this->op('get', '/reportes/balance-prueba-terceros', 'Reportes', 'Balance de prueba por tercero', 'Igual que el balance de prueba pero cada cuenta se abre por tercero (por defecto a nivel auxiliar).', [
            'parameters' => [...$range, $this->q('nivel', 'Nivel de detalle de 1 a 5.', 5, 'integer'), $this->q('tercero', 'Identificación o nombre del tercero.', '900555123')],
            'responses' => ['200' => $this->json($row(true), ['desde' => '2026-01-01', 'hasta' => '2026-10-31', 'nivel' => 5, 'data' => [
                ['cuenta' => '13050501', 'nombre' => 'Clientes nacionales', 'nivel' => 5, 'identificacion' => '900555123', 'tercero' => 'DISTRIBUCIONES ANDINA S.A.S.', 'saldo_inicial' => 0, 'debitos' => 357000, 'creditos' => 120000, 'saldo_final' => 237000],
            ]])],
        ]);
        foreach (['cobrar' => ['cliente', 'Facturas de venta y notas débito con saldo pendiente por cobrar.', 'FV-1-1201', 'receivables'], 'pagar' => ['proveedor', 'Facturas de compra y documentos soporte con saldo pendiente por pagar.', 'FC-1-310', 'payables']] as $what => [$party, $desc, $number]) {
            $this->op('get', "/cuentas-por-$what", 'Reportes', 'Cuentas por '.$what, "$desc Ordenadas por vencimiento; `saldo_total` suma todas las páginas.", [
                'parameters' => [...$this->pageParams(), $this->q('tercero_id', 'Id del tercero.', null, 'integer'), $this->q('identificacion', 'Identificación del tercero.'),
                    $this->q('vencidas', 'Solo las vencidas a la fecha de corte.', null, 'boolean'), $this->q('fecha_corte', 'Fecha para calcular días vencidos (por defecto hoy).', '2026-10-31', 'string', 'date')],
                'responses' => ['200' => $this->json(['allOf' => [$this->paged('DocumentoPendiente'), ['type' => 'object', 'properties' => ['saldo_total' => ['type' => 'number']]]]], [
                    'data' => [['id' => 1201, 'tipo' => substr($number, 0, 2), 'numero' => $number, 'fecha' => '2026-09-15', 'vencimiento' => '2026-10-15', 'dias_vencido' => 16,
                        $party => ['id' => 42, 'identificacion' => '900555123', 'nombre' => 'DISTRIBUCIONES ANDINA S.A.S.'], 'total' => 357000, 'saldo' => 237000]],
                    'pagina' => 1, 'por_pagina' => 50, 'total' => 1, 'paginas' => 1, 'saldo_total' => 237000,
                ])],
            ]);
        }
    }

    private function webhooks(): void
    {
        $tag = 'Webhooks';
        $example = ['id' => 3, 'url' => 'https://tienda.example/webhooks/nubicta', 'eventos' => ['factura.creada', 'existencias.actualizadas'], 'activo' => true,
            'ultimo_envio' => '2026-10-20T09:15:02-05:00', 'ultimo_estado_http' => 200, 'ultimo_error' => null, 'fallas_seguidas' => 0, 'creado' => '2026-10-01T08:00:00-05:00'];
        $this->op('get', '/webhooks/eventos', $tag, 'Eventos disponibles', 'Lista de eventos a los que te puedes suscribir. Usa `*` para recibirlos todos.', [
            'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['evento' => ['type' => 'string'], 'descripcion' => ['type' => 'string']]]]]],
                ['data' => collect(Webhooks::EVENTS)->map(fn ($d, $e) => ['evento' => $e, 'descripcion' => $d])->values()->all()])],
        ]);
        $this->op('get', '/webhooks', $tag, 'Listar webhooks', 'Webhooks registrados con el resultado del último envío.', [
            'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $this->ref('Webhook')]]], ['data' => [$example]])],
        ]);
        $this->op('post', '/webhooks', $tag, 'Crear webhook', 'Registra una URL `https` pública (hasta 20 por empresa). La respuesta trae el `secreto` para verificar la firma; se muestra solo esta vez. El usuario de la llave necesita el permiso de parámetros de configuración.', [
            'requestBody' => $this->body('WebhookEntrada', ['url' => 'https://tienda.example/webhooks/nubicta', 'eventos' => ['factura.creada', 'existencias.actualizadas']]),
            'responses' => ['201' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => ['allOf' => [$this->ref('Webhook'), ['type' => 'object', 'properties' => ['secreto' => ['type' => 'string']]]]]]],
                ['mensaje' => 'Se creó el webhook. Guarda el "secreto": se muestra solo esta vez y sirve para verificar la firma.', 'data' => $example + ['secreto' => 'q8Zt3w...']], 'Webhook creado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('get', '/webhooks/{id}', $tag, 'Ver webhook', 'Datos y último resultado de envío.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Webhook'), $example), '404' => $this->r('NoEncontrado')],
        ]);
        $this->op('put', '/webhooks/{id}', $tag, 'Actualizar webhook', 'Cambia la URL, los eventos o lo activa/desactiva. Con `regenerar_secreto: true` genera un secreto nuevo.', [
            'parameters' => [$this->id()], 'requestBody' => $this->body('WebhookEntrada', ['eventos' => ['*'], 'activo' => true]),
            'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Webhook')]], ['mensaje' => 'Se actualizó el webhook.', 'data' => $example]), '404' => $this->r('NoEncontrado'), '422' => $this->r('Validacion')],
        ]);
        $this->op('delete', '/webhooks/{id}', $tag, 'Eliminar webhook', 'Deja de enviar eventos a esa URL.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json($this->ref('Mensaje'), ['mensaje' => 'Se eliminó el webhook.']), '404' => $this->r('NoEncontrado')],
        ]);
        $this->op('post', '/webhooks/{id}/probar', $tag, 'Enviar evento de prueba', 'Envía de inmediato el evento `prueba` y devuelve cómo respondió tu servidor.', [
            'parameters' => [$this->id()], 'responses' => ['200' => $this->json(['type' => 'object', 'properties' => ['mensaje' => ['type' => 'string'], 'data' => $this->ref('Webhook')]], ['mensaje' => 'La URL respondió HTTP 200.', 'data' => $example]), '422' => $this->r('Validacion')],
        ]);
        $this->paths['/webhooks']['post']['callbacks'] = ['evento' => ['{$request.body#/url}' => ['post' => [
            'summary' => 'Envío de un evento a tu URL',
            'parameters' => [
                ['name' => 'X-Nubicta-Evento', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'example' => 'factura.creada']],
                ['name' => 'X-Nubicta-Firma', 'in' => 'header', 'required' => true, 'description' => 'sha256=HMAC-SHA256 del cuerpo con el secreto del webhook.', 'schema' => ['type' => 'string']],
            ],
            'requestBody' => $this->body('EventoWebhook', ['evento' => 'existencias.actualizadas', 'id' => '0f5f1c1e-8f3a-4f0e-9b7e-2d6c9a1b4e55', 'fecha' => '2026-10-20T09:15:02-05:00',
                'data' => ['bodega_id' => 1, 'cantidad' => 41, 'producto' => ['id' => 15, 'codigo' => 'CAF-500', 'nombre' => 'Café molido 500 g', 'existencias' => 41]]]),
            'responses' => ['200' => ['description' => 'Responde 2xx para confirmar la recepción.'], '4XX' => ['description' => 'Nubicta reintenta el envío más tarde.']],
        ]]]];
    }

    private function saleCreateText(string $doc): string
    {
        return match ($doc) {
            'FV' => "Crea una factura de venta con la misma lógica de la pantalla: calcula impuestos y retenciones, contabiliza, descuenta inventario y registra la cartera.\n\n"
                ."* **Cliente:** `cliente_id` o `cliente: {\"identificacion\": \"900555123\"}`.\n* **Ítems:** por `producto_id` o `codigo`. Si no envías `precio` se toma el de la lista de precios del cliente; si no envías `impuesto_id`, el IVA del producto. `descuento` es porcentaje.\n"
                ."* Una línea puede ir sin producto: envía `descripcion`, `cantidad` y `precio`. No mueve inventario ni costo.\n"
                ."* **Mandato:** `mandante` (identificación y nombre; ciudad, régimen, correo y dirección si los tienes) y en cada ítem `rol`: `mandante` o `mandatario`. Si alguna línea es del mandante, la factura usa el perfil 11. Si todas son del mandatario, no envíes mandante: sigue en el perfil 10. Logo, teléfono, WhatsApp, sitio, atención y cuentas del mandante solo salen en el PDF.\n"
                ."* **Impuesto ZZ:** `\"impuesto\": \"ZZ\"` sin `impuesto_id`. El precio puede ser de centavos y negativo. No se combina con otro impuesto.\n"
                ."* **Pago:** `pagos` (deben sumar el total) o `credito: true`. Sin pagos queda a crédito. `\"forma_pago\": \"contado\"` es contado ya pagado: medio electrónico 1, vencimiento igual a la fecha, saldo cero y débito a la cuenta puente. No crea recibo de caja.\n"
                ."* **Referencia externa:** `referencia_externa` (1 a 120 caracteres, única por empresa y tipo). La primera vez responde 201. La misma clave y el mismo cuerpo responde 200, sin otro consecutivo y sin reenviar si la DIAN ya aceptó. La misma clave con otro cuerpo responde 409. Si quedó rechazado o pendiente, la repetición reenvía con el mismo número. El listado acepta `?referencia_externa=`.\n"
                ."* Si hay líneas de mandante y faltan las cuentas de mandato, responde 422 y no envía nada a la DIAN.\n"
                .'* **DIAN:** si el tipo es electrónico se envía de inmediato; la respuesta trae el resultado en `dian`. Envía `"enviar_dian": false` para dejarla como borrador y enviarla luego con `POST /facturas/{id}/dian`.',
            'NC' => "Crea una nota crédito. Si es electrónica indica la factura de este sistema (`factura_id` o `factura`, por ejemplo `FV-1-1201`) o `factura_externa` con `numero`, `cufe` y `fecha`. Las dos opciones no se combinan. Lleva `motivo` DIAN y puede ser parcial. Si la factura está aquí y el cuerpo no trae mandante, se copian el mandante y los roles. El perfil de la nota sigue en 20.\n\n"
                ."`referencia_externa` y `forma_pago` se comportan como en la factura. El aviso `nota_credito.dian` se envía cuando la nota queda aceptada o rechazada.",
            'ND' => 'Crea una nota débito sobre una factura (intereses, gastos o mayor valor). Lleva `motivo` DIAN y, si es electrónica, la factura (`factura_id` o `factura`).',
            default => 'Crea una cotización. Usa los mismos campos que la factura, sin pagos; no mueve contabilidad ni inventario.',
        };
    }

    // ---------------------------------------------------------------- esquemas

    private function schemas(): array
    {
        $s = fn (string $type, string $desc = '', mixed $example = null, array $extra = []) => array_filter(
            ['type' => $type, 'description' => $desc ?: null, 'example' => $example] + $extra + ($example === null ? ['nullable' => true] : []), fn ($v) => $v !== null);
        $party = fn (string $who) => ['type' => 'object', 'description' => "Alternativa a `{$who}_id`: busca el {$who} por identificación (sin DV).", 'properties' => [
            'identificacion' => $s('string', '', '900555123'), 'sucursal' => $s('integer', 'Por defecto 0.', 0),
        ], 'required' => ['identificacion']];
        $partyOut = ['type' => 'object', 'properties' => ['id' => $s('integer'), 'identificacion' => $s('string'), 'nombre' => $s('string')]];
        $pay = ['type' => 'object', 'required' => ['forma_pago_id', 'valor'], 'properties' => [
            'forma_pago_id' => $s('integer', 'Id de `GET /formas-pago`.', 8), 'valor' => $s('number', '', 23800), 'vencimiento' => $s('string', 'Para formas de pago a crédito.', '2026-11-19', ['format' => 'date']),
        ]];
        $common = [
            'tipo_documento_id' => $s('integer', 'Id de `GET /tipos-documento`. Si no lo envías se usa el primero activo (prefiriendo el electrónico).', 1),
            'fecha' => $s('string', 'Fecha de elaboración.', '2026-10-20', ['format' => 'date']),
            'items' => null,
            'pagos' => ['type' => 'array', 'items' => $pay, 'description' => 'Formas de pago; deben sumar el total. Sin pagos queda a crédito.'],
            'credito' => $s('boolean', 'Todo a crédito (ignora `pagos`).', false),
            'vencimiento' => $s('string', 'Vencimiento si es a crédito; por defecto la fecha más los días de crédito del tercero.', '2026-11-19', ['format' => 'date']),
            'retenciones' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids de retenciones sobre el total (ReteICA, ReteIVA...).', 'example' => [6]],
            'precios_con_iva' => $s('boolean', 'Los precios enviados ya incluyen IVA/impoconsumo.', false),
            'centro_costo_id' => $s('integer', '', null), 'bodega_id' => $s('integer', 'Bodega de los productos; por defecto la principal.', 1),
            'observaciones' => $s('string', '', 'Pedido web #10045'),
        ];
        $saleItem = ['type' => 'object', 'required' => ['cantidad'], 'properties' => [
            'producto_id' => $s('integer', 'Id del producto (o usa `codigo`).', 15), 'codigo' => $s('string', 'Código del producto.', 'CAF-500'),
            'descripcion' => $s('string', 'Por defecto el nombre del producto.'), 'cantidad' => $s('number', '', 2), 'precio' => $s('number', 'Precio unitario; por defecto el de la lista de precios.', 18000),
            'descuento' => $s('number', 'Porcentaje de descuento.', 0), 'impuesto_id' => $s('integer', 'IVA o impoconsumo; por defecto el del producto. `null` sin impuesto.', 1, ['nullable' => true]),
            'impuesto' => $s('string', 'ZZ cuando la línea no causa impuesto. No se combina con impuesto_id. El precio puede ser negativo.', 'ZZ', ['enum' => ['ZZ']]),
            'rol' => $s('string', 'En factura por mandato: mandante (del ISP) o mandatario (de la empresa).', 'mandatario', ['enum' => ['mandante', 'mandatario']]),
            'retencion_id' => $s('integer', 'Retención en la fuente del ítem.', null),
        ]];
        $sale = $common;
        $sale['items'] = ['type' => 'array', 'items' => $saleItem, 'minItems' => 1];
        $sale = ['cliente_id' => $s('integer', 'Id del cliente (o usa `cliente`).', 42), 'cliente' => $party('cliente')] + $sale + [
            'vendedor_id' => $s('integer', '', 2), 'lista_precio_id' => $s('integer', 'Lista para los precios por defecto; por defecto la del cliente.', 1),
            'enviar_dian' => $s('boolean', 'Enviar a la DIAN al crear si el tipo es electrónico.', true), 'enviar_correo' => $s('boolean', 'Enviar por correo al cliente (los electrónicos se envían al ser aceptados).', false),
            'referencia_externa' => $s('string', 'Clave de idempotencia, única por empresa y tipo (factura o nota crédito).', 'pedido-10045'),
            'forma_pago' => $s('string', 'contado: ya pagado, saldo cero, sin recibo de caja. Si se omite, queda el crédito de hoy.', 'contado', ['enum' => ['contado']]),
            'mandante' => ['type' => 'object', 'description' => 'Obligatorio si hay una línea con rol mandante. Logo, teléfonos, sitio, atención y cuentas solo van al PDF.', 'properties' => [
                'identificacion' => $s('string', 'NIT sin DV.', '900111222'), 'dv' => $s('string', '', '1'), 'tipo_documento' => $s('string', '31 si es NIT.', '31'),
                'nombre' => $s('string', '', 'ISP Ejemplo S.A.S.'), 'ciudad' => $s('string', '', 'Bogotá, D.C.'), 'regimen' => $s('string', '', 'Responsable de IVA'),
                'correo' => $s('string', '', 'facturacion@ispejemplo.test'), 'direccion' => $s('string', '', 'Calle 10 # 20-30'),
                'logo_url' => $s('string'), 'telefono' => $s('string'), 'whatsapp' => $s('string'), 'sitio' => $s('string'), 'atencion' => $s('string', 'Texto de atención al cliente, solo en el PDF.'),
                'cuentas' => ['type' => 'array', 'items' => $this->props(['banco' => 'string', 'tipo' => 'string', 'numero' => 'string'])],
            ]],
        ];
        $purchaseItem = ['type' => 'object', 'required' => ['cantidad'], 'properties' => [
            'producto_id' => $s('integer', 'Producto (entra al inventario).', 15), 'codigo' => $s('string', 'Código del producto.', 'CAF-500'),
            'cuenta' => $s('string', 'Código PUC de gasto o costo, en vez de producto.', '513530'), 'cuenta_id' => $s('integer', 'Id de la cuenta.'),
            'activo_fijo_id' => $s('integer', 'Activo fijo que se compra.'), 'descripcion' => $s('string', '', 'Mantenimiento equipos'),
            'cantidad' => $s('number', '', 10), 'precio' => $s('number', 'Costo unitario.', 12000), 'descuento' => $s('number', 'Porcentaje (o valor si `descuento_en_porcentaje` es false).', 0),
            'impuesto_id' => $s('integer', 'IVA o impoconsumo descontable.', 1, ['nullable' => true]), 'retencion_id' => $s('integer', 'Retención en la fuente que practicas.', 4),
        ]];
        $purchase = $common;
        $purchase['items'] = ['type' => 'array', 'items' => $purchaseItem, 'minItems' => 1];
        $purchase = ['proveedor_id' => $s('integer', 'Id del proveedor (o usa `proveedor`).', 58), 'proveedor' => $party('proveedor'),
            'factura_proveedor' => ['type' => 'object', 'properties' => ['prefijo' => $s('string', '', 'FE'), 'numero' => $s('string', '', '4581')], 'description' => 'Número de la factura del proveedor.'],
        ] + $purchase + [
            'descuento_en_porcentaje' => $s('boolean', '', true), 'enviar_dian' => $s('boolean', 'Solo documentos soporte electrónicos.', true),
        ];
        $receiptDocs = ['type' => 'array', 'description' => 'Documentos que se abonan (modo `abono`).', 'items' => ['type' => 'object', 'required' => ['documento_id', 'valor'], 'properties' => [
            'documento_id' => $s('integer', 'Id del documento (ver `GET /cuentas-por-cobrar` o `/cuentas-por-pagar`).', 1201),
            'valor' => $s('number', 'Valor pagado a ese documento.', 120000),
            'retenciones' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['impuesto_id', 'valor'], 'properties' => ['impuesto_id' => $s('integer', '', 6), 'base' => $s('number'), 'valor' => $s('number')]]],
            'descuentos' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['concepto_id', 'valor'], 'properties' => ['concepto_id' => $s('integer'), 'valor' => $s('number')]]],
        ]]];
        $receipt = fn (string $who, bool $advanced) => ['type' => 'object', 'required' => ['fecha'], 'properties' => [
            'tipo_documento_id' => $s('integer', 'Por defecto el primero activo.'), 'fecha' => $s('string', '', '2026-10-20', ['format' => 'date']),
            "{$who}_id" => $s('integer', "Id del {$who} (o usa `{$who}`)."), $who => $party($who),
            'tipo' => $s('string', 'Modalidad.', 'abono', ['enum' => $advanced ? ['abono', 'anticipo', 'otro', 'avanzado'] : ['abono', 'anticipo', 'otro']]),
            'forma_pago_id' => $s('integer', $advanced ? 'De dónde sale el dinero (o usa `cuenta_bancaria_id`).' : 'Forma de pago con la que entra el dinero.', 8),
            ...($advanced ? ['cuenta_bancaria_id' => $s('integer', 'Banco o caja de donde sale el dinero.')] : []),
            'documentos' => $receiptDocs, 'valor' => $s('number', 'Total del recibo; en `abono` por defecto la suma de los documentos.'),
            'concepto_id' => $s('integer', 'Concepto para el modo `otro`.'),
            ...($advanced ? ['lineas' => ['type' => 'array', 'description' => 'Modo `avanzado`: líneas contables.', 'items' => ['type' => 'object', 'properties' => [
                'cuenta' => $s('string', '', '236540'), 'cuenta_id' => $s('integer'), 'debito' => $s('number'), 'credito' => $s('number'), 'documento_id' => $s('integer', 'Compra que se abona con la línea.'), 'descripcion' => $s('string'),
            ]]]] : []),
            'observaciones' => $s('string'),
        ]];
        $documentOut = fn (string $who, array $extra) => ['type' => 'object', 'properties' => [
            'id' => $s('integer'), 'tipo' => $s('string'), 'tipo_documento_id' => $s('integer'), 'numero' => $s('string', 'Prefijo, código y consecutivo.'), 'consecutivo' => $s('integer'),
            'fecha' => $s('string', '', null, ['format' => 'date']), 'vencimiento' => $s('string', '', null, ['format' => 'date']), $who => $partyOut,
            ...$extra,
            'subtotal' => $s('number'), 'descuento' => $s('number'), 'impuestos' => $s('number'), 'retenciones' => $s('number'), 'total' => $s('number'), 'saldo' => $s('number', 'Pendiente por cobrar o pagar.'),
            'observaciones' => $s('string'), 'estado' => $s('string', '', null, ['enum' => ['emitido', 'anulado']]),
            'estado_dian' => $s('string', 'Draft, Pending, Accepted o Rejected; null si no es electrónico.', null, ['nullable' => true]), 'mensaje_dian' => $s('string', '', null, ['nullable' => true]),
            'creado' => $s('string', '', null, ['format' => 'date-time']), 'actualizado' => $s('string', '', null, ['format' => 'date-time']),
        ]];

        $schemas = [
            'Mensaje' => ['type' => 'object', 'properties' => ['mensaje' => $s('string')]],
            'Error' => ['type' => 'object', 'properties' => ['message' => $s('string', '', 'No existe la factura con id 99.')]],
            'ErrorValidacion' => ['type' => 'object', 'properties' => ['message' => $s('string'), 'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]]]],
            'Paginado' => ['type' => 'object', 'properties' => ['pagina' => $s('integer', '', 1), 'por_pagina' => $s('integer', '', 50), 'total' => $s('integer', 'Total de registros.', 134), 'paginas' => $s('integer', '', 3)]],

            'TipoDocumento' => $this->props(['id' => 'integer', 'tipo' => 'string', 'codigo' => 'string', 'nombre' => 'string', 'prefijo' => 'string', 'consecutivo' => 'integer', 'electronico' => 'boolean',
                'numeracion_automatica' => 'boolean', 'centro_costo_obligatorio' => 'boolean', 'resolucion' => 'object', 'activo' => 'boolean']),
            'FormaPago' => $this->props(['id' => 'integer', 'nombre' => 'string', 'tipo' => 'string', 'codigo_dian' => 'string', 'cuenta_id' => 'integer', 'maneja_vencimiento' => 'boolean', 'tipos_documento' => 'array']),
            'Impuesto' => $this->props(['id' => 'integer', 'codigo' => 'integer', 'nombre' => 'string', 'tipo' => 'string', 'porcentaje' => 'number', 'valor_fijo' => 'number', 'base_minima' => 'number', 'codigo_dian' => 'string']),
            'ListaPrecio' => $this->props(['id' => 'integer', 'posicion' => 'integer', 'nombre' => 'string']),
            'Bodega' => $this->props(['id' => 'integer', 'nombre' => 'string', 'direccion' => 'string', 'responsable' => 'string', 'principal' => 'boolean']),
            'Usuario' => $this->props(['id' => 'integer', 'nombre' => 'string', 'identificacion' => 'string', 'correo' => 'string', 'vendedor' => 'boolean']),
            'Vendedor' => $this->props(['id' => 'integer', 'nombre' => 'string', 'identificacion' => 'string', 'correo' => 'string', 'comision' => 'number', 'usuario_id' => 'integer']),
            'CentroCosto' => $this->props(['id' => 'integer', 'codigo' => 'string', 'nombre' => 'string', 'padre_id' => 'integer']),
            'GrupoInventario' => $this->props(['id' => 'integer', 'nombre' => 'string', 'cuenta_inventario_id' => 'integer', 'cuenta_ingreso_id' => 'integer', 'cuenta_costo_id' => 'integer']),
            'ActivoFijo' => $this->props(['id' => 'integer', 'codigo' => 'string', 'nombre' => 'string', 'grupo_id' => 'integer']),
            'Cuenta' => $this->props(['id' => 'integer', 'codigo' => 'string', 'nombre' => 'string', 'nivel' => 'integer', 'naturaleza' => 'string', 'movimiento' => 'boolean', 'requiere_tercero' => 'boolean', 'requiere_centro_costo' => 'boolean', 'detalle' => 'string']),
            'Ciudad' => $this->props(['codigo' => 'string', 'departamento' => 'string', 'nombre' => 'string']),
            'Concepto' => $this->props(['id' => 'integer', 'tipo' => 'string', 'nombre' => 'string', 'cuenta_id' => 'integer']),
            'CuentaBancaria' => $this->props(['id' => 'integer', 'nombre' => 'string', 'tipo' => 'string', 'banco' => 'string', 'cuenta_id' => 'integer']),

            'Tercero' => $this->props([
                'id' => 'integer', 'tipo_persona' => 'string', 'tipo_identificacion' => 'string', 'identificacion' => 'string', 'dv' => 'string', 'sucursal' => 'integer', 'nombre' => 'string',
                'nombres' => 'string', 'apellidos' => 'string', 'razon_social' => 'string', 'nombre_comercial' => 'string', 'correo' => 'string', 'telefono' => 'string', 'direccion' => 'string',
                'ciudad' => 'string', 'codigo_postal' => 'string', 'responsable_iva' => 'boolean', 'responsabilidades_fiscales' => 'array', 'roles' => 'array', 'vendedor_id' => 'integer',
                'lista_precio_id' => 'integer', 'cupo_credito' => 'number', 'dias_credito' => 'integer', 'observaciones' => 'string', 'activo' => 'boolean', 'creado' => 'string', 'actualizado' => 'string',
            ]),
            'TerceroEntrada' => ['type' => 'object', 'required' => ['tipo_persona', 'tipo_identificacion', 'identificacion'], 'properties' => [
                'tipo_persona' => $s('string', '', 'juridica', ['enum' => ['natural', 'juridica']]),
                'tipo_identificacion' => $s('string', $this->enumText(config('erp.identification_types', [])), '31', ['enum' => array_map('strval', array_keys(config('erp.identification_types', [])))]),
                'identificacion' => $s('string', 'Sin dígito de verificación.', '900555123'), 'sucursal' => $s('integer', 'Por defecto 0.', 0),
                'nombres' => $s('string', 'Obligatorio para persona natural.'), 'apellidos' => $s('string', 'Obligatorio para persona natural.'),
                'razon_social' => $s('string', 'Obligatoria para persona jurídica.', 'Distribuciones Andina S.A.S.'), 'nombre_comercial' => $s('string'),
                'responsable_iva' => $s('boolean', '', true),
                'responsabilidades_fiscales' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(config('erp.fiscal_responsibilities', []))], 'description' => $this->enumText(config('erp.fiscal_responsibilities', []))],
                'direccion' => $s('string'), 'ciudad' => $s('string', 'Código DANE de 5 dígitos (`GET /ciudades`).', '11001'), 'codigo_postal' => $s('string'),
                'indicativo' => $s('string'), 'telefono_fijo' => $s('string'), 'telefono' => $s('string', 'Celular.', '3001234567'), 'correo' => $s('string', 'Correo para facturación electrónica.', 'compras@andina.example', ['format' => 'email']),
                'vendedor_id' => $s('integer'), 'cobrador_id' => $s('integer'), 'lista_precio_id' => $s('integer'), 'cupo_credito' => $s('number'), 'dias_credito' => $s('integer', '', 30),
                'observaciones' => $s('string'), 'activo' => $s('boolean', '', true),
                'roles' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(Contact::ROLES)], 'description' => 'Por defecto `["cliente"]`.'],
            ]],
            'Producto' => $this->props([
                'id' => 'integer', 'codigo' => 'string', 'nombre' => 'string', 'tipo' => 'string', 'grupo_inventario_id' => 'integer', 'referencia' => 'string', 'descripcion' => 'string',
                'codigo_barras' => 'string', 'marca' => 'string', 'modelo' => 'string', 'unidad_dian' => 'string', 'unidad' => 'string', 'inventariable' => 'boolean', 'visible_en_ventas' => 'boolean',
                'clasificacion_tributaria' => 'string', 'iva_incluido' => 'boolean', 'arancel' => 'string', 'existencias' => 'number', 'existencias_por_bodega' => 'array', 'existencia_minima' => 'number',
                'costo_promedio' => 'number', 'precios' => 'array', 'impuestos' => 'array', 'activo' => 'boolean', 'creado' => 'string', 'actualizado' => 'string',
            ]),
            'ProductoEntrada' => ['type' => 'object', 'required' => ['codigo', 'nombre', 'grupo_inventario_id'], 'properties' => [
                'codigo' => $s('string', 'Único en la empresa.', 'CAF-500'), 'nombre' => $s('string', '', 'Café molido 500 g'),
                'tipo' => $s('string', '', 'producto', ['enum' => array_keys(ApiPresenter::PRODUCT_TYPES)]),
                'grupo_inventario_id' => $s('integer', 'Id de `GET /grupos-inventario`.', 3), 'unidad_dian' => $s('string', 'Código de unidad DIAN; por defecto 94 (unidad).', '94'),
                'inventariable' => $s('boolean', 'Controla existencias (solo productos).', true), 'visible_en_ventas' => $s('boolean', '', true),
                'clasificacion_tributaria' => $s('string', '', 'gravado', ['enum' => array_keys(ApiPresenter::TAX_CLASSES)]),
                'iva_incluido' => $s('boolean', 'El precio de venta incluye IVA.', false), 'referencia' => $s('string'), 'descripcion' => $s('string'), 'codigo_barras' => $s('string'),
                'marca' => $s('string'), 'modelo' => $s('string'), 'arancel' => $s('string'), 'existencia_minima' => $s('number'), 'activo' => $s('boolean', '', true),
                'impuestos' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids de `GET /impuestos`.', 'example' => [1]],
                'precios' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['lista_precio_id' => $s('integer', '', 1), 'valor' => $s('number', '', 18000)]]],
                'componentes' => ['type' => 'array', 'description' => 'Obligatorio para combos.', 'items' => ['type' => 'object', 'properties' => ['producto_id' => $s('integer'), 'cantidad' => $s('number')]]],
            ]],
            'Existencia' => $this->props(['producto_id' => 'integer', 'codigo' => 'string', 'nombre' => 'string', 'bodega_id' => 'integer', 'bodega' => 'string', 'cantidad' => 'number']),

            'VentaEntrada' => ['type' => 'object', 'required' => ['fecha', 'items'], 'properties' => $sale],
            'NotaEntrada' => ['type' => 'object', 'required' => ['fecha', 'items', 'motivo'], 'properties' => [
                'factura_id' => $s('integer', 'Factura de este sistema. Excluyente con factura_externa.', 1201), 'factura' => $s('string', 'Alternativa: número de la factura.', 'FV-1-1201'),
                'factura_externa' => ['type' => 'object', 'description' => 'Factura que no está en Nubicta. Excluyente con factura_id. Solo nota crédito.', 'properties' => [
                    'numero' => $s('string', '', 'FE123'), 'cufe' => $s('string', '', 'a1b2c3d4e5f6'), 'fecha' => $s('string', '', '2026-10-01', ['format' => 'date']),
                ]],
                'motivo' => $s('string', 'Código del motivo DIAN (ver la tabla de motivos).', '1'),
            ] + $sale],
            'Venta' => $documentOut('cliente', [
                'vendedor_id' => $s('integer'), 'centro_costo_id' => $s('integer'), 'lista_precio_id' => $s('integer'),
                'documento_relacionado' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $s('integer'), 'numero' => $s('string')]], 'motivo' => $s('string', '', null, ['nullable' => true]),
                'referencia_externa' => $s('string', '', null, ['nullable' => true]), 'forma_pago' => $s('string', '', null, ['nullable' => true]),
                'mandante' => ['type' => 'object', 'nullable' => true, 'properties' => ['identificacion' => $s('string'), 'nombre' => $s('string')]],
                'factura_externa' => ['type' => 'object', 'nullable' => true, 'properties' => ['numero' => $s('string'), 'cufe' => $s('string'), 'fecha' => $s('string')]],
                'cufe' => $s('string', '', null, ['nullable' => true]), 'estado_correo' => $s('string', '', null, ['nullable' => true]),
                'items' => ['type' => 'array', 'items' => $this->props(['producto_id' => 'integer', 'codigo' => 'string', 'descripcion' => 'string', 'cantidad' => 'number', 'precio' => 'number', 'descuento' => 'number',
                    'subtotal' => 'number', 'impuestos' => 'number', 'porcentaje_impuesto' => 'number', 'total' => 'number', 'bodega_id' => 'integer', 'vendedor_id' => 'integer', 'rol' => 'string', 'impuesto' => 'string'])],
                'pagos' => ['type' => 'array', 'items' => $this->props(['forma_pago_id' => 'integer', 'nombre' => 'string', 'valor' => 'number', 'vencimiento' => 'string'])],
                'retenciones_detalle' => ['type' => 'array', 'items' => $this->props(['impuesto_id' => 'integer', 'nombre' => 'string', 'tipo' => 'string', 'porcentaje' => 'number', 'base' => 'number', 'valor' => 'number'])],
            ]),
            'VentaCreada' => ['type' => 'object', 'properties' => ['mensaje' => $s('string'), 'dian' => $s('string', 'Resultado del envío a la DIAN; null si no es electrónico.', null, ['nullable' => true]), 'data' => $this->ref('Venta')]],
            'EstadoDian' => ['type' => 'object', 'properties' => [
                'id' => $s('integer'), 'numero' => $s('string'), 'estado_dian' => $s('string', '', null, ['enum' => ['Draft', 'Pending', 'Accepted', 'Rejected']]), 'mensaje_dian' => $s('string'), 'cufe' => $s('string'),
                'errores' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Reglas de validación de la DIAN que fallaron en el último envío.'],
                'transmisiones' => ['type' => 'array', 'items' => $this->props(['operacion' => 'string', 'ambiente' => 'string', 'simulado' => 'boolean', 'track_id' => 'string', 'valido' => 'boolean', 'codigo' => 'string', 'mensaje' => 'string', 'errores' => 'array', 'fecha' => 'string'])],
            ]],
            'CompraEntrada' => ['type' => 'object', 'required' => ['fecha', 'items'], 'properties' => $purchase],
            'Compra' => $documentOut('proveedor', [
                'factura_proveedor' => ['type' => 'object', 'properties' => ['prefijo' => $s('string'), 'numero' => $s('string')]], 'centro_costo_id' => $s('integer'),
                'documento_relacionado' => ['type' => 'object', 'nullable' => true, 'properties' => ['id' => $s('integer'), 'numero' => $s('string')]], 'cuds' => $s('string', 'CUDS del documento soporte electrónico.', null, ['nullable' => true]),
                'items' => ['type' => 'array', 'items' => $this->props(['tipo' => 'string', 'producto_id' => 'integer', 'cuenta_id' => 'integer', 'codigo' => 'string', 'descripcion' => 'string', 'cantidad' => 'number', 'precio' => 'number',
                    'descuento' => 'number', 'subtotal' => 'number', 'impuestos' => 'number', 'porcentaje_impuesto' => 'number', 'total' => 'number', 'bodega_id' => 'integer'])],
                'pagos' => ['type' => 'array', 'items' => $this->props(['forma_pago_id' => 'integer', 'nombre' => 'string', 'valor' => 'number', 'vencimiento' => 'string'])],
            ]),
            'CompraCreada' => ['type' => 'object', 'properties' => ['mensaje' => $s('string'), 'dian' => $s('string', '', null, ['nullable' => true]), 'data' => $this->ref('Compra')]],
            'ReciboCajaEntrada' => $receipt('cliente', false),
            'ReciboPagoEntrada' => $receipt('proveedor', true),
            'Recibo' => ['type' => 'object', 'properties' => [
                'id' => $s('integer'), 'tipo' => $s('string', 'RC o RP.'), 'modalidad' => $s('string', '', null, ['enum' => ['abono', 'anticipo', 'otro', 'avanzado']]), 'tipo_documento_id' => $s('integer'),
                'numero' => $s('string'), 'consecutivo' => $s('integer'), 'fecha' => $s('string', '', null, ['format' => 'date']), 'tercero' => $partyOut,
                'forma_pago' => ['type' => 'object', 'properties' => ['id' => $s('integer'), 'nombre' => $s('string')]], 'total' => $s('number'), 'observaciones' => $s('string'),
                'estado' => $s('string', '', null, ['enum' => ['emitido', 'anulado']]), 'creado' => $s('string', '', null, ['format' => 'date-time']),
                'items' => ['type' => 'array', 'items' => $this->props(['documento_id' => 'integer', 'documento' => 'string', 'cuenta_id' => 'integer', 'descripcion' => 'string', 'valor' => 'number', 'ajustes' => 'number'])],
            ]],
            'ComprobanteEntrada' => ['type' => 'object', 'required' => ['fecha', 'lineas'], 'properties' => [
                'tipo_documento_id' => $s('integer', 'Tipo CC; por defecto el primero activo.'), 'fecha' => $s('string', '', '2026-10-31', ['format' => 'date']), 'observaciones' => $s('string'),
                'lineas' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'object', 'properties' => [
                    'cuenta' => $s('string', 'Código PUC (o usa `cuenta_id`).', '512010'), 'cuenta_id' => $s('integer'),
                    'tercero_id' => $s('integer'), 'tercero' => $party('tercero'), 'descripcion' => $s('string'),
                    'debito' => $s('number', '', 2500000), 'credito' => $s('number', '', 0),
                    'cruzar_documento_id' => $s('integer', 'Cuentas de cartera o proveedores: documento cuyo saldo se cruza.'),
                    'vencimiento' => ['type' => 'object', 'description' => 'Cuentas de cartera o proveedores: crea un vencimiento nuevo.', 'properties' => [
                        'prefijo' => $s('string', 'FV, FC, RC, RP o CC.', 'CC'), 'numero' => $s('integer', '', 15), 'cuota' => $s('integer', '', 1), 'fecha' => $s('string', '', '2026-11-05', ['format' => 'date']),
                    ]],
                    'inventario' => ['type' => 'object', 'description' => 'Cuentas de inventario: mueve existencias.', 'properties' => [
                        'producto_id' => $s('integer'), 'codigo' => $s('string'), 'cantidad' => $s('number'), 'tipo' => $s('string', '', 'entrada', ['enum' => ['entrada', 'salida']]),
                    ]],
                ]]],
            ]],
            'Comprobante' => ['type' => 'object', 'properties' => [
                'id' => $s('integer'), 'tipo_documento_id' => $s('integer'), 'numero' => $s('string'), 'consecutivo' => $s('integer'), 'fecha' => $s('string', '', null, ['format' => 'date']),
                'observaciones' => $s('string'), 'debitos' => $s('number'), 'creditos' => $s('number'), 'estado' => $s('string', '', null, ['enum' => ['contabilizado', 'anulado']]), 'creado' => $s('string', '', null, ['format' => 'date-time']),
                'lineas' => ['type' => 'array', 'items' => $this->props(['cuenta_id' => 'integer', 'cuenta' => 'string', 'tercero_id' => 'integer', 'centro_costo_id' => 'integer', 'descripcion' => 'string',
                    'debito' => 'number', 'credito' => 'number', 'cruce' => 'object', 'producto_id' => 'integer', 'cantidad' => 'number'])],
            ]],
            'FilaBalance' => $this->props(['cuenta' => 'string', 'nombre' => 'string', 'nivel' => 'integer', 'saldo_inicial' => 'number', 'debitos' => 'number', 'creditos' => 'number', 'saldo_final' => 'number']),
            'FilaBalanceTercero' => $this->props(['cuenta' => 'string', 'nombre' => 'string', 'nivel' => 'integer', 'identificacion' => 'string', 'tercero' => 'string', 'saldo_inicial' => 'number', 'debitos' => 'number', 'creditos' => 'number', 'saldo_final' => 'number']),
            'DocumentoPendiente' => ['type' => 'object', 'properties' => [
                'id' => $s('integer', 'Úsalo como `documento_id` en recibos.'), 'tipo' => $s('string'), 'numero' => $s('string'), 'fecha' => $s('string', '', null, ['format' => 'date']),
                'vencimiento' => $s('string', '', null, ['format' => 'date']), 'dias_vencido' => $s('integer'), 'cliente' => $partyOut, 'proveedor' => $partyOut, 'total' => $s('number'), 'saldo' => $s('number'),
            ]],
            'Webhook' => $this->props(['id' => 'integer', 'url' => 'string', 'eventos' => 'array', 'activo' => 'boolean', 'ultimo_envio' => 'string', 'ultimo_estado_http' => 'integer', 'ultimo_error' => 'string', 'fallas_seguidas' => 'integer', 'creado' => 'string']),
            'WebhookEntrada' => ['type' => 'object', 'properties' => [
                'url' => $s('string', 'URL https pública.', 'https://tienda.example/webhooks/nubicta', ['format' => 'uri']),
                'eventos' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => [...array_keys(Webhooks::EVENTS), '*']]],
                'activo' => $s('boolean', '', true), 'regenerar_secreto' => $s('boolean', 'Solo al actualizar.', false),
            ]],
            'EventoWebhook' => ['type' => 'object', 'properties' => [
                'evento' => $s('string', '', 'factura.creada', ['enum' => [...array_keys(Webhooks::EVENTS), 'prueba']]), 'id' => $s('string', 'Id único del envío; úsalo para ignorar duplicados.', null, ['format' => 'uuid']),
                'fecha' => $s('string', '', null, ['format' => 'date-time']), 'data' => ['type' => 'object', 'description' => 'El recurso con la misma forma que en `GET` (tercero, producto, factura, recibo...). En existencias: `bodega_id`, `cantidad` y `producto`.'],
            ]],
        ];
        foreach (['TerceroEntrada' => 'TerceroCambios', 'ProductoEntrada' => 'ProductoCambios'] as $from => $to) {
            $schemas[$to] = array_diff_key($schemas[$from], ['required' => 1]) + ['description' => 'Solo los campos que cambian.'];
        }

        return $schemas;
    }

    private function responses(): array
    {
        $err = fn (string $desc, string $msg) => ['description' => $desc, 'content' => ['application/json' => ['schema' => $this->ref('Error'), 'example' => ['message' => $msg]]]];

        return [
            'NoAutorizado' => $err('Llave ausente, inválida o revocada.', 'Llave de acceso inválida o revocada.'),
            'Prohibido' => $err('API deshabilitada, IP no permitida, llave de solo lectura o usuario sin permiso.', 'La llave solo tiene permiso de lectura.'),
            'NoEncontrado' => $err('El registro no existe en tu empresa.', 'No existe la factura con id 99.'),
            'Conflicto' => $err('El estado del documento no permite la operación.', 'La DIAN ya aceptó este documento electrónico: para reversarlo elabora una nota crédito.'),
            'Limite' => $err('Superaste 120 peticiones por minuto.', 'Too Many Attempts.'),
            'Validacion' => ['description' => 'Datos inválidos.', 'content' => ['application/json' => ['schema' => $this->ref('ErrorValidacion'), 'example' => [
                'message' => 'Indica el cliente con "cliente_id" o con "cliente": {"identificacion": "..."}.', 'errors' => ['cliente' => ['Indica el cliente con "cliente_id" o con "cliente": {"identificacion": "..."}.']],
            ]]]],
        ];
    }

    // ---------------------------------------------------------------- ejemplos ficticios

    private function exampleContact(): array
    {
        return ['id' => 42, 'tipo_persona' => 'juridica', 'tipo_identificacion' => '31', 'identificacion' => '900555123', 'dv' => '1', 'sucursal' => 0, 'nombre' => 'DISTRIBUCIONES ANDINA S.A.S.',
            'nombres' => null, 'apellidos' => null, 'razon_social' => 'DISTRIBUCIONES ANDINA S.A.S.', 'nombre_comercial' => null, 'correo' => 'compras@andina.example', 'telefono' => '3001234567',
            'direccion' => 'Carrera 7 # 45-10', 'ciudad' => '11001', 'codigo_postal' => null, 'responsable_iva' => true, 'responsabilidades_fiscales' => ['O-13'], 'roles' => ['cliente'],
            'vendedor_id' => null, 'lista_precio_id' => 1, 'cupo_credito' => 0, 'dias_credito' => 30, 'observaciones' => null, 'activo' => true,
            'creado' => '2026-10-01T08:30:00-05:00', 'actualizado' => '2026-10-01T08:30:00-05:00'];
    }

    private function exampleProduct(): array
    {
        return ['id' => 15, 'codigo' => 'CAF-500', 'nombre' => 'Café molido 500 g', 'tipo' => 'producto', 'grupo_inventario_id' => 3, 'referencia' => 'CM500', 'descripcion' => null,
            'codigo_barras' => '7700000000017', 'marca' => null, 'modelo' => null, 'unidad_dian' => '94', 'unidad' => 'unidad', 'inventariable' => true, 'visible_en_ventas' => true,
            'clasificacion_tributaria' => 'gravado', 'iva_incluido' => false, 'arancel' => null, 'existencias' => 42, 'existencias_por_bodega' => [['bodega_id' => 1, 'bodega' => 'Principal', 'cantidad' => 42]],
            'existencia_minima' => 5, 'costo_promedio' => 11250, 'precios' => [['lista_precio_id' => 1, 'valor' => 18000], ['lista_precio_id' => 2, 'valor' => 16500]],
            'impuestos' => [['id' => 1, 'nombre' => 'IVA 19%', 'tipo' => 'IVA', 'porcentaje' => 19]], 'activo' => true,
            'creado' => '2026-09-10T10:00:00-05:00', 'actualizado' => '2026-10-18T16:20:00-05:00'];
    }

    private function exampleSaleInput(string $doc): array
    {
        $base = ['fecha' => '2026-10-20', 'cliente' => ['identificacion' => '900555123'], 'items' => [['codigo' => 'CAF-500', 'cantidad' => 2], ['producto_id' => 16, 'cantidad' => 1, 'precio' => 12000, 'descuento' => 10]]];

        return match ($doc) {
            'FV' => ['tipo_documento_id' => 1] + $base + ['pagos' => [['forma_pago_id' => 8, 'valor' => 55692]], 'observaciones' => 'Pedido web #10045'],
            'NC' => ['fecha' => '2026-10-22', 'cliente_id' => 42, 'factura' => 'FV-1-1201', 'motivo' => '1', 'items' => [['codigo' => 'CAF-500', 'cantidad' => 1]]],
            'ND' => ['fecha' => '2026-10-25', 'cliente_id' => 42, 'factura_id' => 1201, 'motivo' => '1', 'items' => [['codigo' => 'INT-MORA', 'cantidad' => 1, 'precio' => 15000, 'impuesto_id' => null]]],
            default => $base + ['observaciones' => 'Validez 15 días'],
        };
    }

    private function exampleSale(string $doc): array
    {
        $number = ['FV' => 'FV-1-1201', 'NC' => 'NC-1-87', 'ND' => 'ND-1-12', 'CT' => 'CT-1-340'][$doc];
        $note = in_array($doc, ['NC', 'ND'], true);

        return ['id' => ['FV' => 1201, 'NC' => 1210, 'ND' => 1215, 'CT' => 980][$doc], 'tipo' => $doc, 'tipo_documento_id' => 1, 'numero' => $number, 'consecutivo' => (int) substr($number, strrpos($number, '-') + 1),
            'fecha' => '2026-10-20', 'vencimiento' => '2026-10-20', 'cliente' => ['id' => 42, 'identificacion' => '900555123', 'nombre' => 'DISTRIBUCIONES ANDINA S.A.S.'],
            'vendedor_id' => null, 'centro_costo_id' => null, 'lista_precio_id' => 1,
            'documento_relacionado' => $note ? ['id' => 1201, 'numero' => 'FV-1-1201'] : null, 'motivo' => $note ? '1' : null,
            'subtotal' => 46800, 'descuento' => 1200, 'impuestos' => 8892, 'retenciones' => 0, 'total' => 55692, 'saldo' => 0, 'observaciones' => $doc === 'FV' ? 'Pedido web #10045' : null,
            'estado' => 'emitido', 'cufe' => $doc === 'CT' ? null : 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d',
            'estado_dian' => $doc === 'CT' ? null : 'Accepted', 'mensaje_dian' => $doc === 'CT' ? null : 'Procesado Correctamente.', 'estado_correo' => null,
            'creado' => '2026-10-20T11:42:10-05:00', 'actualizado' => '2026-10-20T11:42:14-05:00',
            'items' => [
                ['producto_id' => 15, 'codigo' => 'CAF-500', 'descripcion' => 'Café molido 500 g', 'cantidad' => 2, 'precio' => 18000, 'descuento' => 0, 'subtotal' => 36000, 'impuestos' => 6840, 'porcentaje_impuesto' => 19, 'total' => 42840, 'bodega_id' => 1, 'vendedor_id' => null],
                ['producto_id' => 16, 'codigo' => 'FIL-100', 'descripcion' => 'Filtros de papel x100', 'cantidad' => 1, 'precio' => 12000, 'descuento' => 1200, 'subtotal' => 10800, 'impuestos' => 2052, 'porcentaje_impuesto' => 19, 'total' => 12852, 'bodega_id' => 1, 'vendedor_id' => null],
            ],
            'pagos' => $doc === 'FV' ? [['forma_pago_id' => 8, 'nombre' => 'Efectivo', 'valor' => 55692, 'vencimiento' => null]] : [],
            'retenciones_detalle' => [],
        ];
    }

    private function exampleDian(string $number): array
    {
        return ['id' => 1201, 'numero' => $number, 'estado_dian' => 'Accepted', 'mensaje_dian' => 'Procesado Correctamente.', 'cufe' => 'a1b2c3d4e5f6...', 'errores' => [],
            'transmisiones' => [['operacion' => 'SendBillSync', 'ambiente' => 'production', 'simulado' => false, 'track_id' => null, 'valido' => true, 'codigo' => '00', 'mensaje' => 'Procesado Correctamente.', 'errores' => [], 'fecha' => '2026-10-20 11:42:13']]];
    }

    private function examplePurchaseInput(string $doc): array
    {
        return $doc === 'DS'
            ? ['fecha' => '2026-10-20', 'proveedor' => ['identificacion' => '1098765432'], 'items' => [['cuenta' => '513525', 'descripcion' => 'Servicio de aseo octubre', 'cantidad' => 1, 'precio' => 450000]], 'pagos' => [['forma_pago_id' => 11, 'valor' => 450000]]]
            : ['fecha' => '2026-10-20', 'proveedor' => ['identificacion' => '800777321'], 'factura_proveedor' => ['prefijo' => 'FE', 'numero' => '4581'], 'vencimiento' => '2026-11-19',
                'items' => [['codigo' => 'CAF-500', 'cantidad' => 20, 'precio' => 11000, 'impuesto_id' => 1], ['cuenta' => '513530', 'descripcion' => 'Mantenimiento molino', 'cantidad' => 1, 'precio' => 80000]]];
    }

    private function examplePurchase(string $doc): array
    {
        $ds = $doc === 'DS';

        return ['id' => $ds ? 77 : 310, 'tipo' => $doc, 'tipo_documento_id' => $ds ? 32 : 28, 'numero' => $ds ? 'DS-1-77' : 'FC-1-310', 'consecutivo' => $ds ? 77 : 310,
            'fecha' => '2026-10-20', 'vencimiento' => $ds ? '2026-10-20' : '2026-11-19',
            'proveedor' => $ds ? ['id' => 61, 'identificacion' => '1098765432', 'nombre' => 'CARLOS PÉREZ RUIZ'] : ['id' => 58, 'identificacion' => '800777321', 'nombre' => 'TOSTADORA DEL VALLE S.A.S.'],
            'factura_proveedor' => ['prefijo' => $ds ? null : 'FE', 'numero' => $ds ? null : '4581'], 'centro_costo_id' => null, 'documento_relacionado' => null,
            'subtotal' => $ds ? 450000 : 300000, 'descuento' => 0, 'impuestos' => $ds ? 0 : 41800, 'retenciones' => 0, 'total' => $ds ? 450000 : 341800, 'saldo' => $ds ? 0 : 341800,
            'observaciones' => null, 'estado' => 'emitido', 'cuds' => $ds ? 'f0e1d2c3b4a5...' : null, 'estado_dian' => $ds ? 'Accepted' : null, 'mensaje_dian' => $ds ? 'Procesado Correctamente.' : null,
            'creado' => '2026-10-20T15:05:00-05:00', 'actualizado' => '2026-10-20T15:05:00-05:00',
            'items' => $ds
                ? [['tipo' => 'cuenta', 'producto_id' => null, 'cuenta_id' => 4512, 'codigo' => '513525', 'descripcion' => 'Servicio de aseo octubre', 'cantidad' => 1, 'precio' => 450000, 'descuento' => 0, 'subtotal' => 450000, 'impuestos' => 0, 'porcentaje_impuesto' => 0, 'total' => 450000, 'bodega_id' => null]]
                : [['tipo' => 'producto', 'producto_id' => 15, 'cuenta_id' => null, 'codigo' => 'CAF-500', 'descripcion' => 'Café molido 500 g', 'cantidad' => 20, 'precio' => 11000, 'descuento' => 0, 'subtotal' => 220000, 'impuestos' => 41800, 'porcentaje_impuesto' => 19, 'total' => 261800, 'bodega_id' => 1],
                    ['tipo' => 'cuenta', 'producto_id' => null, 'cuenta_id' => 4520, 'codigo' => '513530', 'descripcion' => 'Mantenimiento molino', 'cantidad' => 1, 'precio' => 80000, 'descuento' => 0, 'subtotal' => 80000, 'impuestos' => 0, 'porcentaje_impuesto' => 0, 'total' => 80000, 'bodega_id' => null]],
            'pagos' => $ds ? [['forma_pago_id' => 11, 'nombre' => 'Transferencia', 'valor' => 450000, 'vencimiento' => null]] : [['forma_pago_id' => null, 'nombre' => 'Crédito', 'valor' => 341800, 'vencimiento' => '2026-11-19']],
        ];
    }

    private function exampleReceipt(string $type): array
    {
        $rc = $type === 'RC';

        return ['id' => $rc ? 512 : 204, 'tipo' => $type, 'modalidad' => 'abono', 'tipo_documento_id' => $rc ? 4 : 30, 'numero' => $rc ? 'RC-1-512' : 'RP-1-204', 'consecutivo' => $rc ? 512 : 204, 'fecha' => '2026-10-20',
            'tercero' => $rc ? ['id' => 42, 'identificacion' => '900555123', 'nombre' => 'DISTRIBUCIONES ANDINA S.A.S.'] : ['id' => 58, 'identificacion' => '800777321', 'nombre' => 'TOSTADORA DEL VALLE S.A.S.'],
            'forma_pago' => ['id' => $rc ? 8 : 11, 'nombre' => $rc ? 'Efectivo' : 'Transferencia'], 'total' => $rc ? 120000 : 341800, 'observaciones' => null, 'estado' => 'emitido', 'creado' => '2026-10-20T17:00:00-05:00',
            'items' => [['documento_id' => $rc ? 1201 : 310, 'documento' => $rc ? 'FV-1-1201' : 'FC-1-310', 'cuenta_id' => $rc ? 3802 : 3990, 'descripcion' => 'Abono', 'valor' => $rc ? 117478.99 : 341800, 'ajustes' => $rc ? 2521.01 : 0]]];
    }

    private function exampleJournal(): array
    {
        return ['id' => 15, 'tipo_documento_id' => 7, 'numero' => 'CC-1-15', 'consecutivo' => 15, 'fecha' => '2026-10-31', 'observaciones' => 'Causación arriendo octubre', 'debitos' => 2500000, 'creditos' => 2500000,
            'estado' => 'contabilizado', 'creado' => '2026-10-31T09:00:00-05:00', 'lineas' => [
                ['cuenta_id' => 4480, 'cuenta' => '512010', 'tercero_id' => 70, 'centro_costo_id' => null, 'descripcion' => 'Arriendo oficina', 'debito' => 2500000, 'credito' => 0, 'cruce' => null, 'producto_id' => null, 'cantidad' => null],
                ['cuenta_id' => 3995, 'cuenta' => '23359501', 'tercero_id' => 70, 'centro_costo_id' => null, 'descripcion' => null, 'debito' => 0, 'credito' => 2500000, 'cruce' => ['prefijo' => 'CC', 'numero' => 15, 'vencimiento' => '2026-11-05'], 'producto_id' => null, 'cantidad' => null],
            ]];
    }

    // ---------------------------------------------------------------- utilidades

    private function op(string $method, string $path, string $tag, string $summary, string $description, array $extra): void
    {
        $responses = $extra['responses'] + ['401' => $this->r('NoAutorizado'), '429' => $this->r('Limite')];
        if ($method !== 'get') {
            $responses += ['403' => $this->r('Prohibido')];
        }
        ksort($responses);
        $this->paths[$path][$method] = array_filter([
            'tags' => [$tag], 'summary' => $summary, 'description' => $description,
            'operationId' => $method.str_replace(' ', '', ucwords(preg_replace('/[^a-z0-9]+/', ' ', strtolower($path)))),
            'parameters' => $extra['parameters'] ?? null, 'requestBody' => $extra['requestBody'] ?? null, 'responses' => $responses,
        ]);
    }

    private function ref(string $schema): array
    {
        return ['$ref' => "#/components/schemas/$schema"];
    }

    private function r(string $response): array
    {
        return ['$ref' => "#/components/responses/$response"];
    }

    private function json(array $schema, mixed $example = null, string $description = 'OK'): array
    {
        return ['description' => $description, 'content' => ['application/json' => array_filter(['schema' => $schema, 'example' => $example], fn ($v) => $v !== null)]];
    }

    private function body(string $schema, array $example): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $this->ref($schema), 'example' => $example]]];
    }

    private function paged(string $schema): array
    {
        return ['allOf' => [$this->ref('Paginado'), ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $this->ref($schema)]]]]];
    }

    private function id(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'example' => 1201];
    }

    private function q(string $name, string $description, mixed $example = null, string $type = 'string', ?string $format = null): array
    {
        return array_filter(['name' => $name, 'in' => 'query', 'description' => $description, 'schema' => array_filter(['type' => $type, 'format' => $format]), 'example' => $example], fn ($v) => $v !== null);
    }

    private function pageParams(): array
    {
        return [$this->q('page', 'Página.', null, 'integer'), $this->q('por_pagina', 'Registros por página, de 1 a 100 (por defecto 50).', null, 'integer')];
    }

    private function syncParams(): array
    {
        return [$this->q('creado_desde', 'Creados desde esta fecha u hora (ISO 8601).', '2026-10-01'), $this->q('actualizado_desde', 'Modificados desde esta fecha u hora (ISO 8601).', '2026-10-01T00:00:00')];
    }

    /** @param array<string, string> $types */
    private function props(array $types): array
    {
        return ['type' => 'object', 'properties' => array_map(fn ($t) => $t === 'array' ? ['type' => 'array', 'items' => new \stdClass] : ['type' => $t, 'nullable' => true], $types)];
    }

    private function enumText(array $map): string
    {
        return collect($map)->map(fn ($v, $k) => "`$k` $v")->implode(' · ');
    }
}
