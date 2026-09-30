# Translations

Every user-facing string in the plugin — control panel labels, statuses, emails — is passed through `Craft::t('qr-payments', ...)` in PHP and `|t('qr-payments')` in Twig, so the whole plugin can be translated per site.

Translation files live at `translations/{locale}/qr-payments.php`, as a flat array of English source strings mapped to their translation. Add a locale by creating the matching file and translating each key.
