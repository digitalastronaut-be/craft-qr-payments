# Requesting a payment

Post to the `qr-payments/payments/request` action to create a payment. It's public and anonymous — no control panel session required — and accepts two body params:

| Param    | Required | Notes                                                                                                                       |
| -------- | -------- | --------------------------------------------------------------------------------------------------------------------------- |
| `amount` | Yes      | Must be a value previously produced by `craft.app.security.hashData()` — see [Tamper protection](#tamper-protection) below. |
| `email`  | Yes      | The payer's email address.                                                                                                  |

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
