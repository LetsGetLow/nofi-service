<?php

declare(strict_types=1);

namespace Nofi\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use DateTimeImmutable;
use DateTimeInterface;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\Notification;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\State\CancelNotificationProcessor;
use Nofi\Notification\State\DeleteNotificationProcessor;
use Nofi\Notification\State\NotificationProvider;
use Nofi\Notification\State\SendNotificationProcessor;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: NotificationProvider::class,
        ),
        new Get(
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: NotificationProvider::class,
        ),
        new Post(
            uriTemplate: "/notifications/send",
            openapi: new OpenApiOperation(
                summary: "Queues a notification for delivery",
                description: "Answers 202 with the record that was created, not with the outcome: "
                    . "delivery happens in a worker afterwards, and each target reports back under "
                    . "recipients. What is accepted here is what goes out — the request is carried "
                    . "in the queued message, so it cannot be edited later.",
            ),
            status: Response::HTTP_ACCEPTED,
            input: SendNotificationDto::class,
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            processor: SendNotificationProcessor::class,
        ),
        new Post(
            uriTemplate: "/notifications/{id}/cancel",
            // An action on something that exists, not a submission. Without
            // input: false API Platform deserialises a body that is not there
            // and answers 400; without read: true a POST does not run the
            // provider, so the ownership check and its 404 never happen; and
            // 201 Created would claim something was created.
            input: false,
            read: true,
            status: Response::HTTP_OK,
            openapi: new OpenApiOperation(
                summary: "Stops a send that has not gone out yet",
                description: "Keeps the notification as a record of the decision, unlike DELETE which removes it. "
                    . "Answers 409 once the send is processing or finished, because a cancellation would then "
                    . "arrive too late to prevent anything. Every recipient still waiting is cancelled with it.",
            ),
            security: "is_granted('IS_AUTHENTICATED_FULLY')",
            provider: NotificationProvider::class,
            processor: CancelNotificationProcessor::class,
        ),
        new Delete(
            // The security expression is enforced at runtime but never reaches
            // the OpenAPI document, so a reader of the documentation would meet
            // the 403 and the 409 without warning.
            openapi: new OpenApiOperation(
                summary: "Removes a notification that has not gone out yet",
                description: "Requires ROLE_ADMIN, and removes the record along with its recipients. "
                    . "To stop a send but keep the record, use POST /notifications/{id}/cancel "
                    . "instead. Only a notification that is still waiting can be removed: one that "
                    . "is finished is kept as the record of what went out and the call is answered "
                    . "with 409 Conflict.",
            ),
            security: "is_granted('ROLE_ADMIN')",
            provider: NotificationProvider::class,
            processor: DeleteNotificationProcessor::class,
        ),
    ],
    routePrefix: "/v1",
    // Without this the routes are derived from the class name and read
    // /v1/notification_resources: an internal naming pattern leaking into a
    // public URL, and a second name for what /notifications/send addresses.
    shortName: "Notification",
    // Every property carries this group. Filtering on a group nobody annotated
    // is what made a GET answer with @id and @type and nothing else.
    normalizationContext: ["groups" => ["notification:read"]],
)]
final class NotificationResource
{
    #[ApiProperty(identifier: true)]
    #[Groups(["notification:read"])]
    public string $id;

    /**
     * What was asked for, as it was accepted. Its shape follows the channel,
     * and it is never what the delivery reads — the worker sends the request
     * carried in the queued message, so this is the record, not the source.
     *
     * @var array<string, mixed>
     */
    #[ApiProperty(
        openapiContext: [
            "type" => "object",
            "description" => "The request as it was accepted. For email: sender, subject, message, "
                . "template, data, and attachments as metadata only (filename, contentType, "
                . "contentId, size) — the bytes are never kept. For push: title, message, icon, "
                . "data. The targets are not in here; they are listed under recipients, each with "
                . "its own status.",
            "example" => [
                "sender" => "noreply@example.com",
                "subject" => "Order 4711 shipped",
                "message" => "<p>Your parcel is on its way.</p>",
                "template" => null,
                "attachments" => [[
                    "filename" => "company-logo.png",
                    "contentType" => "image/png",
                    "contentId" => "company-logo",
                    "size" => 14722,
                ]],
                "data" => ["orderId" => 4711],
            ],
        ],
    )]
    #[Groups(["notification:read"])]
    public array $payload = [];

    #[Groups(["notification:read"])]
    public NotificationStatus $status = NotificationStatus::CREATED;

    #[Groups(["notification:read"])]
    public NotificationChannel $channel = NotificationChannel::EMAIL;

    #[Groups(["notification:read"])]
    public ?DateTimeImmutable $createdAt = null;

    // The delay is stamped onto the queued message when the send is
    // accepted, so this records the time that was asked for. Moving it means
    // cancelling and sending again.
    #[Groups(["notification:read"])]
    public ?DateTimeImmutable $scheduledAt = null;

    /**
     * Who it went to and how each one fared. The targets are not part of
     * payload because each carries its own status and delivery time, which is
     * also what makes a retry skip the ones already reached. A topic appears
     * in the form the application addresses it, prefixed, so it cannot be
     * confused with a device token.
     *
     * @var list<array{recipient: string|null, status: string, sentAt: string|null}>
     */
    #[ApiProperty(
        openapiContext: [
            "type" => "array",
            "description" => "One entry per target of the send: an email address, a device token, or a topic as \"/topics/NAME\". status and sentAt are that target's own, so a partly failed send shows which one failed.",
            "items" => [
                "type" => "object",
                "properties" => [
                    "recipient" => ["type" => "string", "example" => "kunde@example.com"],
                    "status" => ["type" => "string", "example" => "sent"],
                    "sentAt" => ["type" => "string", "format" => "date-time", "nullable" => true],
                ],
            ],
        ],
    )]
    #[Groups(["notification:read"])]
    public array $recipients = [];

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? Uuid::v7()->toRfc4122();
    }

    /**
     * The read representation of a stored notification. Everything that
     * answers with a notification goes through here, so a caller cannot be
     * shown the class defaults where the record has real values.
     */
    public static function fromEntity(Notification $notification): self
    {
        $resource = new self($notification->getId());
        $resource->payload = $notification->getPayload();
        $resource->status = $notification->getStatus();
        $resource->channel = $notification->getChannel();
        $resource->createdAt = $notification->getCreatedAt();
        $resource->scheduledAt = $notification->getScheduledAt();

        foreach ($notification->getRecipients() as $recipient) {
            $resource->recipients[] = [
                "recipient" => $recipient->getRecipient(),
                "status" => $recipient->getStatus()->value,
                "sentAt" => $recipient->getSentAt()?->format(DateTimeInterface::ATOM),
            ];
        }

        return $resource;
    }

}
