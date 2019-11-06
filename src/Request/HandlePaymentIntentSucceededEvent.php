<?php

namespace Combodo\StripeV3\Request;

use Stripe\Event;

class HandlePaymentIntentSucceededEvent
{
    const TOKEN_CAN_BE_INVALIDATED = true;
    const TOKEN_MUST_BE_KEPT = false;

    /** @var Event $event */
    private $event;

    /** @var bool $canTokenBeInvalidated */
    private $canTokenBeInvalidated;

    public function __construct(Event $event, bool $canTokenBeInvalidated)
    {
        $this->event = $event;
        $this->canTokenBeInvalidated = $canTokenBeInvalidated;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function canTokenBeInvalidated(): bool
    {
        return $this->canTokenBeInvalidated;
    }
}
