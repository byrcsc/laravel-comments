<?php

declare(strict_types=1);

namespace ByRcsc\LaravelComments\Support;

use ByRcsc\LaravelComments\Exceptions\InvalidConfigurationException;

/**
 * Whether the shipped reply notification delivers, and over which channels.
 *
 * Off by default and off on purpose: installing a package should never send
 * mail nobody asked for. Turning it on is one config key, and the channel list
 * is the other lever - an application that wants the database channel or Slack
 * changes it there rather than forking the notification.
 *
 * Boot-time validation and the send path both come through {@see read()}, so a
 * malformed section cannot be caught in one and quietly tolerated in the
 * other.
 */
final class ReplyNotificationSettings
{
    /**
     * @param  list<string>  $channels
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly array $channels,
    ) {}

    public static function fromConfig(): self
    {
        return self::read(config('comments.notifications'));
    }

    public static function read(mixed $configured): self
    {
        if (! is_array($configured)) {
            throw InvalidConfigurationException::invalidNotifications($configured);
        }

        $reply = $configured['reply'] ?? null;

        if (! is_array($reply)) {
            throw InvalidConfigurationException::invalidReplyNotification($reply);
        }

        $enabled = $reply['enabled'] ?? null;

        if (! is_bool($enabled)) {
            throw InvalidConfigurationException::invalidReplyNotificationEnabled($enabled);
        }

        return new self($enabled, self::channels($reply['channels'] ?? null));
    }

    /**
     * An empty list is refused rather than treated as "off": a switch that is
     * on and delivers nowhere is a configuration mistake worth hearing about,
     * and `enabled` is the honest way to say off.
     *
     * @return list<string>
     */
    private static function channels(mixed $configured): array
    {
        if (! is_array($configured) || $configured === []) {
            throw InvalidConfigurationException::invalidReplyNotificationChannels($configured);
        }

        $channels = [];

        foreach ($configured as $channel) {
            if (! is_string($channel) || trim($channel) === '') {
                throw InvalidConfigurationException::invalidReplyNotificationChannels($configured);
            }

            $channels[] = $channel;
        }

        return $channels;
    }
}
