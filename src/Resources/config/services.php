<?php declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Api\LogsApiController;
use Emz\Monitorio\Api\MessageQueueApiController;
use Emz\Monitorio\Api\MonitorioApiController;
use Emz\Monitorio\Log\LogLineParser;
use Emz\Monitorio\Log\LogReader;
use Emz\Monitorio\Log\LogVolumeReader;
use Emz\Monitorio\Stock\Command\StockBaselineCommand;
use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Emz\Monitorio\Stock\Message\StockChangedHandler;
use Emz\Monitorio\Stock\Message\StockReconciliationHandler;
use Emz\Monitorio\Stock\MonitorioStockClient;
use Emz\Monitorio\Stock\ScheduledTask\StockReconciliationTask;
use Emz\Monitorio\Stock\ScheduledTask\StockReconciliationTaskHandler;
use Emz\Monitorio\Stock\StockEventBuilder;
use Emz\Monitorio\Stock\StockOutbox;
use Emz\Monitorio\Stock\StockPushConfig;
use Emz\Monitorio\Stock\StockPushService;
use Emz\Monitorio\Stock\StockStateStore;
use Emz\Monitorio\Stock\Subscriber\StockChangeSubscriber;
use Emz\Monitorio\Storefront\JsErrorTracking\JsErrorTrackingConfigProvider;
use Emz\Monitorio\Storefront\Twig\JsErrorTrackingExtension;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\AbstractThemePathBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(MonitorioApiController::class)
        ->public()
        ->call('setContainer', [service('service_container')]);

    $services->set(LogLineParser::class);

    $services->set(LogReader::class)
        ->args([
            param('kernel.logs_dir'),
            service(LogLineParser::class),
        ]);

    $services->set(LogVolumeReader::class)
        ->args([param('kernel.logs_dir')]);

    $services->set(LogsApiController::class)
        ->public()
        ->args([
            service(LogReader::class),
            service(LogVolumeReader::class),
        ])
        ->call('setContainer', [service('service_container')]);

    $services->set(MessageQueueApiController::class)
        ->public()
        ->args([tagged_iterator('messenger.receiver', 'alias')])
        ->call('setContainer', [service('service_container')]);

    $services->set(JsErrorTrackingConfigProvider::class)
        ->args([
            service(SystemConfigService::class),
            service('request_stack'),
            service(AbstractThemePathBuilder::class),
        ]);

    $services->set(JsErrorTrackingExtension::class)
        ->args([service(JsErrorTrackingConfigProvider::class)])
        ->tag('twig.extension');

    // Stock-Push

    $services->set(StockPushConfig::class)
        ->args([service(SystemConfigService::class)]);

    // Die Shopware-Version entscheidet, woher `availableStock` kommt
    // (ab 6.6 spiegelt `product.available_stock` nur noch `product.stock`)
    $services->set(StockStateStore::class)
        ->args([
            service(Connection::class),
            param('kernel.shopware_version'),
        ]);

    $services->set(StockOutbox::class)
        ->args([service(Connection::class)]);

    $services->set(StockEventBuilder::class);

    $services->set(MonitorioStockClient::class)
        ->args([
            service('http_client'),
            service(StockPushConfig::class),
        ]);

    $services->set(StockPushService::class)
        ->args([
            service(StockPushConfig::class),
            service(StockStateStore::class),
            service(StockOutbox::class),
            service(StockEventBuilder::class),
            service(MonitorioStockClient::class),
            service('monolog.logger'),
            service('notification.repository')->nullOnInvalid(),
        ]);

    $services->set(StockChangedHandler::class)
        ->args([service(StockPushService::class)])
        ->tag('messenger.message_handler');

    $services->set(StockReconciliationHandler::class)
        ->args([service(StockPushService::class)])
        ->tag('messenger.message_handler');

    $services->set(DedicatedTransportWatchdog::class)
        ->args([
            service(Connection::class),
            service(StockPushConfig::class),
            service(SystemConfigService::class),
            service('monolog.logger'),
            service('notification.repository')->nullOnInvalid(),
        ]);

    $services->set(StockChangeSubscriber::class)
        ->args([
            service('messenger.default_bus'),
            service(StockPushConfig::class),
        ])
        ->tag('kernel.event_subscriber');

    $services->set(StockReconciliationTask::class)
        ->tag('shopware.scheduled.task');

    // handles explizit: der __invoke(ScheduledTask)-Typehint der Basisklasse
    // wuerde sonst auf alle ScheduledTask-Messages matchen
    $services->set(StockReconciliationTaskHandler::class)
        ->args([
            service('scheduled_task.repository'),
            service('monolog.logger'),
            service('messenger.default_bus'),
            service(StockPushConfig::class),
            service(DedicatedTransportWatchdog::class),
        ])
        ->tag('messenger.message_handler', ['handles' => StockReconciliationTask::class]);

    $services->set(StockBaselineCommand::class)
        ->args([
            service(StockPushConfig::class),
            service(StockPushService::class),
            service(StockOutbox::class),
        ])
        ->tag('console.command');
};
