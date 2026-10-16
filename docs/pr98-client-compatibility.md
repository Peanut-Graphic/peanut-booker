# PR #98 client compatibility

Pending release; do not enable hardware locks merely because this client is updated.
The `peanut_license_hardware_id` WordPress filter receives an empty default and the product slug (`peanut-booker`, `peanut-suite`, or `formflow`). Return the exact fingerprint already recorded by the license administrator. Empty defaults retain existing unrestricted behavior. This is a supplied identity, not hardware attestation. Never derive a replacement fingerprint from the domain, IP, path, hostname, or a random UUID; it would not match existing bindings and can change on migration.

IP locks use the HTTP request's observed egress address; clients cannot select it. Domain locks use `home_url()` and need matching apex/subdomain/staging entries. Saved values apply even when the corresponding enforce flag is false. Missing, incorrect or unreadable restrictions continue to fail closed.

Server header support must deploy before these header-using clients. Deploy fingerprint configuration before enforcing a non-empty hardware lock; verify matching and mismatching requests on staging. Do not restore email-based ownership or grandfather existing activations around restrictions.

Download URLs still need separate review: WordPress package downloads cannot inherit headers from an earlier update-check request. Keep existing signed-token packages working; never remove a key from a legacy download URL without implementing scoped download authorization.

## October 16 qualification continuation

Refreshed onto released Booker 1.8.2; this candidate is 1.8.3. Header-only POST support is released in License Server 1.5.5. The candidate also normalizes WordPress REST/HTTP errors and malformed license responses, preserving explicit restriction denials and Booker's existing metadata defaults. The Property suite runs transport and eleven response regression cases.

Shared SDK testing against the exact server package passed real WordPress 7.1 / WooCommerce 11.2.1 activation/status/deactivation, restriction and synthetic guest claim/mail/browser/concurrency/security matrices. Production WooCommerce remains inactive. Booker-specific full suites and exact packaged plugin installation/update delivery remain required; shared SDK evidence alone is insufficient to release Booker. No customer bindings, ownership or restrictions were changed.

Local PHP 8.4.25 qualification: Property 13 tests / 4,595 assertions and Security Core 39 tests / 151 assertions pass. The legacy advisory default suite has 52 errors / 11 failures, reproduced unchanged on a clean origin/main 1.8.2 baseline (178 tests versus 179 with the new Property wrapper). Its existing CI advisory treatment is preserved; no gate was weakened.

The clean production-only 1.8.3 candidate ZIP installed and activated on real WordPress 7.1 / WooCommerce 11.2.1 / PHP 8.2.33. The actual configured `peanut_booker_license()` client completed activation, forced status and deactivation against exact released License Server 1.5.5. All credentials were headers, none entered URLs, final active seats were zero, and the bundled signed-update verifier loaded. Assets are byte-identical to released 1.8.2. Fresh exact-head CI and signed packaged-update delivery remain required.
