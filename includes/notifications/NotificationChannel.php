<?php
declare(strict_types=1);

/**
 * One delivery mechanism for a notification. Implementations only know how
 * to deliver — whether a given notification is even allowed on this
 * channel (e.g. "SMS only carries Critical alerts and renewals") is a
 * policy decision made by the caller (includes/notify.php's notifyUser()),
 * not by the channel itself.
 */
interface NotificationChannel
{
    /**
     * @return bool true on success. Implementations throw (rather than
     *     return false) when delivery fails outright, so a caller can log
     *     the real reason; returning false is reserved for "nothing to do."
     */
    public function send(string $recipient, string $subject, string $message): bool;
}
