<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Inventory\ProductController;
use App\Http\Controllers\Reports\MovementsController;
use App\Http\Controllers\Reports\ReportsController;
use App\Http\Controllers\Banks\BankController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\Sales\SalesDocumentController;
use App\Http\Controllers\Sales\ReceiptController;
use App\Http\Controllers\Sales\PortfolioController;
use App\Http\Controllers\Purchases\PurchaseDocumentController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Settings\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Settings\AuditController;
use App\Http\Controllers\Settings\CompanyController;
use App\Http\Controllers\Settings\ElectronicInvoicingController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/documentos-firmados/{id}/imprimir', [\App\Http\Controllers\DocumentController::class, 'signedPrint'])
    ->whereNumber('id')->middleware('signed')->name('documents.signed-print');
Route::get('/documento/{id}', \App\Http\Controllers\PublicDocumentController::class)
    ->whereNumber('id')->middleware(['signed', 'throttle:60,1'])->name('documents.public');
Route::get('/nomina-firmada/{id}/imprimir', [\App\Http\Controllers\Payroll\ElectronicPayrollController::class, 'signedPrint'])
    ->whereNumber('id')->middleware('signed')->name('payroll.signed-print');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    $pr = \App\Http\Controllers\Auth\PasswordResetController::class;
    Route::get('/recuperar-contrasena', [$pr, 'request']);
    Route::post('/recuperar-contrasena', [$pr, 'send'])->middleware('throttle:5,1');
    Route::get('/restablecer-contrasena/{token}', [$pr, 'edit'])->name('password.reset');
    Route::post('/restablecer-contrasena', [$pr, 'update'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/inicio/indicadores', [\App\Http\Controllers\IndicatorsController::class, 'index']);
    Route::get('/inicio/habilitaciones', [\App\Http\Controllers\IndicatorsController::class, 'enablement']);

    Route::prefix('configuracion')->group(function () {
        Route::get('/', [SettingsController::class, 'index']);

        Route::middleware('can:configuracion.empresa')->group(function () {
            Route::get('/empresa', [CompanyController::class, 'edit']);
            Route::post('/empresa', [CompanyController::class, 'update']);
        });
        Route::get('/municipios/{department}', [CompanyController::class, 'municipalities']);

        Route::prefix('facturacion-electronica')->middleware('can:configuracion.facturacion_electronica')->group(function () {
            Route::get('/', [ElectronicInvoicingController::class, 'edit']);
            Route::put('/software', [ElectronicInvoicingController::class, 'updateSoftware']);
            Route::post('/certificado', [ElectronicInvoicingController::class, 'uploadCertificate']);
            Route::post('/certificado/prueba', [ElectronicInvoicingController::class, 'testCertificate']);
            Route::delete('/certificado', [ElectronicInvoicingController::class, 'deleteCertificate']);
            Route::post('/probar-conexion', [ElectronicInvoicingController::class, 'testConnection']);
            Route::post('/rangos', [ElectronicInvoicingController::class, 'queryRanges']);
            Route::post('/rangos/aplicar', [ElectronicInvoicingController::class, 'applyRange']);
            Route::post('/set-pruebas/{scope}', [ElectronicInvoicingController::class, 'runTestSet']);
            Route::get('/set-pruebas/{scope}/estado', [ElectronicInvoicingController::class, 'testSetProgress']);
            Route::post('/produccion', [ElectronicInvoicingController::class, 'activateProduction']);
            Route::get('/envios/{transmission}', [ElectronicInvoicingController::class, 'transmission'])->whereNumber('transmission');
        });

        Route::get('/respaldos', [\App\Http\Controllers\Settings\BackupController::class, 'index']);
        Route::post('/respaldos', [\App\Http\Controllers\Settings\BackupController::class, 'store']);
        Route::post('/respaldos/restaurar', [\App\Http\Controllers\Settings\BackupController::class, 'restore']);
        Route::get('/respaldos/{backup}/descargar', [\App\Http\Controllers\Settings\BackupController::class, 'download']);
        Route::delete('/respaldos/{backup}', [\App\Http\Controllers\Settings\BackupController::class, 'destroy']);
        Route::middleware('can:configuracion.usuarios')->group(function () {
            Route::get('/usuarios', [UserController::class, 'index']);
            Route::get('/usuarios/crear', [UserController::class, 'create']);
            Route::post('/usuarios', [UserController::class, 'store']);
            Route::get('/usuarios/{user}/editar', [UserController::class, 'edit']);
            Route::put('/usuarios/{user}', [UserController::class, 'update']);

            Route::get('/perfiles', [RoleController::class, 'index']);
            Route::get('/perfiles/crear', [RoleController::class, 'create']);
            Route::post('/perfiles', [RoleController::class, 'store']);
            Route::get('/perfiles/{role}/editar', [RoleController::class, 'edit']);
            Route::put('/perfiles/{role}', [RoleController::class, 'update']);
        });

        Route::get('/auditoria', [AuditController::class, 'index'])->middleware('can:configuracion.auditoria');

        Route::middleware('can:configuracion.parametros')->group(function () {
            Route::get('/nomina', [\App\Http\Controllers\Settings\PayrollSettingsController::class, 'edit']);
            Route::put('/nomina', [\App\Http\Controllers\Settings\PayrollSettingsController::class, 'update']);

            $pages = array_keys(config('settings_pages'));
            Route::get('/ajustes/{page}', [\App\Http\Controllers\Settings\SettingsPageController::class, 'show'])->whereIn('page', $pages);
            Route::put('/ajustes/{page}', [\App\Http\Controllers\Settings\SettingsPageController::class, 'update'])->whereIn('page', $pages);

            $kinds = array_keys(\App\Http\Controllers\Settings\OpeningBalanceController::KINDS);
            Route::get('/saldos-iniciales/{kind}', [\App\Http\Controllers\Settings\OpeningBalanceController::class, 'show'])->whereIn('kind', $kinds);
            Route::post('/saldos-iniciales/{kind}', [\App\Http\Controllers\Settings\OpeningBalanceController::class, 'import'])->whereIn('kind', $kinds);
        });

        Route::middleware('can:configuracion.parametros')->group(function () {
            Route::get('/impuestos', [\App\Http\Controllers\Settings\TaxController::class, 'index']);
            Route::post('/impuestos', [\App\Http\Controllers\Settings\TaxController::class, 'store']);
            Route::put('/impuestos/{id}', [\App\Http\Controllers\Settings\TaxController::class, 'update'])->whereNumber('id');
            Route::delete('/impuestos/{id}', [\App\Http\Controllers\Settings\TaxController::class, 'destroy'])->whereNumber('id');

            $pi = \App\Http\Controllers\Payroll\PayrollImportController::class;
            $piKinds = array_keys(\App\Http\Controllers\Payroll\PayrollImportController::KINDS);
            Route::get('/nomina/importacion/{kind}', [$pi, 'show'])->whereIn('kind', $piKinds);
            Route::get('/nomina/importacion/{kind}/plantilla', [$pi, 'template'])->whereIn('kind', $piKinds);
            Route::post('/nomina/importacion/{kind}', [$pi, 'upload'])->whereIn('kind', $piKinds);
            Route::post('/nomina/importacion/{kind}/confirmar', [$pi, 'confirm'])->whereIn('kind', $piKinds);

            $vi = \App\Http\Controllers\Settings\VoucherImportController::class;
            Route::get('/importacion-comprobantes', [$vi, 'show']);
            Route::get('/importacion-comprobantes/plantilla', [$vi, 'template']);
            Route::post('/importacion-comprobantes', [$vi, 'upload']);
            Route::post('/importacion-comprobantes/confirmar', [$vi, 'confirm']);

            $dc = \App\Http\Controllers\Settings\DocumentClassController::class;
            $classes = array_keys(config('document_classes'));
            Route::get('/documentos/{class}', [$dc, 'index'])->whereIn('class', $classes);
            Route::get('/documentos/{class}/crear', [$dc, 'create'])->whereIn('class', $classes);
            Route::post('/documentos/{class}', [$dc, 'store'])->whereIn('class', $classes);
            Route::get('/documentos/{class}/{id}/editar', [$dc, 'edit'])->whereIn('class', $classes)->whereNumber('id');
            Route::get('/documentos/{class}/{id}/vista-previa', [$dc, 'preview'])->whereIn('class', $classes)->whereNumber('id');
            Route::put('/documentos/{class}/{id}', [$dc, 'update'])->whereIn('class', $classes)->whereNumber('id');
            Route::delete('/documentos/{class}/{id}', [$dc, 'destroy'])->whereIn('class', $classes)->whereNumber('id');

            $catalogs = array_keys(config('catalogs'));
            Route::get('/{catalog}', [CatalogController::class, 'index'])->whereIn('catalog', $catalogs);
            Route::get('/{catalog}/crear', [CatalogController::class, 'create'])->whereIn('catalog', $catalogs);
            Route::post('/{catalog}', [CatalogController::class, 'store'])->whereIn('catalog', $catalogs);
            Route::get('/{catalog}/{id}/editar', [CatalogController::class, 'edit'])->whereIn('catalog', $catalogs)->whereNumber('id');
            Route::put('/{catalog}/{id}', [CatalogController::class, 'update'])->whereIn('catalog', $catalogs)->whereNumber('id');
        });
    });

    Route::prefix('contabilidad/cuentas')->middleware('can:contabilidad.ver')->group(function () {
        Route::get('/', [AccountController::class, 'index']);
        Route::get('/excel', [AccountController::class, 'export']);
        Route::get('/buscar', [AccountController::class, 'search']);
        Route::get('/arbol', [AccountController::class, 'tree']);
        Route::get('/{account}', [AccountController::class, 'show'])->whereNumber('account');
        Route::get('/{account}/hijos', [AccountController::class, 'children'])->whereNumber('account');
        Route::post('/', [AccountController::class, 'store'])->middleware('can:contabilidad.crear');
        Route::put('/{account}', [AccountController::class, 'update'])->middleware('can:contabilidad.editar');
        Route::delete('/{account}', [AccountController::class, 'destroy'])->middleware('can:contabilidad.editar');
    });

    Route::prefix('terceros')->middleware('can:terceros.ver')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::get('/excel', [ContactController::class, 'export'])->middleware('can:terceros.exportar');
        Route::get('/crear', [ContactController::class, 'create'])->middleware('can:terceros.crear');
        Route::post('/', [ContactController::class, 'store'])->middleware('can:terceros.crear');
        $ci = \App\Http\Controllers\Contacts\ContactImportController::class;
        $ciKinds = array_keys(\App\Http\Controllers\Contacts\ContactImportController::KINDS);
        Route::middleware('can:terceros.crear')->group(function () use ($ci, $ciKinds) {
            Route::get('/importar/{kind}', [$ci, 'show'])->whereIn('kind', $ciKinds);
            Route::get('/importar/{kind}/plantilla', [$ci, 'template'])->whereIn('kind', $ciKinds);
            Route::post('/importar/{kind}', [$ci, 'upload'])->whereIn('kind', $ciKinds);
            Route::post('/importar/{kind}/confirmar', [$ci, 'confirm'])->whereIn('kind', $ciKinds);
        });
        Route::get('/{contact}', [ContactController::class, 'show'])->whereNumber('contact');
        Route::get('/{contact}/editar', [ContactController::class, 'edit'])->whereNumber('contact');
        Route::put('/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->middleware('can:terceros.editar');
    });

    Route::prefix('productos')->middleware('can:inventario.ver')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/excel', [ProductController::class, 'export'])->middleware('can:inventario.exportar');
        Route::get('/crear', [ProductController::class, 'create'])->middleware('can:inventario.crear');
        Route::post('/', [ProductController::class, 'store'])->middleware('can:inventario.crear');
        Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product');
        Route::get('/{product}/editar', [ProductController::class, 'edit'])->whereNumber('product');
        Route::put('/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('can:inventario.editar');
        Route::get('/{product}/kardex', [ProductController::class, 'kardex'])->whereNumber('product')->middleware('can:inventario.ver_costos');
        Route::get('/siguiente-codigo/{line}', [ProductController::class, 'nextCode'])->whereNumber('line');
        Route::get('/combos', [ProductController::class, 'combos']);
        Route::post('/{product}/estado', [ProductController::class, 'toggle'])->whereNumber('product')->middleware('can:inventario.editar');
        Route::post('/{product}/eliminar', [ProductController::class, 'destroy'])->whereNumber('product')->middleware('can:inventario.editar');
        $import = \App\Http\Controllers\Inventory\ProductImportController::class;
        Route::middleware('can:inventario.crear')->group(function () use ($import) {
            Route::get('/importar', [$import, 'show']);
            Route::get('/importar/plantilla', [$import, 'template']);
            Route::get('/importar/existentes', [$import, 'existing']);
            Route::post('/importar', [$import, 'upload']);
            Route::post('/importar/confirmar', [$import, 'confirm']);
            $lines = \App\Http\Controllers\Inventory\InventoryLinesImportController::class;
            Route::get('/lineas-excel/{kind}/plantilla', [$lines, 'template'])->whereIn('kind', ['conteo', 'ajuste']);
            Route::post('/lineas-excel/{kind}', [$lines, 'parse'])->whereIn('kind', ['conteo', 'ajuste']);
        });
        $cost = \App\Http\Controllers\Inventory\CostingController::class;
        Route::post('/costeo/{year}/{month}', [$cost, 'run'])->whereNumber(['year', 'month'])->middleware('can:inventario.editar');
        Route::get('/costeo/{year}/{month}/excel', [$cost, 'excel'])->whereNumber(['year', 'month']);
    });

    Route::prefix('ventas')->middleware('can:ventas.ver')->group(function () {
        Route::get('/envio-masivo', [\App\Http\Controllers\Sales\MassSendController::class, 'index']);
        Route::get('/envio-masivo/imprimir', [\App\Http\Controllers\DocumentController::class, 'batch']);
        Route::post('/envio-masivo/enviar', [\App\Http\Controllers\Sales\MassSendController::class, 'send']);

        $crm = \App\Http\Controllers\Sales\CrmTaskController::class;
        Route::get('/seguimiento-comercial', [$crm, 'index']);
        Route::get('/seguimiento-comercial/crear', [$crm, 'create']);
        Route::post('/seguimiento-comercial', [$crm, 'store']);
        Route::get('/seguimiento-comercial/{task}/editar', [$crm, 'edit'])->whereNumber('task');
        Route::put('/seguimiento-comercial/{task}', [$crm, 'update'])->whereNumber('task');
        Route::post('/seguimiento-comercial/{task}/terminar', [$crm, 'toggle'])->whereNumber('task');
        Route::post('/seguimiento-comercial/{task}/eliminar', [$crm, 'destroy'])->whereNumber('task');

        $rec = \App\Http\Controllers\Sales\RecurringInvoiceController::class;
        Route::get('/facturas-recurrentes', [$rec, 'index']);
        Route::get('/facturas-recurrentes/excel', [$rec, 'excel']);
        Route::middleware('can:ventas.crear')->group(function () use ($rec) {
            Route::get('/facturas-recurrentes/crear', [$rec, 'create']);
            Route::post('/facturas-recurrentes', [$rec, 'store']);
            Route::get('/facturas-recurrentes/{recurring}/editar', [$rec, 'edit'])->whereNumber('recurring');
            Route::put('/facturas-recurrentes/{recurring}', [$rec, 'update'])->whereNumber('recurring');
            Route::post('/facturas-recurrentes/{recurring}/estado', [$rec, 'toggle'])->whereNumber('recurring');
            Route::post('/facturas-recurrentes/{recurring}/generar', [$rec, 'generate'])->whereNumber('recurring');
            Route::post('/facturas-recurrentes/{recurring}/eliminar', [$rec, 'destroy'])->whereNumber('recurring');
        });
    });

    Route::prefix('contabilidad')->middleware('can:contabilidad.ver')->group(function () {
        $b = \App\Http\Controllers\Accounting\BudgetController::class;
        Route::get('/presupuesto', [$b, 'index']);
        Route::get('/presupuesto/{budget}', [$b, 'show'])->whereNumber('budget');
        Route::middleware('can:contabilidad.crear')->group(function () use ($b) {
            Route::get('/presupuesto/crear', [$b, 'create']);
            Route::post('/presupuesto', [$b, 'store']);
            Route::get('/presupuesto/{budget}/editar', [$b, 'edit'])->whereNumber('budget');
            Route::put('/presupuesto/{budget}', [$b, 'update'])->whereNumber('budget');
            Route::post('/presupuesto/{budget}/eliminar', [$b, 'destroy'])->whereNumber('budget');
        });

        $pc = \App\Http\Controllers\Accounting\ProcessesController::class;
        Route::get('/comprobantes-favoritos', [$pc, 'favorites']);
        Route::get('/medios-magneticos', [$pc, 'exogenous']);
        Route::get('/medios-municipales', [$pc, 'municipal']);
        Route::get('/certificados-retencion', [$pc, 'certificates']);
        Route::middleware('can:contabilidad.crear')->group(function () use ($pc) {
            Route::get('/cierre-anio', [$pc, 'yearCloses']);
            Route::get('/cierre-anio/crear', [$pc, 'yearClose']);
            Route::post('/cierre-anio', [$pc, 'yearCloseStore']);
            Route::post('/cierre-anio/{entry}/borrar', [$pc, 'yearCloseDelete'])->whereNumber('entry');
            Route::get('/cierre-impuestos', [$pc, 'taxClose']);
            Route::post('/cierre-impuestos', [$pc, 'taxCloseStore']);
            Route::get('/diferencia-cambio', [$pc, 'exchange']);
            Route::get('/diferencia-cambio/revisar', [$pc, 'exchangeReview']);
            Route::post('/diferencia-cambio', [$pc, 'exchangeStore']);
        });
        Route::middleware('can:contabilidad.anular')->group(function () use ($pc) {
            Route::get('/borrado-masivo', [$pc, 'massDelete']);
            Route::post('/borrado-masivo', [$pc, 'massDeleteStore']);
        });

        $vc = \App\Http\Controllers\Accounting\VerificationController::class;
        Route::get('/verifica-documentos', [$vc, 'index']);
        Route::get('/verifica-documentos/excel', [$vc, 'excel']);
        Route::post('/verifica-documentos', [$vc, 'store'])->middleware('can:contabilidad.cerrar_periodo');

        $ob = \App\Http\Controllers\Accounting\OpeningBalanceController::class;
        $obKinds = array_keys(\App\Services\Accounting\OpeningBalances::KINDS);
        Route::middleware('can:contabilidad.crear')->group(function () use ($ob, $obKinds) {
            Route::get('/saldos-iniciales/{kind}', [$ob, 'create'])->whereIn('kind', $obKinds);
            Route::post('/saldos-iniciales/{kind}', [$ob, 'store'])->whereIn('kind', $obKinds);
            Route::get('/saldos-iniciales/{kind}/plantilla', [$ob, 'template'])->whereIn('kind', $obKinds);
            Route::post('/saldos-iniciales/{kind}/excel', [$ob, 'excel'])->whereIn('kind', $obKinds);
        });

        $fa = \App\Http\Controllers\Accounting\FixedAssetImportController::class;
        Route::middleware('can:contabilidad.crear')->group(function () use ($fa) {
            Route::get('/activos-fijos/importar', [$fa, 'show']);
            Route::get('/activos-fijos/importar/plantilla', [$fa, 'template']);
            Route::post('/activos-fijos/importar', [$fa, 'upload']);
            Route::post('/activos-fijos/importar/confirmar', [$fa, 'confirm']);
        });
    });

    Route::get('/ventas', [SalesDocumentController::class, 'index'])->middleware('can:ventas.ver');
    Route::get('/ventas/recaudos', [ReceiptController::class, 'cashReceipts'])->middleware('can:ventas.ver');
    Route::get('/compras', [PurchaseDocumentController::class, 'index'])->middleware('can:compras.ver');
    Route::get('/compras/pagos', [ReceiptController::class, 'paymentReceipts'])->middleware('can:compras.ver');
    Route::middleware('can:contabilidad.ver')->group(function () {
        Route::get('/contabilidad/comprobantes', [JournalController::class, 'index']);
        Route::get('/contabilidad/comprobantes/{journal}', [JournalController::class, 'show'])->whereNumber('journal');
        Route::get('/contabilidad/libro-auxiliar', [\App\Http\Controllers\Accounting\LedgerController::class, 'auxiliary']);
        Route::get('/contabilidad/balance-prueba', [\App\Http\Controllers\Accounting\LedgerController::class, 'trialBalance']);
        Route::get('/contabilidad/libro-mayor', [\App\Http\Controllers\Accounting\LedgerController::class, 'generalLedger']);
        Route::get('/contabilidad/libro-diario', [\App\Http\Controllers\Accounting\LedgerController::class, 'journalBook']);
        Route::get('/contabilidad/situacion-financiera', [\App\Http\Controllers\Accounting\LedgerController::class, 'financialPosition']);
        Route::get('/contabilidad/estado-resultados', [\App\Http\Controllers\Accounting\LedgerController::class, 'incomeStatement']);
    });

    Route::get('/reportes', [ReportsController::class, 'index'])->middleware('can:reportes.ver');
    Route::get('/reportes/{slug}', [\App\Http\Controllers\Reports\GenericReportController::class, 'show'])
        ->whereIn('slug', array_keys(\App\Reports\ReportRegistry::all()))->middleware('can:reportes.ver');
    Route::get('/bancos', [BankController::class, 'index'])->middleware('can:bancos.ver');
    Route::get('/bancos/excel', [BankController::class, 'excel'])->middleware('can:bancos.ver');
    Route::prefix('bancos/conciliacion')->group(function () {
        $c = \App\Http\Controllers\Banks\ReconciliationController::class;
        Route::get('/', [$c, 'index'])->middleware('can:bancos.ver');
        Route::post('/importar', [$c, 'import'])->middleware('can:bancos.crear');
        Route::post('/auto', [$c, 'auto'])->middleware('can:bancos.editar');
        Route::post('/cruzar', [$c, 'match'])->middleware('can:bancos.editar');
        Route::post('/descruzar', [$c, 'unmatch'])->middleware('can:bancos.editar');
        Route::post('/contabilizar', [$c, 'post'])->middleware('can:bancos.crear');
        Route::delete('/extracto/{statement}', [$c, 'destroy'])->whereNumber('statement')->middleware('can:bancos.anular');
    });
    Route::prefix('nomina')->middleware('can:nomina.ver')->group(function () {
        Route::get('/', [PayrollController::class, 'runs']);
        Route::get('/empleados', [PayrollController::class, 'employees']);
        Route::get('/documentos', [PayrollController::class, 'documents']);
        $ne = \App\Http\Controllers\Payroll\ElectronicPayrollController::class;
        Route::post('/documentos/generar', [$ne, 'generate'])->middleware('can:nomina.liquidar');
        Route::post('/documentos/enviar', [$ne, 'send'])->middleware('can:nomina.liquidar');
        Route::get('/documentos/{id}', [$ne, 'show'])->whereNumber('id');
        Route::get('/documentos/{id}/xml', [$ne, 'xml'])->whereNumber('id');
        Route::post('/documentos/{id}/ajuste', [$ne, 'adjust'])->whereNumber('id')->middleware('can:nomina.liquidar');
        Route::delete('/documentos/{id}', [$ne, 'destroy'])->whereNumber('id')->middleware('can:nomina.liquidar');
        Route::get('/documentos/excel', [$ne, 'excel']);
        Route::get('/documentos/{id}/pdf', [$ne, 'pdf'])->whereNumber('id');
        Route::post('/documentos/correo', [$ne, 'mail'])->middleware('can:nomina.liquidar');
        Route::put('/documentos/{id}/correo', [$ne, 'updateEmail'])->whereNumber('id')->middleware('can:nomina.liquidar');
        Route::put('/documentos/configuracion', [$ne, 'updateConfig'])->middleware('can:nomina.liquidar');

        $p = \App\Http\Controllers\Payroll\PayrollRunController::class;
        Route::get('/crear', [$p, 'create'])->middleware('can:nomina.crear');
        Route::post('/', [$p, 'store'])->middleware('can:nomina.crear');
        Route::get('/pila', [$p, 'pila']);
        Route::get('/pila/descargar', [$p, 'pilaExport']);
        Route::get('/pila/csv', [$p, 'pilaExport']);
        Route::get('/vacaciones', [$p, 'vacations']);
        Route::get('/certificado-ingresos', [$p, 'certificate']);
        Route::post('/empleados', [$p, 'storeEmployee'])->middleware('can:nomina.crear');
        Route::get('/empleados/{employee}/editar', [$p, 'editEmployee'])->whereNumber('employee')->middleware('can:nomina.editar');
        Route::put('/empleados/{employee}', [$p, 'updateEmployee'])->whereNumber('employee')->middleware('can:nomina.editar');
        Route::prefix('{run}')->whereNumber('run')->group(function () use ($p) {
            Route::get('/', [$p, 'show']);
            Route::get('/colilla/{doc}', [$p, 'payslip'])->whereNumber('doc');
            Route::post('/novedades', [$p, 'novelty'])->middleware('can:nomina.editar');
            Route::delete('/novedades/{novelty}', [$p, 'removeNovelty'])->whereNumber('novelty')->middleware('can:nomina.editar');
            Route::post('/calcular', [$p, 'recalculate'])->middleware('can:nomina.editar');
            Route::post('/contabilizar', [$p, 'post'])->middleware('can:nomina.liquidar');
            Route::post('/pagar', [$p, 'pay'])->middleware('can:nomina.liquidar');
            Route::post('/reabrir', [$p, 'reopen'])->middleware('can:nomina.liquidar');
            Route::delete('/', [$p, 'destroy'])->middleware('can:nomina.editar');
        });
    });

    Route::prefix('movimientos')->middleware('can:movimientos.ver')->group(function () {
        Route::get('/', [MovementsController::class, 'index']);
        Route::get('/excel', [MovementsController::class, 'export'])->middleware('can:movimientos.exportar');
    });

    Route::get('/ayuda', [\App\Http\Controllers\AssistController::class, 'help']);
    Route::prefix('api')->group(function () {
        Route::get('/buscar-global', [\App\Http\Controllers\AssistController::class, 'search']);
        Route::get('/notificaciones', [\App\Http\Controllers\AssistController::class, 'notifications']);
        Route::get('/cuentas', [LookupController::class, 'accounts']);
        Route::get('/municipios', [LookupController::class, 'municipalities']);
        Route::get('/terceros', [ContactController::class, 'search']);
        Route::get('/terceros/dv', [ContactController::class, 'dv']);
        Route::get('/productos', [ProductController::class, 'search']);
        Route::get('/buscar/terceros', [LookupController::class, 'contacts']);
        Route::get('/buscar/productos', [LookupController::class, 'products']);
        Route::get('/buscar/documentos', [LookupController::class, 'documents']);
        Route::get('/buscar/pendientes', [LookupController::class, 'pending']);
        Route::get('/buscar/anticipos', [LookupController::class, 'advances']);
        Route::get('/buscar/activos', [LookupController::class, 'fixedAssets']);
        Route::get('/buscar/documentos/{id}/items', [LookupController::class, 'documentItems'])->whereNumber('id');
        Route::get('/buscar/documentos/{id}/encabezado', [LookupController::class, 'documentHeader'])->whereNumber('id');
    });

    Route::prefix('documentos/{kind}/{id}')->whereIn('kind', array_keys(\App\Services\Documents\DocumentLocator::KINDS))->whereNumber('id')->group(function () {
        Route::get('/', [\App\Http\Controllers\DocumentController::class, 'show']);
        Route::post('/anular', [\App\Http\Controllers\DocumentController::class, 'annul']);
        Route::post('/eliminar', [\App\Http\Controllers\DocumentController::class, 'destroy']);
        Route::get('/xml', [\App\Http\Controllers\DocumentController::class, 'xml']);
        Route::get('/imprimir', [\App\Http\Controllers\DocumentController::class, 'print']);
        Route::post('/favorito', [\App\Http\Controllers\DocumentController::class, 'favorite']);
        Route::post('/estado', [\App\Http\Controllers\DocumentController::class, 'quoteStatus']);
        Route::post('/correo', [\App\Http\Controllers\DocumentController::class, 'mail']);
        Route::post('/dian', [\App\Http\Controllers\DocumentController::class, 'resendDian']);
        Route::post('/actualizar-dian', [\App\Http\Controllers\DocumentController::class, 'refreshDian']);
        Route::post('/enlace', [\App\Http\Controllers\DocumentController::class, 'shareLink']);
        Route::post('/cobranza', [\App\Http\Controllers\DocumentController::class, 'collection']);
        Route::post('/adjuntos', [\App\Http\Controllers\DocumentController::class, 'attach']);
        Route::get('/editar', [\App\Http\Controllers\DocumentFormController::class, 'edit']);
        Route::post('/editar', [\App\Http\Controllers\DocumentFormController::class, 'update']);
    });

    Route::get('/adjuntos/{id}', function (int $id) {
        $file = \App\Models\Attachment::findOrFail($id);
        if ($owner = $file->attachable) {
            \Illuminate\Support\Facades\Gate::authorize(\App\Services\Documents\DocumentLocator::module($owner).'.ver');
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->download($file->path, $file->name);
    })->whereNumber('id');

    Route::get('/bandeja-dian', [\App\Http\Controllers\Purchases\DianInboxController::class, 'index'])->middleware('can:compras.ver');
    Route::post('/bandeja-dian/sincronizar', [\App\Http\Controllers\Purchases\DianInboxController::class, 'sync'])->middleware('can:compras.crear');
    Route::get('/bandeja-dian/{id}/xml', [\App\Http\Controllers\Purchases\DianInboxController::class, 'xml'])->whereNumber('id')->middleware('can:compras.ver');
    Route::get('/compras/soporte-legalizar', [\App\Http\Controllers\ModuleViewController::class, 'supportsPage'])->middleware('can:compras.ver');
    Route::get('/compras/soporte-legalizar/excel', [\App\Http\Controllers\ModuleViewController::class, 'supportsExcel'])->middleware('can:compras.ver');
    Route::post('/compras/soporte-legalizar', function (\Illuminate\Http\Request $request) {
        $request->validate(['file' => ['required', 'file', 'max:10240']], ['file.required' => 'Selecciona el archivo del soporte', 'file.max' => 'El soporte no puede superar 10 MB']);
        $file = $request->file('file');
        \App\Models\ExpenseSupport::create([
            'user_id' => auth()->id(), 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(),
            'path' => $file->store('soportes/'.\App\Support\CurrentCompany::id(), 'local'),
        ]);

        return back()->with('success', 'Se cargó el soporte. Pulsa Causar para registrarlo como factura de compra.');
    })->middleware('can:compras.crear');
    Route::get('/compras/soporte-legalizar/{id}', function (int $id) {
        $s = \App\Models\ExpenseSupport::query()->findOrFail($id);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($s->path, $s->name);
    })->whereNumber('id')->middleware('can:compras.ver');
    Route::post('/compras/soporte-legalizar/{id}/eliminar', function (int $id) {
        \App\Models\ExpenseSupport::query()->where('status', 'Sin causar')->findOrFail($id)->delete();

        return back()->with('success', 'Se eliminó el soporte.');
    })->whereNumber('id')->middleware('can:compras.crear');
    Route::prefix('compras/registro-eventos')->group(function () {
        $r = \App\Http\Controllers\Purchases\RadianEventController::class;
        Route::get('/', [$r, 'index'])->middleware('can:compras.ver');
        Route::post('/', [$r, 'store'])->middleware('can:compras.crear');
        Route::post('/{id}/mercancia', [$r, 'goods'])->whereNumber('id')->middleware('can:compras.crear');
        Route::post('/{id}/aceptar', [$r, 'accept'])->whereNumber('id')->middleware('can:compras.crear');
        Route::post('/{id}/reclamo', [$r, 'claim'])->whereNumber('id')->middleware('can:compras.crear');
        Route::delete('/{id}', [$r, 'destroy'])->whereNumber('id')->middleware('can:compras.crear');
        Route::get('/{id}/xml/{index}', [$r, 'xml'])->whereNumber(['id', 'index'])->middleware('can:compras.ver');
    });
    Route::get('/compras/importar-xml', [\App\Http\Controllers\Purchases\XmlImportController::class, 'create'])->middleware('can:compras.crear');
    Route::post('/compras/importar-xml', [\App\Http\Controllers\Purchases\XmlImportController::class, 'store'])->middleware('can:compras.crear');
    Route::post('/compras/importar-xml/{id}/vincular-ia', [\App\Http\Controllers\Purchases\XmlImportController::class, 'match'])->whereNumber('id')->middleware('can:compras.crear');

    foreach (\App\Http\Controllers\DocumentFormController::FORMS as $uri => $form) {
        Route::get($uri, [\App\Http\Controllers\DocumentFormController::class, 'create'])->middleware("can:{$form['perm']}")->defaults('form', $uri);
        Route::post($uri, [\App\Http\Controllers\DocumentFormController::class, 'store'])->middleware("can:{$form['perm']}")->defaults('form', $uri);
    }

    Route::redirect('/contabilidad', '/contabilidad/cuentas');
    Route::get('/ventas/clientes', [PortfolioController::class, 'customers'])->middleware('can:ventas.ver');
    Route::get('/ventas/clientes/{contact}', [PortfolioController::class, 'customerStatement'])->whereNumber('contact')->middleware('can:ventas.ver');
    Route::post('/ventas/clientes/{contact}/enviar', [PortfolioController::class, 'sendStatement'])->whereNumber('contact')->middleware('can:ventas.ver');
    Route::get('/compras/proveedores', [PortfolioController::class, 'suppliers'])->middleware('can:compras.ver');
    Route::get('/compras/proveedores/{contact}', [PortfolioController::class, 'supplierStatement'])->whereNumber('contact')->middleware('can:compras.ver');

    foreach (\App\Http\Controllers\ModuleViewController::processRoutes() as $uri) {
        Route::get('/'.$uri, [\App\Http\Controllers\ModuleViewController::class, 'processes'])->middleware('can:'.explode('/', $uri)[0].'.ver');
    }
    Route::get('/productos/documentos/excel', [\App\Http\Controllers\ModuleViewController::class, 'excel'])->middleware('can:inventario.ver_costos');
    Route::get('/ventas/remisiones/excel', [\App\Http\Controllers\ModuleViewController::class, 'excel'])->middleware('can:ventas.ver');
    Route::get('/ventas/cotizaciones/excel', [\App\Http\Controllers\ModuleViewController::class, 'excel'])->middleware('can:ventas.ver');
    foreach (\App\Http\Controllers\ModuleViewController::routes() as $uri) {
        Route::get('/'.$uri, [\App\Http\Controllers\ModuleViewController::class, 'show'])
            ->middleware('can:'.(['bandeja-dian' => 'compras', 'productos' => 'inventario'][explode('/', $uri)[0]] ?? explode('/', $uri)[0]).'.ver');
    }
});
