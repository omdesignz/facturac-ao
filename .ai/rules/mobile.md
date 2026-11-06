---
paths:
  - app/Http/Controllers/Api/Mobile/MobileInvoiceController.php
  - 'app/Http/Controllers/Api/Mobile/**'
---

# Mobile

## Recover pending invoices without issuing again
Mobile recovery is an authenticated, company- and creator-scoped, uncached read of the original client_key. A null result means no committed result is visible yet, not that a prior send failed. The iOS client must retain its original key, payload and draft/issue intent across restarts and require fresh proof for retries; never persist passwords or passkey proofs or automatically issue during restoration.

## Physical iPhone demo builds require a reachable LAN endpoint
For the sibling facturac-ao-ios app, physical Debug builds must supply both FACTURACAO_API_URL and INFOPLIST_PREPROCESSOR_DEFINITIONS=FACTURACAO_LOCAL_NETWORK=1; verify the built Info.plist before installing. A launch-only environment variable does not survive opening from the Home Screen, and vap-invoice.test resolves only on the Mac. The current local demo endpoint is http://172.20.10.2:8088, provided by Herd config/valet/Nginx/facturac-ios-lan.conf, bound to the Mac Wi-Fi address and restricted to the 172.20.10.0/28 subnet and /api/mobile/v1/ paths. Recheck the Mac address after network changes; do not expose a public tunnel without authorization. Verify a Home Screen launch and physical-device API requests before reporting connectivity fixed. Browser handoff links still use the local .test web origin.

## LAN web handoffs must preserve the request origin
Supersedes the API-only proxy and .test browser-handoff limitation above: the local Herd LAN listener now serves the public front controller directly through Herd PHP-FPM, preserving the requested host so generated continuation URLs use the reachable LAN origin. Keep the listener bound to the Wi-Fi address and restricted to its subnet. Browser checks on the iPhone require built frontend assets; public/hot must not point to a Mac-only Vite origin. Verify authenticated browser continuation as well as API connectivity. Native passkeys separately require HTTPS and a matching associated webcredentials domain; local Face ID app unlock does not satisfy that requirement.
