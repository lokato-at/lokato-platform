<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\Facades\MQTT;

class MqttScanListener extends Command
{
    protected $signature = 'mqtt:listen-scan';
    protected $description = 'Listen on /api/v1/scan and log incoming scan events.';

    public function handle(): int
    {
        $topic = '/api/v1/scan';

        /** @var \PhpMqtt\Client\Contracts\MqttClient $mqtt */
        $mqtt = MQTT::connection(); // nutzt die "default_connection" aus config/mqtt-client.php

        $this->info("Subscribing to {$topic} ...");

        $mqtt->subscribe($topic, function (string $topic, string $message) {
            // optional: JSON parsen
            $payload = json_decode($message, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($payload)) {
                $this->info("MQTT scan received");
                Log::info('MQTT scan received', [
                    'topic' => $topic,
                    'device_key' => $payload['device_key'] ?? null,
                    'tracker_uid' => $payload['tracker_uid'] ?? null,
                    'event_time' => $payload['event_time'] ?? null,
                ]);
            } else {
                $this->info("MQTT scan received (raw)");
                Log::info('MQTT scan received (raw)', [
                    'topic' => $topic,
                    'message' => $message,
                    'len' => strlen($message),
                    'json_error' => json_last_error_msg(),
                    'hex_prefix' => bin2hex(substr($message, 0, 32)),
                ]);
            }
        }, 0); // QoS 0 passend zu mosquitto_pub default

        // Endlosschleife: Prozess läuft dauerhaft
        $mqtt->loop(true);

        return self::SUCCESS;
    }
}
