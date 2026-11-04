<?php

/* Menu de Configuracion (engranaje): grupos, opciones y rutas. */

$item = fn (string $label, string $url) => ['label' => $label, 'url' => $url];
$sales = [
    $item('Facturas de ventas', '/configuracion/documentos/facturas-venta'),
    $item('Cotizaciones', '/configuracion/documentos/cotizaciones'),
    $item('Remisiones', '/configuracion/documentos/remisiones'),
    $item('Notas débito (Ventas)', '/configuracion/documentos/notas-debito'),
    $item('Recibos de caja', '/configuracion/documentos/recibos-caja'),
    $item('Notas crédito', '/configuracion/documentos/notas-credito'),
];
$purchases = [
    $item('Compras y gastos', '/configuracion/documentos/compras'),
    $item('Ordenes de compra', '/configuracion/documentos/ordenes-compra'),
    $item('Documento soporte', '/configuracion/documentos/documento-soporte'),
    $item('Nota débito / Nota de ajuste documento soporte', '/configuracion/documentos/notas-ajuste-soporte'),
    $item('Recibo de pago / egreso', '/configuracion/documentos/recibos-pago'),
];
$reminders = $item('Configuración de recordatorios automáticos', '/configuracion/ajustes/recordatorios');

return [
    'General' => [
        ['title' => 'Organización', 'column' => 0, 'items' => [
            $item('Perfil de la organización', '/configuracion/empresa'),
            $item('Logo para impresión de comprobantes', '/configuracion/empresa?seccion=logo'),
            $item('Contrato de servicio', '/configuracion/ajustes/contrato'),
        ]],
        ['title' => 'Usuarios', 'column' => 0, 'items' => [
            $item('Creación Usuarios', '/configuracion/usuarios/crear'),
            $item('Invita a tu contador', '/configuracion/usuarios/crear?contador=1'),
            $item('Usuarios administradores', '/configuracion/usuarios?perfil=administrador'),
            $item('Roles', '/configuracion/perfiles'),
            $item('Administración de usuarios y permisos', '/configuracion/usuarios'),
        ]],
        ['title' => 'Seguridad', 'column' => 1, 'items' => [
            $item('Copias de seguridad (descargar y restaurar)', '/configuracion/respaldos'),
            $item('Bloqueo fecha de transacciones', '/configuracion/bloqueo-periodos'),
            $item('Bloqueo de transacciones por periodo', '/configuracion/bloqueo-periodos'),
        ]],
        ['title' => 'Regional', 'column' => 1, 'items' => [
            $item('Ciudades', '/configuracion/ciudades'),
        ]],
        ['title' => 'Inteligencia artificial', 'column' => 1, 'items' => [
            $item('Asistente IA para causar facturas DIAN', '/configuracion/ajustes/ia'),
        ]],
    ],
    'Habilitaciones DIAN' => [
        ['title' => 'Ventas', 'column' => 0, 'items' => [
            $item('Facturación Electrónica', '/configuracion/facturacion-electronica?ambito=fe'),
            $item('Sincroniza tu resolución', '/configuracion/facturacion-electronica?tab=resoluciones'),
        ]],
        ['title' => 'Nómina', 'column' => 0, 'items' => [
            $item('Nómina Electrónica', '/configuracion/facturacion-electronica?ambito=nomina'),
        ]],
        ['title' => 'Compras y gastos', 'column' => 1, 'items' => [
            $item('Documento soporte para no obligados a facturar', '/configuracion/facturacion-electronica?ambito=ds'),
            $item('Sincroniza tu resolución de documento soporte', '/configuracion/facturacion-electronica?tab=resoluciones'),
        ]],
        ['title' => 'Certificado digital', 'column' => 1, 'items' => [
            $item('Seguimiento Certificado digital', '/configuracion/facturacion-electronica?tab=certificado'),
        ]],
        ['title' => 'Parámetros', 'column' => 1, 'items' => [
            $item('Tipos de documento', '/configuracion/tipos-documento'),
        ]],
    ],
    'Ventas' => [
        ['title' => 'Generales', 'column' => 0, 'items' => [
            $item('Tipos de documento', '/configuracion/tipos-documento'),
            $item('Formas de pago', '/configuracion/formas-pago'),
            $item('Vendedores', '/configuracion/vendedores'),
            $item('Listas de precios', '/configuracion/listas-precios'),
            $item('Grupos de inventario', '/configuracion/grupos-inventario'),
            $item('Líneas de producto', '/configuracion/lineas-producto'),
        ]],
        ['title' => 'Procesos', 'column' => 0, 'items' => [
            $item('Cuentas puente y de mandato', '/configuracion/ajustes/mandato'),
            $item('Renovación de numeración agotada o próxima a agotarse', '/configuracion/facturacion-electronica?tab=resoluciones'),
            $item('Habilitación de numeración vencida o próxima a vencerse', '/configuracion/facturacion-electronica?tab=resoluciones'),
        ]],
        ['title' => 'Seguimientos', 'column' => 1, 'items' => [
            $item('Tareas de seguimiento de cobranza', '/configuracion/ajustes/cobranza'),
            $item('Tarea de seguimiento comercial', '/ventas/seguimiento-comercial'),
        ]],
        ['title' => 'Importación', 'column' => 1, 'items' => [
            $item('Facturas de venta', '/configuracion/importacion-comprobantes?tipo=fv'),
        ]],
        ['title' => 'Impresión, estilo y envío de documentos', 'column' => 1, 'items' => $sales],
    ],
    'Compras y gastos' => [
        ['title' => 'Documentos', 'column' => 0, 'items' => $purchases],
        ['title' => 'Impresión, estilo y envío de documentos', 'column' => 1, 'items' => $purchases],
    ],
    'Clientes y proveedores' => [
        ['title' => 'Generales', 'column' => 0, 'items' => [
            $item('Campos libres clientes, proveedores u otros', '/configuracion/campos-libres'),
            $item('Desbloqueo de correos para envío de emails y notificaciaciones', '/configuracion/ajustes/correos'),
        ]],
        ['title' => 'Importación', 'column' => 0, 'items' => [
            $item('Saldos iniciales cuentas por cobrar', '/configuracion/saldos-iniciales/cuentas-por-cobrar'),
            $item('Saldos iniciales cuentas por pagar', '/configuracion/saldos-iniciales/cuentas-por-pagar'),
            $item('Clientes, proveedores u otros', '/terceros'),
        ]],
        ['title' => 'Gestión de recaudo', 'column' => 1, 'items' => [$reminders]],
    ],
    'Productos y servicios' => [
        ['title' => 'Generales', 'column' => 0, 'items' => [
            $item('Líneas de producto', '/configuracion/lineas-producto'),
            $item('Grupos de inventario', '/configuracion/grupos-inventario'),
            $item('Movimiento de productos / Kárdex', '/reportes/movimiento-productos'),
            $item('Lista de precios', '/configuracion/listas-precios'),
            $item('Configuración de bodegas', '/configuracion/bodegas'),
            $item('Unidades de medida factura electrónica', '/configuracion/unidades-medida'),
            $item('Configuración inventario', '/productos/costeo'),
            $item('Vendedores', '/configuracion/vendedores'),
        ]],
        ['title' => 'Documentos', 'column' => 1, 'items' => [
            $item('Notas de ensamble', '/configuracion/documentos/notas-ensamble'),
        ]],
        ['title' => 'Importación', 'column' => 1, 'items' => [
            $item('Productos y servicios', '/productos/importar'),
            $item('Ajuste de inventario', '/inventario/ajustes/crear'),
            $item('Saldos iniciales de inventario', '/configuracion/saldos-iniciales/inventario'),
            $item('Conteo físico', '/inventario/conteos/crear'),
        ]],
    ],
    'Nómina' => [
        ['title' => 'Generales', 'column' => 0, 'items' => [
            $item('Creación de cargos laborales', '/configuracion/cargos'),
            $item('Configuración de nómina', '/configuracion/nomina'),
            $item('Conceptos de nómina', '/configuracion/conceptos-nomina'),
            $item('Parámetros contables de nómina', '/configuracion/parametros-nomina'),
            $item('Configuración de prefijos', '/configuracion/facturacion-electronica?tab=resoluciones'),
        ]],
        ['title' => 'Importación', 'column' => 1, 'items' => [
            $item('Empleados', '/configuracion/nomina/importacion/empleados'),
            $item('Contratos', '/configuracion/nomina/importacion/contratos'),
            $item('Acumulados iniciales', '/reportes/acumulados-iniciales-nomina'),
            $item('Salarios', '/configuracion/nomina/importacion/salarios'),
        ]],
    ],
    'Contabilidad' => [
        ['title' => 'Generales', 'column' => 0, 'items' => [
            $item('Impuestos', '/configuracion/impuestos'),
            $item('Centros de costo', '/configuracion/centros-costo'),
            $item('Cuentas contables', '/contabilidad/cuentas'),
            $item('Cuentas de gastos frecuentes', '/configuracion/gastos-frecuentes'),
            $item('Otros ingresos', '/configuracion/otros-ingresos'),
            $item('Formas de pago en documentos', '/configuracion/formas-pago'),
            $item('Tipos de documento', '/configuracion/tipos-documento'),
        ]],
        ['title' => 'Documentos', 'column' => 0, 'items' => [
            $item('Comprobantes contables', '/configuracion/documentos/comprobantes-contables'),
        ]],
        ['title' => 'Activos fijos', 'column' => 1, 'items' => [
            $item('Grupos de activos', '/configuracion/grupos-activos'),
        ]],
        ['title' => 'Importación', 'column' => 1, 'items' => [
            $item('Comprobantes contables', '/configuracion/importacion-comprobantes?tipo=cc'),
            $item('Activos fijos', '/contabilidad/activos-fijos/importar'),
            $item('Saldos iniciales de otras cuentas contables', '/contabilidad/saldos-iniciales/otras-cuentas'),
        ]],
    ],
    'Alianzas e integraciones' => [
        ['title' => 'Configuraciones e integraciones', 'column' => 0, 'items' => [
            $item('Credenciales de integración a plataformas digitales (API)', '/configuracion/ajustes/api'),
        ]],
        ['title' => 'Gestión de recaudo', 'column' => 1, 'items' => [$reminders]],
    ],
];
