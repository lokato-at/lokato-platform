<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once : Process a single message and exit}';
    protected $description = 'Subscribe to Lokato MQTT topics and ingest scan events.';

    public function handle(ScanIngestService $scanIngestService): int
    {
        $host = config('mqtt.host');
        $port = config('mqtt.port');
        $clientId = config('mqtt.client_id') . '-' . getmypid();

        $username = config('mqtt.username');
        $password = config('mqtt.password');

        $topicScan = config('mqtt.topic_scan_wildcard');

        $connectionSettings = (new ConnectionSettings)
            ->setUsername($username ?: null)
            ->setPassword($password ?: null)
            ->setConnectTimeout(5)
            ->setKeepAliveInterval(20);

        $client = new MqttClient($host, $port, $clientId);

        $this->info("Connecting to MQTT broker at {$host}:{$port} as {$clientId}");
        $client->connect($connectionSettings, true);

        $this->info("Subscribing to topic: {$topicScan}");

        $processedOne = false;

        $client->subscribe($topicScan, function (string $topic, string $message) use ($scanIngestService, &$processedOne) {
            try {
                $deviceKey = $this->extractDeviceKeyFromTopic($topic);
                if ($deviceKey === null) {
                    Log::warning('MQTT topic did not match expected pattern', [
                        'topic' => $topic,
                    ]);
                    return;
                }

                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);

                $trackerUid = (string)($payload['tracker_uid'] ?? '');
                $eventTime  = isset($payload['event_time']) ? (string)$payload['event_time'] : null;

                if ($trackerUid === '') {
                    Log::warning('MQTT scan payload missing tracker_uid', [
                        'topic' => $topic,
                        'message' => $message,
                    ]);
                    return;
                }

                $scanIngestService->ingestScan(
                    deviceKey: $deviceKey,
                    trackerUid: $trackerUid,
                    eventTimeIso: $eventTime,
                    source: 'mqtt_scanner'
                );

                $processedOne = true;

            } catch (\Throwable $e) {
                Log::error('Failed to process MQTT scan message', [
                    'topic' => $topic,
                    'message' => $message,
                    'error' => $e->getMessage(),
                ]);
            }
        }, 0); // QoS 0

        while (true) {
            $client->loop(true);

            if ($this->option('once') && $processedOne) {
                break;
            }
        }

        $client->disconnect();
        return self::SUCCESS;
    }

    private function extractDeviceKeyFromTopic(string $topic): ?string
    {
        // Expected: lokato/v1/devices/{deviceKey}/scan
        $parts = explode('/', $topic);

        if (count($parts) !== 5) {
            return null;
        }

        if ($parts[0] !== 'lokato' || $parts[1] !== 'v1' || $parts[2] !== 'devices' || $parts[4] !== 'scan') {
            return null;
        }

        return $parts[3] !== '' ? $parts[3] : null;
    }
}
