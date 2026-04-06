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
                    config('services.rabbitmq.host', 'rabbitmq'),
                    config('services.rabbitmq.port', 5672),
                    config('services.rabbitmq.user') ?: 'guest',
                    config('services.rabbitmq.password') ?: 'guest',
                    '/',
                    false,
                    'AMQPLAIN',
                    null,
                    'en_US',
                    3.0,
                    360.0,
                    null,
                    true,
                    0
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
     * Declare the queue with the correct arguments to avoid PRECONDITION_FAILED errors.
     */
    protected function declareQueue(string $queue)
    {
        $channel = $this->getChannel();
        
        $arguments = [];
        if ($queue === 'pdf_extraction_tasks') {
            $arguments = new \PhpAmqpLib\Wire\AMQPTable([
                'x-dead-letter-exchange' => 'pdf_extraction_tasks_dlx',
                'x-dead-letter-routing-key' => 'dead_letter'
            ]);
        }
        
        $channel->queue_declare($queue, false, true, false, false, false, $arguments);
    }

    /**
     * Publish a message to a specific queue.
     */
    public function publish(string $queue, array $data): void
    {
        $channel = $this->getChannel();

        // Ensure the queue exists with correct arguments
        $this->declareQueue($queue);

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

        // Ensure the queue exists with correct arguments
        $this->declareQueue($queue);

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
