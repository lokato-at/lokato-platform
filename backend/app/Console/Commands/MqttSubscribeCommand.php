<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use App\Support\AppLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once}';
    protected $description = 'Subscribe to scan topic and ingest scans safely.';

    public function handle(ScanIngestService $scanIngestService): int
    {
        $topic = (string) env('MQTT_TOPIC_SCAN', '/api/v1/scan');
        $qos = (int) env('MQTT_QOS', 0);
        $latWarn = (int) env('MQTT_LATENCY_WARN_MS', 3000);
        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        config(['mqtt-client.connections.default.client_id' => $baseClientId]);

        AppLogger::event('mqtt', 'mqtt_subscriber_starting', ['topic'=>$topic,'qos'=>$qos,'client_id'=>$baseClientId], 'info', true);

        $mqtt = MQTT::connection();
        $mqtt->subscribe($topic, function (string $incomingTopic, string $message) use ($scanIngestService, $mqtt, $latWarn) {
            $mqttReceivedAt = now();
            try {
                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
                $scannerSentAt = isset($payload['event_time']) ? Carbon::parse((string) $payload['event_time']) : null;
                $deliveryLatency = $scannerSentAt ? $mqttReceivedAt->diffInMilliseconds($scannerSentAt, false) * -1 : null;

                if ($deliveryLatency !== null && $deliveryLatency > $latWarn) {
                    AppLogger::event('mqtt', 'mqtt_latency_warning', [
                        'mqtt_delivery_latency_ms' => $deliveryLatency,
                        'topic' => $incomingTopic,
                        'scanner_sent_at' => $scannerSentAt?->toIso8601String(),
                        'mqtt_received_at' => $mqttReceivedAt->toIso8601String(),
                    ], 'warning');
                }

                $processingStart = microtime(true);
                $movement = $scanIngestService->ingestScan(
                    deviceKey: (string) ($payload['device_key'] ?? ''),
                    trackerUid: (string) ($payload['tracker_uid'] ?? ''),
                    eventTimeIso: $scannerSentAt?->toIso8601String(),
                    source: 'mqtt_scanner'
                );

                AppLogger::event('mqtt', 'mqtt_message_processed', [
                    'topic' => $incomingTopic,
                    'processing_duration_ms' => (int) ((microtime(true)-$processingStart)*1000),
                    'total_app_latency_ms' => (int) now()->diffInMilliseconds($mqttReceivedAt),
                    'movement_id' => $movement?->id,
                ], AppLogger::shouldLogDiagnostics('mqtt') ? 'info' : 'debug');

                if ($this->option('once')) {
                    $mqtt->interrupt();
                }
            } catch (Throwable $e) {
                AppLogger::exception('mqtt', 'mqtt_message_failed', $e, ['topic' => $incomingTopic]);
            }
        }, $qos);

        $mqtt->loop(true);
        return self::SUCCESS;
    }
}
