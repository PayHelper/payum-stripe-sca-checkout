<?php

namespace Combodo\StripeV3\Action;

use Payum\Core\Request\GetBinaryStatus;

interface SubscriptionCancelledInformationProvider
{
    public function getStatus(): ?GetBinaryStatus;
}
