<?php

declare(strict_types=1);

namespace App\Enums;

enum PermissionCode: string
{
    case ArticlesView = 'articulos.ver';
    case ArticlesManage = 'articulos.gestionar';
    case SuppliersView = 'proveedores.ver';
    case SuppliersManage = 'proveedores.gestionar';
    case InventoryCostsView = 'inventario.costos.ver';
    case RecipesView = 'recetas.ver';
    case RecipesManage = 'recetas.gestionar';
    case InventoryReceiptsView = 'inventario.recepciones.ver';
    case InventoryReceiptsCreate = 'inventario.recepciones.crear';
    case InventoryReceiptsEdit = 'inventario.recepciones.editar';
    case InventoryReceiptsConfirm = 'inventario.recepciones.confirmar';
    case InventoryReceiptsAnnul = 'inventario.recepciones.anular';
    case InventoryReceiptsOtherOrigin = 'inventario.recepciones.origen_otro';
    case InventoryReceiptsRetroactive = 'inventario.recepciones.retroactivas';
    case InventoryCostsRegister = 'inventario.costos.registrar';
    case InventoryInitialBalanceExecute = 'inventario.saldo_inicial.ejecutar';
    case InventoryAdjustmentsInput = 'inventario.ajustes.entrada';
    case InventoryAdjustmentsOutput = 'inventario.ajustes.salida';
    case InventoryAdjustmentsRetroactive = 'inventario.ajustes.retroactivos';
    case InventoryCountsManage = 'inventario.conteos.gestionar';
    case InventoryCountsConfirm = 'inventario.conteos.confirmar';
    case InventoryWasteRegister = 'inventario.mermas.registrar';
    case InventoryWasteAnnul = 'inventario.mermas.anular';
    case InventoryWasteRetroactive = 'inventario.mermas.retroactivas';
    case InventoryMovementsView = 'inventario.movimientos.ver';
    case InventoryDailyStockRecalculate = 'inventario.stock_diario.recalcular';
    case SalesCreate = 'ventas.crear';
    case SalesAnnul = 'ventas.anular';
    case OrdersCreate = 'pedidos.crear';
    case OrdersManage = 'pedidos.gestionar';
    case OrdersCancel = 'pedidos.cancelar';
    case OrdersDeliver = 'pedidos.entregar';
    case OrdersCollect = 'pedidos.cobrar';
    case CashSessionsView = 'caja.sesiones.ver';
    case CashSessionsOpen = 'caja.sesiones.abrir';
    case CashSessionsClose = 'caja.sesiones.cerrar';
    case CashFundsAuthorize = 'caja.fondos.autorizar';
    case CashMovementsView = 'caja.movimientos.ver';
    case CashManualMovementsManage = 'caja.movimientos.gestionar';
    case ProductionConfirm = 'produccion.confirmar';
    case ProductionAnnul = 'produccion.anular';
    case ProductionView = 'produccion.ver';
    case ProductionManage = 'produccion.gestionar';
    case PersonnelView = 'personal.ver';
    case PersonnelManage = 'personal.gestionar';
    case HourlyRatesView = 'personal.tarifas.ver';
    case HourlyRatesManage = 'personal.tarifas.gestionar';
    case HourlyPayView = 'personal.pago_hora.ver';
    case AttendanceMark = 'asistencia.marcar';
    case AttendanceManage = 'asistencia.gestionar';
    case UsersView = 'usuarios.ver';
    case UsersManage = 'usuarios.gestionar';
    case RolesView = 'roles.ver';
    case RolesManage = 'roles.gestionar';
    case PermissionsView = 'permisos.ver';
    case RolePermissionsManage = 'roles.permisos.gestionar';

    public function module(): string
    {
        return explode('.', $this->value, 2)[0];
    }

    public function label(): string
    {
        return match ($this) {
            self::ArticlesView => 'Ver articulos',
            self::ArticlesManage => 'Gestionar articulos',
            self::SuppliersView => 'Ver proveedores',
            self::SuppliersManage => 'Gestionar proveedores',
            self::InventoryCostsView => 'Ver costos de inventario',
            self::RecipesView => 'Ver recetas',
            self::RecipesManage => 'Gestionar recetas',
            self::InventoryReceiptsView => 'Ver recepciones de mercaderia',
            self::InventoryReceiptsCreate => 'Crear recepciones de mercaderia',
            self::InventoryReceiptsEdit => 'Editar recepciones en borrador',
            self::InventoryReceiptsConfirm => 'Confirmar recepciones de mercaderia',
            self::InventoryReceiptsAnnul => 'Anular recepciones confirmadas',
            self::InventoryReceiptsOtherOrigin => 'Usar otro origen autorizado en recepciones',
            self::InventoryReceiptsRetroactive => 'Registrar recepciones retroactivas',
            self::InventoryCostsRegister => 'Registrar costos de inventario',
            self::InventoryInitialBalanceExecute => 'Ejecutar saldo inicial de inventario',
            self::InventoryAdjustmentsInput => 'Registrar ajustes manuales de entrada',
            self::InventoryAdjustmentsOutput => 'Registrar ajustes manuales de salida',
            self::InventoryAdjustmentsRetroactive => 'Registrar ajustes manuales retroactivos',
            self::InventoryCountsManage => 'Gestionar conteos de inventario',
            self::InventoryCountsConfirm => 'Confirmar conteos de inventario',
            self::InventoryWasteRegister => 'Registrar mermas de inventario',
            self::InventoryWasteAnnul => 'Anular mermas de inventario',
            self::InventoryWasteRetroactive => 'Registrar mermas retroactivas',
            self::InventoryMovementsView => 'Ver movimientos de inventario',
            self::InventoryDailyStockRecalculate => 'Recalcular stock diario',
            self::SalesCreate => 'Crear ventas',
            self::SalesAnnul => 'Anular ventas',
            self::OrdersCreate => 'Crear pedidos',
            self::OrdersManage => 'Gestionar pedidos',
            self::OrdersCancel => 'Cancelar pedidos',
            self::OrdersDeliver => 'Entregar pedidos',
            self::OrdersCollect => 'Cobrar pedidos',
            self::CashSessionsView => 'Ver sesiones de caja',
            self::CashSessionsOpen => 'Abrir sesiones de caja',
            self::CashSessionsClose => 'Cerrar sesiones de caja',
            self::CashFundsAuthorize => 'Autorizar fondos de caja',
            self::CashMovementsView => 'Ver movimientos de caja',
            self::CashManualMovementsManage => 'Gestionar movimientos manuales de caja',
            self::ProductionConfirm => 'Confirmar produccion',
            self::ProductionAnnul => 'Anular produccion',
            self::ProductionView => 'Ver produccion',
            self::ProductionManage => 'Gestionar produccion',
            self::PersonnelView => 'Ver personal',
            self::PersonnelManage => 'Gestionar personal',
            self::HourlyRatesView => 'Ver tarifas por hora',
            self::HourlyRatesManage => 'Gestionar tarifas por hora',
            self::HourlyPayView => 'Ver pagos operacionales por hora',
            self::AttendanceMark => 'Marcar asistencia propia',
            self::AttendanceManage => 'Gestionar asistencia',
            self::UsersView => 'Ver usuarios',
            self::UsersManage => 'Gestionar usuarios',
            self::RolesView => 'Ver roles',
            self::RolesManage => 'Gestionar roles',
            self::PermissionsView => 'Ver permisos',
            self::RolePermissionsManage => 'Gestionar permisos de roles',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ArticlesView => 'Permite listar, buscar y consultar articulos, stock actual, stock minimo y precios vigentes.',
            self::ArticlesManage => 'Permite crear, editar, activar, desactivar y actualizar precios de articulos sin modificar stock directamente.',
            self::SuppliersView => 'Permite consultar proveedores, sus articulos asociados y los costos historicos autorizados.',
            self::SuppliersManage => 'Permite crear, editar, activar, desactivar y gestionar asociaciones entre proveedores y articulos.',
            self::InventoryCostsView => 'Permite consultar costos vigentes e historicos originados por recepciones de mercaderia.',
            self::RecipesView => 'Permite consultar recetas, ingredientes, versiones y vigencia.',
            self::RecipesManage => 'Permite crear recetas y nuevas versiones sin reescribir historiales ni modificar stock.',
            self::InventoryReceiptsView => 'Permite consultar recepciones de mercaderia y sus detalles.',
            self::InventoryReceiptsCreate => 'Permite crear nuevas recepciones de mercaderia en borrador.',
            self::InventoryReceiptsEdit => 'Permite modificar recepciones de mercaderia que siguen en borrador.',
            self::InventoryReceiptsConfirm => 'Permite confirmar recepciones e incorporar sus efectos de inventario.',
            self::InventoryReceiptsAnnul => 'Permite anular recepciones mediante operaciones compensatorias trazables.',
            self::InventoryReceiptsOtherOrigin => 'Permite registrar una recepcion con otro origen expresamente autorizado.',
            self::InventoryReceiptsRetroactive => 'Permite registrar recepciones con una fecha comercial anterior.',
            self::InventoryCostsRegister => 'Permite registrar costos oficiales asociados a articulos de inventario.',
            self::InventoryInitialBalanceExecute => 'Permite documentar el saldo inicial al activar el ledger de inventario.',
            self::InventoryAdjustmentsInput => 'Permite aumentar stock mediante un ajuste manual de entrada trazable.',
            self::InventoryAdjustmentsOutput => 'Permite disminuir stock mediante un ajuste manual de salida trazable.',
            self::InventoryAdjustmentsRetroactive => 'Permite fechar retroactivamente ajustes manuales autorizados.',
            self::InventoryCountsManage => 'Permite crear y registrar conteos fisicos de inventario.',
            self::InventoryCountsConfirm => 'Permite confirmar conteos y aplicar sus ajustes de inventario.',
            self::InventoryWasteRegister => 'Permite registrar mermas con impacto trazable en inventario.',
            self::InventoryWasteAnnul => 'Permite anular mermas mediante movimientos compensatorios.',
            self::InventoryWasteRetroactive => 'Permite registrar mermas con una fecha comercial anterior.',
            self::InventoryMovementsView => 'Permite consultar el ledger causal de movimientos de inventario.',
            self::InventoryDailyStockRecalculate => 'Permite reconstruir la proyeccion diaria derivada de stock.',
            self::SalesCreate => 'Permite crear ventas con sus efectos operacionales autorizados.',
            self::SalesAnnul => 'Permite anular ventas mediante operaciones compensatorias trazables.',
            self::OrdersCreate => 'Permite crear pedidos con sus detalles y datos de entrega.',
            self::OrdersManage => 'Permite gestionar el ciclo operativo de los pedidos.',
            self::OrdersCancel => 'Permite cancelar pedidos dejando un motivo trazable.',
            self::OrdersDeliver => 'Permite entregar pedidos y registrar su transicion operacional.',
            self::OrdersCollect => 'Permite cobrar pedidos mediante pagos asociados.',
            self::CashSessionsView => 'Permite consultar sesiones, montos y cierres historicos de caja.',
            self::CashSessionsOpen => 'Permite abrir una sesion de caja con el fondo previamente autorizado para su fecha operativa.',
            self::CashSessionsClose => 'Permite cerrar sesiones de caja registrando monto declarado y calculo teorico.',
            self::CashFundsAuthorize => 'Permite autorizar el primer fondo y el fondo de la proxima jornada al confirmar un cierre.',
            self::CashMovementsView => 'Permite consultar movimientos de caja y su causalidad operacional.',
            self::CashManualMovementsManage => 'Permite gestionar movimientos manuales de caja trazables.',
            self::ProductionConfirm => 'Permite confirmar producciones y sus movimientos de inventario.',
            self::ProductionAnnul => 'Permite anular producciones mediante movimientos compensatorios.',
            self::ProductionView => 'Permite consultar producciones, recetas usadas, consumos y trazabilidad.',
            self::ProductionManage => 'Permite crear producciones y preparar sus datos previos a la confirmacion.',
            self::PersonnelView => 'Permite consultar trabajadores y sus datos operativos.',
            self::PersonnelManage => 'Permite crear, actualizar, activar y desactivar trabajadores.',
            self::HourlyRatesView => 'Permite consultar el historial de tarifas horarias.',
            self::HourlyRatesManage => 'Permite registrar tarifas horarias append-only.',
            self::HourlyPayView => 'Permite consultar reportes de pago operacional por hora.',
            self::AttendanceMark => 'Permite marcar el inicio y cierre de la propia jornada.',
            self::AttendanceManage => 'Permite gestionar asistencia, correcciones manuales y anulaciones.',
            self::UsersView => 'Permite listar y consultar usuarios, su estado y rol efectivo.',
            self::UsersManage => 'Permite crear, editar, activar, desactivar, cambiar rol y administrar contrasenas de usuarios.',
            self::RolesView => 'Permite consultar roles, usuarios asociados y permisos asignados.',
            self::RolesManage => 'Permite crear, editar y activar o desactivar roles cuando corresponda.',
            self::PermissionsView => 'Permite consultar el catalogo persistido de permisos y sus asignaciones.',
            self::RolePermissionsManage => 'Permite asignar y quitar permisos existentes de los roles de forma segura.',
        };
    }

    public function level(): string
    {
        return match ($this) {
            self::InventoryReceiptsOtherOrigin,
            self::InventoryReceiptsRetroactive,
            self::InventoryInitialBalanceExecute,
            self::InventoryAdjustmentsRetroactive,
            self::InventoryWasteRetroactive,
            self::InventoryDailyStockRecalculate => 'elevado',
            self::CashFundsAuthorize => 'elevado',
            self::PersonnelManage,
            self::HourlyRatesView,
            self::HourlyRatesManage,
            self::HourlyPayView,
            self::AttendanceManage => 'elevado',
            default => 'ordinario',
        };
    }
}
