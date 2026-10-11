<?php

/**
 * Every page the old sidebar linked to, by its route helper. Grouping the
 * navigation into a rail must never make one of them unreachable.
 */
dataset('navigable pages', [
    'dashboard' => 'dashboard.url()',
    'point of sale' => 'posShow.url()',
    'documents' => 'documentsIndex.url()',
    'quotes' => 'quotesIndex.url()',
    'transport documents' => 'transportDocumentsIndex.url()',
    'recurring invoices' => 'recurringIndex.url()',
    'customers' => 'customersIndex.url()',
    'debts' => 'debtsIndex.url()',
    'catalogue' => 'billingCatalogue.url()',
    'price lists' => 'priceListsIndex.url()',
    'stock' => 'stockIndex.url()',
    'AGT monitor' => 'agtSubmissions.url()',
    'AGT connection' => 'agtConnection.url()',
    'SAF-T' => 'saftIndex.url()',
    'analytics' => 'analyticsIndex.url()',
    'onboarding' => 'onboarding.url()',
    'establishments' => 'establishmentsIndex.url()',
    'imports' => 'importIndex.url()',
    'security' => 'security.url()',
    'account' => 'accountSettings.url()',
    'billing' => 'billingShow.url()',
    'help' => 'helpIndex.url()',
    'support console' => 'supportIndex.url()',
    'support complaints' => 'supportComplaints.url()',
    'support settings' => 'supportSettings.url()',
]);

test('every page stays reachable from the navigation', function (string $href) {
    $navigation = (string) file_get_contents(resource_path('js/lib/navigation.ts'));

    expect($navigation)->toContain("href: {$href}");
})->with('navigable pages');

test('the rail and the mobile menu read the same navigation', function () {
    foreach (['AppRail.vue', 'SidebarNavigation.vue'] as $component) {
        expect((string) file_get_contents(resource_path("js/components/{$component}")))
            ->toContain("import { useNavigation } from '@/lib/navigation';");
    }
});

test('the support console is only listed for platform staff', function () {
    $navigation = (string) file_get_contents(resource_path('js/lib/navigation.ts'));

    expect($navigation)->toContain('is_support_staff === true');
});
