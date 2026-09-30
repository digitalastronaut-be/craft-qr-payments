# The pay page

A successful form-post request (see [Requesting a payment](/integration/requesting-a-payment)) redirects to:

```
/qr-payments/payments/<uid>/pay
```

This renders the plugin's own bundled "scan to pay" template — the QR code, the amount, and the beneficiary details a payer needs to complete the transfer from their banking app. It's served from the plugin regardless of which site template initiated the request, so there's nothing to build for the default flow to work.

## Customizing the page

If the bundled page doesn't fit your site's design, point the **Pay page template** setting (see [Settings](/plugin/settings)) at your own site template instead of building a separate flow — the redirect target stays the same, only what renders there changes. Your template receives the same `payment` element (and a ready-to-use `qrCodeDataUri`) the bundled page does.
