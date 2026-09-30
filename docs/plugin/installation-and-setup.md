# Installation and setup

## Installing using composer

Install the latest version or a specific release of the QR payments plugin via composer with the following console command

::: code-group

```bash[DDEV]
ddev composer install digitalastronaut/craft-qr-payments
```

```bash[Other]
composer install digitalastronaut/craft-qr-payments
```

:::

## Installing via the Craft plugin store

Alternatively you can install the QR payments plugin via the [craft plugin store](https://plugins.craftcms.com/qr-payments?craft5).

<!-- ![Craft plugin store installation page](../public/images/plugin-store-installation.png) -->

## Beneficiary settings

Once installed, head to the plugin's settings page to configure the beneficiary IBAN, BIC and name used to generate every payment's EPC QR code — see [Settings](/plugin/settings).
