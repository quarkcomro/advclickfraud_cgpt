# Advanced Click Fraud Protection - User manual

## Purpose

Advanced Click Fraud Protection helps PrestaShop merchants collect defensive browser, click, request and network-fingerprint signals. The module is intended for evidence collection, fraud-risk review and controlled mitigation of scraping or advertising click-fraud patterns.

The module is defensive. It does not guarantee that every fraudulent request is detected and it should not be the only control used for paid traffic protection.

## Compatibility

- PrestaShop 8.2 or newer.
- PHP versions supported by the target PrestaShop installation.
- A working database user with permission to create the module tables during installation.

## First installation

1. Install the module from the PrestaShop Back Office.
2. Open the module configuration page.
3. Keep the module disabled until the settings are reviewed.
4. Keep the operating mode on `Observe only` for the first production review period.
5. Save the configuration for the current shop context.

The module starts disabled and observe-only by default. This is intentional, so that risk events can be reviewed before any traffic is limited or blocked.

## Core settings

### Enable module

Turns signal collection and server-side risk evaluation on for the current shop context.

### Operating mode

- `Observe only`: records decisions and risk events without blocking traffic.
- `Rate limit`: blocks only when the configured risk logic reaches the rate-limit threshold.
- `Block high-risk traffic`: blocks high-risk traffic when the blocking threshold is reached.

Start with `Observe only`. Move to stricter modes only after reviewing real store traffic and false positives.

### Log retention days

Controls how long detailed fraud-risk events should be kept before cleanup.

### Admin table refresh interval

Controls the Back Office dashboard refresh countdown. Use `Disabled` when you do not need automatic refresh.

### Delete stored fraud data on uninstall

When disabled, uninstalling the module keeps stored fraud events and rate-limit counters in the database. When enabled, uninstalling the module permanently removes the module data tables.

Keep this disabled if the data may be needed for audit, dispute or later reinstall. Enable it only when permanent removal is intended.

## Network integrations

### JA4 and JA4H correlation

JA4 and JA4H headers must only be accepted from trusted infrastructure, such as a controlled CDN, WAF, reverse proxy, HAProxy, NGINX or edge worker.

Before enabling JA4 correlation:

1. Configure trusted proxy IP addresses.
2. Ensure the edge proxy strips any incoming client-supplied JA4 or JA4H headers.
3. Ensure the edge proxy sets the internal fingerprint headers itself.
4. Verify events in observe mode before changing enforcement behavior.

Do not enable JA4 correlation if the headers can be sent directly by public clients.

## Scraping protection

Anti-scraping scoring evaluates request patterns for product, category and search-like routes. The product page threshold and search threshold control when route velocity starts to increase risk.

Recommended rollout:

1. Enable anti-scraping scoring in observe mode.
2. Review the reason codes and traffic patterns.
3. Adjust thresholds to match normal catalog browsing and crawler behavior.
4. Enable rate limiting or blocking only after reviewing false positives.

## Advertising click-fraud protection

Ad click-fraud scoring records click identifiers, attribution hints and post-click browser behavior. It can help identify suspicious paid traffic, such as paid clicks without observed interaction or browser automation indicators.

Use the recorded events as supporting evidence. Paid-media platform decisions, campaign exclusions and refunds should be handled through the relevant advertising platform policies and reporting tools.

## Operational checks

- Confirm that the module JavaScript is loaded on storefront pages when the module is enabled.
- Confirm that the collection endpoint returns JSON responses.
- Confirm that the pixel endpoint is requested only when advertising click identifiers are present.
- Review Back Office analytics before enabling stricter enforcement modes.
- Re-test after theme, CDN, proxy, cache or PrestaShop upgrades.
