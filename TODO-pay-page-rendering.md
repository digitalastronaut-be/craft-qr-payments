# Pay page / preview rendering — cleanup needed

Found while chasing a grey `html` background bleeding into the pay page
preview iframe in the payment type edit screen. The preview bug is fixed
(`EmailPreviewService::renderPayPagePreview()` now only registers the
bundled stylesheet when there's no custom `payTemplate`), but the same
bug — and the design that caused it — still lives in the real, live
pay page, and the implementation around this is fragile in a few ways.

## 1. Live `actionPay()` has the same bug the preview had

`PaymentsController::payTemplateVariables()` (src/controllers/PaymentsController.php:297)
registers `FrontendAssetBundle` and the `frontend.js` Vite entry
**before** checking `$payment->getType()->payTemplate`:

```php
private function payTemplateVariables(Payment $payment): array {
    Craft::$app->view->registerAssetBundle(FrontendAssetBundle::class);
    QrPayments::getInstance()->getVite()->register('src/web/assets/frontend/frontend.js');

    $variables = [...];
    $override = $payment->getType()->payTemplate;

    if ($override !== '') return [$override, $variables, View::TEMPLATE_MODE_SITE];

    return ['qr-payments/components/_pay.twig', $variables, View::TEMPLATE_MODE_CP];
}
```

So any payment type with a custom `payTemplate` still gets the
plugin's `html { background-color: #f3f4f6 }` and `.qr-pay` styling
injected on the real, public page — not just in the CP preview.
Needs the same guard the preview now has: only register the bundle
when `$override === ''`.

## 2. The override-detection/asset-registration logic is duplicated and drifted

The same "bundled default vs. custom site template" branching exists
in two places with two different shapes:

- `PaymentsController::payTemplateVariables()` — keyed off `$payment->getType()->payTemplate`
- `EmailPreviewService::renderPayPagePreview()` — keyed off a `?string $template` param, with its own copy of the asset-registration + mode logic

They already disagree (this conversation just fixed one copy, not
the other). Should be one method — e.g.
`EmailPreviewService::resolvePayTemplate(string $override): array{0: string, 1: string, 2: bool}`
(template path, view mode, whether it's the bundled default) — that
both the controller and the preview action call, so the "only register
the bundle for the bundled default" rule can't drift again.

## 3. `EmailPreviewService::renderPayPagePreview()`'s CSS injection is a fragile hack

```php
$cssFilesBefore = $view->cssFiles;
$view->registerAssetBundle(FrontendAssetBundle::class);
...
$newCssFiles = array_diff_key($view->cssFiles, $cssFilesBefore);
$view->cssFiles = $cssFilesBefore; // roll back
```

Diffing and rolling back the shared CP `View`'s `cssFiles` to avoid
leaking the CP's own cp.css/admin.css into the iframe, then
hand-rebuilding `<link>` tags via regex-extracted `href`s, works but
is easy to break (e.g. `registerAssetBundle` changing how/where it
tracks tags) and silently produces wrong output rather than erroring.
Worth rendering the preview through a real isolated `View` instance
(or a dedicated minimal layout) instead of borrowing and patching the
request's shared one.

## 4. Stale sandbox comment / inconsistent trust model between the two preview iframes

The comment at src/services/EmailPreviewService.php:~219 (near the
Vite `onload` stylesheet workaround) says scripts are blocked because
the iframe's `sandbox` has no `allow-scripts` — true for the **email**
preview iframe (`_emailPreviewField.twig`, `sandbox="allow-same-origin"`),
but no longer true for the **pay page** preview iframe
(`_edit.twig`, now `sandbox="allow-same-origin allow-scripts"`), since
`renderPayPagePreview()` is only used by the pay page preview. Worth
either dropping the now-unneeded `onload`-tag rewrite for this path,
or at least fixing the comment so it doesn't claim scripts can't run
here.

## 5. Bundled default template renders in `View::TEMPLATE_MODE_CP`

Both call sites render the plugin's own bundled `_pay.twig` component
in `TEMPLATE_MODE_CP`, even on the live public page
(`PaymentsController::actionPay()`). Works today because the template
lives under the plugin's own CP-registered template root, but it's a
public-facing page being rendered as if it were a control panel
template — worth a comment explaining why, or moving it to a site
template root if there's no real reason it needs CP mode.
