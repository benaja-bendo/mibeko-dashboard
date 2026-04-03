<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMQService
{
    protected $connection;

    protected $channel;

    /**
     * Initialize the RabbitMQ connection lazily.
     */
    protected function getChannel()
    {
        if (!$this->channel) {
            $this->connection = new AMQPStreamConnection(
                config('services.rabbitmq.host'),
                config('services.rabbitmq.port'),
                config('services.rabbitmq.user'),
                config('services.rabbitmq.password')
            );

            $this->channel = $this->connection->channel();
        }

        return $this->channel;
    }

    /**
     * Publish a message to a specific queue.
     */
    public function publish(string $queue, array $data): void
    {
        $channel = $this->getChannel();

        // Ensure the queue exists (durable = true as per python app)
        $channel->queue_declare($queue, false, true, false, false);

        $messageBody = json_encode($data);
        $message = new AMQPMessage(
            $messageBody,
            ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]
        );

        // Publish to default exchange with routing key as queue name
        $channel->basic_publish($message, '', $queue);
    }

    /**
     * Consume messages from a specific queue.
     */
    public function consume(string $queue, callable $callback): void
    {
        $channel = $this->getChannel();

        $channel->queue_declare($queue, false, true, false, false);

        $channel->basic_consume($queue, '', false, false, false, false, function ($msg) use ($callback) {
            $data = json_decode($msg->body, true);
            $callback($data, $msg);
            $msg->ack();
        });

        while ($channel->is_open()) {
            $channel->wait();
        }
    }

    /**
     * Close the connection when the service is destroyed.
     */
    public function __destruct()
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }
}
