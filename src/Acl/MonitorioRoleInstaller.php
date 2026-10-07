<?php declare(strict_types=1);

namespace Emz\Monitorio\Acl;

use Shopware\Core\Framework\Api\Acl\Role\AclRoleEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Legt die ACL-Rolle an, die eine Monitorio-Integration statt Admin-Rechten
 * bekommt. Laeuft im Plugin-Lifecycle (install/update/uninstall) und ist dort
 * kein DI-Service - die eigenen Services stehen bei der Installation noch
 * nicht im Container.
 */
final class MonitorioRoleInstaller
{
    public const ROLE_NAME = 'Monitorio';

    /**
     * Rechte fuer genau die Admin-API-Aufrufe, die Monitorio absetzt
     * (internal/plugins/* und internal/shopware_app im Monitorio-Repo). Die
     * Admin-API prueft neben der gesuchten Entity auch jede Entity, die per
     * Association, Filter oder Sortierung beruehrt wird - daher z. B.
     * product_media (Filter `media.id`) und order_delivery.
     *
     * Die Plugin-eigenen Endpunkte (/api/monitorio/*, /api/_action/emz/monitorio/*)
     * und /api/_info/version verlangen kein Privileg.
     */
    public const PRIVILEGES = [
        // Bestellungen: Statistik, Zahlungs-, Liefer- und Bestellstatus-Alarme, Dashboard
        'order:read',
        'order_transaction:read',
        'order_delivery:read',
        'currency:read',
        'payment_method:read',
        'state_machine:read',
        'state_machine_state:read',
        // Sales-Channels: Wartungsmodus, Domains, Default-Waehrung
        'sales_channel:read',
        'sales_channel_domain:read',
        // Produkte ohne Bild, fehlende Meta-Angaben
        'product:read',
        'product_media:read',
        'property_group_option:read',
        'property_group:read',
        'category:read',
        'language:read',
        'scheduled_task:read',
        // Erweiterungen: Security-Plugin-Erkennung, Dashboard-Zaehler
        'plugin:read',
        'app:read',
        // Einziges Nicht-Lese-Recht: /api/_action/extension/installed haengt
        // im Core an `system.plugin_maintain`, ein Leserecht dafuer gibt es nicht.
        'system.plugin_maintain',
    ];

    private const ROLE_DESCRIPTION = 'Leserechte für die Monitorio-Integration (angelegt vom Plugin EmzMonitorio).';

    public function __construct(private readonly EntityRepository $aclRoleRepository)
    {
    }

    /**
     * Legt die Rolle an bzw. ergaenzt fehlende Rechte. Im Admin zusaetzlich
     * vergebene Rechte bleiben erhalten.
     */
    public function ensureRole(Context $context): void
    {
        $role = $this->findRole($context);

        if ($role === null) {
            $this->aclRoleRepository->create([[
                'name' => self::ROLE_NAME,
                'description' => self::ROLE_DESCRIPTION,
                'privileges' => self::PRIVILEGES,
            ]], $context);

            return;
        }

        $missing = array_diff(self::PRIVILEGES, $role->getPrivileges());
        if ($missing === []) {
            return;
        }

        $this->aclRoleRepository->update([[
            'id' => $role->getId(),
            'privileges' => [...$role->getPrivileges(), ...array_values($missing)],
        ]], $context);
    }

    public function removeRole(Context $context): void
    {
        $role = $this->findRole($context);
        if ($role === null) {
            return;
        }

        $this->aclRoleRepository->delete([['id' => $role->getId()]], $context);
    }

    private function findRole(Context $context): ?AclRoleEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::ROLE_NAME));
        $criteria->setLimit(1);

        $role = $this->aclRoleRepository->search($criteria, $context)->first();

        return $role instanceof AclRoleEntity ? $role : null;
    }
}
