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

namespace digitalastronaut\craftqrpayments\services;

use Craft;
use craft\base\Component;

/**
 * Class RequestTokensService
 *
 * Signs and verifies the short-lived "request on behalf of someone else"
 * token `PaymentsController::actionRequest()`/`SubscriptionsController::
 * actionRequest()` accept as an optional `payer` body param — see
 * `REQUEST_ACTIONS_HARDENING_PLAN.md` for the full design rationale.
 *
 * The token bundles a target `userId` (or none, for a guest), a type
 * handle, and the time it was issued into one signed string
 * (`Craft::$app->getSecurity()->hashData()`, the same mechanism Twig's
 * `|hash` filter uses) so none of the three can be tampered with — or
 * mixed with a different legitimately-issued value — once it leaves the
 * server. Unlike a plain `hashData()` call, `resolve()` also enforces a
 * max age: the hash itself never expires on its own (it's a static HMAC,
 * not bound to a session), so without one, a token leaked or left over
 * from a page view would stay replayable forever, even after whatever
 * authorized issuing it in the first place (e.g. an agent's management
 * rights over an artist) is no longer true.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class RequestTokensService extends Component {
    public const int DEFAULT_MAX_AGE = 3600;

    /**
     * @param int|null $userId The target user this token certifies a request is for — null for a guest.
     * @param string $typeHandle The payment/subscription type handle this token certifies a request is for.
     * @return string
     */
    public function issue(?int $userId, string $typeHandle): string {
        return Craft::$app->getSecurity()->hashData($this->encode($userId, $typeHandle, time()));
    }

    /**
     * @param string $token A token previously returned by `issue()`.
     * @param int $maxAgeSeconds How long, in seconds, a token stays valid after being issued.
     * @return array{userId: int|null, typeHandle: string}|null Null if the token's signature doesn't check out, or it's older than `$maxAgeSeconds`.
     */
    public function resolve(string $token, int $maxAgeSeconds = self::DEFAULT_MAX_AGE): ?array {
        $payload = Craft::$app->getSecurity()->validateData($token);
        if ($payload === false) return null;

        [$userId, $typeHandle, $issuedAt] = $this->decode($payload);
        if ($typeHandle === null || $issuedAt === null) return null;
        if (time() - $issuedAt > $maxAgeSeconds) return null;

        return ['userId' => $userId, 'typeHandle' => $typeHandle];
    }

    /**
     * @param int|null $userId
     * @param string $typeHandle
     * @param int $issuedAt
     * @return string
     */
    private function encode(?int $userId, string $typeHandle, int $issuedAt): string {
        return sprintf('%s|%s|%d', $userId ?? '', $typeHandle, $issuedAt);
    }

    /**
     * @param string $payload
     * @return array{0: int|null, 1: string|null, 2: int|null}
     */
    private function decode(string $payload): array {
        $parts = explode('|', $payload, 3);
        if (\count($parts) !== 3) return [null, null, null];

        [$userIdPart, $typeHandle, $issuedAtPart] = $parts;

        return [
            $userIdPart !== '' ? (int) $userIdPart : null,
            $typeHandle !== '' ? $typeHandle : null,
            ctype_digit($issuedAtPart) ? (int) $issuedAtPart : null,
        ];
    }
}
