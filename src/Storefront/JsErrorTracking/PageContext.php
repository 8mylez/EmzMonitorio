<?php declare(strict_types=1);

namespace Emz\Monitorio\Storefront\JsErrorTracking;

/**
 * Seitenbereich, den das Monitorio-Snippet als `context` mitschickt. Monitorio wertet
 * Checkout-Fehler mit einer eigenen Alert-Schwelle aus.
 */
final class PageContext
{
    public const STOREFRONT = 'storefront';
    public const CHECKOUT = 'checkout';
    public const ACCOUNT = 'account';

    private const CHECKOUT_ROUTE_PREFIX = 'frontend.checkout.';
    private const ACCOUNT_ROUTE_PREFIX = 'frontend.account.';

    /**
     * Der Storefront-Routenname traegt den Bereich bereits im Praefix: Cart, Confirm, Finish
     * und der Register-Schritt im Checkout-Flow heissen alle `frontend.checkout.*`, das
     * Kundenkonto inklusive des eigenstaendigen Registrierungsformulars `frontend.account.*`.
     * Ueber das Praefix statt ueber eine Routenliste zu gehen haelt die Zuordnung stabil,
     * wenn Shopware Routen ergaenzt.
     */
    public static function fromRoute(?string $route): string
    {
        if ($route === null) {
            return self::STOREFRONT;
        }

        if (str_starts_with($route, self::CHECKOUT_ROUTE_PREFIX)) {
            return self::CHECKOUT;
        }

        if (str_starts_with($route, self::ACCOUNT_ROUTE_PREFIX)) {
            return self::ACCOUNT;
        }

        return self::STOREFRONT;
    }
}
