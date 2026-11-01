<?php

namespace Tests\Feature;

use App\Events\OrderPlaced;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\TestCase;

class OrderPlacedEventTest extends TestCase
{
    public function test_order_placed_broadcasts_transaction_details_on_the_users_private_channel(): void
    {
        $transaction = new Transaction([
            'grand_total' => 12500,
        ]);
        $transaction->id = 42;
        $user = new User([
            'name' => 'Kasir 1',
        ]);
        $user->id = 7;

        $event = new OrderPlaced($transaction, $user);
        $channels = $event->broadcastOn();

        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-user.7', $channels[0]->name);
        $this->assertSame('order.placed', $event->broadcastAs());
        $this->assertSame([
            'order_id' => 42,
            'total' => 12500.0,
            'user_name' => 'Kasir 1',
        ], $event->broadcastWith());
    }
}
