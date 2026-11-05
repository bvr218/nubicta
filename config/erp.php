<?php

/*
| Estructura de navegacion del ERP: modulos, pestanas y procesos de cada menu.
*/

return [

    'sidebar' => [
        ['key' => 'inicio', 'label' => 'Inicio', 'icon' => 'house', 'url' => '/', 'permission' => null],
        ['key' => 'ventas', 'label' => 'Ventas', 'icon' => 'circle-dollar', 'url' => '/ventas', 'permission' => 'ventas.ver'],
        ['key' => 'bandeja-dian', 'label' => 'Bandeja DIAN', 'icon' => 'download', 'url' => '/bandeja-dian', 'permission' => 'compras.ver'],
        ['key' => 'compras', 'label' => 'Compras y gastos', 'icon' => 'bag-shopping', 'url' => '/compras', 'permission' => 'compras.ver'],
        ['key' => 'productos', 'label' => 'Productos y servicios', 'icon' => 'cart-flatbed', 'url' => '/productos', 'permission' => 'inventario.ver'],
        ['key' => 'bancos', 'label' => 'Cajas y bancos', 'icon' => 'landmark', 'url' => '/bancos', 'permission' => 'bancos.ver'],
        ['key' => 'nomina', 'label' => 'Nómina', 'icon' => 'user-tie', 'url' => '/nomina', 'permission' => 'nomina.ver'],
        ['key' => 'contabilidad', 'label' => 'Contabilidad', 'icon' => 'book', 'url' => '/contabilidad', 'permission' => 'contabilidad.ver'],
        ['key' => 'reportes', 'label' => 'Reportes', 'icon' => 'clipboard-check', 'url' => '/reportes', 'permission' => 'reportes.ver'],
        ['key' => 'movimientos', 'label' => 'Movimientos del mes', 'icon' => 'chart-column', 'url' => '/movimientos', 'permission' => 'movimientos.ver', 'separator' => true],
    ],

    'module_tabs' => [
        'ventas' => ['Ventas', 'Recaudos', 'Cotizaciones', 'Remisiones', 'Facturas recurrentes', 'Envío masivo', 'Clientes', 'Seguimiento comercial'],
        'bandeja-dian' => ['Bandeja DIAN'],
        'compras' => ['Documentos de compras y gastos', 'Registro de eventos', 'Proveedores', 'Soporte por legalizar'],
        'productos' => ['Gestión de productos / servicios', 'Gestión combos', 'Documentos de productos / servicios', 'Costeo mensual'],
        'bancos' => ['Cajas y bancos', 'Conciliación bancaria'],
        'nomina' => ['Nóminas', 'Empleados', 'Documentos electrónicos', 'Más procesos'],
        'contabilidad' => ['Cuentas contables', 'Comprobantes contables', 'Presupuesto contable', 'Más procesos'],
    ],

    /* Pestanas que ya tienen pantalla propia: "modulo.Pestana" => url */
    'tab_links' => [
        'ventas.Ventas' => '/ventas',
        'ventas.Recaudos' => '/ventas/recaudos',
        'ventas.Clientes' => '/ventas/clientes',
        'compras.Documentos de compras y gastos' => '/compras',
        'compras.Proveedores' => '/compras/proveedores',
        'contabilidad.Más procesos' => '/contabilidad/mas-procesos',
        'nomina.Más procesos' => '/nomina/mas-procesos',
        'productos.Gestión de productos / servicios' => '/productos',
        'nomina.Nóminas' => '/nomina',
        'nomina.Empleados' => '/nomina/empleados',
        'nomina.Documentos electrónicos' => '/nomina/documentos',
        'bancos.Cajas y bancos' => '/bancos',
        'bancos.Conciliación bancaria' => '/bancos/conciliacion',
        'contabilidad.Cuentas contables' => '/contabilidad/cuentas',
        'contabilidad.Comprobantes contables' => '/contabilidad/comprobantes',
        'contabilidad.Presupuesto contable' => '/contabilidad/presupuesto',
        'ventas.Cotizaciones' => '/ventas/cotizaciones',
        'ventas.Remisiones' => '/ventas/remisiones',
        'ventas.Facturas recurrentes' => '/ventas/facturas-recurrentes',
        'ventas.Envío masivo' => '/ventas/envio-masivo',
        'ventas.Seguimiento comercial' => '/ventas/seguimiento-comercial',
        'compras.Registro de eventos' => '/compras/registro-eventos',
        'compras.Soporte por legalizar' => '/compras/soporte-legalizar',
        'productos.Gestión combos' => '/productos/combos',
        'productos.Documentos de productos / servicios' => '/productos/documentos',
        'productos.Costeo mensual' => '/productos/costeo',
        'bandeja-dian.Bandeja DIAN' => '/bandeja-dian',
    ],

    'create_menu' => [
        'Clientes' => [
            ['label' => 'Factura Venta / Ingresos', 'url' => '/ventas/facturas/crear', 'permission' => 'ventas.crear'],
            ['label' => 'Recibo de Caja', 'url' => '/ventas/recibos/crear', 'permission' => 'ventas.crear'],
            ['label' => 'Clientes', 'url' => '/terceros/crear?tipo=cliente', 'permission' => 'terceros.crear'],
            ['label' => 'Cotización', 'url' => '/ventas/cotizaciones/crear', 'permission' => 'ventas.crear'],
            ['label' => 'Remisión', 'url' => '/ventas/remisiones/crear', 'permission' => 'ventas.crear'],
            ['label' => 'Nota crédito', 'url' => '/ventas/notas-credito/crear', 'permission' => 'ventas.crear'],
            ['label' => 'Nota débito (Ventas)', 'url' => '/ventas/notas-debito/crear', 'permission' => 'ventas.crear'],
        ],
        'Proveedores' => [
            ['label' => 'Factura de Compra / Gasto', 'url' => '/compras/facturas/crear', 'permission' => 'compras.crear'],
            ['label' => 'Documento soporte', 'url' => '/compras/documentos-soporte/crear', 'permission' => 'compras.crear'],
            ['label' => 'Proveedores', 'url' => '/terceros/crear?tipo=proveedor', 'permission' => 'terceros.crear'],
            ['label' => 'Recibo de Pago / Egreso', 'url' => '/compras/egresos/crear', 'permission' => 'compras.crear'],
            ['label' => 'Orden de Compra', 'url' => '/compras/ordenes/crear', 'permission' => 'compras.crear'],
            ['label' => 'Nota débito (Compras)', 'url' => '/compras/notas-debito/crear', 'permission' => 'compras.crear'],
            ['label' => 'Nota ajuste Doc. Soporte', 'url' => '/compras/notas-ajuste/crear', 'permission' => 'compras.crear'],
        ],
        'Usuarios y más' => [
            ['label' => 'Usuario', 'url' => '/configuracion/usuarios/crear', 'permission' => 'configuracion.usuarios'],
            ['label' => 'Invita a tu contador', 'url' => '/configuracion/usuarios/crear?contador=1', 'permission' => 'configuracion.usuarios'],
        ],
        'Otros' => [
            ['label' => 'Producto / Servicio', 'url' => '/productos/crear', 'permission' => 'inventario.crear'],
            ['label' => 'Traslado entre bodegas', 'url' => '/inventario/traslados/crear', 'permission' => 'inventario.crear'],
            ['label' => 'Ajuste inventarios', 'url' => '/inventario/ajustes/crear', 'permission' => 'inventario.crear'],
            ['label' => 'Conteo físico', 'url' => '/inventario/conteos/crear', 'permission' => 'inventario.crear'],
            ['label' => 'Ensamble de producto', 'url' => '/inventario/ensambles/crear', 'permission' => 'inventario.crear'],
            ['label' => 'Costeo mensual', 'url' => '/productos/costeo', 'permission' => 'inventario.crear'],
            ['label' => 'Comprobante contable', 'url' => '/contabilidad/comprobantes/crear', 'permission' => 'contabilidad.crear'],
            ['label' => 'Impuestos', 'url' => '/configuracion/impuestos/crear', 'permission' => 'configuracion.parametros'],
            ['label' => 'Traslado de dinero', 'url' => '/bancos/traslados/crear', 'permission' => 'bancos.crear'],
            ['label' => 'Ajuste de cartera / proveedores', 'url' => '/contabilidad/ajustes-cartera/crear', 'permission' => 'contabilidad.crear'],
            ['label' => 'Clientes, proveedores u otros', 'url' => '/terceros/crear', 'permission' => 'terceros.crear'],
            ['label' => 'Favoritos', 'url' => '/contabilidad/comprobantes-favoritos', 'permission' => 'contabilidad.ver'],
        ],
    ],

    'shortcuts' => [
        ['label' => 'Crea una Factura Electrónica', 'icon' => 'file-invoice-dollar', 'url' => '/ventas/facturas/crear'],
        ['label' => 'Registra eventos en factura electrónica de proveedores', 'icon' => 'list-check', 'url' => '/compras/registro-eventos'],
        ['label' => 'Crea un Documento soporte', 'icon' => 'file-lines', 'url' => '/compras/documentos-soporte/crear'],
        ['label' => 'Registra una Compra o Gasto', 'icon' => 'wallet', 'url' => '/compras/facturas/crear'],
        ['label' => 'Crea un Comprobante contable', 'icon' => 'book', 'url' => '/contabilidad/comprobantes/crear'],
        ['label' => 'Crea un Recibo de caja', 'icon' => 'cash-register', 'url' => '/ventas/recibos/crear'],
        ['label' => 'Crea un Recibo de pago', 'icon' => 'receipt', 'url' => '/compras/egresos/crear'],
        ['label' => 'Crea un Tercero', 'icon' => 'user-plus', 'url' => '/terceros/crear'],
        ['label' => 'Crea un Producto o Servicio', 'icon' => 'cart-flatbed', 'url' => '/productos/crear'],
        ['label' => 'Importa una Compra o Gasto desde XML o ZIP', 'icon' => 'file-arrow-up', 'url' => '/compras/importar-xml'],
        ['label' => 'Causación automática DIAN', 'icon' => 'download', 'url' => '/bandeja-dian'],
    ],

    /* Modulo => acciones. Genera permisos "modulo.accion" para los perfiles. */
    'permissions' => [
        'ventas' => ['label' => 'Ventas', 'actions' => ['ver', 'crear', 'editar', 'anular', 'imprimir', 'exportar']],
        'compras' => ['label' => 'Compras y gastos', 'actions' => ['ver', 'crear', 'editar', 'anular', 'imprimir', 'exportar']],
        'terceros' => ['label' => 'Clientes, proveedores y otros', 'actions' => ['ver', 'crear', 'editar', 'eliminar', 'exportar']],
        'inventario' => ['label' => 'Productos e inventario', 'actions' => ['ver', 'ver_costos', 'crear', 'editar', 'eliminar', 'exportar']],
        'bancos' => ['label' => 'Cajas y bancos', 'actions' => ['ver', 'crear', 'editar', 'anular']],
        'nomina' => ['label' => 'Nómina', 'actions' => ['ver', 'crear', 'editar', 'liquidar', 'enviar_dian']],
        'contabilidad' => ['label' => 'Contabilidad', 'actions' => ['ver', 'crear', 'editar', 'anular', 'cerrar_periodo']],
        'reportes' => ['label' => 'Reportes', 'actions' => ['ver', 'exportar']],
        'movimientos' => ['label' => 'Movimientos del mes', 'actions' => ['ver', 'exportar']],
        'configuracion' => ['label' => 'Configuración', 'actions' => ['empresa', 'usuarios', 'parametros', 'facturacion_electronica', 'auditoria']],
    ],

    'action_labels' => [
        'ver' => 'Ver', 'ver_costos' => 'Ver costos', 'crear' => 'Crear', 'editar' => 'Editar', 'anular' => 'Anular', 'eliminar' => 'Eliminar',
        'imprimir' => 'Imprimir', 'exportar' => 'Exportar', 'liquidar' => 'Liquidar', 'enviar_dian' => 'Enviar a DIAN',
        'cerrar_periodo' => 'Cerrar periodo', 'empresa' => 'Datos de la empresa', 'usuarios' => 'Usuarios y perfiles',
        'parametros' => 'Parámetros', 'facturacion_electronica' => 'Facturación electrónica', 'auditoria' => 'Auditoría',
    ],

    'fiscal_responsibilities' => [
        'O-13' => 'Gran contribuyente',
        'O-15' => 'Autorretenedor',
        'O-23' => 'Agente de retención IVA',
        'O-47' => 'Régimen simple de tributación',
        'R-99-PN' => 'No aplica - Otros',
    ],

    'identification_types' => [
        '11' => 'Registro civil', '12' => 'Tarjeta de identidad', '13' => 'Cédula de ciudadanía',
        '21' => 'Tarjeta de extranjería', '22' => 'Cédula de extranjería', '31' => 'NIT',
        '41' => 'Pasaporte', '42' => 'Documento de identificación extranjero', '47' => 'PEP',
        '48' => 'PPT', '50' => 'NIT de otro país', '91' => 'NUIP',
    ],

    'tax_regimes' => [
        '48' => 'Responsable de IVA',
        '49' => 'No responsable de IVA',
    ],

    'currencies' => [
        'COP' => 'Peso colombiano',
        'USD' => 'Dólar estadounidense',
        'EUR' => 'Euro',
    ],

    /* Lineas de producto del modulo de deposito: el codigo del producto es linea + consecutivo de 4 o 5 digitos */
    'product_lines' => [
        '1' => 'Mascotas', '2' => 'Medicamentos', '3' => 'Peces', '4' => 'Cuyes', '5' => 'Cerdos',
        '6' => 'Bovinos', '7' => 'Accesorios bovinos', '8' => 'Equinos', '9' => 'Accesorios equinos',
        '10' => 'Avícola', '11' => 'Accesorios avícolas', '13' => 'Varios', '14' => 'Medicamentos pequeños',
        '15' => 'Agrícolas', '16' => 'Accesorios mascotas', '17' => 'Equipo de ordeño',
    ],
];
