<?php
/**
 * QR payments plugin for Craft CMS
 *
 * Generates EPC QR code SEPA payment requests and lets the site owner
 * manually track and reconcile payment status from the control panel.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftqrpayments\elements\conditions;

use craft\elements\conditions\ElementCondition;

/**
 * Class SubscriptionCondition
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.7.0-beta
 */
class SubscriptionCondition extends ElementCondition {
    /**
     * @return array
     */
    protected function selectableConditionRules(): array {
        return array_merge(parent::selectableConditionRules(), [
            // Custom condition rules (subscription type, amount range, etc.) go here as advanced filtering needs come up.
        ]);
    }
}
