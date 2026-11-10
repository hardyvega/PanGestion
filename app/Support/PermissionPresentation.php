<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PermissionCode;

final class PermissionPresentation
{
    public static function description(string $code): string
    {
        return match (PermissionCode::tryFrom($code)) {
            PermissionCode::ArticlesView => 'Consultar artículos, stock disponible, stock mínimo y precios de venta, sin modificarlos.',
            PermissionCode::ArticlesManage => 'Crear y editar artículos, gestionar sus imágenes, estado y precios de venta. No modifica stock directamente.',
            PermissionCode::SuppliersView => 'Consultar proveedores y sus artículos asociados. Los costos requieren el permiso de consulta de costos.',
            PermissionCode::SuppliersManage => 'Crear y editar proveedores, cambiar su estado y asociar o desasociar artículos cuando sea seguro.',
            PermissionCode::InventoryCostsView => 'Consultar costos vigentes e históricos y la recepción que los originó, sin crear ni modificar costos.',
            PermissionCode::RecipesView => 'Consultar recetas, ingredientes, versiones y vigencia, sin modificarlos.',
            PermissionCode::RecipesManage => 'Crear recetas, agregar versiones y activar una versión. No modifica el stock ni reemplaza el historial.',
            PermissionCode::InventoryReceiptsView => 'Consultar recepciones de mercadería, sus artículos y su estado.',
            PermissionCode::InventoryReceiptsCreate => 'Crear borradores de recepción de mercadería. El stock cambia solo al confirmarlos.',
            PermissionCode::InventoryReceiptsEdit => 'Editar los datos y artículos de una recepción mientras siga en borrador.',
            PermissionCode::InventoryReceiptsConfirm => 'Confirmar mercadería recibida y sumar su stock. También requiere el permiso para registrar costos.',
            PermissionCode::InventoryReceiptsAnnul => 'Anular recepciones confirmadas con un motivo, revirtiendo su inventario cuando las validaciones lo permitan.',
            PermissionCode::InventoryReceiptsOtherOrigin => 'Usar la opción de otro origen autorizado al crear o editar recepciones. Requiere además el permiso de esa operación.',
            PermissionCode::InventoryReceiptsRetroactive => 'Usar una fecha anterior en recepciones, con motivo y los permisos de la operación correspondiente.',
            PermissionCode::InventoryCostsRegister => 'Registrar costos oficiales al confirmar una recepción autorizada. No habilita el ingreso manual de costos.',
            PermissionCode::InventoryInitialBalanceExecute => 'Documentar el stock existente al iniciar el registro de inventario, sin volver a sumarlo.',
            PermissionCode::InventoryAdjustmentsInput => 'Registrar entradas manuales de stock con su motivo y movimiento de inventario.',
            PermissionCode::InventoryAdjustmentsOutput => 'Registrar salidas manuales de stock con su motivo, sin permitir stock negativo.',
            PermissionCode::InventoryAdjustmentsRetroactive => 'Usar una fecha anterior en un ajuste de stock. Requiere además el permiso de entrada o salida correspondiente.',
            PermissionCode::InventoryCountsManage => 'Crear conteos físicos y registrar cantidades contadas, sin aplicar diferencias hasta su confirmación.',
            PermissionCode::InventoryCountsConfirm => 'Confirmar conteos físicos y aplicar las diferencias de stock permitidas por el sistema.',
            PermissionCode::InventoryWasteRegister => 'Registrar pérdidas o mermas con su motivo y descuento de stock.',
            PermissionCode::InventoryWasteAnnul => 'Anular una merma con motivo y devolver su stock mediante un movimiento de reversa.',
            PermissionCode::InventoryWasteRetroactive => 'Usar una fecha anterior al registrar una merma. También requiere el permiso para registrar mermas.',
            PermissionCode::InventoryMovementsView => 'Consultar entradas, salidas y motivos del registro de inventario, sin modificarlos.',
            PermissionCode::InventoryDailyStockRecalculate => 'Reconstruir el resumen diario de stock a partir de sus movimientos, sin cambiar el historial original.',
            PermissionCode::SalesCreate => 'Completar ventas, registrar sus pagos y descontar stock, sujeto a las validaciones de caja e inventario.',
            PermissionCode::SalesAnnul => 'Anular ventas y registrar las devoluciones y reversas correspondientes, según las validaciones del sistema.',
            PermissionCode::OrdersCreate => 'Crear pedidos con productos y datos de entrega. Registrar un abono requiere además el permiso para cobrar pedidos.',
            PermissionCode::OrdersManage => 'Cambiar pedidos entre los estados confirmados, en preparación y listos, según su estado actual.',
            PermissionCode::OrdersCancel => 'Cancelar pedidos con un motivo, conservando su historial y las devoluciones correspondientes.',
            PermissionCode::OrdersDeliver => 'Entregar pedidos listos y convertirlos en venta. Cobrar un saldo final requiere además el permiso para cobrar pedidos.',
            PermissionCode::OrdersCollect => 'Registrar cobros de pedidos dentro de los flujos autorizados de creación o entrega, con caja disponible.',
            PermissionCode::CashSessionsView => 'Consultar sesiones de caja, sus montos, saldos y cierres anteriores.',
            PermissionCode::CashSessionsOpen => 'Abrir la caja con el fondo previamente autorizado, sin elegir ni modificar su monto.',
            PermissionCode::CashFundsAuthorize => 'Autorizar el primer fondo y el fondo de la próxima jornada. El cierre requiere además el permiso para cerrar caja.',
            PermissionCode::CashSessionsClose => 'Cerrar una sesión de caja con el monto declarado y comparar el saldo calculado.',
            PermissionCode::CashMovementsView => 'Consultar ingresos, egresos y el origen de los movimientos de caja, sin modificarlos.',
            PermissionCode::CashManualMovementsManage => 'Registrar ingresos y egresos manuales en una caja abierta, con motivo y trazabilidad.',
            PermissionCode::ProductionConfirm => 'Confirmar producciones, descontar materias primas e ingresar los productos elaborados, validando el stock.',
            PermissionCode::ProductionAnnul => 'Anular producciones cuando sea permitido y revertir sus movimientos de inventario.',
            PermissionCode::ProductionView => 'Consultar producciones, recetas utilizadas, cantidades y consumos registrados.',
            PermissionCode::ProductionManage => 'Crear producciones en borrador y preparar sus cantidades y consumos. Confirmarlas requiere otro permiso.',
            PermissionCode::PersonnelView => 'Consultar trabajadores y sus datos operativos. Las tarifas y reportes de pago requieren sus propios permisos.',
            PermissionCode::PersonnelManage => 'Crear y editar trabajadores, asociar su usuario y cambiar su estado, sin gestionar tarifas horarias.',
            PermissionCode::HourlyRatesView => 'Consultar las tarifas horarias y su historial, sin registrar nuevas tarifas.',
            PermissionCode::HourlyRatesManage => 'Registrar nuevas tarifas por hora conservando las anteriores, sin reemplazar su historial.',
            PermissionCode::HourlyPayView => 'Consultar reportes de horas y pago operacional. No realiza pagos ni modifica jornadas.',
            PermissionCode::AttendanceMark => 'Registrar el inicio y cierre de la jornada propia del trabajador asociado al usuario.',
            PermissionCode::AttendanceManage => 'Consultar y gestionar jornadas de trabajadores, registrar correcciones manuales y anular jornadas con motivo.',
            PermissionCode::UsersView => 'Consultar usuarios, su estado y rol asignado, sin modificarlos.',
            PermissionCode::UsersManage => 'Crear y editar usuarios, cambiar su rol, gestionar contraseñas y estado, respetando las protecciones administrativas.',
            PermissionCode::RolesView => 'Consultar roles, sus permisos y usuarios asociados, sin modificar sus asignaciones.',
            PermissionCode::RolesManage => 'Crear y editar roles y cambiar su estado cuando sea seguro. Asignar permisos requiere un permiso independiente.',
            PermissionCode::PermissionsView => 'Consultar el catálogo de permisos y sus asignaciones a roles, sin modificarlas.',
            PermissionCode::RolePermissionsManage => 'Asignar y quitar permisos existentes y activos a roles, sin dejar al sistema sin administración operativa.',
            null => 'Este permiso no tiene una descripción verificada. Su alcance requiere revisión administrativa.',
        };
    }

    public static function category(string $module): string
    {
        return match ($module) {
            'articulos' => 'Artículos', 'ventas' => 'Ventas', 'caja' => 'Caja',
            'inventario' => 'Inventario', 'produccion' => 'Producción', 'personal' => 'Personal',
            'asistencia' => 'Asistencia', 'pedidos' => 'Pedidos', 'proveedores' => 'Proveedores', 'recetas' => 'Recetas',
            'usuarios' => 'Administración: usuarios', 'roles' => 'Administración: roles', 'permisos' => 'Administración: permisos',
            default => ucfirst(str_replace('_', ' ', $module)),
        };
    }
}
