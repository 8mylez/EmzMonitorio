<?php declare(strict_types=1);

namespace Emz\Monitorio;

use Doctrine\DBAL\Connection;
use Emz\Monitorio\Acl\MonitorioRoleInstaller;
use Emz\Monitorio\Config\MonitorioBaseUrl;
use Emz\Monitorio\Stock\DedicatedTransportWatchdog;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
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
     * bei den Core-Bundles passiert das fuer Plugins NICHT automatisch.
     * Bewusst nicht ueber Bundle::buildDefaultConfig() - die gibt es erst ab
     * Shopware 6.6, das Plugin unterstuetzt aber 6.5.7+.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $configDir = $this->getPath() . '/Resources/config';
        $locator = new FileLocator($configDir);
        $loader = new DelegatingLoader(new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
        ]));

        $loader->load($configDir . '/{packages}/*.yaml', 'glob');
    }

    public function install(InstallContext $installContext): void
    {
        $this->createRoleInstaller()->ensureRole($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->createRoleInstaller()->removeRole($uninstallContext->getContext());

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);
        $connection->executeStatement('DROP TABLE IF EXISTS `emz_monitorio_stock_outbox`');
        $connection->executeStatement('DROP TABLE IF EXISTS `emz_monitorio_stock_state`');

        // Der Watchdog-Marker liegt bewusst ausserhalb von config.xml und wird
        // von Shopwares Config-Cleanup deshalb nicht erfasst.
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` = :key',
            ['key' => DedicatedTransportWatchdog::NOTIFIED_AT_KEY]
        );
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

        // Bestandsinstallationen bekommen die Rolle beim Update; neue Rechte
        // spaeterer Versionen werden hier nachgezogen.
        $this->createRoleInstaller()->ensureRole($updateContext->getContext());
    }

    public function postInstall(InstallContext $installContext): void
    {
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    private function createRoleInstaller(): MonitorioRoleInstaller
    {
        /** @var EntityRepository $aclRoleRepository */
        $aclRoleRepository = $this->container->get('acl_role.repository');

        return new MonitorioRoleInstaller($aclRoleRepository);
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
