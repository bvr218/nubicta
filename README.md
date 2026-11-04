<p align="center">
  <img src="docs/brand/nubicta-logo.svg" alt="Nubicta" width="440">
</p>

<p align="center">
  <b>El ERP contable libre para empresas colombianas.</b><br>
  Contabilidad, facturación electrónica DIAN como software propio, inventario, nómina electrónica y bancos. Gratis, de código abierto y con tus datos siempre bajo tu control.
</p>

<p align="center">
  <a href="LICENSE"><img src="https://img.shields.io/badge/licencia-MIT-22c55e" alt="Licencia MIT"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white" alt="PHP 8.3+">
  <img src="https://img.shields.io/badge/Laravel-13-ff2d20?logo=laravel&logoColor=white" alt="Laravel 13">
  <img src="https://img.shields.io/badge/Vue-3-42b883?logo=vuedotjs&logoColor=white" alt="Vue 3">
  <img src="https://img.shields.io/badge/DIAN-UBL%202.1-0284c7" alt="DIAN UBL 2.1">
</p>

---

## ¿Qué es Nubicta?

Nubicta es un sistema administrativo y contable **gratuito y de código abierto**, pensado para la pequeña y mediana empresa en Colombia. Corre en tu propio servidor, con tus datos y sin mensualidades por el programa.

Se usa en el modo de operación **software propio** de la DIAN: cada empresa se habilita a su nombre y el sistema firma y envía directamente la factura electrónica, el documento soporte y la nómina electrónica, sin intermediarios ni costo por documento. Nubicta **no es un proveedor tecnológico** y no factura por cuenta de nadie.

Sitio web, guía de habilitación y versión en la nube: [nubicta.online](https://nubicta.online).

## Módulos

| Módulo | Qué incluye |
|---|---|
| **Ventas** | Cotizaciones, remisiones, factura electrónica de venta, notas crédito y débito, recibos de caja, facturas recurrentes, cartera y gestión de cobro. |
| **Compras y gastos** | Facturas de proveedor, documento soporte electrónico y notas de ajuste, comprobantes de egreso, órdenes de compra. |
| **Bandeja DIAN / RADIAN** | Recepción de facturas de proveedores por correo (IMAP), acuse de recibo, recibo del bien, aceptación expresa y reclamo. |
| **Productos e inventario** | Productos, servicios y combos, bodegas, listas de precios, kárdex, ajustes, traslados, conteo físico, importación por Excel y costeo mensual. |
| **Cajas y bancos** | Cuentas bancarias y cajas, saldos desde la contabilidad, conciliación con extractos. |
| **Nómina** | Empleados, liquidación mensual o quincenal, novedades, vacaciones, PILA, colillas, certificado de ingresos y retenciones. |
| **Nómina electrónica** | Documento soporte de pago (DNE) y notas de ajuste (DNA) con CUNE, envío a la DIAN, envío por correo a los empleados. |
| **Contabilidad** | Plan único de cuentas, comprobantes, saldos iniciales, presupuesto, cierres de periodo y de año, activos fijos y depreciación. |
| **Reportes** | Libro auxiliar, balance de prueba, estado de resultados, balance general, movimiento por tercero, impuestos y retenciones, más de 60 reportes exportables a Excel. |
| **Configuración** | Empresa, usuarios y perfiles con permisos, impuestos, retenciones, numeraciones, correo SMTP/IMAP, auditoría. |

## Facturación electrónica DIAN

- UBL 2.1 con firma XAdES-EPES; CUFE, CUDE, CUDS y CUNE según el anexo técnico vigente.
- Servicios web de la DIAN (`SendBillSync`, `SendTestSetAsync`, `GetStatusZip`, `GetNumberingRange`, `GetXmlByDocumentKey`, `SendEventUpdateStatus`) en habilitación y producción.
- Asistente de habilitación: carga del certificado, set de pruebas de factura, documento soporte y nómina, y paso a producción.
- Consulta de rangos de numeración en la DIAN y asociación automática a cada tipo de documento.
- `AttachedDocument` y envío del ZIP al cliente por correo; reintento automático en cola cuando la DIAN no responde.

## Requisitos

- PHP 8.3 o superior con `ext-soap`, `ext-openssl`, `ext-intl`, `ext-gd`, `ext-zip` y `ext-redis` (o predis)
- Composer 2 y Node.js 20 o superior
- MySQL 8 o MariaDB 10.6 o superior
- Redis (colas con Laravel Horizon)
- Chromium (genera los PDF de facturas, recibos y nómina)
- Un certificado de firma digital emitido por una entidad autorizada por la DIAN (para producción)

## Instalación

```bash
git clone https://github.com/bvr218/nubicta.git
cd nubicta
composer install
npm ci && npm run build
cp .env.example .env
php artisan key:generate
```

Edita `.env` con la base de datos, Redis, el correo y los datos iniciales (`ERP_COMPANY_NAME`, `ERP_ADMIN_EMAIL`). Para crear la empresa como persona natural, configura `ERP_COMPANY_IS_PERSON=true`; `ERP_COMPANY_NAME` se usa como nombre(s) y `ERP_COMPANY_SURNAME` como apellidos (opcional). `ERP_COMPANY_TRADE_NAME` define el nombre comercial; si se omite o está vacío, usa el nombre de empresa o nombre(s) y apellidos. Luego:

```bash
php artisan migrate --seed      # crea la empresa, el PUC y el usuario administrador (la contraseña sale en la consola)
php artisan storage:link
php artisan horizon             # colas: envíos a la DIAN, correos, importaciones
php artisan schedule:work       # facturas recurrentes y gestión de cobro (o un cron con schedule:run)
```

En producción, sirve `public/` con Nginx y PHP-FPM, y deja `horizon` corriendo con Supervisor. El script [`deploy/after_deploy.sh`](deploy/after_deploy.sh) muestra los pasos de un despliegue.

### Primeros pasos

1. **Configuración → Empresa:** NIT, razón social, responsabilidades fiscales, dirección y logo.
2. **Configuración → Facturación electrónica:** sube el certificado, registra el software propio de la DIAN (ID y PIN) y corre el set de pruebas.
3. **Consultar rangos de la DIAN:** asocia la resolución de factura (con su clave técnica) y la de documento soporte.
4. **Correo:** configura SMTP para enviar documentos e IMAP para recibir las facturas de proveedores.

## Migración desde Siigo Nube

Nubicta puede traer tu información desde Siigo Nube **en modo solo lectura** (no modifica nada en Siigo). Configura `SIIGO_USERNAME` y `SIIGO_ACCESS_KEY` (la access key de la API de Siigo) y ejecuta:

```bash
php artisan siigo:import terceros
php artisan siigo:import productos
php artisan siigo:import ventas --desde=2024-01-01
php artisan siigo:import documentos --desde=2024-01-01
php artisan siigo:import-ledger --xlsx=movimiento_auxiliar.xlsx   # libro auxiliar exportado de Siigo
php artisan siigo:import-exports                                   # bancos, empleados y nómina exportados
```

El libro auxiliar importado se convierte en la contabilidad del ERP: saldos de bancos, cartera, proveedores y estados financieros cuadran con el sistema de origen al centavo. Después del cambio, la numeración continúa donde quedó.

## Pruebas

```bash
php artisan test
```

Las pruebas de la DIAN validan el XML, la firma y los códigos CUFE/CUDS/CUNE sin enviar nada. Para ensayar sin conexión a la DIAN, activa el **modo simulado** en Configuración → Facturación electrónica.

## Tecnología

Laravel 13 · Inertia.js · Vue 3 · Tailwind CSS 4 · MySQL · Redis y Horizon · Laravel Excel · Chromium sin interfaz para PDF.

## Contribuir

Las contribuciones son bienvenidas. Abre un *issue* para reportar errores o proponer mejoras, o envía un *pull request* con una descripción clara del cambio y cómo probarlo. Si tu cambio toca la DIAN, incluye o actualiza las pruebas de XML.

## Aviso

Nubicta es un producto de COLOMBIAWISP S.A.S. y un proyecto independiente. No está afiliado, patrocinado ni respaldado por la DIAN ni por ningún proveedor tecnológico. "Siigo" y las demás marcas mencionadas pertenecen a sus titulares y se citan solo para describir la importación de datos.

Nubicta no es un proveedor tecnológico autorizado por la DIAN. En el modo software propio, cada empresa se habilita a su nombre y responde por el cumplimiento de la Resolución 000165 de 2023 y demás normas vigentes. Todos los datos son de la empresa que usa el sistema.

El software se entrega "tal cual", sin garantía. Verifica la normativa tributaria vigente con tu contador antes de usarlo en producción.

## Licencia

Distribuido bajo la [licencia MIT](LICENSE). Puedes usarlo, modificarlo y distribuirlo libremente, incluso con fines comerciales, conservando el aviso de copyright.
