# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff. Releases before 2.0.4 are described by their release commits.

## 2.3.2 - 2026-10-16

### Fixes

- Require vpndetection 4.6.2: the spec re-pinned to 2026.10.15 ([`8b6b291`](https://github.com/vpndetection-io/sdk-php-symfony/commit/8b6b291bbfde545889343d8525012eb2f06a7560))

## 2.3.1 - 2026-10-15

### Fixes

- Require vpndetection 4.6.1: OAuth discovery and device authorization retry an unreadable answer ([`08fbba8`](https://github.com/vpndetection-io/sdk-php-symfony/commit/08fbba8b8c090dc386dfd8e59280bf86176b5069))

## 2.3.0 - 2026-10-13

### Features

- Keep answers in the app's cache pool between requests ([`016f6c4`](https://github.com/vpndetection-io/sdk-php-symfony/commit/016f6c4e6828a09e5384d467c819539a14aae78c))

## 2.2.0 - 2026-10-11

### Features

- Skip the paths in skip_paths, which the bundle's config now takes ([`fe30882`](https://github.com/vpndetection-io/sdk-php-symfony/commit/fe3088272a0d78065f30d6f557994c3ba7c4ccd9))

## 2.1.1 - 2026-10-10

### Fixes

- Require vpndetection/vpndetection 4.5.2: the spec re-pinned to 2026.10.09 ([`355c605`](https://github.com/vpndetection-io/sdk-php-symfony/commit/355c605ec65337420e6de3e0e2c09c10308fe834))

## 2.1.0 - 2026-10-10

### Features

- Support Symfony 8 ([`0073449`](https://github.com/vpndetection-io/sdk-php-symfony/commit/0073449b29ce9680916564df21d098a5b6e76e50))

## 2.0.10 - 2026-10-07

### Fixes

- Require vpndetection 4.5.1: unreadable answers retry as server_error ([`2f514bd`](https://github.com/vpndetection-io/sdk-php-symfony/commit/2f514bd7dc200b928e2e30aad923c549fcf4cd94))

## 2.0.9 - 2026-10-04

### Features

- Require vpndetection 4.5.0: the authorization code sign-in ([`6e12ea8`](https://github.com/vpndetection-io/sdk-php-symfony/commit/6e12ea83b34aa0efc9bdac607dfa2421275b2d65))

## 2.0.8 - 2026-10-04

### Fixes

- Require vpndetection 4.4.3, re-pinned to spec 2026.10.03 ([`74e8f44`](https://github.com/vpndetection-io/sdk-php-symfony/commit/74e8f44f53206259331a787cba204c96abcab340))

## 2.0.7 - 2026-09-29

### Fixes

- Require vpndetection 4.4.2: 26 more reserved ranges are answered locally ([`ee224c4`](https://github.com/vpndetection-io/sdk-php-symfony/commit/ee224c47ba1cf9401b1eb550d452bfb063f57752))

## 2.0.6 - 2026-09-28

### Fixes

- Require vpndetection 4.4.1: IPv4-mapped visitors are looked up, not waved through ([`0ba3eb4`](https://github.com/vpndetection-io/sdk-php-symfony/commit/0ba3eb4e745afb094e498ca8ee8fd2698ed140db))

## 2.0.5 - 2026-09-27

### Features

- Require vpndetection 4.4.0: OauthMetadata carries clientIdMetadataDocumentSupported ([`8e9e4fa`](https://github.com/vpndetection-io/sdk-php-symfony/commit/8e9e4fa991f407ecf30fb6cef75b849d1a99e89d))

## 2.0.4 - 2026-09-23

### Fixes

- Raise the vpndetection/vpndetection floor to 4.3.2 ([`b338f16`](https://github.com/vpndetection-io/sdk-php-symfony/commit/b338f16d3a957973987cf7dfae5fb322b3f1e8a2))
