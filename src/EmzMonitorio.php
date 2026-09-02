<?php declare(strict_types=1);

namespace Emz\Monitorio;

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Config\MonitorioBaseUrl;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class EmzMonitorio extends Plugin
{
    /**
     * Laedt Resources/config/packages/*.yaml (den dedizierten Messenger-
     * Transport `emz_monitorio`) in die Container-Konfiguration. Anders als
     * bei den Core-Bundles passiert das fuer Plugins NICHT automatisch -
     * buildDefaultConfig() rufen nur Framework und Profiling selbst auf.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->buildDefaultConfig($container);
    }

    public function install(InstallContext $installContext): void
    {
        // Do stuff such as creating a new payment method
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `emz_monitorio_stock_outbox`');
        $connection->executeStatement('DROP TABLE IF EXISTS `emz_monitorio_stock_state`');
    }

    public function activate(ActivateContext $activateContext): void
    {
        // Activate entities, such as a new payment method
        // Or create new entities here, because now your plugin is installed and active for sure
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        // Deactivate entities, such as a new payment method
        // Or remove previously created entities
    }

    public function update(UpdateContext $updateContext): void
    {
        // Bis 1.2.0 hiess der Instanz-Override `snippetUrl` und enthielt die volle URL
        // der Snippet-Datei; seit 1.3.0 gibt es nur noch die gemeinsame Monitorio-URL.
        if (\version_compare($updateContext->getCurrentPluginVersion(), '1.3.0', '<')) {
            $this->migrateLegacySnippetUrl();
        }
    }

    public function postInstall(InstallContext $installContext): void
    {
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    /**
     * Uebernimmt gesetzte `snippetUrl`-Werte (je Scope) als Monitorio-URL und entfernt
     * den Alt-Schluessel. Ein bereits vorhandener Monitorio-URL-Wert im selben Scope
     * gewinnt - er ist die neuere, bewusste Einstellung.
     */
    private function migrateLegacySnippetUrl(): void
    {
        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        /** @var SystemConfigService $systemConfig */
        $systemConfig = $this->container->get(SystemConfigService::class);

        $legacyRows = $connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS scope, configuration_value AS value
             FROM system_config WHERE configuration_key = :key',
            ['key' => 'EmzMonitorio.config.snippetUrl']
        );
        $migratedScopes = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(sales_channel_id)) FROM system_config WHERE configuration_key = :key',
            ['key' => MonitorioBaseUrl::CONFIG_KEY]
        );

        foreach ($legacyRows as $row) {
            $stored = json_decode((string) $row['value'], true)['_value'] ?? null;
            $baseUrl = \is_string($stored) ? MonitorioBaseUrl::fromLegacySnippetUrl($stored) : null;

            if ($baseUrl !== null && !\in_array($row['scope'], $migratedScopes, true)) {
                $systemConfig->set(MonitorioBaseUrl::CONFIG_KEY, $baseUrl, $row['scope']);
            }

            $systemConfig->delete('EmzMonitorio.config.snippetUrl', $row['scope']);
        }
    }
}
