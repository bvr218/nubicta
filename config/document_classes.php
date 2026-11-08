<?php

/*
 * Pantallas de configuracion de cada tipo de comprobante (Configuracion > Ventas / Compras / Productos / Contabilidad > Documentos).
 * slug => tipo interno, textos, columnas del listado y secciones del formulario.
 */

$saleColumns = ['% Desc.', 'Bodega', 'Código', 'Código arancelario', 'Descripción', 'Impuesto cargo', 'Impuesto cargo dos', 'Impuesto retención', 'Marca',
    'Modelo', 'Referencia de fábrica', 'Unidad de medida', 'Valor Impto.Rete.', 'Valor Unitario', 'Valor bruto', 'Valor descuento', 'Valor impuesto cargo',
    'Valor impuesto cargo dos', 'Vendedor', 'Nombre', 'Cantidad', 'Valor'];
$discount = ['key' => 'discount_type', 'label' => 'Tipo de descuento por ítem', 'type' => 'radio', 'options' => ['Percentage' => 'Porcentaje', 'Value' => 'Valor']];
$discountAccount = ['key' => 'discount_account_id', 'label' => 'Cuenta contable para descuentos por ítem', 'type' => 'account'];
$code = ['key' => 'code', 'label' => 'Código del comprobante', 'type' => 'text', 'required' => true];
$name = ['key' => 'name', 'label' => 'Nombre del comprobante', 'type' => 'text', 'required' => true];
$internal = ['key' => 'name', 'label' => 'Descripción interna', 'type' => 'text', 'required' => true];
$title = ['key' => 'title', 'label' => 'Título para visualización', 'type' => 'text'];
$titleRequired = ['key' => 'title', 'label' => 'Título comprobante', 'type' => 'text', 'required' => true];
$active = ['key' => 'active', 'label' => 'En uso', 'type' => 'checkbox'];
$auto = ['key' => 'automatic_number', 'label' => 'Numeración automática', 'type' => 'checkbox'];
$decimals = ['key' => 'decimals', 'label' => 'Incluir decimales', 'type' => 'checkbox'];
$costCenter = ['key' => 'cost_center', 'label' => 'Habilitar centros de costo', 'type' => 'checkbox'];
$sellers = ['key' => 'seller_by_item', 'label' => 'Activar vendedores por ítem', 'type' => 'checkbox'];
$withholdings = [
    ['key' => 'reteiva', 'label' => 'Habilitar ReteIVA', 'type' => 'checkbox'],
    ['key' => 'reteica', 'label' => 'Habilitar ReteICA', 'type' => 'checkbox'],
];
$selfWithholding = ['key' => 'self_withholding', 'label' => 'Habilitar Autorretención', 'type' => 'checkbox'];
$format = fn (array $options) => ['key' => 'print_format', 'label' => 'Formato', 'type' => 'select', 'options' => array_combine($options, $options), 'preview' => true];
$contactData = [
    ['key' => 'city', 'label' => 'Ciudad', 'type' => 'text'],
    ['key' => 'address', 'label' => 'Dirección', 'type' => 'text'],
    ['key' => 'phones', 'label' => 'Teléfono', 'type' => 'phones'],
];
$observations = ['key' => 'footer_text', 'label' => 'Observaciones', 'type' => 'textarea', 'max' => 500];
$mail = [
    ['key' => 'mail_subject', 'label' => 'Asunto', 'type' => 'text'],
    ['key' => 'mail_body', 'label' => 'Contenido', 'type' => 'textarea'],
];
$columns = fn (string $who, ?array $available = null) => ['key' => 'print_columns', 'label' => "Datos que verá tu cliente en el detalle de $who", 'type' => 'columns', 'options' => $available ?? $saleColumns];
$charges = fn (string $doc) => ['title' => "Otros Cargos y descuentos en totales de $doc", 'fields' => [['key' => 'charges', 'label' => 'Cargos y descuentos', 'type' => 'charges']]];
$codeColumns = [['key' => 'code', 'label' => 'Código del comprobante'], ['key' => 'name', 'label' => 'Descripción interna'], ['key' => 'title', 'label' => 'Título comprobante']];

$noteSections = fn (string $doc, string $electronic, bool $costCenters) => [
    ['title' => 'Datos principales', 'fields' => [
        $active,
        ['heading' => 'Datos principales del comprobante'], $code, $name,
        ['heading' => 'Facturación electrónica'], ['key' => 'electronic', 'label' => 'Usar como documento electrónico', 'type' => 'checkbox', 'electronic' => $electronic],
        ['heading' => 'Numeración'], ['key' => 'consecutive', 'label' => "Próximo número de $doc", 'type' => 'number', 'required' => true], $auto,
    ]],
    ['title' => 'Configuraciones complementarias', 'fields' => array_values(array_filter([
        $costCenters ? $costCenter : null, $decimals, $sellers, $discount,
        ['heading' => 'Cuentas contables'], $discountAccount,
        ['heading' => 'Retenciones'], ...$withholdings, $selfWithholding,
        ['heading' => 'Configuraciones de otras actividades económicas'], ['key' => 'third_party_income', 'label' => 'Facturar Ingresos para terceros', 'type' => 'checkbox'],
    ]))],
    $charges("la $doc"),
    ['title' => "Visualización, Impresión y envío de la $doc", 'fields' => [
        ['heading' => 'Datos para visualización'], $title, ...$contactData, $observations,
        ['heading' => 'Datos para envío por mail'], ...$mail,
        $columns("la $doc"),
    ]],
];

return [
    'facturas-venta' => [
        'type' => 'FV', 'module' => 'Ventas',
        'list_title' => 'Crear tipos de comprobante factura de venta / Ingresos',
        'edit_title' => 'Editar tipo de documento Factura / Ingreso',
        'new_title' => 'Crear tipo de documento Factura / Ingreso',
        'style' => 'panel', 'duplicate' => true, 'export' => true,
        'columns' => [
            ['key' => 'kind', 'label' => 'Tipo de comprobante'], ['key' => 'usage', 'label' => 'Uso'], ['key' => 'code', 'label' => 'Código'],
            ['key' => 'name', 'label' => 'Nombre'], ['key' => 'title', 'label' => 'Título para visualización'],
            ['key' => 'resolution_number', 'label' => 'Número de resolución'], ['key' => 'numbering_status', 'label' => 'Estado numeración'],
        ],
        'defaults' => ['electronic_type' => 'ElectronicInvoice', 'print_format' => 'Tirilla'],
        'sections' => [
            ['title' => 'Tipo de documento a configurar', 'tutorials' => true, 'fields' => [
                ['key' => 'active', 'label' => 'Activo', 'type' => 'switch'],
                ['key' => 'electronic', 'label' => 'Tipo', 'type' => 'radio', 'electronic' => 'ElectronicInvoice', 'options' => ['si' => 'Factura electrónica', 'no' => 'Documento de ingreso']],
            ]],
            ['title' => 'Datos principales', 'fields' => [$code, $name]],
            ['title' => 'Configuración de factura electrónica', 'fields' => [
                ['key' => 'electronic_type', 'label' => 'Tipo de factura electrónica', 'type' => 'select', 'options' => ['ElectronicInvoice' => 'Factura electrónica de venta', 'NoElectronic' => 'Documento de ingreso']],
                ['key' => 'consecutive', 'label' => 'Próximo número del comprobante', 'type' => 'number', 'required' => true],
                $auto,
                ['key' => 'resolution', 'label' => 'Configurar resolución', 'type' => 'resolution'],
            ], 'tabs' => [
                ['title' => 'Sector comercial', 'icon' => 'building', 'fields' => [
                    ['key' => 'commercial_sector', 'label' => 'Sector comercial', 'type' => 'select', 'options' => [
                        '' => 'Sin sector comercial', 'salud' => 'Sector salud', 'transporte' => 'Transporte', 'servicios_publicos' => 'Servicios públicos',
                        'cambiario' => 'Operaciones cambiarias', 'aiu' => 'Construcción (AIU)',
                    ]],
                    ['key' => 'third_party_income', 'label' => 'Ingresos para terceros', 'type' => 'checkbox'],
                ]],
                ['title' => 'Documentos de referencia', 'icon' => 'file-lines', 'fields' => [
                    ['key' => 'purchase_order_ref', 'label' => 'Habilitar orden de compra / pedido', 'type' => 'checkbox'],
                    ['key' => 'delivery_order_ref', 'label' => 'Habilitar orden de entrega / despacho', 'type' => 'checkbox'],
                ]],
            ]],
            ['title' => 'Configuraciones adicionales', 'tabs' => [
                ['title' => 'Datos complementarios', 'icon' => 'file-circle-plus', 'fields' => [
                    ['key' => 'cost_center', 'label' => 'Habilitar centro de costos', 'type' => 'checkbox', 'span' => 2],
                    ['key' => 'remission_crossing', 'label' => 'Habilitar cruce de remisiones', 'type' => 'checkbox'], $sellers,
                    $decimals, ['key' => 'copay', 'label' => 'Habilitar copago / anticipo', 'type' => 'checkbox'],
                ]],
                ['title' => 'Descuentos y otros', 'icon' => 'dollar-sign', 'fields' => [
                    ['key' => 'discount_type', 'label' => 'Tipo de descuento por ítem', 'type' => 'select', 'options' => ['Percentage' => 'Porcentaje', 'Value' => 'Valor']],
                    $discountAccount + ['required' => true],
                    ['key' => 'gift_account_id', 'label' => 'Cuenta contable para obsequios', 'type' => 'account', 'required' => true],
                    ['heading' => 'Aplicar cargos y descuentos totales'], ['key' => 'charges', 'label' => 'Cargos y descuentos', 'type' => 'charges'],
                ]],
                ['title' => 'Retenciones', 'icon' => 'file-invoice-dollar', 'fields' => [...$withholdings, ['key' => 'self_withholding', 'label' => 'Habilitar autorretención', 'type' => 'checkbox']]],
            ]],
            ['title' => 'Configuración de impresión y envío', 'tabs' => [
                ['title' => 'Formato de impresión', 'icon' => 'file-lines', 'fields' => [
                    $format(['Básico', 'Básico2', 'Básico3', 'Tirilla']) + ['columns_link' => true],
                    ['key' => 'title', 'label' => 'Título del documento', 'type' => 'text', 'required' => true],
                    $columns('la factura') + ['hidden' => true],
                ]],
                ['title' => 'Detalles del documento', 'icon' => 'file', 'fields' => [
                    ['key' => 'city', 'label' => 'Ciudad', 'type' => 'text'], ['key' => 'address', 'label' => 'Dirección', 'type' => 'text'],
                    ['key' => 'phones', 'label' => 'Teléfono', 'type' => 'phones'],
                    ['heading' => 'Datos de pie de página'], $observations,
                    ...$mail,
                ]],
            ]],
        ],
    ],

    'cotizaciones' => [
        'type' => 'CT', 'module' => 'Ventas', 'label' => 'C ',
        'list_title' => 'Crear tipos de comprobante cotización',
        'edit_title' => 'Editando configuración de cotización',
        'new_title' => 'Nueva configuración de cotización',
        'columns' => $codeColumns,
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                $active, ['heading' => 'Datos principales del comprobante'], $code, $name,
                ['heading' => 'Numeración'], ['key' => 'consecutive', 'label' => 'Próximo número de cotización', 'type' => 'number', 'required' => true], $auto,
            ]],
            ['title' => 'Configuraciones complementarias', 'fields' => [$decimals, $discount]],
            ['title' => 'Visualización, impresión y envío de la cotización', 'fields' => [
                $format(['Básico', 'Básico2', 'Básico3', 'Tirilla']),
                ['heading' => 'Datos para visualización'], $title,
                ['key' => 'description', 'label' => 'Encabezado', 'type' => 'textarea'],
                ['key' => 'footer_text', 'label' => 'Condiciones comerciales u observaciones', 'type' => 'textarea'],
                ['heading' => 'Datos para envío por mail'], ...$mail,
                $columns('la cotización'),
            ]],
        ],
    ],

    'remisiones' => [
        'type' => 'RM', 'module' => 'Ventas',
        'list_title' => 'Crear tipos de comprobante de remisión',
        'edit_title' => 'Editando configuración remisión',
        'new_title' => 'Nueva configuración remisión',
        'columns' => $codeColumns,
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                $active, ['heading' => 'Datos principales del comprobante'], $code, $name,
                ['heading' => 'Numeración'], ['key' => 'consecutive', 'label' => 'Próximo número de Remisión', 'type' => 'number', 'required' => true], $auto,
            ]],
            ['title' => 'Configuraciones complementarias', 'fields' => [
                $costCenter, $decimals, ['key' => 'show_values', 'label' => 'Visualiza valores', 'type' => 'checkbox'], $discount,
            ]],
            ['title' => 'Visualización, Impresión y envío de remisión', 'fields' => [
                $format(['Básico']),
                ['heading' => 'Datos para visualización'], $title, ...$contactData,
                ['key' => 'footer_text', 'label' => 'Observaciones', 'type' => 'textarea', 'max' => 200],
                ['heading' => 'Datos para envío por mail'], ...$mail,
                $columns('la remisión'),
            ]],
        ],
    ],

    'notas-debito' => [
        'type' => 'ND', 'module' => 'Ventas', 'label' => 'DF',
        'list_title' => 'Crear tipos de nota débito (Ventas)',
        'edit_title' => 'Editando configuración de nota débito (Ventas)',
        'new_title' => 'Nueva configuración de nota débito (Ventas)',
        'columns' => $codeColumns,
        'sections' => $noteSections('nota débito (ventas)', 'ElectronicDebitNote', true),
    ],

    'recibos-caja' => [
        'type' => 'RC', 'module' => 'Ventas', 'style' => 'simple',
        'list_title' => 'Crear nuevo tipo de comprobante Recibo de caja',
        'edit_title' => 'Configuración tipo de recibo de caja',
        'new_title' => 'Configuración tipo de recibo de caja',
        'columns' => [['key' => 'code', 'label' => 'Código'], ['key' => 'name', 'label' => 'Descripción interna'], ['key' => 'title', 'label' => 'Título del comprobante']],
        'sections' => [
            ['title' => 'Datos generales', 'fields' => [
                ['key' => 'active', 'label' => 'Activo', 'type' => 'switch'], $code, $titleRequired, $internal,
                ['heading' => '¿Maneja centro de costos?'], ['key' => 'cost_center', 'label' => '¿Maneja centro de costos?', 'type' => 'yesno'],
                ['heading' => 'Configuraciones complementarias'], $auto,
                ['key' => 'consecutive', 'label' => 'Consecutivo', 'type' => 'number', 'required' => true],
                ['key' => 'advance_account_id', 'label' => 'Cuenta contable de anticipo clientes', 'type' => 'account', 'required' => true],
            ]],
            ['title' => 'Formato de impresión', 'side' => true, 'fields' => [$format(['Básico', 'Tirilla'])]],
        ],
    ],

    'notas-credito' => [
        'type' => 'NC', 'module' => 'Ventas',
        'list_title' => 'Crear tipos de nota crédito',
        'edit_title' => 'Editando configuración de nota crédito',
        'new_title' => 'Nueva configuración de nota crédito',
        'columns' => $codeColumns,
        'sections' => $noteSections('nota crédito', 'ElectronicCreditNote', false),
    ],

    'compras' => [
        'type' => 'FC', 'module' => 'Compras',
        'list_title' => 'Crear tipos de comprobante compra / gastos',
        'edit_title' => 'Editando configuración factura de compra',
        'new_title' => 'Nueva configuración factura de compra',
        'buttons' => 'back-first',
        'columns' => $codeColumns,
        'defaults' => ['print_columns' => ['Valor descuento', 'Impuesto cargo', 'Impuesto retención', 'Valor Unitario', 'Nombre', 'Cantidad', 'Valor']],
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                $active, ['heading' => 'Datos principales del comprobante'], $code, $name,
                ['heading' => 'Numeración'], ['key' => 'consecutive', 'label' => 'Próximo número de documento', 'type' => 'number', 'required' => true], $auto,
            ]],
            ['title' => 'Configuraciones complementarias', 'fields' => [
                $costCenter, $decimals, ['key' => 'aiu', 'label' => 'Habilitar manejo de AIU', 'type' => 'checkbox'],
                ['heading' => 'Cuentas contables'], $discountAccount,
                ['heading' => 'Retenciones'], ...$withholdings,
            ]],
            ['title' => 'Visualización e Impresión del documento', 'fields' => [
                $format(['Básico', 'Básico2', 'Básico3']),
                ['heading' => 'Datos para visualización'], $title,
                $columns('el documento'),
            ]],
        ],
    ],

    'ordenes-compra' => [
        'type' => 'OC', 'module' => 'Compras',
        'list_title' => 'Crear tipos de comprobante ordenes de compra',
        'edit_title' => 'Editando configuración orden de compra',
        'new_title' => 'Nueva configuración orden de compra',
        'columns' => $codeColumns,
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                $active, ['heading' => 'Datos principales del comprobante'], $code, $name,
                ['heading' => 'Numeración'], ['key' => 'consecutive', 'label' => 'Próximo número de orden de compra', 'type' => 'number', 'required' => true], $auto,
            ]],
            ['title' => 'Configuraciones complementarias', 'fields' => [$costCenter, $decimals, $discount]],
            ['title' => 'Visualización, impresión y envío de la orden de compra', 'fields' => [
                $format(['Básico']),
                ['heading' => 'Datos para visualización'], $title, ...$contactData, $observations,
                ['heading' => 'Datos para envío por mail'], ...$mail,
                $columns('la orden de compra'),
            ]],
        ],
    ],

    'documento-soporte' => [
        'type' => 'DS', 'module' => 'Compras', 'buttons' => 'back-first',
        'list_title' => 'Crear tipos de Documento soporte',
        'edit_title' => 'Editando configuración documento soporte',
        'new_title' => 'Nueva configuración documento soporte',
        'columns' => [
            ['key' => 'kind', 'label' => 'Tipo de comprobante'], ['key' => 'code', 'label' => 'Código del comprobante'], ['key' => 'name', 'label' => 'Nombre del comprobante'],
            ['key' => 'title', 'label' => 'Título para visualización'], ['key' => 'resolution_number', 'label' => 'Número de resolución'], ['key' => 'numbering_status', 'label' => 'Estado resolución'],
        ],
        'defaults' => ['electronic_type' => 'SupportDocument', 'title' => 'Documento soporte por compras a sujetos no obligados a facturar'],
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                $active, ['heading' => 'Datos principales del comprobante'], $code, $name,
                ['heading' => 'Resolucion Documentos'],
                ['key' => 'electronic_type', 'label' => 'Tipo de documento soporte', 'type' => 'select', 'options' => ['SupportDocument' => 'Electrónico', 'NoElectronic' => 'No electrónico']],
                ['key' => 'resolution_number', 'label' => 'Número resolución DIAN', 'type' => 'text',
                    'help' => 'Aprende a solicitar la numeración y autoriza los rangos aquí. Aprende a asociar los prefijos en la DIAN y asócialos aquí.'],
                ['key' => 'valid_from', 'label' => 'Fecha de inicio de vigencia', 'type' => 'date'],
                ['key' => 'validity_months', 'label' => 'Vigencia en meses', 'type' => 'select', 'virtual' => true, 'options' => ['' => '', '6' => '6 meses', '12' => '12 meses', '18' => '18 meses', '24' => '24 meses']],
                ['key' => 'valid_to', 'label' => 'Fecha final de vigencia', 'type' => 'date'],
                ['heading' => 'Numeración'],
                ['key' => 'prefix', 'label' => 'Prefijo', 'type' => 'text'],
                ['key' => 'range_from', 'label' => 'Numeración inicial', 'type' => 'number'],
                ['key' => 'range_to', 'label' => 'Numeración final', 'type' => 'number'],
                ['key' => 'consecutive', 'label' => 'Próximo número de documento', 'type' => 'number', 'required' => true], $auto,
            ]],
            ['title' => 'Configuraciones complementarias', 'fields' => [
                $costCenter, $decimals, ['heading' => 'Cuentas contables'], $discountAccount, ['heading' => 'Retenciones'], ...$withholdings,
            ]],
            ['title' => 'Visualización e Impresión del documento', 'fields' => [
                $format(['Básico']), ['heading' => 'Datos para visualización'], $title, $columns('el documento'),
            ]],
        ],
    ],

    'notas-ajuste-soporte' => [
        'type' => 'NA', 'module' => 'Compras', 'label' => 'ND', 'buttons' => 'back-first', 'delete_label' => 'Eliminar',
        'list_title' => 'Crear tipo de nota débito / nota de ajuste Documento Soporte',
        'edit_title' => 'Editando configuración nota débito / nota de ajuste documento soporte',
        'new_title' => 'Nueva configuración nota débito / nota de ajuste documento soporte',
        'columns' => [['key' => 'kind', 'label' => 'Tipo de comprobante'], ...$codeColumns],
        'defaults' => ['electronic_type' => 'SupportDocumentAdjustment', 'title' => 'Nota de ajuste al documento soporte'],
        'sections' => [
            ['title' => 'Datos principales', 'fields' => [
                ['key' => 'active', 'label' => 'Activo', 'type' => 'switch'], $code, $name,
                ['key' => 'title', 'label' => 'Título para visualización', 'type' => 'text', 'required' => true, 'readonly' => true, 'help' => 'El título está predefinido por la normativa vigente'],
                $auto,
                ['key' => 'electronic_type', 'label' => 'Tipo de documento soporte', 'type' => 'select', 'required' => true, 'options' => ['SupportDocumentAdjustment' => 'Electrónico', 'NoElectronic' => 'No electrónico']],
                $decimals,
                ['key' => 'consecutive', 'label' => 'Próximo número de Nota de Débito o nota de ajuste', 'type' => 'number', 'required' => true],
                $observations,
            ]],
        ],
    ],

    'recibos-pago' => [
        'type' => 'CE', 'module' => 'Compras', 'style' => 'simple', 'label' => 'RP', 'delete_label' => 'Eliminar',
        'list_title' => 'Crear nuevo tipo de comprobante Recibo de pago',
        'edit_title' => 'Configuración tipo de recibo de pago',
        'new_title' => 'Configuración tipo de recibo de pago',
        'columns' => [['key' => 'code', 'label' => 'Código'], ['key' => 'name', 'label' => 'Descripción interna'], ['key' => 'title', 'label' => 'Título del comprobante']],
        'sections' => [
            ['title' => 'Datos generales', 'fields' => [
                ['key' => 'active', 'label' => 'Activo', 'type' => 'switch'], $code, $titleRequired, $internal,
                ['heading' => '¿Maneja centro de costos?'], ['key' => 'cost_center', 'label' => '¿Maneja centro de costos?', 'type' => 'yesno'],
                ['heading' => 'Configuraciones complementarias'], $auto,
                ['key' => 'consecutive', 'label' => 'Consecutivo', 'type' => 'number', 'required' => true],
                ['key' => 'advance_account_id', 'label' => 'Cuenta contable de anticipo proveedores', 'type' => 'account', 'required' => true],
            ]],
            ['title' => 'Formato de impresión', 'side' => true, 'fields' => [$format(['Básico'])]],
        ],
    ],

    'notas-ensamble' => [
        'type' => 'NE', 'module' => 'Productos', 'style' => 'simple', 'delete' => false,
        'list_title' => 'Crear tipo de nota de ensamble',
        'edit_title' => 'Configuración nota de ensamble',
        'new_title' => 'Nueva configuración nota de ensamble',
        'columns' => $codeColumns,
        'sections' => [
            ['title' => 'Datos generales', 'fields' => [
                $code, $titleRequired, $internal, $auto,
                ['key' => 'consecutive', 'label' => 'Consecutivo', 'type' => 'number', 'required' => true],
                $active, ['key' => 'cost_center', 'label' => 'Maneja centro de costos', 'type' => 'checkbox'],
            ]],
        ],
    ],

    'comprobantes-contables' => [
        'type' => 'CC', 'module' => 'Contabilidad', 'style' => 'simple', 'delete' => false,
        'list_title' => 'Tipos de comprobante contable',
        'edit_title' => 'Configuración de comprobante contable',
        'new_title' => 'Configuración de comprobante contable',
        'columns' => [['key' => 'code', 'label' => 'Código del comprobante'], ['key' => 'name', 'label' => 'Título comprobante']],
        'sections' => [
            ['title' => 'Datos generales', 'fields' => [
                $code, ['key' => 'name', 'label' => 'Título comprobante', 'type' => 'text', 'required' => true], $auto,
                ['key' => 'consecutive', 'label' => 'Consecutivo', 'type' => 'number', 'required' => true],
                $active, ['key' => 'cost_center', 'label' => 'Maneja centro de costos', 'type' => 'checkbox'],
                ['key' => 'official_book', 'label' => 'Comprobante aplica en libros oficiales como', 'type' => 'radio', 'options' => [
                    '' => 'Ninguno', 'ventas' => 'Libro ventas - Ventas', 'ventas_devoluciones' => 'Libro ventas - Devoluciones',
                    'compras' => 'Libro compras - Compras', 'compras_devoluciones' => 'Libro compras - Devoluciones', 'soporte' => 'Documento soporte',
                ]],
            ]],
        ],
    ],
];
