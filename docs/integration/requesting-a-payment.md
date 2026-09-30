# Requesting a payment

Post to the `qr-payments/payments/request` action to create a payment. It's public and anonymous — no control panel session required — and accepts two body params:

| Param | Required | Notes |
| --- | --- | --- |
| `amount` | Yes | Must be a value previously produced by `craft.app.security.hashData()` — see [Tamper protection](#tamper-protection) below. |
| `email` | Yes | The payer's email address. |

## Tamper protection

Since the amount is decided by your own template, not typed in by the payer, it's hashed server-side with the app's security key before it ever reaches the browser — a payer editing the plain amount in devtools would just invalidate the hash rather than change what they're charged:

```twig
<input type="hidden" name="amount" value="{{ craft.app.security.hashData('10.00') }}">
```

## As a form post

A normal form submission redirects to the [pay page](/integration/the-pay-page) on success:

```twig
<form method="post" action="">
    {{ csrfInput() }}
    {{ actionInput('qr-payments/payments/request') }}

    <input type="hidden" name="amount" value="{{ craft.app.security.hashData('10.00') }}">

    <label>
        <span>Email</span>
        <input type="email" name="email" required>
    </label>

    <button type="submit">Pay €10</button>
</form>
```

## Handling failures

Invalid hash, missing email, or an error creating the payment all fail the same way Craft's other controllers do: a redirect back with flash errors for a normal form post, or `{ success: false, message }` for a request whose `Accept` header includes `application/json`.

## What a successful AJAX request actually gets back

This is easy to get wrong, so to be precise: a *successful* request **always redirects** to the [pay page](/integration/the-pay-page) — the `Accept` header makes no difference to it at all (unlike the failure case above). What that redirect looks like to your JS depends on a *different* header, `X-Requested-With`:

- **Without `X-Requested-With: XMLHttpRequest`** (a plain `fetch()` that only sets `Accept`, which is the mistake it's easy to make): the server sends a real `Location`-based redirect. `fetch()` follows that automatically, so the response you get back is the *pay page's HTML*, already loaded — calling `.json()` on it throws, since there's no JSON in an HTML page.
- **With `X-Requested-With: XMLHttpRequest` set** (what `jQuery.ajax()` does automatically, or what you'd add to `fetch()`'s headers by hand): the server responds with status `302` and an `X-Redirect` header carrying the pay page's URL, and *no body at all* — `.json()` fails here too, just because there's nothing to parse. Your own JS has to read `response.headers.get('X-Redirect')` and navigate there itself; this is the same convention Craft's own CP JS (`Craft.sendActionRequest`) follows for its own redirects.

Either way, no payment data — QR code, reference, anything — comes back over AJAX on success today. If you need to build a confirmation UI in place without leaving the page, that would need `PaymentsController::actionRequest()` to return `$this->asSuccess($message, ['payment' => [...]])` instead of redirecting when the request accepts JSON — it doesn't do that today, but the plugin's own JSON success responses elsewhere already follow exactly that pattern, so it'd be a small, self-contained change.
