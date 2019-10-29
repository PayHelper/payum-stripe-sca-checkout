<?php

namespace Combodo\StripeV3\Action;

use Combodo\StripeV3\Exception\TokenNotFound;
use Combodo\StripeV3\Request\handleCheckoutCompletedEvent;
use Combodo\StripeV3\Request\HandleSubscriptionCancelledEvent;
use Payum\Core\Action\ActionInterface;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetBinaryStatus;
use Payum\Core\Request\GetToken;
use Payum\Core\Security\TokenInterface;
use Stripe\Event;

class SubscriptionCancelledAction implements ActionInterface, GatewayAwareInterface, SubscriptionCancelledInformationProvider
{
    use GatewayAwareTrait;

    /** @var GetBinaryStatus $status */
    private $status;
    /** @var TokenInterface $token */
    private $token;

    /**
     * {@inheritdoc}
     *
     * @param handleCheckoutCompletedEvent $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $event = $request->getEvent();
        $this->handleEvent($event);
    }

    /**
     * @param Event $event
     */
    private function handleEvent(Event $event): void
    {
        $metadata = $event->data->object->metadata;
        $tokenHash = $metadata->client_reference_id;

        $this->token = $this->findTokenByHash($tokenHash);
        $this->status = $this->findStatusByToken($this->token);

        $this->status->markCanceled();
    }

    /**
     * @param $tokenHash
     */
    private function findTokenByHash($tokenHash): TokenInterface
    {
        $getTokenRequest = new GetToken($tokenHash);
        try {
            $this->gateway->execute($getTokenRequest);
        } catch (LogicException $exception) {
            throw new TokenNotFound('The requested token was not found');
        }
        $token = $getTokenRequest->getToken();

        return $token;
    }

    private function findStatusByToken(TokenInterface $token): GetBinaryStatus
    {
        $status = new GetBinaryStatus($token);
        $this->gateway->execute($status);

        if (empty($status->getValue())) {
            throw new LogicException('The payment status could not be fetched');
        }

        return $status;
    }

    public function getStatus(): ?GetBinaryStatus
    {
        return $this->status;
    }

    public function getToken(): ?TokenInterface
    {
        return $this->token;
    }

    public function supports($request)
    {
        return $request instanceof HandleSubscriptionCancelledEvent;
    }
}
