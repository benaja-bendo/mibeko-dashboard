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
            try {
                $this->connection = new AMQPStreamConnection(
                    config('services.rabbitmq.host'),
                    config('services.rabbitmq.port'),
                    config('services.rabbitmq.user'),
                    config('services.rabbitmq.password'),
                    '/',
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    3.0,
                    60.0,
                    null,
                    true,
                    60
                );

                $this->channel = $this->connection->channel();
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('RabbitMQ Connection Error: ' . $e->getMessage());
                throw $e;
            }
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

        try {
            while ($channel->is_open()) {
                $channel->wait(null, false, 60); // Wait for messages, timeout after 60 seconds
            }
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            // This is expected if no messages arrive within the timeout period
            // It allows the loop to continue and check if the channel is still open
            // and allows signal handlers (like for graceful shutdown) to run.
            $this->connection->checkHeartBeat(); // Send heartbeat to keep connection alive
            $this->consume($queue, $callback); // Resume consuming
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('RabbitMQ Consume Error: ' . $e->getMessage());
            throw $e;
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
