<?php declare(strict_types=1);

namespace Emz\Monitorio\Tests\Storefront\JsErrorTracking;

use Emz\Monitorio\Storefront\JsErrorTracking\PageContext;
use PHPUnit\Framework\TestCase;

final class PageContextTest extends TestCase
{
    /**
     * @dataProvider checkoutRoutes
     */
    public function testCheckoutRoutesMapToCheckout(string $route): void
    {
        self::assertSame(PageContext::CHECKOUT, PageContext::fromRoute($route));
    }

    public static function checkoutRoutes(): iterable
    {
        yield 'cart' => ['frontend.checkout.cart.page'];
        yield 'confirm' => ['frontend.checkout.confirm.page'];
        yield 'finish' => ['frontend.checkout.finish.page'];
        // Registrierung innerhalb des Checkout-Flows - im Gegensatz zu frontend.account.register.page.
        yield 'register im checkout' => ['frontend.checkout.register.page'];
    }

    /**
     * @dataProvider accountRoutes
     */
    public function testAccountRoutesMapToAccount(string $route): void
    {
        self::assertSame(PageContext::ACCOUNT, PageContext::fromRoute($route));
    }

    public static function accountRoutes(): iterable
    {
        yield 'uebersicht' => ['frontend.account.home.page'];
        yield 'login' => ['frontend.account.login.page'];
        yield 'bestellungen' => ['frontend.account.order.page'];
        // Eigenstaendige Registrierung gehoert ins Kundenkonto, nicht in den Checkout.
        yield 'register ausserhalb des checkouts' => ['frontend.account.register.page'];
    }

    /**
     * @dataProvider storefrontRoutes
     */
    public function testRemainingRoutesMapToStorefront(?string $route): void
    {
        self::assertSame(PageContext::STOREFRONT, PageContext::fromRoute($route));
    }

    public static function storefrontRoutes(): iterable
    {
        yield 'startseite' => ['frontend.home.page'];
        yield 'navigation' => ['frontend.navigation.page'];
        yield 'detailseite' => ['frontend.detail.page'];
        yield 'suche' => ['frontend.search.page'];
        yield 'keine route ermittelbar' => [null];
    }

    public function testPrefixMatchIsAnchoredAtTheStart(): void
    {
        // Nur das Praefix zaehlt - eine Route, die die Woerter nur enthaelt, bleibt storefront.
        self::assertSame(PageContext::STOREFRONT, PageContext::fromRoute('frontend.cms.checkout.page'));
        self::assertSame(PageContext::STOREFRONT, PageContext::fromRoute('widgets.account.info'));
    }
}
